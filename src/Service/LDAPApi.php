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
use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonFromUserItemPostEvent;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPreEvent;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonUserItemPreEvent;
use Dbp\Relay\CoreBundle\API\UserSessionInterface;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Helpers\Tools as CoreTools;
use Dbp\Relay\CoreBundle\LocalData\LocalDataAwareEventDispatcher;
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

    private $PAGESIZE = 50;

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

    private $emailAttributeName;

    private $birthdayAttributeName;

    /** @var LocalDataAwareEventDispatcher */
    private $eventDispatcher;

    public function __construct(ContainerInterface $locator, EventDispatcherInterface $dispatcher)
    {
        $this->provider = null;
        $this->cacheTTL = 0;
        $this->currentPerson = null;
        $this->locator = $locator;
        $this->deploymentEnv = 'production';
        $this->eventDispatcher = new LocalDataAwareEventDispatcher(Person::class, $dispatcher);
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
        assert(in_array($encryption, ['start_tls', 'simple_tls'], true));
        $this->providerConfig['use_tls'] = ($encryption === 'start_tls');
        $this->providerConfig['use_ssl'] = ($encryption === 'simple_tls');
        $this->providerConfig['port'] = ($encryption === 'start_tls') ? 389 : 636;
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
    private function getPeopleUserItems(array $filters): array
    {
        try {
            $provider = $this->getProvider();
            $builder = $this->getCachedBuilder($provider);

            $search = $builder
                ->where('objectClass', '=', $provider->getSchema()->person());

            if (isset($filters['search'])) {
                $items = explode(' ', $filters['search']);

                // search for all substrings
                foreach ($items as $item) {
                    $search->whereContains('fullName', $item);
                }
            }

            return $search->sortBy($this->familyNameAttributeName, 'asc')->paginate($this->PAGESIZE)->getResults();
        } catch (\Adldap\Auth\BindException $e) {
            // There was an issue binding / connecting to the server.
            throw ApiError::withDetails(Response::HTTP_BAD_GATEWAY, sprintf('People could not be loaded! Message: %s', CoreTools::filterErrorMessage($e->getMessage())));
        }
    }

    /*
     * @throws ApiError
     */
    public function getPersons(array $options): array
    {
        $this->eventDispatcher->onNewOperation($options);

        $preEvent = new PersonPreEvent();
        $this->eventDispatcher->dispatch($preEvent, PersonPreEvent::NAME);
        $options = array_merge($options, $preEvent->getQueryParameters());

        $persons = [];
        $items = $this->getPeopleUserItems($options);
        foreach ($items as $item) {
            $person = $this->personFromUserItem($item, false);
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
        $this->eventDispatcher->dispatch($preEvent, PersonUserItemPreEvent::NAME);
        $identifier = $preEvent->getIdentifier();

        try {
            $provider = $this->getProvider();
            $builder = $this->getCachedBuilder($provider);

            /** @var User $user */
            $user = $builder
                ->where('objectClass', '=', $provider->getSchema()->person())
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

    public function personFromUserItem(User $user, bool $full): Person
    {
//        $preEvent = new PersonFromUserItemPreEvent($user, $full);
//        $this->eventDispatcher->dispatch($preEvent, PersonFromUserItemPreEvent::NAME);
//        $user = $preEvent->getUser();

        $identifier = $user->getFirstAttribute($this->identifierAttributeName) ?? '';

        $person = new Person();
        $person->setIdentifier($identifier);
        $person->setGivenName($user->getFirstAttribute($this->givenNameAttributeName) ?? '');
        $person->setFamilyName($user->getFirstAttribute($this->familyNameAttributeName) ?? '');

        if ($this->emailAttributeName !== '') {
            $person->setEmail($user->getFirstAttribute($this->emailAttributeName) ?? '');
        }

        $birthDateString = $this->birthdayAttributeName !== '' ?
            trim($user->getFirstAttribute($this->birthdayAttributeName) ?? '') : '';

        if ($birthDateString !== '') {
            $matches = [];

            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $birthDateString, $matches)) {
                $person->setBirthDate("{$matches[1]}-{$matches[2]}-{$matches[3]}");
            }
        }

        // Remove all value with numeric keys
        $attributes = [];
        foreach ($user->getAttributes() as $key => $value) {
            if (!is_numeric($key)) {
                $attributes[$key] = $value;
            }
        }

        $postEvent = new PersonFromUserItemPostEvent($attributes, $person, $full);
        $this->eventDispatcher->dispatch($postEvent, PersonFromUserItemPostEvent::NAME);

        return $postEvent->getPerson();
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
            $person = $this->personFromUserItem($user, true);
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

        $forceCreation = false;

        if ($this->currentPerson) {
            if ($this->currentPerson->getIdentifier() === $currentIdentifier) {
                if (!$checkLocalDataAttributes || $this->eventDispatcher->checkRequestedAttributesIdentical($this->currentPerson)) {
                    return $this->currentPerson;
                } else {
                    // cache a new instance of Person because the cached instance's local data attributes do not match the requested attributes
                    $forceCreation = true;
                }
            }
            $this->currentPerson = null;
        }

        $cache = $this->personCache;
        $cacheKey = $session->getSessionCacheKey().'-'.$currentIdentifier;
        // make sure the cache is longer than the session, so just double it.
        $cacheTTL = $session->getSessionTTL() * 2;
        $person = null;

        $item = $cache->getItem($cacheKey);
        if (!$forceCreation && $item->isHit()) {
            $person = $item->get();
        } else {
            try {
                $user = $this->getPersonUserItem($currentIdentifier);
                $person = $this->personFromUserItem($user, true);
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
    public function getCurrentPerson(): ?Person
    {
        return $this->getCurrentPersonCached(false);
    }

    public static function getSubscribedServices(): array
    {
        return [
            UserSessionInterface::class,
        ];
    }
}
