<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\TestUtils;

use Dbp\Relay\BasePersonConnectorLdapBundle\DependencyInjection\Configuration;
use Dbp\Relay\BasePersonConnectorLdapBundle\EventSubscriber\PersonEventSubscriber;
use Dbp\Relay\BasePersonConnectorLdapBundle\Service\LDAPPersonProvider;
use Dbp\Relay\CoreBundle\TestUtils\TestAuthorizationService;
use Dbp\Relay\CoreBundle\TestUtils\TestUserSession;
use Dbp\Relay\CoreConnectorLdapBundle\TestUtils\TestLdapConnectionProvider;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;

class TestLDAPPersonProvider extends LDAPPersonProvider
{
    public const TEST_USER_IDENTIFIER = 'test-user';
    public const EMAIL_LOCAL_DATA_ATTRIBUTE_NAME = 'email';
    public const BIRTHDATE_LOCAL_DATA_ATTRIBUTE_NAME = 'birthDate';
    public const TEST_LDAP_CONNECTION_IDENTIFIER = TestLdapConnectionProvider::DEFAULT_CONNECTION_IDENTIFIER;

    public const LDAP_CONFIG_DEFAULT = [
        Configuration::LDAP_CONNECTION_ATTRIBUTE => self::TEST_LDAP_CONNECTION_IDENTIFIER,
        Configuration::LDAP_ATTRIBUTES_ATTRIBUTE => [
            Configuration::LDAP_IDENTIFIER_ATTRIBUTE_ATTRIBUTE => 'cn',
            Configuration::LDAP_GIVEN_NAME_ATTRIBUTE_ATTRIBUTE => 'givenName',
            Configuration::LDAP_FAMILY_NAME_ATTRIBUTE_ATTRIBUTE => 'sn',
        ],
        Configuration::LDAP_CURRENT_PERSON_IDENTIFIER_EXPRESSION_ATTRIBUTE => 'user.getIdentifier()',
    ];
    public const LDAP_MAPPING_CONFIG_DEFAULT = [
        [
            'local_data_attribute' => self::EMAIL_LOCAL_DATA_ATTRIBUTE_NAME,
            'source_attribute' => 'email',
            'default_value' => '',
        ],
        [
            'local_data_attribute' => self::BIRTHDATE_LOCAL_DATA_ATTRIBUTE_NAME,
            'source_attribute' => 'dateofbirth',
            'default_value' => '',
        ],
    ];

    public static function createAndSetUp(
        ?array $ldapConfig = null,
        ?array $localDataMappingConfig = null,
        array $personEventSubscribers = []): self
    {
        $testConfig = self::createTestConfig($ldapConfig, $localDataMappingConfig);
        $eventDispatcher = new EventDispatcher();
        $localDataEventSubscriber = new PersonEventSubscriber();
        $localDataEventSubscriber->setConfig($testConfig);
        $eventDispatcher->addSubscriber($localDataEventSubscriber);
        foreach ($personEventSubscribers as $subscriber) {
            $eventDispatcher->addSubscriber($subscriber);
        }

        $personProvider = new self(
            new TestUserSession(self::TEST_USER_IDENTIFIER, isAuthenticated: true),
            $eventDispatcher, TestLdapConnectionProvider::create());

        $personProvider->setConfig($testConfig);
        $personProvider->setLogger(new NullLogger());
        $personProvider->setPersonCache(new ArrayAdapter());
        $personProvider->login();

        return $personProvider;
    }

    public static function createTestConfig(
        ?array $ldapConfig = null,
        ?array $localDataMappingConfig = null): array
    {
        return [
            Configuration::LDAP_ATTRIBUTE => $ldapConfig ?? self::LDAP_CONFIG_DEFAULT,
            'local_data_mapping' => $localDataMappingConfig ?? self::LDAP_MAPPING_CONFIG_DEFAULT,
        ];
    }

    public function tearDown(): void
    {
        $testLdapConnectionProvider = $this->getLdapConnectionProvider();
        assert($testLdapConnectionProvider instanceof TestLdapConnectionProvider);
    }

    public function mockResults(array $results = [],
        ?string $expectCn = null, ?callable $isQueryAsExpected = null,
        string $mockConnectionIdentifier = self::TEST_LDAP_CONNECTION_IDENTIFIER): void
    {
        $testLdapConnectionProvider = $this->getLdapConnectionProvider();
        assert($testLdapConnectionProvider instanceof TestLdapConnectionProvider);
        $testLdapConnectionProvider->mockResults($results, $expectCn, $isQueryAsExpected, $mockConnectionIdentifier);
    }

    public function login(string $userIdentifier = self::TEST_USER_IDENTIFIER, array $userAttributes = []): void
    {
        TestAuthorizationService::setUp($this, $userIdentifier, $userAttributes);
    }
}
