<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\TestUtils;

use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\BasePersonConnectorLdapBundle\API\LDAPApiProviderInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class DummyLDAPApiProvider implements LDAPApiProviderInterface
{
    /**
     * Allows manipulation of the person with a hash array of $attributes at the end of "personFromUserItem".
     */
    public function personFromUserItemPostHook(array $attributes, Person $person, bool $full = false)
    {
        $person->setExtraData('test', 'my-test-string');
    }

    public function getPersonForExternalServiceHook(string $service, string $serviceID): Person
    {
        throw new BadRequestHttpException("Unknown service: $service");
    }
}
