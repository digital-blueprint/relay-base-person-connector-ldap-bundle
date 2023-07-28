<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Service;

class AbstractGetItemPreEvent extends AbstractGetPreEvent
{
    /*
     * @var string
     */
    private $id;

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }
}
