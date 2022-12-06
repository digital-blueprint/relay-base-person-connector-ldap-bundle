<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\TestUtils;

use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonUserItemPreEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PersonUserItemSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            PersonUserItemPreEvent::class => 'onPre',
        ];
    }

    public function onPre(PersonUserItemPreEvent $event)
    {
        $identifier = $event->getIdentifier();

        $event->setIdentifier($identifier);
    }
}
