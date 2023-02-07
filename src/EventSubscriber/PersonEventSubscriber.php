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

    protected function onPreEvent(LocalDataPreEvent $preEvent, array $mappedQueryParameters)
    {
        $options = $preEvent->getOptions();
        foreach ($mappedQueryParameters as $sourceParameter => $parameterValue) {
            LDAPApi::addFilter($options, $sourceParameter, LDAPApi::CONTAINS_CI_FILTER_OPERATOR, $parameterValue);
        }
        $preEvent->setOptions($options);
    }
}
