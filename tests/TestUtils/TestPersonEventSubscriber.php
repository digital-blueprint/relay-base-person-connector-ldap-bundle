<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Tests\TestUtils;

use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPagePostEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class TestPersonEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            PersonPagePostEvent::class => 'onPost',
        ];
    }

    public function onPost(PersonPagePostEvent $event)
    {
        $person = $event->getEntity();
        if ($person instanceof Person) {
            $person->setLocalDataValue('test', 'my-test-string');
        }
    }
}
