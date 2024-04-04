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
use Dbp\Relay\CoreBundle\Helpers\Tools;
use Dbp\Relay\CoreBundle\Helpers\Tools as CoreTools;
use Dbp\Relay\CoreBundle\LocalData\LocalDataEventDispatcher;
use Dbp\Relay\CoreBundle\Rest\Options;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Filter;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterException;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTreeBuilder;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Nodes\ConditionNode;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Nodes\LogicalNode;
use Dbp\Relay\CoreBundle\Rest\Query\Sorting\Sorting;
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

    private ?CacheItemPoolInterface $personCache;
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
        $this->personCache = $cachePool;
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

            $searchOption = $options[Person::SEARCH_PARAMETER_NAME] ?? null;
            if (Tools::isNullOrEmpty($searchOption) === false) {
                // full name MUST contain  ALL substrings of search term
                $filterTreeBuilder = FilterTreeBuilder::create($filter->getRootNode());
                $searchTerms = explode(' ', $searchOption);
                foreach ($searchTerms as $searchTerm) {
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

            $sorting = Options::getSorting($options);
            if ($sorting !== null && count($sorting->getSortFields()) > 0) {
                $sortField = Sorting::getPath($sorting->getSortFields()[0]);
            } else {
                // TODO: sorting should be requested by the client, or at least by
                // the base person bundle instead of here (mapping of attribute names required!):
                $sortField = self::FAMILY_NAME_ATTRIBUTE_KEY;
            }

            $targetSortField = $this->attributeMapper->getTargetAttributePath($sortField);
            if ($targetSortField === null) {
                throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'undefined person attribute to sort by: '.$sortField);
            } else {
                Options::setSorting($ldapOptions, new Sorting([Sorting::createSortField($targetSortField)]));
            }

            $persons = [];
            foreach ($this->getPersonEntries($currentPageNumber, $maxNumItemsPerPage, $ldapOptions) as $userItem) {
                $person = $this->personFromLdapEntry($userItem);
                if ($person === null) {
                    continue;
                }
                $persons[] = $person;
            }

            return $persons;
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
    private function getPersonEntries(int $currentPageNumber, int $maxNumItemsPerPage, array $options): array
    {
        try {
            return $this->getLdapConnection()->getEntries($currentPageNumber, $maxNumItemsPerPage, $options);
        } catch (LdapException $ldapException) {
            // There was an issue binding / connecting to the server.
            throw ApiError::withDetails(Response::HTTP_BAD_GATEWAY,
                sprintf('People could not be loaded! Message: %s', CoreTools::filterErrorMessage($ldapException->getMessage())));
        }
    }

    /*
     * @throws ApiError
     */
    private function getPersonEntry(string $identifier): LdapEntryInterface
    {
        $preEvent = new PersonUserItemPreEvent($identifier);
        $this->eventDispatcher->dispatch($preEvent);
        $identifier = $preEvent->getIdentifier();

        try {
            return $this->getLdapConnection()->getEntryByAttribute($this->attributeMapper->getTargetAttributePath(self::IDENTIFIER_ATTRIBUTE_KEY), $identifier);
        } catch (LdapException $ldapException) {
            if ($ldapException->getCode() === LdapException::USER_NOT_FOUND) {
                throw ApiError::withDetails(Response::HTTP_NOT_FOUND,
                    sprintf("Person with id '%s' could not be found!", $identifier));
            }
            throw ApiError::withDetails(Response::HTTP_BAD_GATEWAY,
                sprintf("Person with id '%s' could not be loaded! Message: %s", $identifier,
                    CoreTools::filterErrorMessage($ldapException->getMessage())));
        }
    }

    /**
     * @thorws ApiError
     */
    public function getPerson(string $id, array $options = []): Person
    {
        $this->eventDispatcher->onNewOperation($options);

        $currentIdentifier = $this->userSession->getUserIdentifier();
        if ($currentIdentifier !== null && $currentIdentifier === $id) {
            // fast path
            $person = $this->getCurrentPersonCached(true);
            assert($person !== null);
        } else {
            $personEntry = $this->getPersonEntry($id);
            $person = $this->personFromLdapEntry($personEntry);
            // this should never happen (since we have searched by identifier):
            if ($person === null) {
                throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'identifier missing in LDAP entry');
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

        return $this->getCurrentPersonCached(count(Options::getLocalDataAttributes($options)) > 0);
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
    private function getCurrentPersonCached(bool $checkLocalDataAttributes): ?Person
    {
        $currentIdentifier = $this->userSession->getUserIdentifier();
        if ($currentIdentifier === null) {
            return null;
        }

        if ($this->currentPerson) {
            if ($this->currentPerson->getIdentifier() === $currentIdentifier
                && (!$checkLocalDataAttributes || $this->eventDispatcher->checkRequestedAttributesIdentical($this->currentPerson))) {
                return $this->currentPerson;
            }
            $this->currentPerson = null;
        }

        $cache = $this->personCache;
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
                $ldapEntry = $this->getPersonEntry($currentIdentifier);
                $person = $this->personFromLdapEntry($ldapEntry);
                // this should never happen (since we have searched by identifier):
                if ($person === null) {
                    throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'identifier missing in LDAP entry');
                }
            } catch (ApiError $exc) {
                if ($exc->getStatusCode() !== Response::HTTP_NOT_FOUND) {
                    throw $exc;
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
