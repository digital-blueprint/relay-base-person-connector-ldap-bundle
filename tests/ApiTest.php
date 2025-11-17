<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Tests;

use Dbp\Relay\CoreBundle\TestUtils\AbstractApiTest;
use Dbp\Relay\CoreConnectorLdapBundle\TestUtils\TestLdapConnectionProvider;
use Symfony\Component\HttpFoundation\Response;

class ApiTest extends AbstractApiTest
{
    private ?TestLdapConnectionProvider $testLdapConnectionProvider = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testLdapConnectionProvider = TestLdapConnectionProvider::create();
        $this->testLdapConnectionProvider->useInApiTest($this->testClient->getContainer());
    }

    public function testGetPerson()
    {
        $this->testLdapConnectionProvider->mockResults([
            [
                'cn' => ['jane_doe'],
                'givenName' => ['Jane'],
                'sn' => ['Doe'],
            ],
        ]);
        $response = $this->testClient->get('/base/people/jane_doe');
        if ($response->getStatusCode() !== 200) {
            dump($response->getContent(false));
        }
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $personArray = json_decode($response->getContent(false), true);
        $this->assertEquals('jane_doe', $personArray['identifier']);
        $this->assertEquals('Jane', $personArray['givenName']);
        $this->assertEquals('Doe', $personArray['familyName']);
    }

    public function testGetPersonNotFound()
    {
        $this->testLdapConnectionProvider->mockResults([]);
        $response = $this->testClient->get('/base/people/foo');
        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testGetPersons()
    {
        $this->testLdapConnectionProvider->mockResults([
            [
                'cn' => ['jane_doe'],
                'givenName' => ['Jane'],
                'sn' => ['Doe'],
            ],
            [
                'cn' => ['john_smith'],
                'givenName' => ['John'],
                'sn' => ['Smith'],
            ],
        ]);
        $response = $this->testClient->get('/base/people');
        if ($response->getStatusCode() !== 200) {
            dump($response->getContent(false));
        }
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $personsArray = json_decode($response->getContent(false), true)['hydra:member'];
        $this->assertCount(2, $personsArray);
        $personArray = $personsArray[0];
        $this->assertEquals('jane_doe', $personArray['identifier']);
        $this->assertEquals('Jane', $personArray['givenName']);
        $this->assertEquals('Doe', $personArray['familyName']);
        $personArray = $personsArray[1];
        $this->assertEquals('john_smith', $personArray['identifier']);
        $this->assertEquals('John', $personArray['givenName']);
        $this->assertEquals('Smith', $personArray['familyName']);
    }
}
