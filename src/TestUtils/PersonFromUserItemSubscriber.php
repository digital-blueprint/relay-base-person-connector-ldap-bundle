<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\TestUtils;

use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonFromUserItemPostEvent;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonFromUserItemPreEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PersonFromUserItemSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            PersonFromUserItemPreEvent::NAME => 'onPre',
            PersonFromUserItemPostEvent::NAME => 'onPost',
        ];
    }

    public function onPre(PersonFromUserItemPreEvent $event)
    {
        $user = $event->getUser();
        $user->setCompany('TestCompany');
        $event->setUser($user);
    }

    public function onPost(PersonFromUserItemPostEvent $event)
    {
        $person = $event->getPerson();
        $person->setExtraData('test', 'my-test-string');
        $event->setPerson($person);
    }
}
