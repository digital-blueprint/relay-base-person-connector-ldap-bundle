<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

class PersonUserItemPreEvent extends Event
{
    public const NAME = 'dbp.relay.base_person_connector_ldap_bundle.person_user_item.pre';

    protected $identifier;

    public function __construct(string $identifier)
    {
        $this->identifier = $identifier;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }
}
