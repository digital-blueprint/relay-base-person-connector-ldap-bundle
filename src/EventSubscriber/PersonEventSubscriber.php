<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\EventSubscriber;

use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPostEvent;
use Dbp\Relay\CoreBundle\LocalData\AbstractLocalDataEventSubscriber;

class PersonEventSubscriber extends AbstractLocalDataEventSubscriber
{
    public static function getSubscribedEventNames(): array
    {
        return [
            PersonPostEvent::class,
        ];
    }
}
