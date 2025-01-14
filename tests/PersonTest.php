<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dbp\Relay\BasePersonConnectorLdapBundle\DependencyInjection\Configuration;
use Dbp\Relay\BasePersonConnectorLdapBundle\EventSubscriber\PersonEventSubscriber;
use Dbp\Relay\BasePersonConnectorLdapBundle\Service\LDAPApi;
use Dbp\Relay\BasePersonConnectorLdapBundle\Service\LDAPPersonProvider;
use Dbp\Relay\BasePersonConnectorLdapBundle\Tests\TestUtils\TestPersonEventSubscriber;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\Options;
use Dbp\Relay\CoreBundle\TestUtils\TestUserSession;
use Dbp\Relay\CoreConnectorLdapBundle\TestUtils\TestLdapConnectionProvider;
use Symfony\Component\EventDispatcher\EventDispatcher;

class PersonTest extends ApiTestCase
{
    private const EMAIL_ATTRIBUTE_NAME = 'email';
    private const BIRTHDATE_ATTRIBUTE_NAME = 'birthDate';

    private ?LDAPApi $ldapApi = null;
    private ?LDAPPersonProvider $personProvider = null;
    private ?TestLdapConnectionProvider $testLdapConnectionProvider = null;

    protected function setUp(): void
    {
        parent::setUp();

        $customPersonEventSubscriber = new TestPersonEventSubscriber();
        $localDataEventSubscriber = new PersonEventSubscriber();
        $localDataEventSubscriber->setConfig(self::createLocalDataMappingConfig());

        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber($customPersonEventSubscriber);
        $eventDispatcher->addSubscriber($localDataEventSubscriber);

        $this->testLdapConnectionProvider = TestLdapConnectionProvider::create();

        $this->ldapApi = new LDAPApi(new TestUserSession(), $eventDispatcher, $this->testLdapConnectionProvider);
        $this->ldapApi->setConfig([
            Configuration::LDAP_ATTRIBUTE => [
                Configuration::LDAP_CONNECTION_ATTRIBUTE => TestLdapConnectionProvider::DEFAULT_CONNECTION_IDENTIFIER,
                Configuration::LDAP_ATTRIBUTES_ATTRIBUTE => [
                    Configuration::LDAP_IDENTIFIER_ATTRIBUTE_ATTRIBUTE => 'cn',
                    Configuration::LDAP_GIVEN_NAME_ATTRIBUTE_ATTRIBUTE => 'givenName',
                    Configuration::LDAP_FAMILY_NAME_ATTRIBUTE_ATTRIBUTE => 'sn',
                ],
            ],
        ]);

        $this->personProvider = new LDAPPersonProvider($this->ldapApi);
    }

    public function testGetPersonNotFound()
    {
        try {
            $this->testLdapConnectionProvider->mockResults([]);
            $this->personProvider->getPerson('____nope____');
            $this->fail('exception not thrown as expected');
        } catch (ApiError $apiError) {
            $this->assertEquals(404, $apiError->getStatusCode());
        }
    }

    public function testGetPerson()
    {
        $this->testLdapConnectionProvider->mockResults([
            [
                'cn' => ['foobar'],
                'givenName' => ['John'],
                'sn' => ['Doe'],
            ],
        ]);

        $person = $this->personProvider->getPerson('foobar');
        $this->assertEquals('John', $person->getGivenName());
        $this->assertEquals('Doe', $person->getFamilyName());
    }

    public function testLocalDataAttributes()
    {
        $EMAIL = 'john@doe.com';
        $BIRTHDATE = '1994-06-24 00:00:00';
        $this->testLdapConnectionProvider->mockResults([[
            'cn' => ['foobar'],
            'email' => [$EMAIL],
            'dateofbirth' => [$BIRTHDATE],
            'givenName' => ['John'],
            'sn' => ['Doe'],
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
        $this->testLdapConnectionProvider->mockResults([
            [
                'cn' => ['foobar'],
                'givenName' => ['John'],
                'sn' => ['Doe'],
            ],
        ]);

        $person = $this->personProvider->getPerson('foobar');

        $this->assertEquals('my-test-string', $person->getLocalDataValue('test'));
    }

    public function testGetPersonCollection()
    {
        $this->testLdapConnectionProvider->mockResults([
            [
                'cn' => ['foo'],
                'givenName' => ['John'],
                'sn' => ['Doe'],
            ],
            [
                'cn' => ['bar'],
                'givenName' => ['Joni'],
                'sn' => ['Mitchell'],
            ],
            [
                'cn' => ['baz'],
                'givenName' => ['Toni'],
                'sn' => ['Lu'],
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
        $this->testLdapConnectionProvider->mockResults([
            [
                'cn' => ['foo'],
                'givenName' => ['John'],
                'sn' => ['Doe'],
            ],
            [
                'cn' => ['bar'],
                'givenName' => ['Joni'],
                'sn' => ['Mitchell'],
            ],
            [
                'cn' => ['baz'],
                'givenName' => ['Toni'],
                'sn' => ['Lu'],
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
        $this->testLdapConnectionProvider->mockResults([
            [
                'cn' => ['foo'],
                'givenName' => ['John'],
                'sn' => ['Doe'],
                'email' => ['john@doe.com'],
                'dateofbirth' => ['1994-06-24 00:00:00'],
            ],
            [
                'cn' => ['bar'],
                'givenName' => ['Joni'],
                'sn' => ['Mitchell'],
                'email' => ['joni@mitchell.com'],
            ],
            [
                'cn' => ['baz'],
                'givenName' => ['Toni'],
                'sn' => ['Lu'],
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
