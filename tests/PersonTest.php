<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dbp\Relay\BasePersonConnectorLdapBundle\EventSubscriber\PersonEventSubscriber;
use Dbp\Relay\BasePersonConnectorLdapBundle\Service\LDAPApi;
use Dbp\Relay\BasePersonConnectorLdapBundle\Service\LDAPPersonProvider;
use Dbp\Relay\BasePersonConnectorLdapBundle\Tests\TestUtils\TestPersonEventSubscriber;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\Options;
use Dbp\Relay\CoreBundle\TestUtils\TestUserSession;
use Dbp\Relay\CoreConnectorLdapBundle\Ldap\LdapConnectionProvider;
use Dbp\Relay\CoreConnectorLdapBundle\Ldap\TestLdapConnection;
use Symfony\Component\EventDispatcher\EventDispatcher;

class PersonTest extends ApiTestCase
{
    private const EMAIL_ATTRIBUTE_NAME = 'email';
    private const BIRTHDATE_ATTRIBUTE_NAME = 'birthDate';

    private ?LDAPApi $ldapApi = null;
    private ?LDAPPersonProvider $personProvider = null;
    private ?TestLdapConnection $testLdapConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $customPersonEventSubscriber = new TestPersonEventSubscriber();
        $localDataEventSubscriber = new PersonEventSubscriber();
        $localDataEventSubscriber->setConfig(self::createLocalDataMappingConfig());

        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber($customPersonEventSubscriber);
        $eventDispatcher->addSubscriber($localDataEventSubscriber);

        $LDAP_CONNECTION_IDENTIFIER = 'test_connection';
        $ldapConnectionProvider = new LdapConnectionProvider();
        $this->testLdapConnection = $ldapConnectionProvider->addTestConnection($LDAP_CONNECTION_IDENTIFIER);

        $this->ldapApi = new LDAPApi(new TestUserSession(), $eventDispatcher, $ldapConnectionProvider);
        $this->ldapApi->setConfig([
            'ldap' => [
                'connection' => $LDAP_CONNECTION_IDENTIFIER,
                'attributes' => [
                    'identifier' => 'id',
                    'given_name' => 'given_name',
                    'family_name' => 'family_name',
                    'email' => 'email',
                    'birthday' => 'dateofbirth',
                ],
            ],
        ]);

        $this->personProvider = new LDAPPersonProvider($this->ldapApi);
    }

    public function testGetPersonNotFound()
    {
        try {
            $this->personProvider->getPerson('____nope____');
            $this->fail('exception not thrown as expected');
        } catch (ApiError $apiError) {
            $this->assertEquals(404, $apiError->getStatusCode());
        }
    }

    public function testGetPerson()
    {
        $this->testLdapConnection->setTestEntries([[
            'id' => ['foobar'],
            'given_name' => ['John'],
            'family_name' => ['Doe'],
        ]]);

        $person = $this->personProvider->getPerson('foobar');
        $this->assertEquals('John', $person->getGivenName());
        $this->assertEquals('Doe', $person->getFamilyName());
    }

    public function testLocalDataAttributes()
    {
        $EMAIL = 'john@doe.com';
        $BIRTHDATE = '1994-06-24 00:00:00';
        $this->testLdapConnection->setTestEntries([[
            'id' => ['foobar'],
            'email' => [$EMAIL],
            'dateofbirth' => [$BIRTHDATE],
            'given_name' => ['John'],
            'family_name' => ['Doe'],
        ]]);

        $options = [];
        $person = $this->personProvider->getPerson('foobar',
            Options::requestLocalDataAttributes($options, [self::BIRTHDATE_ATTRIBUTE_NAME, self::EMAIL_ATTRIBUTE_NAME]));
        $this->assertEquals('John', $person->getGivenName());
        $this->assertEquals('Doe', $person->getFamilyName());
        $this->assertEquals($BIRTHDATE, $person->getLocalDataValue(self::BIRTHDATE_ATTRIBUTE_NAME));
        $this->assertEquals($EMAIL, $person->getLocalDataValue(self::EMAIL_ATTRIBUTE_NAME));
    }

    public function testCustomPostEventSubscriber()
    {
        $this->testLdapConnection->setTestEntries([[
            'id' => ['foobar'],
            'given_name' => ['John'],
            'family_name' => ['Doe'],
        ]]);

        $person = $this->personProvider->getPerson('foobar');

        $this->assertEquals('my-test-string', $person->getLocalDataValue('test'));
    }

    public function testGetPersonCollection()
    {
        $this->testLdapConnection->setTestEntries([
            [
                'id' => ['foo'],
                'given_name' => ['John'],
                'family_name' => ['Doe'],
            ],
            [
                'id' => ['bar'],
                'given_name' => ['Joni'],
                'family_name' => ['Mitchell'],
            ],
            [
                'id' => ['baz'],
                'given_name' => ['Toni'],
                'family_name' => ['Lu'],
            ],
        ]);

        $persons = $this->personProvider->getPersons(1, 3);
        $this->assertCount(3, $persons);
        // NOTE: results must be sorted by family name
        $this->assertEquals('foo', $persons[0]->getIdentifier());
        $this->assertEquals('John', $persons[0]->getGivenName());
        $this->assertEquals('Doe', $persons[0]->getFamilyName());

        $this->assertEquals('baz', $persons[1]->getIdentifier());
        $this->assertEquals('Toni', $persons[1]->getGivenName());
        $this->assertEquals('Lu', $persons[1]->getFamilyName());

        $this->assertEquals('bar', $persons[2]->getIdentifier());
        $this->assertEquals('Joni', $persons[2]->getGivenName());
        $this->assertEquals('Mitchell', $persons[2]->getFamilyName());
    }

    public function testGetPersonCollectionPaginated()
    {
        $this->testLdapConnection->setTestEntries([
            [
                'id' => ['foo'],
                'given_name' => ['John'],
                'family_name' => ['Doe'],
            ],
            [
                'id' => ['bar'],
                'given_name' => ['Joni'],
                'family_name' => ['Mitchell'],
            ],
            [
                'id' => ['baz'],
                'given_name' => ['Toni'],
                'family_name' => ['Lu'],
            ],
        ]);

        $persons = $this->personProvider->getPersons(2, 2);
        $this->assertCount(1, $persons);
        // NOTE: results must be sorted by family name
        $this->assertEquals('bar', $persons[0]->getIdentifier());
        $this->assertEquals('Joni', $persons[0]->getGivenName());
        $this->assertEquals('Mitchell', $persons[0]->getFamilyName());
    }

    public function testGetPersonCollectionWithLocalData()
    {
        $this->testLdapConnection->setTestEntries([
            [
                'id' => ['foo'],
                'given_name' => ['John'],
                'family_name' => ['Doe'],
                'email' => ['john@doe.com'],
                'dateofbirth' => ['1994-06-24 00:00:00'],
            ],
            [
                'id' => ['bar'],
                'given_name' => ['Joni'],
                'family_name' => ['Mitchell'],
                'email' => ['joni@mitchell.com'],
            ],
            [
                'id' => ['baz'],
                'given_name' => ['Toni'],
                'family_name' => ['Lu'],
                'dateofbirth' => [],
            ],
        ]);

        $options = [];
        $persons = $this->personProvider->getPersons(1, 3,
            Options::requestLocalDataAttributes($options, [self::BIRTHDATE_ATTRIBUTE_NAME, self::EMAIL_ATTRIBUTE_NAME]));
        $this->assertCount(3, $persons);

        // NOTE: results must be sorted by family name
        $this->assertEquals('foo', $persons[0]->getIdentifier());
        $this->assertEquals('John', $persons[0]->getGivenName());
        $this->assertEquals('Doe', $persons[0]->getFamilyName());
        $this->assertEquals('1994-06-24 00:00:00', $persons[0]->getLocalDataValue(self::BIRTHDATE_ATTRIBUTE_NAME));
        $this->assertEquals('john@doe.com', $persons[0]->getLocalDataValue(self::EMAIL_ATTRIBUTE_NAME));

        $this->assertEquals('baz', $persons[1]->getIdentifier());
        $this->assertEquals('Toni', $persons[1]->getGivenName());
        $this->assertEquals('Lu', $persons[1]->getFamilyName());
        $this->assertEquals(null, $persons[1]->getLocalDataValue(self::BIRTHDATE_ATTRIBUTE_NAME));
        $this->assertEquals(null, $persons[1]->getLocalDataValue(self::EMAIL_ATTRIBUTE_NAME));

        $this->assertEquals('bar', $persons[2]->getIdentifier());
        $this->assertEquals('Joni', $persons[2]->getGivenName());
        $this->assertEquals('Mitchell', $persons[2]->getFamilyName());
        $this->assertEquals(null, $persons[2]->getLocalDataValue(self::BIRTHDATE_ATTRIBUTE_NAME));
        $this->assertEquals('joni@mitchell.com', $persons[2]->getLocalDataValue(self::EMAIL_ATTRIBUTE_NAME));
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
