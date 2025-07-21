<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Tests\TestUtils;

use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPostEvent;
use Dbp\Relay\BasePersonConnectorLdapBundle\Event\PersonPreEvent;
use Dbp\Relay\CoreBundle\LocalData\LocalData;
use Dbp\Relay\CoreBundle\Rest\Options;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Nodes\ConditionNode;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Nodes\Node;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class TestPersonEventSubscriber implements EventSubscriberInterface
{
    private array $options = [];
    private ?string $alternativePersonIdentifier = null;

    public static function getSubscribedEvents(): array
    {
        return [
            PersonPreEvent::class => 'onPreEvent',
            PersonPostEvent::class => 'onPostEvent',
        ];
    }

    public function setAlternativePersonIdentifier(?string $identifier): void
    {
        $this->alternativePersonIdentifier = $identifier;
    }

    public function onPreEvent(PersonPreEvent $personPreEvent): void
    {
        $this->options = $personPreEvent->getOptions();
        if ($filter = Options::getFilter($this->options)) {
            $filter->mapConditionNodes(
                function (ConditionNode $conditionNode): Node {
                    if (LocalData::tryGetLocalDataAttributeName($conditionNode->getPath()) === 'test') {
                        $conditionNode->setPath('ldap_test');
                    }

                    return $conditionNode;
                }
            );
        }
        if ($this->alternativePersonIdentifier !== null) {
            $personPreEvent->setIdentifier($this->alternativePersonIdentifier);
        }
    }

    public function onPostEvent(PersonPostEvent $event): void
    {
        $person = $event->getEntity();
        assert($person instanceof Person);
        if ($event->isLocalDataAttributeRequested('test')) {
            $event->setLocalDataAttribute('test', $event->getSourceData()['ldap_test'][0] ?? null);
        }
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}
