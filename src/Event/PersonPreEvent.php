<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Event;

use Dbp\Relay\CoreBundle\LocalData\LocalDataPreEvent;

class PersonPreEvent extends LocalDataPreEvent
{
    public const NAME = 'dbp.relay.base_person_connector_ldap_bundle.person_event.pre';
}
