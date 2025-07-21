<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\BasePersonConnectorLdapBundle\DependencyInjection\Configuration;
use Dbp\Relay\BasePersonConnectorLdapBundle\EventSubscriber\PersonEventSubscriber;
use Dbp\Relay\BasePersonConnectorLdapBundle\Service\LDAPPersonProvider;
use Dbp\Relay\BasePersonConnectorLdapBundle\Tests\TestUtils\TestPersonEventSubscriber;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\Options;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterException;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTreeBuilder;
use Dbp\Relay\CoreBundle\Rest\Query\Sort\Sort;
use Dbp\Relay\CoreBundle\TestUtils\TestAuthorizationService;
use Dbp\Relay\CoreBundle\TestUtils\TestUserSession;
use Dbp\Relay\CoreConnectorLdapBundle\TestUtils\TestLdapConnectionProvider;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Response;

class PersonTest extends ApiTestCase
{
    private const EMAIL_ATTRIBUTE_NAME = 'email';
    private const BIRTHDATE_ATTRIBUTE_NAME = 'birthDate';
    private const TEST_USER_IDENTIFIER = 'test-user';
    private const CUSTOM_PERSON_IDENTIFIER = 'custom-person-identifier';
    private const PERSON_IDENTIFIER_USER_ATTRIBUTE = 'PERSON_IDENTIFIER';

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

        $this->personProvider = new LDAPPersonProvider(
            new TestUserSession(self::TEST_USER_IDENTIFIER, isAuthenticated: true),
            $eventDispatcher, $this->testLdapConnectionProvider);
        $this->personProvider->setConfig($this->getLDAPConfig());
        $this->personProvider->setLogger(new NullLogger());
        $this->personProvider->setPersonCache(new ArrayAdapter());
        TestAuthorizationService::setUp($this->personProvider, self::TEST_USER_IDENTIFIER, [
            self::PERSON_IDENTIFIER_USER_ATTRIBUTE => self::CUSTOM_PERSON_IDENTIFIER]);
    }

    protected function tearDown(): void
    {
        $this->testLdapConnectionProvider->tearDown();
    }

    public function testGetPersonNotFound()
    {
        try {
            $this->testLdapConnectionProvider->mockResults(expectCn: '____nope____');
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
        ], 'foobar');

        $person = $this->personProvider->getPerson('foobar');
        $this->assertEquals('John', $person->getGivenName());
        $this->assertEquals('Doe', $person->getFamilyName());
    }

    public function testGetPersonWithCurrentPersonIdentifier()
    {
        $this->testLdapConnectionProvider->mockResults([
            [
                'cn' => [self::TEST_USER_IDENTIFIER],
                'givenName' => ['Test'],
                'sn' => ['User'],
            ],
        ], self::TEST_USER_IDENTIFIER);

        $person = $this->personProvider->getPerson(self::TEST_USER_IDENTIFIER);
        $this->assertEquals('Test', $person->getGivenName());
        $this->assertEquals('User', $person->getFamilyName());

        // try again to see if caching works:
        $samePerson = $this->personProvider->getPerson(self::TEST_USER_IDENTIFIER);
        $this->assertEquals('Test', $samePerson->getGivenName());
        $this->assertEquals('User', $samePerson->getFamilyName());

        $this->assertEquals(spl_object_id($person), spl_object_id($samePerson));
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
        ], isQueryAsExpected: function (string $query): bool {
            return str_contains($query, '(sn='.TestLdapConnectionProvider::toExpectedValue('D').'*)')
                && str_contains($query, '(cn='.TestLdapConnectionProvider::toExpectedValue('foobar').')');
        });

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

    public function testGetPersonWithLocalDataAttributes()
    {
        $EMAIL = 'john@doe.com';
        $BIRTHDATE = '1994-06-24 00:00:00';
        $this->testLdapConnectionProvider->mockResults([
            [
                'cn' => ['foobar'],
                'email' => [$EMAIL],
                'dateofbirth' => [$BIRTHDATE],
                'givenName' => ['John'],
                'sn' => ['Doe'],
            ],
        ], 'foobar');

        $options = [];
        $person = $this->personProvider->getPerson('foobar',
            Options::requestLocalDataAttributes($options, [self::BIRTHDATE_ATTRIBUTE_NAME, self::EMAIL_ATTRIBUTE_NAME]));
        $this->assertEquals('John', $person->getGivenName());
        $this->assertEquals('Doe', $person->getFamilyName());
        $this->assertEquals($BIRTHDATE, $person->getLocalDataValue(self::BIRTHDATE_ATTRIBUTE_NAME));
        $this->assertEquals($EMAIL, $person->getLocalDataValue(self::EMAIL_ATTRIBUTE_NAME));
    }

    public function testGetPersonWithCustomLocalDataAttribute()
    {
        $this->testLdapConnectionProvider->mockResults([
            [
                'cn' => ['foobar'],
                'givenName' => ['John'],
                'sn' => ['Doe'],
                'ldap_test' => ['my-test-string'],
            ],
        ], 'foobar');

        $options = [];
        Options::requestLocalDataAttributes($options, ['test']);
        $person = $this->personProvider->getPerson('foobar', $options);
        $this->assertEquals('John', $person->getGivenName());
        $this->assertEquals('Doe', $person->getFamilyName());
        $this->assertEquals('my-test-string', $person->getLocalDataValue('test'));
    }

    public function testGetPersonWithCustomPreEvent()
    {
        $alternativePersonIdentifier = 'foobar';
        $this->testLdapConnectionProvider->mockResults([
            [
                'cn' => [$alternativePersonIdentifier],
                'givenName' => ['Test'],
                'sn' => ['User'],
            ],
        ], $alternativePersonIdentifier);

        $this->testPersonEventSubscriber->setAlternativePersonIdentifier($alternativePersonIdentifier);
        $person = $this->personProvider->getPerson('foo');
        $this->assertEquals($alternativePersonIdentifier, $person->getIdentifier());
        $this->assertEquals('Test', $person->getGivenName());
        $this->assertEquals('User', $person->getFamilyName());
    }

    public function testGetCurrentPerson()
    {
        $this->testLdapConnectionProvider->mockResults([
            [
                'cn' => [self::TEST_USER_IDENTIFIER],
                'givenName' => ['Test'],
                'sn' => ['User'],
            ],
        ], self::TEST_USER_IDENTIFIER);

        $person = $this->personProvider->getCurrentPerson();
        $this->assertEquals('Test', $person->getGivenName());
        $this->assertEquals('User', $person->getFamilyName());

        // try again to see if caching works:
        $samePerson = $this->personProvider->getCurrentPerson();
        $this->assertEquals('Test', $samePerson->getGivenName());
        $this->assertEquals('User', $samePerson->getFamilyName());

        $this->assertEquals(spl_object_id($person), spl_object_id($samePerson));
    }

    /**
     * @throws FilterException
     */
    public function testGetCurrentPersonWithFilter()
    {
        $this->testLdapConnectionProvider->mockResults([
            [
                'cn' => [self::TEST_USER_IDENTIFIER],
                'givenName' => ['Test'],
                'sn' => ['User'],
            ],
        ], isQueryAsExpected: function (string $query): bool {
            return str_contains($query, '(sn='.TestLdapConnectionProvider::toExpectedValue('User').')')
                && str_contains($query, '(cn='.TestLdapConnectionProvider::toExpectedValue(self::TEST_USER_IDENTIFIER).')');
        });

        $filter = FilterTreeBuilder::create()
            ->equals('familyName', 'User')
            ->createFilter();
        $options = [];
        Options::setFilter($options, $filter);
        $person = $this->personProvider->getCurrentPerson($options);
        $this->assertEquals('Test', $person->getGivenName());
        $this->assertEquals('User', $person->getFamilyName());

        $ldapFilter = FilterTreeBuilder::create()
            ->equals('sn', 'User')
            ->createFilter();

        $this->assertEquals($ldapFilter->toArray(),
            Options::getFilter($this->testPersonEventSubscriber->getOptions())->toArray());

        // try again with a filter -> the cached person must not be reused!
        $this->testLdapConnectionProvider->mockResults([
            [
                'cn' => [self::TEST_USER_IDENTIFIER],
                'givenName' => ['Test'],
                'sn' => ['User'],
            ],
        ]);

        $options = [];
        Options::setFilter($options, FilterTreeBuilder::create()
            ->iContains('familyName', 'us')
            ->createFilter());

        $newInstancePerson = $this->personProvider->getCurrentPerson($options);
        $this->assertNotEquals(spl_object_id($person), spl_object_id($newInstancePerson));
    }

    public function testGetCurrentPersonWithLocalDataAttributes()
    {
        $EMAIL = 'test@user.com';
        $BIRTHDATE = '1994-06-24 00:00:00';
        $this->testLdapConnectionProvider->mockResults([
            [
                'cn' => [self::TEST_USER_IDENTIFIER],
                'email' => [$EMAIL],
                'dateofbirth' => [$BIRTHDATE],
                'givenName' => ['Test'],
                'sn' => ['User'],
            ],
        ], self::TEST_USER_IDENTIFIER);

        $options = [];
        $person = $this->personProvider->getCurrentPerson(
            Options::requestLocalDataAttributes($options, [self::BIRTHDATE_ATTRIBUTE_NAME, self::EMAIL_ATTRIBUTE_NAME]));
        $this->assertEquals('Test', $person->getGivenName());
        $this->assertEquals('User', $person->getFamilyName());
        $this->assertEquals($BIRTHDATE, $person->getLocalDataValue(self::BIRTHDATE_ATTRIBUTE_NAME));
        $this->assertEquals($EMAIL, $person->getLocalDataValue(self::EMAIL_ATTRIBUTE_NAME));

        // try again to see if caching works:
        $options = [];
        $samePerson = $this->personProvider->getCurrentPerson(
            Options::requestLocalDataAttributes($options, [self::BIRTHDATE_ATTRIBUTE_NAME, self::EMAIL_ATTRIBUTE_NAME]));
        $this->assertEquals('Test', $samePerson->getGivenName());
        $this->assertEquals('User', $samePerson->getFamilyName());
        $this->assertEquals($BIRTHDATE, $samePerson->getLocalDataValue(self::BIRTHDATE_ATTRIBUTE_NAME));
        $this->assertEquals($EMAIL, $samePerson->getLocalDataValue(self::EMAIL_ATTRIBUTE_NAME));

        $this->assertEquals(spl_object_id($person), spl_object_id($samePerson));

        // try again with a different set of local data attributes
        $this->testLdapConnectionProvider->mockResults([
            [
                'cn' => [self::TEST_USER_IDENTIFIER],
                'email' => [$EMAIL],
                'dateofbirth' => [$BIRTHDATE],
                'givenName' => ['Test'],
                'sn' => ['User'],
                'ldap_test' => ['my-test-string'],
            ],
        ], self::TEST_USER_IDENTIFIER);

        $options = [];
        $person = $this->personProvider->getCurrentPerson(
            Options::requestLocalDataAttributes($options, ['test']));
        $this->assertEquals('Test', $person->getGivenName());
        $this->assertEquals('User', $person->getFamilyName());
        $this->assertEquals('my-test-string', $person->getLocalDataValue('test'));
        $this->assertNull($person->getLocalDataValue(self::BIRTHDATE_ATTRIBUTE_NAME));
        $this->assertNull($person->getLocalDataValue(self::EMAIL_ATTRIBUTE_NAME));

        // try again with a different set of local data attributes and an empty result set -> 404
        $this->testLdapConnectionProvider->mockResults();

        $options = [];
        try {
            $this->personProvider->getCurrentPerson(
                Options::requestLocalDataAttributes($options, ['foo']));
        } catch (ApiError $apiError) {
            $this->assertEquals(Response::HTTP_NOT_FOUND, $apiError->getStatusCode());
        }
    }

    public function testGetCurrentPersonWithCustomLocalDataAttribute()
    {
        $this->testLdapConnectionProvider->mockResults([
            [
                'cn' => [self::TEST_USER_IDENTIFIER],
                'givenName' => ['Test'],
                'sn' => ['User'],
                'ldap_test' => ['my-test-string'],
            ],
        ], self::TEST_USER_IDENTIFIER);

        $options = [];
        Options::requestLocalDataAttributes($options, ['test']);
        $person = $this->personProvider->getCurrentPerson($options);
        $this->assertEquals('Test', $person->getGivenName());
        $this->assertEquals('User', $person->getFamilyName());
        $this->assertEquals('my-test-string', $person->getLocalDataValue('test'));
    }

    public function testGetCurrentPersonWithCustomPreEvent()
    {
        $alternativePersonIdentifier = 'foobar';
        $this->testLdapConnectionProvider->mockResults([
            [
                'cn' => [$alternativePersonIdentifier],
                'givenName' => ['Test'],
                'sn' => ['User'],
                'ldap_test' => ['my-test-string'],
            ],
        ], $alternativePersonIdentifier);

        $this->testPersonEventSubscriber->setAlternativePersonIdentifier($alternativePersonIdentifier);
        $person = $this->personProvider->getCurrentPerson();
        $this->assertEquals($alternativePersonIdentifier, $person->getIdentifier());
        $this->assertEquals('Test', $person->getGivenName());
        $this->assertEquals('User', $person->getFamilyName());
    }

    public function testGetCurrentPersonIdentifierDefault()
    {
        $this->assertEquals(self::TEST_USER_IDENTIFIER, $this->personProvider->getCurrentPersonIdentifier());
    }

    public function testGetCurrentPersonIdentifierCustom()
    {
        $config = $this->getLDAPConfig();
        $PERSON_IDENTIFIER_USER_ATTRIBUTE = self::PERSON_IDENTIFIER_USER_ATTRIBUTE;
        $config[Configuration::LDAP_ATTRIBUTE][Configuration::LDAP_CURRENT_PERSON_IDENTIFIER_EXPRESSION_ATTRIBUTE] =
            "user.get('$PERSON_IDENTIFIER_USER_ATTRIBUTE')";
        $this->personProvider->setConfig($config);

        $this->assertEquals(self::CUSTOM_PERSON_IDENTIFIER, $this->personProvider->getCurrentPersonIdentifier());
    }

    public function testGetPersons()
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
        ], isQueryAsExpected: function (string $query): bool {
            return $query === '('.TestLdapConnectionProvider::getObjectClassCriteria().')';
        });

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

    public function testGetPersonsWithSort()
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
        ], isQueryAsExpected: function (string $query): bool {
            return $query === '('.TestLdapConnectionProvider::getObjectClassCriteria().')';
        });

        $sort = new Sort([Sort::createSortField('familyName')]);
        $options = [];
        Options::setSort($options, $sort);
        $persons = $this->personProvider->getPersons(1, 3, $options);
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

    public function testGetPersonsWithTwoSortFields()
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
                'givenName' => ['Jane'],
                'sn' => ['Doe'],
            ],
        ], isQueryAsExpected: function (string $query): bool {
            return $query === '('.TestLdapConnectionProvider::getObjectClassCriteria().')';
        });

        $sort = new Sort([Sort::createSortField('familyName'), Sort::createSortField('givenName')]);
        $options = [];
        Options::setSort($options, $sort);
        $persons = $this->personProvider->getPersons(1, 3, $options);
        $this->assertCount(3, $persons);

        $this->assertEquals('baz', $persons[0]->getIdentifier());
        $this->assertEquals('Jane', $persons[0]->getGivenName());
        $this->assertEquals('Doe', $persons[0]->getFamilyName());

        $this->assertEquals('foo', $persons[1]->getIdentifier());
        $this->assertEquals('John', $persons[1]->getGivenName());
        $this->assertEquals('Doe', $persons[1]->getFamilyName());

        $this->assertEquals('bar', $persons[2]->getIdentifier());
        $this->assertEquals('Joni', $persons[2]->getGivenName());
        $this->assertEquals('Mitchell', $persons[2]->getFamilyName());
    }

    public function testGetPersonsWithSortDescending()
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
        ], isQueryAsExpected: function (string $query): bool {
            return $query === '('.TestLdapConnectionProvider::getObjectClassCriteria().')';
        });

        $sort = new Sort([Sort::createSortField('familyName', Sort::DESCENDING_DIRECTION)]);
        $options = [];
        Options::setSort($options, $sort);
        $persons = $this->personProvider->getPersons(1, 3, $options);
        $this->assertCount(3, $persons);
        // NOTE: results must be sorted by family name
        $this->assertEquals('bar', $persons[0]->getIdentifier());
        $this->assertEquals('Joni', $persons[0]->getGivenName());
        $this->assertEquals('Mitchell', $persons[0]->getFamilyName());

        $this->assertEquals('baz', $persons[1]->getIdentifier());
        $this->assertEquals('Toni', $persons[1]->getGivenName());
        $this->assertEquals('Lu', $persons[1]->getFamilyName());

        $this->assertEquals('foo', $persons[2]->getIdentifier());
        $this->assertEquals('John', $persons[2]->getGivenName());
        $this->assertEquals('Doe', $persons[2]->getFamilyName());
    }

    public function testGetPersonsWithSortTooManyResultsError()
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
            [
                'cn' => ['bat'],
                'givenName' => ['T'],
                'sn' => ['Rex'],
            ],
        ]);

        // Note: config of test ldap connection has a limit of 3 results that it will sort -> should refuse
        $sort = new Sort([Sort::createSortField('familyName', Sort::DESCENDING_DIRECTION)]);
        $options = [];
        Options::setSort($options, $sort);
        try {
            $this->personProvider->getPersons(1, 3, $options);
            $this->fail('exception not thrown as expected');
        } catch (ApiError $apiError) {
            $this->assertEquals(Response::HTTP_INSUFFICIENT_STORAGE, $apiError->getStatusCode());
        }
    }

    /**
     * @throws FilterException
     */
    public function testGetPersonsWithFilter(): void
    {
        $this->testLdapConnectionProvider->mockResults([
            [
                'cn' => ['bar'],
                'givenName' => ['Joni'],
                'sn' => ['Mitchell'],
            ],
        ], isQueryAsExpected: function (string $query): bool {
            return str_contains($query, '(sn='.TestLdapConnectionProvider::toExpectedValue('m').'*)');
        });

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

    public function testGetPersonsWithLocalData()
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

    /**
     * @throws FilterException
     */
    public function testGetPersonsWithFilterWithLocalData()
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
        ], isQueryAsExpected: function (string $query): bool {
            return str_contains($query, '(sn=*'.TestLdapConnectionProvider::toExpectedValue('m').')')
                && str_contains($query, '(email=*'.TestLdapConnectionProvider::toExpectedValue('.com').'*)');
        });

        $filter = FilterTreeBuilder::create()
            ->iEndsWith('familyName', 'm')
            ->iContains('localData.email', '.com')
            ->createFilter();

        $options = [];
        Options::setFilter($options, $filter);
        Options::requestLocalDataAttributes($options,
            [self::BIRTHDATE_ATTRIBUTE_NAME, self::EMAIL_ATTRIBUTE_NAME]);
        $persons = $this->personProvider->getPersons(1, 3, $options);
        $this->assertCount(2, $persons);

        $this->assertCount(1, self::selectWhere($persons, function (Person $person) {
            return $person->getIdentifier() === 'foo'
                && $person->getFamilyName() === 'Doe'
                && $person->getGivenName() === 'John'
                && $person->getLocalDataValue(self::BIRTHDATE_ATTRIBUTE_NAME) === '1994-06-24 00:00:00'
                && $person->getLocalDataValue(self::EMAIL_ATTRIBUTE_NAME) === 'john@doe.com';
        }));
        $this->assertCount(1, self::selectWhere($persons, function (Person $person) {
            return $person->getIdentifier() === 'bar'
                && $person->getFamilyName() === 'Mitchell'
                && $person->getGivenName() === 'Joni'
                && $person->getLocalDataValue(self::BIRTHDATE_ATTRIBUTE_NAME) === null
                && $person->getLocalDataValue(self::EMAIL_ATTRIBUTE_NAME) === 'joni@mitchell.com';
        }));

        $ldapFilter = FilterTreeBuilder::create()
            ->iEndsWith('sn', 'm')
            ->iContains('email', '.com')
            ->createFilter();

        $this->assertEquals($ldapFilter->toArray(),
            Options::getFilter($this->testPersonEventSubscriber->getOptions())->toArray());
    }

    /**
     * @throws FilterException
     */
    public function testGetPersonsWithFilterWithCustomLocalDataAttribute()
    {
        $this->testLdapConnectionProvider->mockResults([
            [
                'cn' => ['foobar'],
                'givenName' => ['John'],
                'sn' => ['Doe'],
                'ldap_test' => ['my-test-string'],
            ],
        ], isQueryAsExpected: function (string $query): bool {
            return str_contains($query, '(ldap_test='.TestLdapConnectionProvider::toExpectedValue('my-test-string').')');
        });

        $filter = FilterTreeBuilder::create()
            ->equals('localData.test', 'my-test-string')
            ->createFilter();
        $options = [];
        Options::setFilter($options, $filter);
        Options::requestLocalDataAttributes($options, ['test']);
        $persons = $this->personProvider->getPersons(1, 30, $options);
        $this->assertCount(1, $persons);
        $person = $persons[0];
        $this->assertEquals('John', $person->getGivenName());
        $this->assertEquals('Doe', $person->getFamilyName());
        $this->assertEquals('my-test-string', $person->getLocalDataValue('test'));

        $ldapFilter = FilterTreeBuilder::create()
            ->equals('ldap_test', 'my-test-string')
            ->createFilter();

        $this->assertEquals($ldapFilter->toArray(),
            Options::getFilter($this->testPersonEventSubscriber->getOptions())->toArray());
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

    /**
     * @return array[]
     */
    private function getLDAPConfig(): array
    {
        return [
            Configuration::LDAP_ATTRIBUTE => [
                Configuration::LDAP_CONNECTION_ATTRIBUTE => TestLdapConnectionProvider::DEFAULT_CONNECTION_IDENTIFIER,
                Configuration::LDAP_ATTRIBUTES_ATTRIBUTE => [
                    Configuration::LDAP_IDENTIFIER_ATTRIBUTE_ATTRIBUTE => 'cn',
                    Configuration::LDAP_GIVEN_NAME_ATTRIBUTE_ATTRIBUTE => 'givenName',
                    Configuration::LDAP_FAMILY_NAME_ATTRIBUTE_ATTRIBUTE => 'sn',
                ],
                Configuration::LDAP_CURRENT_PERSON_IDENTIFIER_EXPRESSION_ATTRIBUTE => 'user.getIdentifier()',
            ],
        ];
    }
}
