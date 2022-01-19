<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\TestUtils;

use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonFromUserItemPostEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PersonFromUserItemSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return [
            PersonFromUserItemPostEvent::NAME => 'onPost',
        ];
    }

    public function onPost(PersonFromUserItemPostEvent $event)
    {
        $person = $event->getPerson();
        $person->setExtraData('test', 'my-test-string');
        $event->setPerson($person);
    }
}
