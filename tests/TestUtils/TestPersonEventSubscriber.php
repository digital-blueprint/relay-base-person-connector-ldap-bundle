<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Tests\TestUtils;

use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPostEvent;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPreEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class TestPersonEventSubscriber implements EventSubscriberInterface
{
    private array $options = [];

    public static function getSubscribedEvents(): array
    {
        return [
            PersonPreEvent::class => 'onPre',
            PersonPostEvent::class => 'onPost',
        ];
    }

    public function onPre(PersonPreEvent $event): void
    {
        $this->options = $event->getOptions();
    }

    public function onPost(PersonPostEvent $event): void
    {
        $person = $event->getEntity();
        if ($person instanceof Person) {
            $person->setLocalDataValue('test', 'my-test-string');
        }
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}
