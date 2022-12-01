<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\EventSubscriber;

use Dbp\Relay\BasePersonBundle\Event\PersonProviderPostEvent;
use Dbp\Relay\CoreBundle\LocalData\AbstractLocalDataPostEventSubscriber;

class PersonPostEventSubscriber extends AbstractLocalDataPostEventSubscriber
{
    public static function getSubscribedEventName(): string
    {
        return PersonProviderPostEvent::NAME;
    }
}
