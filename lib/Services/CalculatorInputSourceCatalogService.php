<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;

/**
 * Read-only authority for Bitrix properties that may feed calculator inputs.
 *
 * Every row carries the concrete entity scope and the exact iblock/property
 * identity. The catalog never derives a source from a form field, a storefront,
 * a product sample or a legacy adapter.
 */
final class CalculatorInputSourceCatalogService
{
    public const CONTRACT = 'prospektweb.calc.calculator-input-source-catalog/v1';

    private const MAX_SAFE_INTEGER = 9007199254740991;

    /** @var array<string,callable> */
    private array $adapters;

    public function __construct(array $adapters = [])
    {
        foreach (['source_iblocks', 'property_rows', 'enum_rows'] as $adapter) {
            if (isset($adapters[$adapter]) && !is_callable($adapters[$adapter])) {
                throw new \InvalidArgumentException($adapter . ' adapter must be callable.');
            }
        }
        $this->adapters = $adapters;
    }

    /** @return array<string,mixed> */
    public function load(int $presetId): array
    {
        $this->assertPositiveInteger($presetId, 'preset_id');
        $iblocks = $this->sourceIblocks($presetId);
        $properties = [];
        $identities = [];

        foreach (['product', 'selected_offer'] as $scope) {
            $iblockId = (int)$iblocks[$scope];
            foreach ($this->propertyRows($iblockId, $scope) as $row) {
                if (!is_array($row) || (string)($row['ACTIVE'] ?? '') !== 'Y') {
                    continue;
                }
                $propertyId = (int)($row['ID'] ?? 0);
                $propertyCode = trim((string)($row['CODE'] ?? ''));
                // A property without an exact stable code cannot satisfy the
                // mapping contract. It is not renamed or inferred here.
                if ($propertyId <= 0
                    || $propertyId > self::MAX_SAFE_INTEGER
                    || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $propertyCode) !== 1) {
                    continue;
                }
                $identity = $scope . '|' . $iblockId . '|' . $propertyId;
                if (isset($identities[$identity])) {
                    throw new \RuntimeException('Source property catalog contains a duplicate authority row.', 409);
                }
                $identities[$identity] = true;

                $propertyType = trim((string)($row['PROPERTY_TYPE'] ?? ''));
                if ($propertyType === '') {
                    throw new \RuntimeException('Source property catalog contains an unknown property type.', 409);
                }
                $values = $propertyType === 'L' ? $this->enumValues($propertyId) : [];
                $properties[] = [
                    'scope' => $scope,
                    'iblock_id' => $iblockId,
                    'property_id' => $propertyId,
                    'property_code' => $propertyCode,
                    'name' => trim((string)($row['NAME'] ?? '')),
                    'property_type' => $propertyType,
                    'user_type' => trim((string)($row['USER_TYPE'] ?? '')),
                    'multiple' => (string)($row['MULTIPLE'] ?? '') === 'Y',
                    'values' => $values,
                ];
            }
        }

        return [
            'contract' => self::CONTRACT,
            'preset_id' => $presetId,
            'product_iblock_id' => (int)$iblocks['product'],
            'offer_iblock_id' => (int)$iblocks['selected_offer'],
            'properties' => $properties,
        ];
    }

    /**
     * Exact source authority consumed by CalculatorInputMappingService.
     *
     * @return array<string,mixed>
     */
    public function validationAuthority(int $presetId): array
    {
        $catalog = $this->load($presetId);
        $properties = [];
        foreach ($catalog['properties'] as $property) {
            $iblockId = (int)$property['iblock_id'];
            $propertyId = (int)$property['property_id'];
            $enumXmlIds = [];
            foreach ($property['values'] as $value) {
                $enumXmlIds[] = (string)$value['xml_id'];
            }
            $scope = (string)$property['scope'];
            $properties[$scope][$iblockId][$propertyId] = [
                'scope' => $scope,
                'code' => (string)$property['property_code'],
                'active' => true,
                'property_type' => (string)$property['property_type'],
                'multiple' => (bool)$property['multiple'],
                'enum_xml_ids' => $enumXmlIds,
            ];
        }

        return [
            'product_iblock_id' => (int)$catalog['product_iblock_id'],
            'offer_iblock_id' => (int)$catalog['offer_iblock_id'],
            'properties' => $properties,
        ];
    }

    /** @return array{product:int,selected_offer:int} */
    private function sourceIblocks(int $presetId): array
    {
        if (isset($this->adapters['source_iblocks'])) {
            $iblocks = call_user_func($this->adapters['source_iblocks'], $presetId);
        } else {
            if (!Loader::includeModule('iblock')) {
                throw new \RuntimeException('Iblock module is unavailable for input source catalog.', 409);
            }
            $config = new ConfigManager();
            $iblocks = [
                'product' => (int)$config->getProductIblockId(),
                'selected_offer' => (int)$config->getSkuIblockId(),
            ];
        }
        if (!is_array($iblocks)
            || array_keys($iblocks) !== ['product', 'selected_offer']
            || !is_int($iblocks['product'] ?? null)
            || !is_int($iblocks['selected_offer'] ?? null)) {
            throw new \RuntimeException('Input source iblock authority has an incompatible shape.', 409);
        }
        $this->assertPositiveInteger($iblocks['product'], 'product iblock_id');
        $this->assertPositiveInteger($iblocks['selected_offer'], 'selected_offer iblock_id');
        return $iblocks;
    }

    /** @return array<int,array<string,mixed>> */
    private function propertyRows(int $iblockId, string $scope): array
    {
        if (isset($this->adapters['property_rows'])) {
            $rows = call_user_func($this->adapters['property_rows'], $iblockId, $scope);
            if (!is_array($rows)) {
                throw new \RuntimeException('Input source property adapter returned invalid data.', 409);
            }
            return $rows;
        }
        $rows = [];
        $cursor = \CIBlockProperty::GetList(['ID' => 'ASC'], ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y']);
        while ($cursor && ($row = $cursor->Fetch())) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return array<int,array{enum_id:int,xml_id:string,label:string}> */
    private function enumValues(int $propertyId): array
    {
        if (isset($this->adapters['enum_rows'])) {
            $rows = call_user_func($this->adapters['enum_rows'], $propertyId);
            if (!is_array($rows)) {
                throw new \RuntimeException('Input source enum adapter returned invalid data.', 409);
            }
        } else {
            $rows = [];
            $cursor = \CIBlockPropertyEnum::GetList(
                ['SORT' => 'ASC', 'ID' => 'ASC'],
                ['PROPERTY_ID' => $propertyId]
            );
            while ($cursor && ($row = $cursor->Fetch())) {
                $rows[] = $row;
            }
        }

        $values = [];
        $enumIds = [];
        $xmlIds = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $enumId = (int)($row['ID'] ?? 0);
            $xmlId = trim((string)($row['XML_ID'] ?? ''));
            if ($enumId <= 0 || $enumId > self::MAX_SAFE_INTEGER || $xmlId === '') {
                throw new \RuntimeException(
                    'Enum source authority is incomplete for property #' . $propertyId . '.',
                    409
                );
            }
            if (isset($enumIds[$enumId]) || isset($xmlIds[$xmlId])) {
                throw new \RuntimeException(
                    'Enum identities are not unique for source property #' . $propertyId . '.',
                    409
                );
            }
            $enumIds[$enumId] = true;
            $xmlIds[$xmlId] = true;
            $values[] = [
                'enum_id' => $enumId,
                'xml_id' => $xmlId,
                'label' => trim((string)($row['VALUE'] ?? '')),
            ];
        }
        return $values;
    }

    private function assertPositiveInteger(int $value, string $path): void
    {
        if ($value <= 0 || $value > self::MAX_SAFE_INTEGER) {
            throw new \InvalidArgumentException($path . ' must be a safe positive integer.');
        }
    }
}
