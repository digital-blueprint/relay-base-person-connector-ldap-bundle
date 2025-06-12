<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Service;

use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\BasePersonConnectorLdapBundle\DependencyInjection\Configuration;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPostEvent;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPreEvent;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonUserItemPreEvent;
use Dbp\Relay\CoreBundle\API\UserSessionInterface;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Helpers\Tools as CoreTools;
use Dbp\Relay\CoreBundle\LocalData\LocalDataEventDispatcher;
use Dbp\Relay\CoreBundle\Rest\Options;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Filter;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterException;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTreeBuilder;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Nodes\ConditionNode;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Nodes\LogicalNode;
use Dbp\Relay\CoreBundle\Rest\Query\Sort\Sort;
use Dbp\Relay\CoreConnectorLdapBundle\Ldap\LdapConnection;
use Dbp\Relay\CoreConnectorLdapBundle\Ldap\LdapConnectionProvider;
use Dbp\Relay\CoreConnectorLdapBundle\Ldap\LdapEntryInterface;
use Dbp\Relay\CoreConnectorLdapBundle\Ldap\LdapException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

class LDAPApi implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const IDENTIFIER_ATTRIBUTE_KEY = 'identifier';
    private const GIVEN_NAME_ATTRIBUTE_KEY = 'givenName';
    private const FAMILY_NAME_ATTRIBUTE_KEY = 'familyName';
    private const LOCAL_DATA_BASE_PATH = 'localData.';

    private ?CacheItemPoolInterface $currentPersonCache;
    private ?Person $currentPerson = null;
    private AttributeMapper $attributeMapper;
    private LocalDataEventDispatcher $eventDispatcher;
    private UserSessionInterface $userSession;
    private LdapConnectionProvider $ldapConnectionProvider;
    private ?LdapConnection $ldapConnection = null;
    private ?string $ldapConnectionIdentifier = null;

    public function __construct(UserSessionInterface $userSession, EventDispatcherInterface $dispatcher, LdapConnectionProvider $ldapConnectionProvider)
    {
        $this->userSession = $userSession;
        $this->eventDispatcher = new LocalDataEventDispatcher(Person::class, $dispatcher);
        $this->attributeMapper = new AttributeMapper();
        $this->ldapConnectionProvider = $ldapConnectionProvider;
    }

    public function setConfig(array $config): void
    {
        $this->ldapConnectionIdentifier = $config[Configuration::LDAP_ATTRIBUTE][Configuration::LDAP_CONNECTION_ATTRIBUTE];

        $this->attributeMapper->addMappingEntry(self::IDENTIFIER_ATTRIBUTE_KEY,
            $config[Configuration::LDAP_ATTRIBUTE][Configuration::LDAP_ATTRIBUTES_ATTRIBUTE][Configuration::LDAP_IDENTIFIER_ATTRIBUTE_ATTRIBUTE] ?? 'cn');
        $this->attributeMapper->addMappingEntry(self::GIVEN_NAME_ATTRIBUTE_KEY,
            $config[Configuration::LDAP_ATTRIBUTE][Configuration::LDAP_ATTRIBUTES_ATTRIBUTE][Configuration::LDAP_GIVEN_NAME_ATTRIBUTE_ATTRIBUTE] ?? 'givenName');
        $this->attributeMapper->addMappingEntry(self::FAMILY_NAME_ATTRIBUTE_KEY,
            $config[Configuration::LDAP_ATTRIBUTE][Configuration::LDAP_ATTRIBUTES_ATTRIBUTE][Configuration::LDAP_FAMILY_NAME_ATTRIBUTE_ATTRIBUTE] ?? 'sn');

        foreach ($config['local_data_mapping'] ?? [] as $localDataMappingEntry) {
            $this->attributeMapper->addMappingEntry(self::LOCAL_DATA_BASE_PATH.$localDataMappingEntry['local_data_attribute'],
                $localDataMappingEntry['source_attribute']);
        }
    }

    public function assertAttributesExist()
    {
        $this->getLdapConnection()->assertAttributesExist(array_values($this->attributeMapper->getMappingEntries()));
    }

    public function setPersonCache(?CacheItemPoolInterface $cachePool): void
    {
        $this->currentPersonCache = $cachePool;
    }

    /*
     * @param array $options    Available options are:
     *                          * Person::SEARCH_PARAMETER_NAME (string) Return all persons whose full name contains (case-insensitive) all substrings of the given string (whitespace separated).
     *                          * Options::FILTER Return all persons that pass the given filters.
     *                          * Options::SORTING Return all persons in the defined sort order.
     *
     * @return Person[]
     *
     * @throws ApiError
     */
    public function getPersons(int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        try {
            $this->eventDispatcher->onNewOperation($options);

            $preEvent = new PersonPreEvent($options);
            $this->eventDispatcher->dispatch($preEvent);
            $options = $preEvent->getOptions();
            $ldapOptions = [];

            if ($filter = Options::getFilter($options)) {
                self::replaceAttributeNamesByLdapAttributeNames($filter->getRootNode(), $this->attributeMapper);
            } else {
                $filter = Filter::create();
            }

            if ($searchOption = $options[Person::SEARCH_PARAMETER_NAME] ?? null) {
                // the full name MUST contain ALL search terms
                $filterTreeBuilder = FilterTreeBuilder::create($filter->getRootNode());
                foreach (explode(' ', $searchOption) as $searchTerm) {
                    $searchTerm = trim($searchTerm);
                    $filterTreeBuilder
                        ->or()
                        ->iContains($this->attributeMapper->getTargetAttributePath(self::GIVEN_NAME_ATTRIBUTE_KEY), $searchTerm)
                        ->iContains($this->attributeMapper->getTargetAttributePath(self::FAMILY_NAME_ATTRIBUTE_KEY), $searchTerm)
                        ->end();
                }
            }

            if ($filter->isEmpty() === false) {
                Options::setFilter($ldapOptions, $filter);
            }

            $targetSortFields = [];
            foreach (Options::getSort($options)?->getSortFields() ?? [] as $sortField) {
                $targetSortAttributePath = $this->attributeMapper->getTargetAttributePath(Sort::getPath($sortField));
                if ($targetSortAttributePath === null) {
                    throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'undefined person attribute to sort by: '.Sort::getPath($sortField));
                }
                $targetSortFields[] = Sort::createSortField($targetSortAttributePath, Sort::getDirection($sortField));
            }
            Options::setSort($ldapOptions, new Sort($targetSortFields));

            return $this->getPersonCollection($currentPageNumber, $maxNumItemsPerPage, $ldapOptions);
        } catch (FilterException $filterException) {
            throw new \RuntimeException($filterException->getMessage());
        }
    }

    private function getLdapConnection(): LdapConnection
    {
        if ($this->ldapConnection === null) {
            $ldapConnection = $this->ldapConnectionProvider->getConnection($this->ldapConnectionIdentifier);
            assert($ldapConnection instanceof LdapConnection);
            $this->ldapConnection = $ldapConnection;
        }

        return $this->ldapConnection;
    }

    /*
     * @return LdapEntryInterface[]
     *
     * @throws ApiError
     */
    private function getPersonCollection(int $currentPageNumber, int $maxNumItemsPerPage, array $options): array
    {
        try {
            $persons = [];
            foreach ($this->getLdapConnection()->getEntries($currentPageNumber, $maxNumItemsPerPage, $options) as $userItem) {
                $person = $this->personFromLdapEntry($userItem);
                if ($person === null) { // person without identifier
                    continue;
                }
                $persons[] = $person;
            }

            return $persons;
        } catch (LdapException $ldapException) {
            if ($ldapException->getCode() === LdapException::TOO_MANY_RESULTS_TO_SORT) {
                throw ApiError::withDetails(Response::HTTP_INSUFFICIENT_STORAGE, 'too many results to sort. please refine your search.');
            }
            // There was an issue binding / connecting to the server.
            throw ApiError::withDetails(Response::HTTP_BAD_GATEWAY,
                sprintf('People could not be loaded! Message: %s', CoreTools::filterErrorMessage($ldapException->getMessage())));
        }
    }

    /*
     * @throws ApiError
     */
    private function getPersonItem(string $identifier, array $options): ?Person
    {
        $personUserItemPreEvent = new PersonUserItemPreEvent($identifier);
        $this->eventDispatcher->dispatch($personUserItemPreEvent);
        $identifier = $personUserItemPreEvent->getIdentifier();

        $personPreEvent = new PersonPreEvent($options);
        $this->eventDispatcher->dispatch($personPreEvent);
        $options = $personPreEvent->getOptions();

        try {
            $filter = FilterTreeBuilder::create()
                ->equals('identifier', $identifier)
                ->createFilter();
            if ($filterFromOptions = Options::getFilter($options)) {
                $filter->combineWith($filterFromOptions);
            }
            self::replaceAttributeNamesByLdapAttributeNames($filter->getRootNode(), $this->attributeMapper);
        } catch (FilterException $filterException) {
            throw new \RuntimeException('failed to create filter: '.$filterException->getMessage());
        }

        $ldapOptions = [];
        Options::setFilter($ldapOptions, $filter);

        try {
            $entries = $this->getLdapConnection()->getEntries(1, 1, $ldapOptions);
        } catch (LdapException $ldapException) {
            throw ApiError::withDetails(Response::HTTP_BAD_GATEWAY,
                sprintf("Person with id '%s' could not be loaded! Message: %s", $identifier,
                    CoreTools::filterErrorMessage($ldapException->getMessage())));
        }

        if (empty($entries)) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND,
                sprintf("Person with id '%s' could not be found!", $identifier));
        }

        return $this->personFromLdapEntry($entries[0]);
    }

    /**
     * @thorws ApiError
     */
    public function getPerson(string $identifier, array $options = []): Person
    {
        $this->eventDispatcher->onNewOperation($options);

        // fast path if requested person is the currently logged-in user
        if ($this->userSession->isAuthenticated() && $this->userSession->getUserIdentifier() === $identifier) {
            $person = $this->getCurrentPersonCached(true, $options);
        } else {
            if (($person = $this->getPersonItem($identifier, $options)) === null) {
                // this should never happen (since we have searched by identifier)
                throw new \UnexpectedValueException('identifier missing in LDAP entry');
            }
        }

        return $person;
    }

    /**
     * @thorws ApiError
     */
    public function getCurrentPerson(array $options): ?Person
    {
        $this->eventDispatcher->onNewOperation($options);

        return $this->getCurrentPersonCached(
            count(Options::getLocalDataAttributes($options)) > 0, $options);
    }

    /**
     * Returns null in case the user is not a valid Person, for example if the identifier is missing.
     */
    private function personFromLdapEntry(LdapEntryInterface $ldapEntry): ?Person
    {
        $identifier = $ldapEntry->getFirstAttributeValue(
            $this->attributeMapper->getTargetAttributePath(self::IDENTIFIER_ATTRIBUTE_KEY));
        if ($identifier === null) {
            return null;
        }

        $person = new Person();
        $person->setIdentifier($identifier);
        $person->setGivenName($ldapEntry->getFirstAttributeValue(
            $this->attributeMapper->getTargetAttributePath(self::GIVEN_NAME_ATTRIBUTE_KEY)) ?? '');
        $person->setFamilyName($ldapEntry->getFirstAttributeValue(
            $this->attributeMapper->getTargetAttributePath(self::FAMILY_NAME_ATTRIBUTE_KEY)) ?? '');

        // Remove all values with numeric keys
        $attributes = array_filter($ldapEntry->getAttributeValues(), function ($key) {
            return !is_numeric($key);
        }, ARRAY_FILTER_USE_KEY);

        $postEvent = new PersonPostEvent($person, $attributes);
        $this->eventDispatcher->dispatch($postEvent);

        return $person;
    }

    /**
     * @thorws ApiError
     */
    private function getCurrentPersonCached(bool $checkLocalDataAttributes, array $options): ?Person
    {
        if (false === $this->userSession->isAuthenticated()
            || $this->userSession->isServiceAccount()
            || ($currentIdentifier = $this->userSession->getUserIdentifier()) === null) {
            return null;
        }

        if ($this->currentPerson) {
            if ($this->currentPerson->getIdentifier() === $currentIdentifier
                && (!$checkLocalDataAttributes || $this->eventDispatcher->checkRequestedAttributesIdentical($this->currentPerson))) {
                return $this->currentPerson;
            }
            $this->currentPerson = null;
        }

        $cache = $this->currentPersonCache;
        $cacheKey = $this->userSession->getSessionCacheKey().'-'.$currentIdentifier;
        // make sure the cache is longer than the session, so just double it.
        $cacheTTL = $this->userSession->getSessionTTL() * 2;
        $person = null;

        $item = $cache->getItem($cacheKey);
        if ($item->isHit()) {
            $cachedPerson = $item->get();
            if (!$checkLocalDataAttributes || $this->eventDispatcher->checkRequestedAttributesIdentical($cachedPerson)) {
                $person = $cachedPerson;
            }
        }

        if ($person === null) {
            try {
                // this should never happen (since we have searched by identifier):
                if (($person = $this->getPersonItem($currentIdentifier, $options)) === null) {
                    throw new \UnexpectedValueException('identifier missing in LDAP entry');
                }
            } catch (ApiError $apiError) {
                if ($apiError->getStatusCode() !== Response::HTTP_NOT_FOUND) {
                    throw $apiError;
                }
            }
            $item->set($person);
            $item->expiresAfter($cacheTTL);
            $cache->save($item);
        }

        $this->currentPerson = $person;

        if ($this->currentPerson === null) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, sprintf("Current person with id '%s' could not be found!", $currentIdentifier));
        }

        return $this->currentPerson;
    }

    /**
     * @throws FilterException
     */
    private static function replaceAttributeNamesByLdapAttributeNames(LogicalNode $logicalNode, AttributeMapper $attributeMapper): void
    {
        foreach ($logicalNode->getChildren() as $childNode) {
            if ($childNode instanceof ConditionNode) {
                $targetField = $attributeMapper->getTargetAttributePath($childNode->getField());
                if ($targetField === null) {
                    throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'undefined person attribute to filter by: '.$childNode->getField());
                }
                $childNode->setField($targetField);
            } elseif ($childNode instanceof LogicalNode) {
                self::replaceAttributeNamesByLdapAttributeNames($childNode, $attributeMapper);
            }
        }
    }
}
