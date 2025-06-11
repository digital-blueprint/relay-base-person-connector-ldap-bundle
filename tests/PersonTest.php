<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\BasePersonConnectorLdapBundle\DependencyInjection\Configuration;
use Dbp\Relay\BasePersonConnectorLdapBundle\EventSubscriber\PersonEventSubscriber;
use Dbp\Relay\BasePersonConnectorLdapBundle\Service\LDAPApi;
use Dbp\Relay\BasePersonConnectorLdapBundle\Service\LDAPPersonProvider;
use Dbp\Relay\BasePersonConnectorLdapBundle\Tests\TestUtils\TestPersonEventSubscriber;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\Options;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterException;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTreeBuilder;
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
    private ?TestPersonEventSubscriber $testPersonEventSubscriber = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testPersonEventSubscriber = new TestPersonEventSubscriber();
        $localDataEventSubscriber = new PersonEventSubscriber();
        $localDataEventSubscriber->setConfig(self::createLocalDataMappingConfig());

        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber($this->testPersonEventSubscriber);
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

    /**
     * @throws FilterException
     */
    public function testGetPersonWithFilter()
    {
        $this->testLdapConnectionProvider->mockResults([
            [
                'cn' => ['foobar'],
                'givenName' => ['John'],
                'sn' => ['Doe'],
            ],
        ]);

        $filter = FilterTreeBuilder::create()
            ->iStartsWith('familyName', 'D')
            ->createFilter();
        $options = [];
        Options::setFilter($options, $filter);
        $person = $this->personProvider->getPerson('foobar', $options);
        $this->assertEquals('John', $person->getGivenName());
        $this->assertEquals('Doe', $person->getFamilyName());

        $ldapFilter = FilterTreeBuilder::create()
            ->iStartsWith('sn', 'D')
            ->createFilter();

        $this->assertEquals($ldapFilter->toArray(),
            Options::getFilter($this->testPersonEventSubscriber->getOptions())->toArray());
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
        $this->assertCount(1, self::selectWhere($persons, function ($person) {
            return $person->getIdentifier() === 'foo'
                && $person->getFamilyName() === 'Doe'
                && $person->getGivenName() === 'John';
        }));
        $this->assertCount(1, self::selectWhere($persons, function ($person) {
            return $person->getIdentifier() === 'bar'
                && $person->getFamilyName() === 'Mitchell'
                && $person->getGivenName() === 'Joni';
        }));
        $this->assertCount(1, self::selectWhere($persons, function ($person) {
            return $person->getIdentifier() === 'baz'
                && $person->getFamilyName() === 'Lu'
                && $person->getGivenName() === 'Toni';
        }));
    }

    /**
     * @throws FilterException
     */
    public function testGetPersonCollectionWithFilter(): void
    {
        $this->testLdapConnectionProvider->mockResults([
            [
                'cn' => ['bar'],
                'givenName' => ['Joni'],
                'sn' => ['Mitchell'],
            ],
        ]);

        $filter = FilterTreeBuilder::create()
            ->iStartsWith('familyName', 'm')
            ->createFilter();
        $options = [];
        Options::setFilter($options, $filter);
        $this->personProvider->getPersons(1, 3, $options);

        $ldapFilter = FilterTreeBuilder::create()
            ->iStartsWith('sn', 'm')
            ->createFilter();
        $this->assertEquals($ldapFilter->toArray(),
            Options::getFilter($this->testPersonEventSubscriber->getOptions())->toArray());
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

        $this->assertCount(1, self::selectWhere($persons, function (Person $person) {
            return $person->getIdentifier() === 'foo'
                && $person->getFamilyName() === 'Doe'
                && $person->getGivenName() === 'John'
                && $person->getLocalDataValue(self::BIRTHDATE_ATTRIBUTE_NAME) === '1994-06-24 00:00:00'
                && $person->getLocalDataValue(self::EMAIL_ATTRIBUTE_NAME) === 'john@doe.com';
        }));
        $this->assertCount(1, self::selectWhere($persons, function (Person $person) {
            return $person->getIdentifier() === 'baz'
                && $person->getFamilyName() === 'Lu'
                && $person->getGivenName() === 'Toni'
                && $person->getLocalDataValue(self::BIRTHDATE_ATTRIBUTE_NAME) === null
                && $person->getLocalDataValue(self::EMAIL_ATTRIBUTE_NAME) === null;
        }));
        $this->assertCount(1, self::selectWhere($persons, function (Person $person) {
            return $person->getIdentifier() === 'bar'
                && $person->getFamilyName() === 'Mitchell'
                && $person->getGivenName() === 'Joni'
                && $person->getLocalDataValue(self::BIRTHDATE_ATTRIBUTE_NAME) === null
                && $person->getLocalDataValue(self::EMAIL_ATTRIBUTE_NAME) === 'joni@mitchell.com';
        }));
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

    private static function selectWhere(array $results, callable $where): array
    {
        return array_values(array_filter($results, $where));
    }
}
