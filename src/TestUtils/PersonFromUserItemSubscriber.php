<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\TestUtils;

use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\BasePersonBundle\Event\PersonProviderPostEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PersonFromUserItemSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            PersonProviderPostEvent::NAME => 'onPost',
        ];
    }

    public function onPost(PersonProviderPostEvent $event)
    {
        $person = $event->getEntity();
        if ($person instanceof Person) {
            $person->setExtraData('test', 'my-test-string');
        }
    }
}
