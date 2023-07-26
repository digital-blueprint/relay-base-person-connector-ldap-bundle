<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Service;

use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

class EntityFactory
{
    private const ATTRIBUTES_CONFIG_NODE = 'attributes';
    private const PATH_CONFIG_NODE = 'path';
    private const SOURCE_ATTRIBUTE_CONFIG_NODE = 'source_attribute';

    /**
     * @var array
     */
    private $pathMapping;

    /**
     * @var array
     */
    private $paths;

    /**
     * @var string
     */
    private $entityClass;

    /**
     * @var callable|null
     */
    private $mapValue;

    /** @var  */
    private $config;

    public function __construct(string $entityClass, array $paths, array $pathMapping = [], callable $mapValue = null)
    {
        $this->entityClass = $entityClass;
        $this->paths = $paths;
        $this->pathMapping = $pathMapping;
        $this->mapValue = $mapValue;
    }

    public function setConfig(array $config): void
    {
    }

    /**
     * @throws \Exception
     */
    public function createFromDataRow(array $dataRow, array $requestedLocalDataAttributes = []): object
    {
        $normalizedEntity = [];

        foreach ($this->paths as $path) {
            $dataPath = $this->pathMapping[$path] ?? $path;
            $dataValue = $dataRow[strtolower($dataPath)] ?? null;
            $dataValue = $this->mapValue !== null ? ($this->mapValue)($dataValue) : $dataValue;
            if ($dataValue === null) {
                continue;
            }
            $pathParts = explode('.', $path);
            if (count($pathParts) === 1) {
                $normalizedEntity[$path] = $dataValue;
            } elseif (count($pathParts) === 2) {
                $normalizedEntity[$pathParts[0]][$pathParts[1]] = $dataValue;
            } else {
                throw new \Exception('attribute path depth is currently limited to 2');
            }
        }

        return $this->denormalize($normalizedEntity);
    }

    protected function denormalize(array $normalizedEntity): object
    {
        $normalizer = new ObjectNormalizer();

        return $normalizer->denormalize($normalizedEntity, $this->entityClass);
    }
}
