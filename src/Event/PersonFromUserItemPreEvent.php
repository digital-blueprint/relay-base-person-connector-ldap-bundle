<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Event;

use Adldap\Models\User;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Class PersonFromUserItemPreEvent.
 *
 * This event is currently disabled!
 */
class PersonFromUserItemPreEvent extends Event
{
    public const NAME = 'dbp.relay.base_person_connector_ldap_bundle.person_from_user_item.pre';

    protected $user;
    protected $full;

    public function __construct(User $user, bool $full)
    {
        $this->user = $user;
        $this->full = $full;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function isFull(): bool
    {
        return $this->full;
    }

    public function setUser(User $user): void
    {
        $this->user = $user;
    }
}
