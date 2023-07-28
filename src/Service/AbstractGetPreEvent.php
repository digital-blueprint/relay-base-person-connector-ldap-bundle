<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Service;

use Symfony\Contracts\EventDispatcher\Event;

class AbstractGetPreEvent extends Event
{
    /*
     * @var array
     */
    private $filters;

    /*
     * @var array
     */
    private $options;

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function setFilters(array $filters): void
    {
        $this->filters = $filters;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function setOptions(array $options): void
    {
        $this->options = $options;
    }
}
