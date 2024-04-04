<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Service;

class AttributeMapper
{
    /** @var string[] */
    private $mappingEntries = [];

    public function addMappingEntry(string $sourceAttributePath, string $targetAttributePath): self
    {
        $this->mappingEntries[$sourceAttributePath] = $targetAttributePath;

        return $this;
    }

    public function getTargetAttributePath(string $sourceAttributePath): ?string
    {
        return $this->mappingEntries[$sourceAttributePath] ?? null;
    }

    public function getMappingEntries(): array
    {
        return $this->mappingEntries;
    }
}
