<?php

declare(strict_types=1);
/**
 * LDAP wrapper service.
 *
 * @see https://github.com/Adldap2/Adldap2
 */

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Service;

use Adldap\Adldap;
use Adldap\Connections\Provider;
use Adldap\Connections\ProviderInterface;
use Adldap\Models\User;
use Adldap\Query\Builder;
use Adldap\Query\Operator;
use Adldap\Query\Paginator as AdldapPaginator;
use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPostEvent;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPreEvent;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonUserItemPreEvent;
use Dbp\Relay\CoreBundle\API\UserSessionInterface;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Helpers\Tools as CoreTools;
use Dbp\Relay\CoreBundle\LocalData\LocalData;
use Dbp\Relay\CoreBundle\LocalData\LocalDataEventDispatcher;
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

    public const SEARCH_OPTION = 'search';
    public const FILTERS_OPTION = 'filters';

    public const CONTAINS_CI_FILTER_OPERATOR = 'contains_ci';
    public const AND_LOGICAL_OPERATOR = 'and';
    public const OR_LOGICAL_OPERATOR = 'or';

    private const FILTER_ATTRIBUTE_OPERATOR = 'operator';
    private const FILTER_ATTRIBUTE_FILTER_VALUE = 'filterValue';
    private const FILTER_ATTRIBUTE_LOGICAL_OPERATOR = 'logical';

    private const FULL_NAME_ATTRIBUTE_NAME = 'fullName';

    /** @var ProviderInterface|null */
    private $provider;

    /** @var CacheItemPoolInterface|null */
    private $cachePool;

    /** @var CacheItemPoolInterface|null */
    private $personCache;

    private $cacheTTL;

    /** @var Person|null */
    private $currentPerson;

    private $providerConfig;

    private $deploymentEnv;

    private $locator;

    private $identifierAttributeName;

    private $givenNameAttributeName;

    private $familyNameAttributeName;

    /** @deprecated */
    private $emailAttributeName;

    private $birthdayAttributeName;

    /** @var LocalDataEventDispatcher */
    private $eventDispatcher;

    public static function addFilter(array &$targetOptions, string $fieldName, string $filterOperator, $filterValue, string $logicalOperator = self::AND_LOGICAL_OPERATOR)
    {
        if ($fieldName === '' || $filterOperator === '' || $filterValue === '' || $logicalOperator === '') {
            throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'invalid filter');
        }

        if (isset($targetOptions[self::FILTERS_OPTION]) === false) {
            $targetOptions[self::FILTERS_OPTION] = [];
        }
        if (isset($targetOptions[self::FILTERS_OPTION][$fieldName]) === false) {
            $targetOptions[self::FILTERS_OPTION][$fieldName] = [];
        }

        $targetOptions[self::FILTERS_OPTION][$fieldName][] = [
            self::FILTER_ATTRIBUTE_OPERATOR => $filterOperator,
            self::FILTER_ATTRIBUTE_FILTER_VALUE => $filterValue,
            self::FILTER_ATTRIBUTE_LOGICAL_OPERATOR => $logicalOperator,
        ];
    }

    private static function toAdldapFilterOperator(string $filterOperator): string
    {
        switch ($filterOperator) {
            case self::CONTAINS_CI_FILTER_OPERATOR:
                return Operator::$contains;
                default:
                    throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'unsupported filter operator: '.$filterOperator);
        }
    }

    private static function toAdldapLogicalOperator(string $logicalOperator): string
    {
        switch ($logicalOperator) {
            case self::AND_LOGICAL_OPERATOR:
                return 'and';
            case self::OR_LOGICAL_OPERATOR:
                return 'or';
            default:
                throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'unsupported logical operator: '.$logicalOperator);
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
    }

    public function setConfig(array $config)
    {
        $this->identifierAttributeName = $config['ldap']['attributes']['identifier'] ?? 'cn';
        $this->givenNameAttributeName = $config['ldap']['attributes']['given_name'] ?? 'givenName';
        $this->familyNameAttributeName = $config['ldap']['attributes']['family_name'] ?? 'sn';
        $this->emailAttributeName = $config['ldap']['attributes']['email'] ?? '';
        $this->birthdayAttributeName = $config['ldap']['attributes']['birthday'] ?? '';

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
            $this->identifierAttributeName,
            $this->givenNameAttributeName,
            $this->familyNameAttributeName,
            $this->emailAttributeName,
            $this->birthdayAttributeName,
            self::FULL_NAME_ATTRIBUTE_NAME,
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
    private function getPeopleUserItems(int $currentPageNumber, int $maxNumItemsPerPage, array $options): AdldapPaginator
    {
        try {
            $provider = $this->getProvider();
            $builder = $this->getCachedBuilder($provider);

            $search = $builder
                ->whereEquals('objectClass', $provider->getSchema()->person());

            if ($filtersOption = $options[self::FILTERS_OPTION] ?? null) {
                foreach ($filtersOption as $fieldName => $filters) {
                    foreach ($filters as $filter) {
                        $search->where($fieldName,
                            self::toAdldapFilterOperator($filter[self::FILTER_ATTRIBUTE_OPERATOR]),
                            $filter[self::FILTER_ATTRIBUTE_FILTER_VALUE],
                            self::toAdldapLogicalOperator($filter[self::FILTER_ATTRIBUTE_LOGICAL_OPERATOR]));
                    }
                }
            }

            // API platform's first page is 1, Adldap's first page is 0
            $currentPageIndex = $currentPageNumber - 1;

            return $search->sortBy($this->familyNameAttributeName, 'asc')
                ->paginate($maxNumItemsPerPage, $currentPageIndex);
        } catch (\Adldap\Auth\BindException $e) {
            // There was an issue binding / connecting to the server.
            throw ApiError::withDetails(Response::HTTP_BAD_GATEWAY, sprintf('People could not be loaded! Message: %s', CoreTools::filterErrorMessage($e->getMessage())));
        }
    }

    /*
     * @param array $options    Available options are:
     *                          * LDAPApi::SEARCH_OPTION (string) Return all persons whose full name contains (case-insensitive) all substrings of the given string (whitespace separated).
     *                          * LDAPApi::FILTERS_OPTIONS (array) Return all persons that pass the given filters. Use LDAPApi::addFilter to add filters.
     *
     * @return Person[]
     *
     * @throws ApiError
     */
    public function getPersons(int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        $this->eventDispatcher->onNewOperation($options);

        $preEvent = new PersonPreEvent($options);
        $this->eventDispatcher->dispatch($preEvent);
        $options = $preEvent->getOptions();

        if (($searchOption = $options[self::SEARCH_OPTION] ?? null) && $searchOption !== '') {
            // full name MUST contain  ALL substrings of search term
            foreach (explode(' ', $searchOption) as $searchTermPart) {
                self::addFilter($options, self::FULL_NAME_ATTRIBUTE_NAME, self::CONTAINS_CI_FILTER_OPERATOR, $searchTermPart);
            }
        }

        $persons = [];
        foreach ($this->getPeopleUserItems($currentPageNumber, $maxNumItemsPerPage, $options) as $userItem) {
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
                ->whereEquals($this->identifierAttributeName, $identifier)
                ->first();

            if ($user === null) {
                throw ApiError::withDetails(Response::HTTP_NOT_FOUND, sprintf("Person with id '%s' could not be found!", $identifier));
            }

            assert($identifier === $user->getFirstAttribute($this->identifierAttributeName));

            /* @var User $user */
            return $user;
        } catch (\Adldap\Auth\BindException $e) {
            // There was an issue binding / connecting to the server.
            throw ApiError::withDetails(Response::HTTP_BAD_GATEWAY, sprintf("Person with id '%s' could not be loaded! Message: %s", $identifier, CoreTools::filterErrorMessage($e->getMessage())));
        }
    }

    /**
     * Returns null in case the user is not a valid Person, for example if the identifier is missing.
     */
    public function personFromUserItem(User $user): ?Person
    {
        $identifier = $user->getFirstAttribute($this->identifierAttributeName);
        if ($identifier === null) {
            return null;
        }

        $person = new Person();
        $person->setIdentifier($identifier);
        $person->setGivenName($user->getFirstAttribute($this->givenNameAttributeName) ?? '');
        $person->setFamilyName($user->getFirstAttribute($this->familyNameAttributeName) ?? '');

        if ($this->emailAttributeName !== '') {
            $person->setEmail($user->getFirstAttribute($this->emailAttributeName) ?? '');
        }

        $attributes = [];
        foreach ($user->getAttributes() as $key => $value) {
            // Remove all values with numeric keys
            if (!is_numeric($key)) {
                if ($this->birthdayAttributeName !== '' && $key === $this->birthdayAttributeName) {
                    $birthDateString = trim($user->getFirstAttribute($this->birthdayAttributeName) ?? '');

                    $matches = [];
                    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $birthDateString, $matches)) {
                        $value = "{$matches[1]}-{$matches[2]}-{$matches[3]}";
                    }

                    $person->setBirthDate($value);
                }

                $attributes[$key] = $value;
            }
        }

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
            if ($this->currentPerson->getIdentifier() === $currentIdentifier &&
                (!$checkLocalDataAttributes || $this->eventDispatcher->checkRequestedAttributesIdentical($this->currentPerson))) {
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

        return $this->getCurrentPersonCached(count($options[LocalData::LOCAL_DATA_ATTRIBUTES] ?? []) > 0);
    }

    public static function getSubscribedServices(): array
    {
        return [
            UserSessionInterface::class,
        ];
    }
}
