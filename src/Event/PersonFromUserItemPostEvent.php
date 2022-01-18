<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Event;

use Dbp\Relay\BasePersonBundle\Entity\Person;
use Symfony\Contracts\EventDispatcher\Event;

class PersonFromUserItemPostEvent extends Event
{
    public const NAME = 'dbp.relay.base_person_connector_ldap_bundle.person_from_user_item.post';

    protected $attributes;
    protected $person;
    protected $full;

    public function __construct(array $attributes, Person $person, bool $full)
    {
        $this->attributes = $attributes;
        $this->person = $person;
        $this->full = $full;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getPerson(): Person
    {
        return $this->person;
    }

    public function isFull(): bool
    {
        return $this->full;
    }

    public function setPerson(Person $person): void
    {
        $this->person = $person;
    }
}
