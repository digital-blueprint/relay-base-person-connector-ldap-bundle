<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Service;

use Dbp\Relay\BasePersonBundle\API\PersonProviderInterface;
use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\BasePersonConnectorLdapBundle\DependencyInjection\Configuration;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPostEvent;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPreEvent;
use Dbp\Relay\CoreBundle\API\UserSessionInterface;
use Dbp\Relay\CoreBundle\Authorization\AbstractAuthorizationService;
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

class LDAPPersonProvider extends AbstractAuthorizationService implements PersonProviderInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const IDENTIFIER_ATTRIBUTE_KEY = 'identifier';
    private const GIVEN_NAME_ATTRIBUTE_KEY = 'givenName';
    private const FAMILY_NAME_ATTRIBUTE_KEY = 'familyName';
    private const LOCAL_DATA_BASE_PATH = 'localData.';
    private const CURRENT_PERSON_IDENTIFIER_AUTHORIZATION_ATTRIBUTE = 'cpi';

    private ?CacheItemPoolInterface $currentPersonCache = null;
    private ?Person $currentPerson = null;
    private AttributeMapper $attributeMapper;
    private LocalDataEventDispatcher $eventDispatcher;
    private UserSessionInterface $userSession;
    private LdapConnectionProvider $ldapConnectionProvider;
    private ?LdapConnection $ldapConnection = null;
    private ?string $ldapConnectionIdentifier = null;
    private ?string $currentPersonIdentifier = null;
    private bool $wasCurrentPersonIdentifierRetrieved = false;

    public function __construct(UserSessionInterface $userSession, EventDispatcherInterface $dispatcher, LdapConnectionProvider $ldapConnectionProvider)
    {
        parent::__construct();

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

        $attributes = [
            self::CURRENT_PERSON_IDENTIFIER_AUTHORIZATION_ATTRIBUTE => $config[Configuration::LDAP_ATTRIBUTE][Configuration::LDAP_CURRENT_PERSON_IDENTIFIER_EXPRESSION_ATTRIBUTE],
        ];
        $this->setUpAccessControlPolicies(attributes: $attributes);
    }

    public function assertAttributesExist(): void
    {
        $this->getLdapConnection()->assertAttributesExist(array_values($this->attributeMapper->getMappingEntries()));
    }

    public function setPersonCache(?CacheItemPoolInterface $cachePool): void
    {
        $this->currentPersonCache = $cachePool;
    }

    /**
     * @param array $options Available options:
     *
     * @see Person::SEARCH_PARAMETER_NAME (whitespace separated list of search terms to perform a partial case-insensitive text search on person's full name)
     * @see Dbp\Relay\CoreBundle\Rest\Options::LOCAL_DATA_ATTRIBUTES
     * @see Dbp\Relay\CoreBundle\Rest\Options::FILTER
     * @see Dbp\Relay\CoreBundle\Rest\Options::SORT
     *
     * @return Person[]
     *
     * @throws ApiError
     */
    public function getPersons(int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        try {
            $this->onNewOperation($options);

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

    /**
     * Returns the person with the given identifier or
     * throws an HTTP_NOT_FOUND exception if no person with the given ID can be found.
     * NOTE: Implementors must consider the FILTER option, even for the item operation,
     * since the current user might not have read access to the requested person.
     *
     * @param array $options Available options are:
     *
     * @see Dbp\Relay\CoreBundle\Rest\Options::LOCAL_DATA_ATTRIBUTES
     * @see Dbp\Relay\CoreBundle\Rest\Options::FILTER
     *
     * @throws ApiError
     */
    public function getPerson(string $identifier, array $options = []): Person
    {
        return $identifier === $this->getCurrentPersonIdentifierInternal() ?
            $this->getCurrentPersonCached(true, $options) :
            $this->getPersonItem($identifier, $options);
    }

    /**
     * Returns the identifier of the person representing the current user, or null if there is none,
     * e.g., when in a client credentials flow, i.e., the authorized party does not represent a person.
     *
     * @throws ApiError
     */
    public function getCurrentPersonIdentifier(): ?string
    {
        return $this->getCurrentPersonIdentifierInternal();
    }

    /**
     * Returns the person representing the current user, or null if there is none,
     * e.g., when in a client credentials flow, i.e., the authorized party does not represent a person.
     *
     * @param array $options Available options:
     *
     * @see Dbp\Relay\CoreBundle\Rest\Options::LOCAL_DATA_ATTRIBUTES
     *
     * @throws ApiError
     */
    public function getCurrentPerson(array $options = []): ?Person
    {
        $this->onNewOperation($options);

        return $this->getCurrentPersonCached(
            false === empty(Options::getLocalDataAttributes($options)), $options);
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
        $this->onNewOperation($options, $identifier);

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

        if (($person = $this->personFromLdapEntry($entries[0])) === null) {
            // this should never happen (since we have searched by identifier)
            throw new \UnexpectedValueException('identifier missing in LDAP entry');
        }

        return $person;
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
     * @throws ApiError
     */
    private function getCurrentPersonIdentifierInternal(): ?string
    {
        if (false === $this->wasCurrentPersonIdentifierRetrieved) {
            try {
                $this->currentPersonIdentifier = $this->getAttribute(self::CURRENT_PERSON_IDENTIFIER_AUTHORIZATION_ATTRIBUTE);
                $this->wasCurrentPersonIdentifierRetrieved = true;
            } catch (\Exception $exception) {
                $this->logger->error('failed to get current person identifier: '.$exception->getMessage());
                throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR,
                    'failed to get current person identifier');
            }
        }

        return $this->currentPersonIdentifier;
    }

    /**
     * @thorws ApiError
     */
    private function getCurrentPersonCached(bool $checkLocalDataAttributes, array $options): ?Person
    {
        $currentPersonIdentifier = $this->getCurrentPersonIdentifierInternal();
        if ($currentPersonIdentifier === null) {
            return null;
        }

        // if we have a current person and the requested local data attributes are equal or irrelevant
        if ($this->currentPerson) {
            if ($this->currentPerson->getIdentifier() === $currentPersonIdentifier
                && $this->mayReuseCachedCurrentPerson($this->currentPerson, $checkLocalDataAttributes, $options)) {
                return $this->currentPerson;
            }
            $this->currentPerson = null;
        }

        // look into the cache if there is current person with an identical set of local data attributes
        $item = $this->currentPersonCache?->getItem($this->userSession->getSessionCacheKey().'-'.$currentPersonIdentifier);
        if ($item?->isHit()) {
            $cachedPerson = $item->get();
            if ($this->mayReuseCachedCurrentPerson($cachedPerson, $checkLocalDataAttributes, $options)) {
                $this->currentPerson = $cachedPerson;
            }
        }

        // still nothing found -> get a new current person
        if ($this->currentPerson === null) {
            try {
                $this->currentPerson = $this->getPersonItem($currentPersonIdentifier, $options);
            } catch (ApiError $apiError) {
                if ($apiError->getStatusCode() !== Response::HTTP_NOT_FOUND) {
                    throw $apiError;
                }
            }
            $item?->set($this->currentPerson);
            $item?->expiresAfter($this->userSession->getSessionTTL() * 2); // make sure the cache is longer than the session
            $this->currentPersonCache?->save($item);
        }

        if ($this->currentPerson === null) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, sprintf("Current person with id '%s' could not be found!", $currentPersonIdentifier));
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
                if ($targetField = $attributeMapper->getTargetAttributePath($childNode->getPath())) {
                    $childNode->setPath($targetField);
                }
            } elseif ($childNode instanceof LogicalNode) {
                self::replaceAttributeNamesByLdapAttributeNames($childNode, $attributeMapper);
            }
        }
    }

    private function mayReuseCachedCurrentPerson(Person $currentPerson, bool $checkLocalDataAttributes, array $options): bool
    {
        // we can re-use the cached current person if
        // - the local data attributes are equal (or irrelevant)
        // - filtering is not requested
        return (false === $checkLocalDataAttributes
            || $this->eventDispatcher->checkRequestedAttributesIdentical($currentPerson))
            && null === Options::getFilter($options);
    }

    private function onNewOperation(array &$options, ?string &$identifier = null): void
    {
        $this->eventDispatcher->onNewOperation($options);

        $preEvent = new PersonPreEvent($options, $identifier);
        $this->eventDispatcher->dispatch($preEvent);

        $options = $preEvent->getOptions();
        $identifier = $preEvent->getIdentifier();
    }
}
