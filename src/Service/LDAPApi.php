<?php

declare(strict_types=1);
/**
 * LDAP wrapper service.
 *
 * @see https://github.com/Adldap2/Adldap2
 */

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Service;

use Adldap\Adldap;
use Adldap\Auth\BindException;
use Adldap\Connections\Provider;
use Adldap\Connections\ProviderInterface;
use Adldap\Models\User;
use Adldap\Query\Builder;
use Adldap\Query\Operator as AdldapOperator;
use Adldap\Query\Paginator as AdldapPaginator;
use Dbp\Relay\BasePersonBundle\Entity\Person;
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
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTreeBuilder;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Nodes\ConditionNode as ConditionFilterNode;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Nodes\LogicalNode as LogicalFilterNode;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Nodes\Node as FilterNode;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Nodes\NodeType as FilterNodeType;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Nodes\OperatorType as FilterOperatorType;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

class LDAPApi implements LoggerAwareInterface, ServiceSubscriberInterface
{
    use LoggerAwareTrait;

    private const IDENTIFIER_ATTRIBUTE_KEY = 'identifier';
    private const GIVEN_NAME_ATTRIBUTE_KEY = 'givenName';
    private const FAMILY_NAME_ATTRIBUTE_KEY = 'familyName';
    private const LOCAL_DATA_BASE_PATH = 'localData.';

    /** @var ProviderInterface|null */
    private $provider;

    /** @var CacheItemPoolInterface|null */
    private $cachePool;

    /** @var CacheItemPoolInterface|null */
    private $personCache;

    private $cacheTTL;

    /** @var Person|null */
    private $currentPerson;

    /** @var array|null */
    private $providerConfig;

    /** @var string */
    private $deploymentEnv;

    /** @var ContainerInterface */
    private $locator;

    /** @var AttributeMapper */
    private $attributeMapper;

    /** @var LocalDataEventDispatcher */
    private $eventDispatcher;

    private static function addFilterToQuery(Builder $query, FilterNode $filterNode, AttributeMapper $attributeMapper)
    {
        if ($filterNode instanceof LogicalFilterNode) {
            switch ($filterNode->getNodeType()) {
                case FilterNodeType::AND:
                    $query->andFilter(function (Builder $builder) use ($attributeMapper, $filterNode) {
                        foreach ($filterNode->getChildren() as $childNodeDefinition) {
                            self::addFilterToQuery($builder, $childNodeDefinition, $attributeMapper);
                        }
                    });
                    break;
                case FilterNodeType::OR:
                    $query->orFilter(function (Builder $builder) use ($attributeMapper, $filterNode) {
                        foreach ($filterNode->getChildren() as $childNodeDefinition) {
                            self::addFilterToQuery($builder, $childNodeDefinition, $attributeMapper);
                        }
                    });
                    break;
                case FilterNodeType::NOT:
                    $query->notFilter(function (Builder $builder) use ($attributeMapper, $filterNode) {
                        foreach ($filterNode->getChildren() as $childNodeDefinition) {
                            self::addFilterToQuery($builder, $childNodeDefinition, $attributeMapper);
                        }
                    });
                    break;
                default:
                    throw new \InvalidArgumentException('invalid filter node type: ', $filterNode->getNodeType());
            }
        } elseif ($filterNode instanceof ConditionFilterNode) {
            $field = $attributeMapper->getTargetAttributePath($filterNode->getField());
            $value = $filterNode->getValue();
            switch ($filterNode->getOperator()) {
                case FilterOperatorType::I_CONTAINS_OPERATOR:
                    $query->whereContains($field, $value);
                    break;
                case FilterOperatorType::EQUALS_OPERATOR: // TODO: case-sensitivity post-precessing required
                    $query->whereEquals($field, $value);
                    break;
                case FilterOperatorType::I_STARTS_WITH_OPERATOR:
                    $query->whereStartsWith($field, $value);
                    break;
                case FilterOperatorType::I_ENDS_WITH_OPERATOR:
                    $query->whereEndsWith($field, $value);
                    break;
                case FilterOperatorType::GREATER_THAN_OR_EQUAL_OPERATOR:
                    $query->where($field, AdldapOperator::$greaterThanOrEquals, $value);
                    break;
                case FilterOperatorType::LESS_THAN_OR_EQUAL_OPERATOR:
                    $query->where($field, AdldapOperator::$lessThanOrEquals, $value);
                    break;
                case FilterOperatorType::IN_ARRAY_OPERATOR:
                    if (!is_array($value)) {
                        throw new \RuntimeException('filter condition operator "'.FilterOperatorType::IN_ARRAY_OPERATOR.'" requires an array type value');
                    }
                    $query->whereIn($field, $value);
                    break;
                case FilterOperatorType::IS_NULL_OPERATOR:
                    $query->whereHas($field);
                    break;
                default:
                    throw new \UnexpectedValueException('unsupported filter condition operator: '.$filterNode->getOperator());
            }
        }
    }

    public function __construct(ContainerInterface $locator, EventDispatcherInterface $dispatcher)
    {
        $this->provider = null;
        $this->cacheTTL = 0;
        $this->currentPerson = null;
        $this->locator = $locator;
        $this->deploymentEnv = 'production';
        $this->eventDispatcher = new LocalDataEventDispatcher(Person::class, $dispatcher);
        $this->attributeMapper = new AttributeMapper();
    }

    public function setConfig(array $config)
    {
        $this->attributeMapper->addMappingEntry(self::IDENTIFIER_ATTRIBUTE_KEY,
            $config['ldap']['attributes']['identifier'] ?? 'cn');
        $this->attributeMapper->addMappingEntry(self::GIVEN_NAME_ATTRIBUTE_KEY,
            $config['ldap']['attributes']['given_name'] ?? 'givenName');
        $this->attributeMapper->addMappingEntry(self::FAMILY_NAME_ATTRIBUTE_KEY,
            $config['ldap']['attributes']['family_name'] ?? 'sn');

        foreach ($config['local_data_mapping'] ?? [] as $localDataMappingEntry) {
            $this->attributeMapper->addMappingEntry(self::LOCAL_DATA_BASE_PATH.$localDataMappingEntry['local_data_attribute'],
                $localDataMappingEntry['source_attribute']);
        }

        $this->providerConfig = [
            'hosts' => [$config['ldap']['host'] ?? ''],
            'base_dn' => $config['ldap']['base_dn'] ?? '',
            'username' => $config['ldap']['username'] ?? '',
            'password' => $config['ldap']['password'] ?? '',
        ];

        $encryption = $config['ldap']['encryption'];
        assert(in_array($encryption, ['start_tls', 'simple_tls', 'plain'], true));
        $this->providerConfig['use_tls'] = ($encryption === 'start_tls');
        $this->providerConfig['use_ssl'] = ($encryption === 'simple_tls');
        $this->providerConfig['port'] = ($encryption === 'start_tls' || $encryption === 'plain') ? 389 : 636;
    }

    public function checkConnection()
    {
        $provider = $this->getProvider();
        $builder = $this->getCachedBuilder($provider);
        $builder->first();
    }

    public function checkAttributeExists(string $attribute): bool
    {
        $provider = $this->getProvider();
        $builder = $this->getCachedBuilder($provider);

        /** @var User $user */
        $user = $builder
            ->where('objectClass', '=', $provider->getSchema()->person())
            ->whereHas($attribute)
            ->first();

        return $user !== null;
    }

    public function checkAttributes()
    {
        $attributes = [
            $this->attributeMapper->getTargetAttributePath(self::IDENTIFIER_ATTRIBUTE_KEY),
            $this->attributeMapper->getTargetAttributePath(self::GIVEN_NAME_ATTRIBUTE_KEY),
            $this->attributeMapper->getTargetAttributePath(self::FAMILY_NAME_ATTRIBUTE_KEY),
        ];

        $missing = [];
        foreach ($attributes as $attr) {
            if ($attr !== '' && !$this->checkAttributeExists($attr)) {
                $missing[] = $attr;
            }
        }

        if (count($missing) > 0) {
            throw new \RuntimeException('The following LDAP attributes were not found: '.join(', ', $missing));
        }
    }

    public function setDeploymentEnvironment(string $env)
    {
        $this->deploymentEnv = $env;
    }

    public function setLDAPCache(?CacheItemPoolInterface $cachePool, int $ttl)
    {
        $this->cachePool = $cachePool;
        $this->cacheTTL = $ttl;
    }

    public function setPersonCache(?CacheItemPoolInterface $cachePool)
    {
        $this->personCache = $cachePool;
    }

    /**
     * For unit testing only.
     */
    public function getEventDispatcher(): LocalDataEventDispatcher
    {
        return $this->eventDispatcher;
    }

    private function getProvider(): ProviderInterface
    {
        if ($this->logger !== null) {
            Adldap::setLogger($this->logger);
        }

        if ($this->provider === null) {
            $ad = new Adldap();
            $ad->addProvider($this->providerConfig);

            $this->provider = $ad->connect();
            assert($this->provider instanceof Provider);

            if ($this->cachePool !== null) {
                $this->provider->setCache(new Psr16Cache($this->cachePool));
            }
        }

        return $this->provider;
    }

    private function getCachedBuilder(ProviderInterface $provider): Builder
    {
        // FIXME: https://github.com/Adldap2/Adldap2/issues/786
        // return $provider->search()->cache($until=$this->cacheTTL);
        // We depend on the default TTL of the cache for now...

        /** @var Builder $builder */
        $builder = $provider->search()->cache();

        return $builder;
    }

    /*
     * @throws ApiError
     */
    private function getPeopleUserItems(int $currentPageNumber, int $maxNumItemsPerPage, Filter $filter): AdldapPaginator
    {
        try {
            $provider = $this->getProvider();
            $query = $this->getCachedBuilder($provider);

            $query = $query
                ->whereEquals('objectClass', $provider->getSchema()->person());

            self::addFilterToQuery($query, $filter->getRootNode(), $this->attributeMapper);

            // dump($query->getUnescapedQuery());

            // API platform's first page is 1, Adldap's first page is 0
            $currentPageIndex = $currentPageNumber - 1;

            return $query->sortBy(
                $this->attributeMapper->getTargetAttributePath(self::FAMILY_NAME_ATTRIBUTE_KEY), 'asc')
                ->paginate($maxNumItemsPerPage, $currentPageIndex);
        } catch (BindException $e) {
            // There was an issue binding / connecting to the server.
            throw ApiError::withDetails(Response::HTTP_BAD_GATEWAY, sprintf('People could not be loaded! Message: %s', CoreTools::filterErrorMessage($e->getMessage())));
        }
    }

    /*
     * @param array $options    Available options are:
     *                          * Person::SEARCH_PARAMETER_NAME (string) Return all persons whose full name contains (case-insensitive) all substrings of the given string (whitespace separated).
     *                          * LDAPApi::FILTERS_OPTIONS (array) Return all persons that pass the given filters. Use LDAPApi::addFilter to add filters.
     *
     * @return Person[]
     *
     * @throws ApiError
     */
    /**
     * @throws \Exception
     */
    public function getPersons(int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        $this->eventDispatcher->onNewOperation($options);

        $preEvent = new PersonPreEvent($options);
        $this->eventDispatcher->dispatch($preEvent);
        $options = $preEvent->getOptions();
        $filter = Options::getFilter($options) ?? Filter::create();

        $searchOption = $options[Person::SEARCH_PARAMETER_NAME] ?? null;
        if (Tools::isNullOrEmpty($searchOption) === false) {
            // full name MUST contain  ALL substrings of search term
            $filterTreeBuilder = FilterTreeBuilder::create($filter->getRootNode());
            $searchTerms = explode(' ', $searchOption);
            foreach ($searchTerms as $searchTerm) {
                $filterTreeBuilder
                    ->or()
                        ->iContains(self::GIVEN_NAME_ATTRIBUTE_KEY, $searchTerm)
                        ->iContains(self::FAMILY_NAME_ATTRIBUTE_KEY, $searchTerm)
                    ->end();
            }
        }

        $persons = [];
        foreach ($this->getPeopleUserItems($currentPageNumber, $maxNumItemsPerPage, $filter) as $userItem) {
            $person = $this->personFromUserItem($userItem);
            if ($person === null) {
                continue;
            }
            $persons[] = $person;
        }

        return $persons;
    }

    /*
     * @throws ApiError
     */
    private function getPersonUserItem(string $identifier): User
    {
        $preEvent = new PersonUserItemPreEvent($identifier);
        $this->eventDispatcher->dispatch($preEvent);
        $identifier = $preEvent->getIdentifier();

        try {
            $provider = $this->getProvider();
            $builder = $this->getCachedBuilder($provider);

            /** @var User $user */
            $user = $builder
                ->whereEquals('objectClass', $provider->getSchema()->person())
                ->whereEquals($this->attributeMapper->getTargetAttributePath(self::IDENTIFIER_ATTRIBUTE_KEY), $identifier)
                ->first();

            if ($user === null) {
                throw ApiError::withDetails(Response::HTTP_NOT_FOUND, sprintf("Person with id '%s' could not be found!", $identifier));
            }

            assert($identifier === $user->getFirstAttribute(
                $this->attributeMapper->getTargetAttributePath(self::IDENTIFIER_ATTRIBUTE_KEY)));

            return $user;
        } catch (BindException $e) {
            // There was an issue binding / connecting to the server.
            throw ApiError::withDetails(Response::HTTP_BAD_GATEWAY, sprintf("Person with id '%s' could not be loaded! Message: %s", $identifier, CoreTools::filterErrorMessage($e->getMessage())));
        }
    }

    /**
     * Returns null in case the user is not a valid Person, for example if the identifier is missing.
     */
    public function personFromUserItem(User $user): ?Person
    {
        $identifier = $user->getFirstAttribute($this->attributeMapper->getTargetAttributePath(self::IDENTIFIER_ATTRIBUTE_KEY));
        if ($identifier === null) {
            return null;
        }

        $person = new Person();
        $person->setIdentifier($identifier);
        $person->setGivenName($user->getFirstAttribute(
            $this->attributeMapper->getTargetAttributePath(self::GIVEN_NAME_ATTRIBUTE_KEY)) ?? '');
        $person->setFamilyName($user->getFirstAttribute(
            $this->attributeMapper->getTargetAttributePath(self::FAMILY_NAME_ATTRIBUTE_KEY)) ?? '');

        // Remove all values with numeric keys
        $attributes = array_filter($user->getAttributes(), function ($key) {
            return !is_numeric($key);
        }, ARRAY_FILTER_USE_KEY);

        $postEvent = new PersonPostEvent($person, $attributes);
        $this->eventDispatcher->dispatch($postEvent);

        return $person;
    }

    /**
     * @thorws ApiError
     */
    public function getPerson(string $id, array $options = []): Person
    {
        $this->eventDispatcher->onNewOperation($options);

        // extract username in case $id is an iri, e.g. /base/people/{user}
        $parts = explode('/', $id);
        $id = $parts[count($parts) - 1];

        $session = $this->getUserSession();
        $currentIdentifier = $session->getUserIdentifier();

        if ($currentIdentifier !== null && $currentIdentifier === $id) {
            // fast path
            $person = $this->getCurrentPersonCached(true);
            assert($person !== null);
        } else {
            $user = $this->getPersonUserItem($id);
            $person = $this->personFromUserItem($user);
            if ($person === null) {
                throw ApiError::withDetails(Response::HTTP_NOT_FOUND, sprintf("Person with id '%s' could not be found!", $id));
            }
        }

        return $person;
    }

    private function getUserSession(): UserSessionInterface
    {
        return $this->locator->get(UserSessionInterface::class);
    }

    /**
     * @thorws ApiError
     */
    private function getCurrentPersonCached(bool $checkLocalDataAttributes): ?Person
    {
        $session = $this->getUserSession();
        $currentIdentifier = $session->getUserIdentifier();
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
        $cacheKey = $session->getSessionCacheKey().'-'.$currentIdentifier;
        // make sure the cache is longer than the session, so just double it.
        $cacheTTL = $session->getSessionTTL() * 2;
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
                $user = $this->getPersonUserItem($currentIdentifier);
                $person = $this->personFromUserItem($user);
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
     * @thorws ApiError
     */
    public function getCurrentPerson(array $options): ?Person
    {
        $this->eventDispatcher->onNewOperation($options);

        return $this->getCurrentPersonCached(count(Options::getLocalDataAttributes($options)) > 0);
    }

    public static function getSubscribedServices(): array
    {
        return [
            UserSessionInterface::class,
        ];
    }
}
