<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Service;

use Dbp\Relay\BasePersonBundle\API\PersonProviderInterface;
use Dbp\Relay\BasePersonBundle\Entity\Person;

class LDAPPersonProvider implements PersonProviderInterface
{
    private $ldapApi;

    public function __construct(LDAPApi $ldapApi)
    {
        $this->ldapApi = $ldapApi;
    }

    public function getPersons(array $filters): array
    {
        return $this->ldapApi->getPersons($filters);
    }

    public function getPerson(string $id): Person
    {
        return $this->ldapApi->getPerson($id);
    }

    public function getCurrentPerson(): ?Person
    {
        return $this->ldapApi->getCurrentPerson();
    }
}
