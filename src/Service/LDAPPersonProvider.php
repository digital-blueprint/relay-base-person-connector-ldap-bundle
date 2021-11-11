<?php

declare(strict_types=1);

namespace Dbp\Relay\LdapPersonProviderBundle\Service;

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

    public function getPersonsByNameAndBirthDate(string $givenName, string $familyName, string $birthDate): array
    {
        return $this->ldapApi->getPersonsByNameAndBirthDate($givenName, $familyName, $birthDate);
    }

    public function getPerson(string $id): Person
    {
        return $this->ldapApi->getPerson($id);
    }

    public function getPersonForExternalService(string $service, string $serviceID): Person
    {
        return $this->ldapApi->getPersonForExternalService($service, $serviceID);
    }

    public function getCurrentPerson(): ?Person
    {
        return $this->ldapApi->getCurrentPerson();
    }
}
