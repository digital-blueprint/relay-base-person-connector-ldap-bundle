<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\API;

use Dbp\Relay\BasePersonBundle\Entity\Person;

interface LDAPApiProviderInterface
{
    /**
     * Allows manipulation of the person with a hash array of $attributes at the end of "personFromUserItem".
     */
    public function personFromUserItemPostHook(array $attributes, Person $person, bool $full = false);

    /**
     * Allows to fetch a person for a services by service id.
     */
    public function getPersonForExternalServiceHook(string $service, string $serviceID): Person;
}
