<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Service;

use Symfony\Contracts\EventDispatcher\Event;

class AbstractGetPreEvent extends Event
{
    /* @var array */
    private $options;

    public function getOptions(): array
    {
        return $this->options;
    }

    public function setOptions(array $options): void
    {
        $this->options = $options;
    }
}
