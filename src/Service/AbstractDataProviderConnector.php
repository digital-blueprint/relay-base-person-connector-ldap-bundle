<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorLdapBundle\Service;

use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\LocalData\LocalDataAwareInterface;
use Dbp\Relay\CoreBundle\LocalData\LocalDataPostEvent;
use Dbp\Relay\CoreBundle\Rest\Options;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Nodes\ConditionNode;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Nodes\LogicalNode;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

abstract class AbstractDataProviderConnector
{
    private const ATTRIBUTES_CONFIG_KEY = 'attributes';
    private const LOCAL_DATA_ATTRIBUTES_CONFIG_KEY = 'local_data_attributes';
    private const NAME_ATTRIBUTE_CONFIG_KEY = 'name';
    private const SOURCE_ATTRIBUTE_ATTRIBUTE_CONFIG_KEY = 'source_attribute';
    private const IS_ARRAY_ATTRIBUTE_CONFIG_KEY = 'is_array';

    private const LOCAL_DATA_PROPERTY = 'localData';

    /**
     * @var array[]
     */
    private $attributeConfigEntries = [];

    /**
     * @var array[]
     */
    private $localDataAttributeConfigEntries = [];

    /**
     * @var EventDispatcher
     */
    private $eventDispatcher;

    /**
     * @var string
     */
    private $itemClass;

    public function __construct(string $itemClass)
    {
        $this->itemClass = $itemClass;
        $this->eventDispatcher = new EventDispatcher(); // needs autowiring??? // new LocalDataEventDispatcher(Person::class, new EventDispatcher());
    }

    public function setConfig(array $config): void
    {
        foreach ($config[self::ATTRIBUTES_CONFIG_KEY] ?? [] as $attributeConfig) {
            $this->attributeConfigEntries[$attributeConfig[self::NAME_ATTRIBUTE_CONFIG_KEY]] = $attributeConfig;
        }

        foreach ($config[self::LOCAL_DATA_ATTRIBUTES_CONFIG_KEY] ?? [] as $attributeConfig) {
            $this->localDataAttributeConfigEntries[$attributeConfig[self::NAME_ATTRIBUTE_CONFIG_KEY]] = $attributeConfig;
        }
    }

    public function getItemById(string $id, array $options = []): ?object
    {
        $preEvent = $this->createGetItemPreEvent();
        if ($preEvent !== null) {
            $preEvent->setId($id);
            $preEvent->setOptions($options);
            $this->eventDispatcher->dispatch($preEvent);
            $id = $preEvent->getId();
            $options = $preEvent->getOptions();
        }

        $item = null;
        $itemData = $this->getItemDataById($id, $options);
        if ($itemData !== null) {
            $item = $this->createItemInternal($itemData, $options);
        }

        return $item;
    }

    public function getItemCollection(int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        if (($filter = Options::getFilter($options)) !== null) {
            $this->mapFilterConditionPaths($filter->getRootNode());
        }

        $preEvent = $this->createGetPagePreEvent();
        if ($preEvent !== null) {
            $preEvent->setOptions($options);
            $this->eventDispatcher->dispatch($preEvent);
            $options = $preEvent->getOptions();
        }

        $pageItems = [];
        foreach ($this->getItemDataCollection($currentPageNumber, $maxNumItemsPerPage, $options) as $itemData) {
            $pageItems[] = $this->createItemInternal($itemData, $options);
        }

        return $pageItems;
    }

    protected function getSourceAttributeName(string $attributePath): ?string
    {
        $attributeConfig = null;
        $pathParts = explode('.', $attributePath);
        if (count($pathParts) === 1) {
            $attributeConfig = $this->attributeConfigEntries[$attributePath] ?? null;
        } elseif (count($pathParts) === 2 && $pathParts[0] === self::LOCAL_DATA_PROPERTY) {
            $attributeConfig = $this->localDataAttributeConfigEntries[$pathParts[1]] ?? null;
        }

        return $attributeConfig !== null ? $attributeConfig[self::SOURCE_ATTRIBUTE_ATTRIBUTE_CONFIG_KEY] : null;
    }

    /**
     * @return string[]
     */
    protected function getSourceAttributeNames(): array
    {
        $attributeNames = [];
        foreach ($this->localDataAttributeConfigEntries as $attributeConfigEntry) {
            $attributeNames[$attributeConfigEntry[self::SOURCE_ATTRIBUTE_ATTRIBUTE_CONFIG_KEY]] = null;
        }
        foreach ($this->attributeConfigEntries as $attributeConfigEntry) {
            $attributeNames[$attributeConfigEntry[self::SOURCE_ATTRIBUTE_ATTRIBUTE_CONFIG_KEY]] = null;
        }

        return array_keys($attributeNames);
    }

    protected function createItem(array $itemData): object
    {
        $normalizedItem = [];
        foreach ($this->attributeConfigEntries as $attributeConfigEntry) {
            $normalizedItem[$attributeConfigEntry[self::NAME_ATTRIBUTE_CONFIG_KEY]] = $this->getSourceAttributeValue($itemData, $attributeConfigEntry);
        }

        return $this->denormalizeItem($normalizedItem);
    }

    protected function denormalizeItem(array $normalizedItem): object
    {
        $normalizer = new ObjectNormalizer();
        // avoid normalization exception for null values:
        $normalizedItem = array_filter($normalizedItem, function ($value) { return $value !== null; });

        return $normalizer->denormalize($normalizedItem, $this->itemClass);
    }

    protected function createGetItemPreEvent(): ?AbstractGetItemPreEvent
    {
        return null;
    }

    protected function createGetPagePreEvent(): ?AbstractGetPagePreEvent
    {
        return null;
    }

    protected function createItemCreatedEvent(object $item, array $itemData): ?AbstractItemCreatedEvent
    {
        return null;
    }

    abstract protected function getItemDataById(string $id, array $options): ?array;

    abstract protected function getItemDataCollection(int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array;

    private function createItemInternal(array $itemData, array $options)
    {
        $item = $this->createItem($itemData);

        if ($item instanceof LocalDataAwareInterface) {
            $item->setLocalData($this->getRequestedLocalData($itemData, Options::getLocalDataAttributes($options)));
        }

        $itemCreatedEvent = $this->createItemCreatedEvent($item, $itemData);
        if ($itemCreatedEvent !== null) {
            if ($itemCreatedEvent instanceof LocalDataPostEvent) {
                $itemCreatedEvent->initRequestedAttributes(array_diff(
                    Options::getLocalDataAttributes($options), array_keys($this->localDataAttributeConfigEntries)));
            }

            $this->eventDispatcher->dispatch($itemCreatedEvent);

            if ($itemCreatedEvent instanceof LocalDataPostEvent) {
                $pendingAttributes = $itemCreatedEvent->getPendingRequestedAttributes();
                if (count($pendingAttributes) !== 0) {
                    throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, sprintf('the following requested local data attributes could not be provided: %s', implode(', ', $pendingAttributes)));
                }
            }
            $item = $itemCreatedEvent->getEntity();
        }

        return $item;
    }

    private function createConfigEntry(array $attributeConfig): array
    {
        return [
            self::NAME_ATTRIBUTE_CONFIG_KEY => $attributeConfig['name'],
            self::SOURCE_ATTRIBUTE_ATTRIBUTE_CONFIG_KEY => $attributeConfig['source_attribute'],
            self::IS_ARRAY_ATTRIBUTE_CONFIG_KEY => $attributeConfig['is_array'] ?? false,
        ];
    }

    private function getRequestedLocalData(array $itemData, array $requestedLocalDataAttributes): array
    {
        $localDataAttributes = [];
        foreach ($requestedLocalDataAttributes as $requestedLocalDataAttribute) {
            if (($localDataAttributeConfigEntry = $this->localDataAttributeConfigEntries[$requestedLocalDataAttribute] ?? null) !== null) {
                $localDataAttributes[$requestedLocalDataAttribute] = $this->getSourceAttributeValue($itemData, $localDataAttributeConfigEntry);
            }
        }

        return $localDataAttributes;
    }

    private function getSourceAttributeValue(array $itemData, array $attributeConfigEntry)
    {
        $attributeValue = $itemData[$attributeConfigEntry[self::SOURCE_ATTRIBUTE_ATTRIBUTE_CONFIG_KEY]] ?? null;
        if ($attributeValue !== null) {
            $is_array_attribute = $attributeConfigEntry[self::IS_ARRAY_ATTRIBUTE_CONFIG_KEY];
            if (is_array($attributeValue)) {
                $attributeValue = $is_array_attribute ? $attributeValue : ($attributeValue[0] ?? null);
            } else {
                $attributeValue = $is_array_attribute ? [$attributeValue] : $attributeValue;
            }
        }

        return $attributeValue;
    }

    private function mapFilterConditionPaths(LogicalNode $logicalNode)
    {
        foreach ($logicalNode->getChildren() as $childNode) {
            if ($childNode instanceof ConditionNode) {
                if (($sourceAttribute = $this->getSourceAttributeName($childNode->getField())) !== null) {
                    $childNode->setField($sourceAttribute);
                }
            } elseif ($childNode instanceof LogicalNode) {
                $this->mapFilterConditionPaths($childNode);
            }
        }
    }
}
