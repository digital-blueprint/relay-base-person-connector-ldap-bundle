<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\TestUtils;

use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPostEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PersonEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            PersonPostEvent::class => 'onPost',
        ];
    }

    public function onPost(PersonPostEvent $event)
    {
        $person = $event->getEntity();
        if ($person instanceof Person) {
            $person->setExtraData('test', 'my-test-string');
        }
    }
}
