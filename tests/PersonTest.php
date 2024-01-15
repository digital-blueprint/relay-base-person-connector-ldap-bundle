<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Tests;

use Adldap\Connections\ConnectionInterface;
use Adldap\Models\User as AdldapUser;
use Adldap\Query\Builder;
use Adldap\Query\Grammar;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dbp\Relay\BasePersonConnectorLdapBundle\EventSubscriber\PersonEventSubscriber;
use Dbp\Relay\BasePersonConnectorLdapBundle\Service\LDAPApi;
use Dbp\Relay\BasePersonConnectorLdapBundle\Service\LDAPPersonProvider;
use Dbp\Relay\BasePersonConnectorLdapBundle\Tests\TestUtils\TestPersonEventSubscriber;
use Dbp\Relay\CoreBundle\Rest\Options;
use Symfony\Component\EventDispatcher\EventDispatcher;

class PersonTest extends ApiTestCase
{
    private const EMAIL_ATTRIBUTE_NAME = 'email';
    private const BIRTHDATE_ATTRIBUTE_NAME = 'birthDate';

    /**
     * @var LDAPApi
     */
    private $ldapApi;

    /**
     * @var LDAPPersonProvider
     */
    private $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $customPersonEventSubscriber = new TestPersonEventSubscriber();
        $localDataEventSubscriber = new PersonEventSubscriber();
        $localDataEventSubscriber->setConfig(self::createLocalDataMappingConfig());

        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber($customPersonEventSubscriber);
        $eventDispatcher->addSubscriber($localDataEventSubscriber);

        $this->ldapApi = new LDAPApi(self::createClient()->getContainer(), $eventDispatcher);
        $this->ldapApi->setConfig([
            'ldap' => [
                'encryption' => 'simple_tls',
                'attributes' => [
                    'email' => 'email',
                    'birthday' => 'dateofbirth',
                ],
            ],
        ]);

        $this->provider = new LDAPPersonProvider($this->ldapApi);
    }

    public function testBasic()
    {
        $this->expectExceptionMessageMatches('/.*/');
        $this->provider->getPerson('____nope____');
    }

    protected function newBuilder()
    {
        $connection = \Mockery::mock(ConnectionInterface::class);

        return new Builder($connection, new Grammar());
    }

    public function testLDAPParsing()
    {
        $user = new AdldapUser([
            'cn' => ['foobar'],
            'givenName' => ['John'],
            'sn' => ['Doe'],
        ], $this->newBuilder());

        $person = $this->ldapApi->personFromUserItem($user);
        $this->assertEquals('John', $person->getGivenName());
        $this->assertEquals('Doe', $person->getFamilyName());
    }

    public function testLocalDataAttributeBirthDate()
    {
        $options = [];
        Options::requestLocalDataAttributes($options, [self::BIRTHDATE_ATTRIBUTE_NAME]);
        $this->ldapApi->getEventDispatcher()->onNewOperation($options);

        $user = new AdldapUser([
            'cn' => ['foobar'],
            'dateofbirth' => ['1994-06-24 00:00:00'],
            'givenName' => ['givenName'],
            'sn' => ['familyName'],
        ], $this->newBuilder());

        $person = $this->ldapApi->personFromUserItem($user);
        $this->assertEquals('1994-06-24 00:00:00', $person->getLocalDataValue(self::BIRTHDATE_ATTRIBUTE_NAME));
    }

    public function testLocalDataAttributeEmail()
    {
        $options = [];
        Options::requestLocalDataAttributes($options, [self::EMAIL_ATTRIBUTE_NAME]);
        $this->ldapApi->getEventDispatcher()->onNewOperation($options);

        $EMAIL = 'john@doe.com';
        $user = new AdldapUser([
            'cn' => ['johndoe'],
            'givenName' => ['John'],
            'sn' => ['Doe'],
            'email' => [$EMAIL],
        ], $this->newBuilder());

        $person = $this->ldapApi->personFromUserItem($user);
        $this->assertEquals($EMAIL, $person->getLocalDataValue(self::EMAIL_ATTRIBUTE_NAME));
    }

    public function testCustomPostEventSubscriber()
    {
        $user = new AdldapUser([
            'cn' => ['foobar'],
            'givenName' => ['givenName'],
            'sn' => ['familyName'],
        ], $this->newBuilder());

        $person = $this->ldapApi->personFromUserItem($user);

        $this->assertEquals('my-test-string', $person->getLocalDataValue('test'));
    }

    public function testPersonFromUserItemNoIdentifier()
    {
        $user = new AdldapUser([
            'givenName' => ['givenName'],
            'sn' => ['familyName'],
        ], $this->newBuilder());

        $person = $this->ldapApi->personFromUserItem($user);
        $this->assertNull($person);
    }

    private static function createLocalDataMappingConfig(): array
    {
        $config = [];
        $config['local_data_mapping'] = [
            [
                'local_data_attribute' => self::EMAIL_ATTRIBUTE_NAME,
                'source_attribute' => self::EMAIL_ATTRIBUTE_NAME,
                'default_value' => '',
            ],
            [
                'local_data_attribute' => self::BIRTHDATE_ATTRIBUTE_NAME,
                'source_attribute' => 'dateofbirth',
                'default_value' => '',
            ],
        ];

        return $config;
    }
}
