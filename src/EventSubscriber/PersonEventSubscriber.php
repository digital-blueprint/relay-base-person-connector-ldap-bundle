<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\EventSubscriber;

use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPostEvent;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPreEvent;
use Dbp\Relay\BasePersonConnectorLdapBundle\Service\LDAPApi;
use Dbp\Relay\CoreBundle\LocalData\AbstractLocalDataEventSubscriber;
use Dbp\Relay\CoreBundle\LocalData\LocalDataPreEvent;

class PersonEventSubscriber extends AbstractLocalDataEventSubscriber
{
    public static function getSubscribedEventNames(): array
    {
        return [
            PersonPreEvent::class,
            PersonPostEvent::class,
            ];
    }

    protected function onPre(LocalDataPreEvent $preEvent)
    {
        $preEvent->setQueryParameters([LDAPApi::FILTERS_OPTION => $preEvent->getQueryParameters()]);
    }
}
