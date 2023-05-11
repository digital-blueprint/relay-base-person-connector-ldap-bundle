<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\EventSubscriber;

use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPostEvent;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPreEvent;
use Dbp\Relay\BasePersonConnectorLdapBundle\Service\LDAPApi;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Helpers\Tools;
use Dbp\Relay\CoreBundle\LocalData\AbstractLocalDataEventSubscriber;
use Dbp\Relay\CoreBundle\LocalData\LocalData;
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

    protected function onPreEvent(LocalDataPreEvent $preEvent, array $localQueryAttributes)
    {
        $options = $preEvent->getOptions();

        foreach ($localQueryAttributes as $localQueryAttribute) {
            $filterValue = $localQueryAttribute[self::LOCAL_QUERY_PARAMETER_VALUE_KEY];
            if (Tools::isNullOrEmpty($filterValue)) {
                throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, sprintf('invalid filter value \'%s\'', $filterValue));
            }

            LDAPApi::addFilter($options,
                $localQueryAttribute[self::LOCAL_QUERY_PARAMETER_SOURCE_ATTRIBUTE_KEY],
                self::toLDAPFilterOperator($localQueryAttribute[self::LOCAL_QUERY_PARAMETER_OPERATOR_KEY]),
                $filterValue);
        }

        $preEvent->setOptions($options);
    }

    private function toLDAPFilterOperator(string $localDataQueryOperator): string
    {
        switch ($localDataQueryOperator) {
            case LocalData::LOCAL_QUERY_OPERATOR_CONTAINS_CI:
                return LDAPApi::CONTAINS_CI_FILTER_OPERATOR;
            default:
                throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, sprintf('unknown local data query operator \'%s\'', $localDataQueryOperator));
        }
    }
}
