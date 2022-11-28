<?php

declare(strict_types=1);

namespace Tugraz\Relay\TugrazBundle\Tests;

use Adldap\Connections\ConnectionInterface;
use Adldap\Models\User as AdldapUser;
use Adldap\Query\Builder;
use Adldap\Query\Grammar;
use ApiPlatform\Core\Bridge\Symfony\Bundle\Test\ApiTestCase;
use Dbp\Relay\BasePersonConnectorLdapBundle\Service\LDAPApi;
use Dbp\Relay\BasePersonConnectorLdapBundle\Service\LDAPPersonProvider;
use Dbp\Relay\BasePersonConnectorLdapBundle\TestUtils\PersonFromUserItemSubscriber;
use Dbp\Relay\BasePersonConnectorLdapBundle\TestUtils\PersonUserItemSubscriber;
use Mockery;
use Symfony\Component\EventDispatcher\EventDispatcher;

class PersonTest extends ApiTestCase
{
    /**
     * @var LDAPApi
     */
    private $api;

    /**
     * @var LDAPPersonProvider
     */
    private $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $personFromUserItemSubscriber = new PersonFromUserItemSubscriber();
        $personUserItemSubscriber = new PersonUserItemSubscriber();
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber($personFromUserItemSubscriber);
        $eventDispatcher->addSubscriber($personUserItemSubscriber);

        $this->api = new LDAPApi(self::createClient()->getContainer(), $eventDispatcher);
        $this->api->setConfig([
            'ldap' => [
                'encryption' => 'simple_tls',
                'attributes' => [
                    'email' => 'email',
                    'birthday' => 'dateofbirth',
                ],
            ],
        ]);

        $this->provider = new LDAPPersonProvider($this->api);
    }

    public function testBasic()
    {
        $this->expectExceptionMessageMatches('/.*/');
        $this->provider->getPerson('____nope____');
    }

    protected function newBuilder()
    {
        $connection = Mockery::mock(ConnectionInterface::class);

        return new Builder($connection, new Grammar());
    }

    public function testLDAPParsing()
    {
        $user = new AdldapUser([
            'cn' => ['foobar'],
            'givenName' => ['John'],
            'sn' => ['Doe'],
        ], $this->newBuilder());

        $person = $this->api->personFromUserItem($user, false);
        $this->assertEquals('John', $person->getGivenName());
        $this->assertEquals('Doe', $person->getFamilyName());
    }

    public function testBirthDateParsing()
    {
        $variants = ['1994-06-24', '1994-06-24 00:00:00'];
        foreach ($variants as $variant) {
            $user = new AdldapUser([
                'cn' => ['foobar'],
                'dateofbirth' => [$variant],
                'givenName' => ['givenName'],
                'sn' => ['familyName'],
            ], $this->newBuilder());

            $person = $this->api->personFromUserItem($user, false);
            $this->assertNotNull($person->getBirthDate());
            $this->assertEquals('1994-06-24', $person->getBirthDate());
        }
    }

    public function testPersonFromUserItemPostEvent()
    {
        $user = new AdldapUser([
            'cn' => ['foobar'],
            'givenName' => ['givenName'],
            'sn' => ['familyName'],
        ], $this->newBuilder());

        $person = $this->api->personFromUserItem($user, false);

        $this->assertEquals($person->getExtraData('test'), 'my-test-string');
    }
}
