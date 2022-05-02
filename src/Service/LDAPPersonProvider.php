<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Service;

use Dbp\Relay\BasePersonBundle\API\PersonProviderInterface;
use Dbp\Relay\BasePersonBundle\Entity\Person;

class LDAPPersonProvider implements PersonProviderInterface
{
    /** @var LDAPApi */
    private $ldapApi;

    public function __construct(LDAPApi $ldapApi)
    {
        $this->ldapApi = $ldapApi;
    }

    /**
     * @param array $filters $filters['search'] can be a string to search for people (e.g. part of the name)
     *
     * @return Person[]
     */
    public function getPersons(array $filters): array
    {
        return $this->ldapApi->getPersons($filters);
    }

    public function getPerson(string $id, array $options = []): Person
    {
        return $this->ldapApi->getPerson($id, $options);
    }

    /**
     * Returns the Person matching the current user. Or null if there is no associated person
     * like when the client is another server.
     */
    public function getCurrentPerson(): ?Person
    {
        return $this->ldapApi->getCurrentPerson();
    }
}
