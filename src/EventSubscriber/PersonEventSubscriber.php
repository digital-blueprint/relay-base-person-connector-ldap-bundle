<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\EventSubscriber;

use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPostEvent;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPreEvent;
use Dbp\Relay\BasePersonConnectorLdapBundle\Service\LDAPApi;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Helpers\Tools;
use Dbp\Relay\CoreBundle\LocalData\AbstractLocalDataEventSubscriber;
use Dbp\Relay\CoreBundle\LocalData\LocalDataPreEvent;
use Symfony\Component\HttpFoundation\Response;

class PersonEventSubscriber extends AbstractLocalDataEventSubscriber
{
    public static function getSubscribedEventNames(): array
    {
        return [
            PersonPreEvent::class,
            PersonPostEvent::class,
            ];
    }

    protected function onPreEvent(LocalDataPreEvent $preEvent, array $localQueryFilters)
    {
        $options = $preEvent->getOptions();

        foreach ($localQueryFilters as $localQueryFilter) {
            $filterValue = $localQueryFilter->getValue();
            if (Tools::isNullOrEmpty($filterValue)) {
                throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'LDAP filter value mustn\'t be null or empty');
            }

            LDAPApi::addFilter($options, $localQueryFilter);
        }

        $preEvent->setOptions($options);
    }
}
