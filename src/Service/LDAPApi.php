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
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonItemCreatedEvent;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPagePostEvent;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPagePreEvent;
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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

class LDAPApi extends AbstractDataProviderConnector implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const IDENTIFIER_ATTRIBUTE_KEY = 'identifier';
    private const GIVEN_NAME_ATTRIBUTE_KEY = 'givenName';
    private const FAMILY_NAME_ATTRIBUTE_KEY = 'familyName';
    private const LOCAL_DATA_BASE_PATH = 'localData.';

    /** @var ProviderInterface|null */
    private $provider;

    /** @var CacheItemPoolInterface|null */
    private $ldapCache;

    /** @var CacheItemPoolInterface|null */
    private $personCache;

    /** @var int */
    private $cacheTTL;

    /** @var array|null */
    private $providerConfig;

    /** @var string */
    private $deploymentEnv;

    /** @var UserSessionInterface */
    private $userSession;

    private static function addFilterToQuery(Builder $query, FilterNode $filterNode)
    {
        if ($filterNode instanceof LogicalFilterNode) {
            switch ($filterNode->getNodeType()) {
                case FilterNodeType::AND:
                    $query->andFilter(function (Builder $builder) use ($filterNode) {
                        foreach ($filterNode->getChildren() as $childNodeDefinition) {
                            self::addFilterToQuery($builder, $childNodeDefinition);
                        }
                    });
                    break;
                case FilterNodeType::OR:
                    $query->orFilter(function (Builder $builder) use ($filterNode) {
                        foreach ($filterNode->getChildren() as $childNodeDefinition) {
                            self::addFilterToQuery($builder, $childNodeDefinition);
                        }
                    });
                    break;
                case FilterNodeType::NOT:
                    $query->notFilter(function (Builder $builder) use ($filterNode) {
                        foreach ($filterNode->getChildren() as $childNodeDefinition) {
                            self::addFilterToQuery($builder, $childNodeDefinition);
                        }
                    });
                    break;
                default:
                    throw new \InvalidArgumentException('invalid filter node type: ', $filterNode->getNodeType());
            }
        } elseif ($filterNode instanceof ConditionFilterNode) {
            $field = $filterNode->getField();
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

    public function __construct(UserSessionInterface $userSession)
    {
        parent::__construct(Person::class);

        $this->provider = null;
        $this->cacheTTL = 0;
        $this->userSession = $userSession;
        $this->deploymentEnv = 'production';
    }

    public function setConfig(array $config): void
    {
        $parentConfig = [
            'attributes' => [
                [
                    'name' => self::IDENTIFIER_ATTRIBUTE_KEY,
                    'source_attribute' => $config['ldap']['attributes']['identifier'] ?? 'cn',
                ],
                [
                    'name' => self::GIVEN_NAME_ATTRIBUTE_KEY,
                    'source_attribute' => $config['ldap']['attributes']['given_name'] ?? 'givenName',
                ],
                [
                    'name' => self::FAMILY_NAME_ATTRIBUTE_KEY,
                    'source_attribute' => $config['ldap']['attributes']['family_name'] ?? 'sn',
                ],
            ],
            'local_data_attributes' => [],
        ];

        foreach ($config['local_data_mapping'] ?? [] as $localDataMappingEntry) {
            $parentConfig['local_data_attributes'][] = [
                'name' => $localDataMappingEntry['local_data_attribute'],
                'source_attribute' => $localDataMappingEntry['source_attribute'],
                ];
        }

        parent::setConfig($parentConfig);

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
        $this->getCachedBuilder($this->getProvider())->first();
    }

    private function checkAttributeExists(string $attribute): bool
    {
        $provider = $this->getProvider();

        /** @var User $user */
        $user = $this->getCachedBuilder($provider)
            ->where('objectClass', '=', $provider->getSchema()->person())
            ->whereHas($attribute)
            ->first();

        return $user !== null;
    }

    public function checkAttributes()
    {
        $attributes = [
            $this->getSourceAttributeName(self::IDENTIFIER_ATTRIBUTE_KEY),
            $this->getSourceAttributeName(self::GIVEN_NAME_ATTRIBUTE_KEY),
            $this->getSourceAttributeName(self::FAMILY_NAME_ATTRIBUTE_KEY),
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
        $this->ldapCache = $cachePool;
        $this->cacheTTL = $ttl;
    }

    public function setPersonCache(?CacheItemPoolInterface $cachePool)
    {
        $this->personCache = $cachePool;
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

            if ($this->ldapCache !== null) {
                $this->provider->setCache(new Psr16Cache($this->ldapCache));
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
            $query = $this->getCachedBuilder($provider)
                ->whereEquals('objectClass', $provider->getSchema()->person());

            self::addFilterToQuery($query, $filter->getRootNode());

            // API platform's first page is 1, Adldap's first page is 0
            $currentPageIndex = $currentPageNumber - 1;

            return $query->sortBy(
                $this->getSourceAttributeName(self::FAMILY_NAME_ATTRIBUTE_KEY), 'asc')
                ->paginate($maxNumItemsPerPage, $currentPageIndex);
        } catch (BindException $e) {
            // There was an issue binding / connecting to the server.
            throw ApiError::withDetails(Response::HTTP_BAD_GATEWAY, sprintf('People could not be loaded! Message: %s', CoreTools::filterErrorMessage($e->getMessage())));
        }
    }

    /*
     * @throws ApiError
     */
    private function getPersonUserItem(string $identifier): User
    {
        try {
            $provider = $this->getProvider();

            /** @var User $user */
            $user = $this->getCachedBuilder($provider)
                ->whereEquals('objectClass', $provider->getSchema()->person())
                ->whereEquals($this->getSourceAttributeName(self::IDENTIFIER_ATTRIBUTE_KEY), $identifier)
                ->first();

            if ($user === null) {
                throw ApiError::withDetails(Response::HTTP_NOT_FOUND, sprintf("Person with id '%s' could not be found!", $identifier));
            }

            assert($identifier === $user->getFirstAttribute(
                    $this->getSourceAttributeName(self::IDENTIFIER_ATTRIBUTE_KEY)));

            return $user;
        } catch (BindException $e) {
            // There was an issue binding / connecting to the server.
            throw ApiError::withDetails(Response::HTTP_BAD_GATEWAY, sprintf("Person with id '%s' could not be loaded! Message: %s", $identifier, CoreTools::filterErrorMessage($e->getMessage())));
        }
    }

//    /**
//     * @thorws ApiError
//     */
//    private function getCurrentPersonCached(bool $checkLocalDataAttributes): ?Person
//    {
//        $session = $this->getUserSession();
//        $currentIdentifier = $session->getUserIdentifier();
//        if ($currentIdentifier === null) {
//            return null;
//        }
//
//        if ($this->currentPerson) {
//            if ($this->currentPerson->getIdentifier() === $currentIdentifier &&
//                (!$checkLocalDataAttributes || $this->eventDispatcher->checkRequestedAttributesIdentical($this->currentPerson))) {
//                return $this->currentPerson;
//            }
//            $this->currentPerson = null;
//        }
//
//        $cache = $this->personCache;
//        $cacheKey = $session->getSessionCacheKey().'-'.$currentIdentifier;
//        // make sure the cache is longer than the session, so just double it.
//        $cacheTTL = $session->getSessionTTL() * 2;
//        $person = null;
//
//        $item = $cache->getItem($cacheKey);
//        if ($item->isHit()) {
//            $cachedPerson = $item->get();
//            if (!$checkLocalDataAttributes || $this->eventDispatcher->checkRequestedAttributesIdentical($cachedPerson)) {
//                $person = $cachedPerson;
//            }
//        }
//
//        if ($person === null) {
//            try {
//                $user = $this->getPersonUserItem($currentIdentifier);
//                $person = $this->personFromUserItem($user);
//            } catch (ApiError $exc) {
//                if ($exc->getStatusCode() !== Response::HTTP_NOT_FOUND) {
//                    throw $exc;
//                }
//            }
//            $item->set($person);
//            $item->expiresAfter($cacheTTL);
//            $cache->save($item);
//        }
//
//        $this->currentPerson = $person;
//
//        if ($this->currentPerson === null) {
//            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, sprintf("Current person with id '%s' could not be found!", $currentIdentifier));
//        }
//
//        return $this->currentPerson;
//    }

    protected function createItemCreatedEvent(object $item, array $itemData): ?AbstractItemCreatedEvent
    {
        return new PersonItemCreatedEvent($item, $itemData);
    }

    protected function getItemDataById(string $id, array $options): ?array
    {
        return $this->getPersonItemData($this->getPersonUserItem($id));
    }

    /**
     * @throws FilterException
     */
    protected function getItemDataCollection(int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
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

        $personDataPage = [];
        foreach ($this->getPeopleUserItems($currentPageNumber, $maxNumItemsPerPage, $filter) as $userItem) {
            $personDataPage[] = $this->getPersonItemData($userItem);
        }

        return $personDataPage;
    }

    private function getPersonItemData(User $user): array
    {
        // Remove all values with numeric keys
        return array_filter($user->getAttributes(), function ($key) {
            return !is_numeric($key);
        }, ARRAY_FILTER_USE_KEY);
    }
}
