<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\EventSubscriber;

use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPostEvent;
use Dbp\Relay\CoreBundle\LocalData\AbstractLocalDataPostEventSubscriber;

class PersonPostEventSubscriber extends AbstractLocalDataPostEventSubscriber
{
    public static function getSubscribedEventName(): string
    {
        return PersonPostEvent::class;
    }
}
