<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Event;

use Dbp\Relay\BasePersonBundle\Entity\Person;
use Symfony\Contracts\EventDispatcher\Event;

class PersonForExternalServiceEvent extends Event
{
    public const NAME = 'dbp.relay.base_person_connector_ldap_bundle.person_for_external_service';

    protected $service;
    protected $serviceID;
    protected $person;

    public function __construct(string $service, string $serviceID)
    {
        $this->service = $service;
        $this->serviceID = $serviceID;
        $this->person = null;
    }

    public function getService(): string
    {
        return $this->service;
    }

    public function getServiceID(): string
    {
        return $this->serviceID;
    }

    public function setPerson(Person $person): void
    {
        $this->person = $person;
    }

    public function getPerson(): ?Person
    {
        return $this->person;
    }
}
