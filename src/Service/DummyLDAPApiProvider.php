<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Service;

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
        // For example, you can parse the date of birth from the LDAP attribute and set it to the person object.

//        $birthDate = $attributes['dateofbirth'][0];
//        $person->setBirthDate($birthDate);
    }

    public function getPersonForExternalServiceHook(string $service, string $serviceID): Person
    {
        // For example, you can use the service and serviceID to get the person from some other service.

//        if ($service === 'SOME-SERVICE') {
//            return getPersonFromSomeService($serviceID);
//        }

        throw new BadRequestHttpException("Unknown service: $service");
    }
}
