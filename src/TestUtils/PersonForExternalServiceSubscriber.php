<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\TestUtils;

use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonForExternalServiceEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PersonForExternalServiceSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            PersonForExternalServiceEvent::NAME => 'onEvent',
        ];
    }

    public function onEvent(PersonForExternalServiceEvent $event)
    {
        $service = $event->getService();
        $serviceID = $event->getServiceID();

        if ($service === 'test-service') {
            $person = new Person();
            $person->setExtraData('test-service', 'my-test-service-string-'.$serviceID);
            $event->setPerson($person);
        }
    }
}
