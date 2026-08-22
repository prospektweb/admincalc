<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Config\Option;
use Prospektweb\Calc\Calculator\ElementDataService;

/** One DB transaction/CAS/audit/readback boundary for shared calculator data. */
final class CalculatorGlobalMutationService
{
    public const CONTRACT = 'prospektweb.calc.global-refresh-mutation/v1';

    /** @var string[] */
    private const PRESET_OWNED_IBLOCKS = ['CALC_PRESETS', 'CALC_DETAILS', 'CALC_STAGES'];

    /** @var array<string,callable> */
    private array $adapters;

    /** @param array<string,callable> $adapters */
    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    public static function fingerprintForRevision(int $revision): string
    {
        if ($revision < 0 || $revision > 9007199254740991) {
            throw new \InvalidArgumentException('Global mutation revision is invalid.', 422);
        }
        return 'sha256:' . GlobalCalculatorMutationCoordinatorService::hashCanonical([
            'contract' => self::CONTRACT,
            'revision' => $revision,
        ]);
    }

    /** @return array{revision:int,fingerprint:string} */
    public function currentAuthority(): array
    {
        $coordinator = $this->coordinator();
        $revision = $coordinator->revision();
        return [
            'revision' => $revision,
            'fingerprint' => self::fingerprintForRevision($revision),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $payload
     * @return array<int,array<string,mixed>>
     */
    public function mutatePayload(
        array $payload,
        int $expectedRevision,
        string $expectedFingerprint,
        string $siteId
    ): array {
        if (!array_is_list($payload) || count($payload) !== 1 || !is_array($payload[0] ?? null)) {
            throw new \InvalidArgumentException(
                'A global refresh payload must contain exactly one aggregate mutation.',
                422
            );
        }
        $request = $payload[0];
        $action = is_string($request['action'] ?? null) ? $request['action'] : '';
        if (!CalculatorRefreshActionRegistryService::isGlobalMutation($action)) {
            throw new \InvalidArgumentException('Unsupported global refresh mutation.', 422);
        }
        $presetId = $request['presetId'] ?? null;
        if (!is_int($presetId) || $presetId <= 0) {
            throw new \InvalidArgumentException('Global mutation requires the exact INIT preset ID.', 422);
        }
        if (!hash_equals(self::fingerprintForRevision($expectedRevision), $expectedFingerprint)) {
            throw new \RuntimeException('Global mutation authority fingerprint is stale.', 409);
        }

        $result = $this->coordinator()->mutate(
            $expectedRevision,
            $expectedFingerprint,
            function ($authority) use ($action, $request): array {
                $iblockIds = is_array($authority['iblockIds'] ?? null)
                    ? $authority['iblockIds']
                    : [];
                if ($iblockIds === []) {
                    throw new \RuntimeException('Global mutation did not receive pinned iblock authority.', 409);
                }
                $before = $this->readState($iblockIds);
                $mutationResult = $this->executeMutation($request, $iblockIds);
                if (($mutationResult['status'] ?? null) !== 'ok') {
                    throw new \RuntimeException(
                        trim((string)($mutationResult['message'] ?? ''))
                            ?: 'Global calculator mutation failed.',
                        409
                    );
                }
                $after = $this->readState($iblockIds);
                $mutationResult['globalStateSha256'] = GlobalCalculatorMutationCoordinatorService::hashCanonical($after);
                return [
                    'before' => $before,
                    'after' => $after,
                    'affected_preset_ids' => $this->affectedPresetIds($iblockIds),
                    'result' => $mutationResult,
                ];
            },
            [
                'action' => 'refresh_' . strtolower((string)preg_replace('/([a-z])([A-Z])/', '$1_$2', $action)),
                'entity_type' => 'calculator_global_aggregate',
                'entity_id' => 'global',
            ]
        );
        $nextRevision = (int)($result['globalRevision'] ?? -1);
        $result['globalFingerprint'] = self::fingerprintForRevision($nextRevision);
        $result['siteId'] = $siteId;
        return [$result];
    }

    private function coordinator(): GlobalCalculatorMutationCoordinatorService
    {
        $coordinator = isset($this->adapters['coordinator'])
            ? call_user_func($this->adapters['coordinator'])
            : new GlobalCalculatorMutationCoordinatorService();
        if (!$coordinator instanceof GlobalCalculatorMutationCoordinatorService) {
            throw new \RuntimeException('Global calculator mutation coordinator is unavailable.');
        }
        return $coordinator;
    }

    /** @param array<string,mixed> $request @param array<string,int> $iblockIds */
    private function executeMutation(array $request, array $iblockIds): array
    {
        $action = (string)($request['action'] ?? '');
        if (in_array($action, [
            'saveCatalogTreeElement',
            'saveCatalogTreeSection',
            'deleteCatalogTreeNode',
        ], true)) {
            $iblockCode = trim((string)($request['iblockCode'] ?? ''));
            $iblockId = $request['iblockId'] ?? null;
            if ($iblockCode === '' || !is_int($iblockId) || $iblockId <= 0
                || (int)($iblockIds[$iblockCode] ?? 0) !== $iblockId) {
                throw new \InvalidArgumentException('Catalog tree mutation does not match pinned iblock authority.', 422);
            }
        }
        if ($action === 'deleteCatalogTreeNode' && ($request['nodeType'] ?? null) === 'element') {
            $this->assertElementIsUnreferenced(
                (int)($request['iblockId'] ?? 0),
                (int)($request['nodeId'] ?? 0)
            );
        }
        if (($request['action'] ?? null) === 'saveSettingsEquipment'
            && is_array($request['image'] ?? null)
            && $request['image'] !== []) {
            throw new \InvalidArgumentException(
                'Equipment image replacement requires a dedicated file-transaction endpoint.',
                422
            );
        }
        if (isset($this->adapters['mutation'])) {
            $result = call_user_func($this->adapters['mutation'], $request, $iblockIds);
            return is_array($result) ? $result : [];
        }
        $rows = (new ElementDataService($iblockIds))->prepareRefreshPayload([$request]);
        return count($rows) === 1 && is_array($rows[0] ?? null) ? $rows[0] : [];
    }

    private function assertElementIsUnreferenced(int $targetIblockId, int $elementId): void
    {
        if ($targetIblockId <= 0 || $elementId <= 0) {
            throw new \InvalidArgumentException('Catalog delete target is invalid.', 422);
        }
        $properties = \CIBlockProperty::GetList(
            ['ID' => 'ASC'],
            ['PROPERTY_TYPE' => 'E', 'LINK_IBLOCK_ID' => $targetIblockId]
        );
        while ($property = $properties->Fetch()) {
            $propertyId = (int)($property['ID'] ?? 0);
            $ownerIblockId = (int)($property['IBLOCK_ID'] ?? 0);
            if ($propertyId <= 0 || $ownerIblockId <= 0) {
                continue;
            }
            $reference = \CIBlockElement::GetList(
                ['ID' => 'ASC'],
                [
                    'IBLOCK_ID' => $ownerIblockId,
                    'PROPERTY_' . $propertyId => $elementId,
                ],
                false,
                ['nTopCount' => 1],
                ['ID', 'IBLOCK_ID']
            )->Fetch();
            if (is_array($reference)) {
                throw new \RuntimeException(
                    'Catalog element #' . $elementId . ' is still referenced by element #'
                    . (int)($reference['ID'] ?? 0) . '.',
                    409
                );
            }
        }
    }

    /** @param array<string,int> $iblockIds @return array<string,mixed> */
    private function readState(array $iblockIds): array
    {
        if (isset($this->adapters['state'])) {
            $state = call_user_func($this->adapters['state'], $iblockIds);
            if (!is_array($state)) {
                throw new \RuntimeException('Global calculator state adapter returned invalid data.', 409);
            }
            return $state;
        }

        $iblocks = [];
        ksort($iblockIds, SORT_STRING);
        foreach ($iblockIds as $code => $iblockId) {
            $code = (string)$code;
            $iblockId = (int)$iblockId;
            if ($iblockId <= 0 || in_array($code, self::PRESET_OWNED_IBLOCKS, true)) {
                continue;
            }
            $iblocks[$code] = $this->readIblock($iblockId);
        }
        return [
            'contract' => self::CONTRACT,
            'iblocks' => $iblocks,
            'priceSettingsPresets' => (new PriceSettingsPresetService())->list(),
            'aiGateway' => [
                'apiKeySha256' => hash('sha256', (string)Option::get('prospektweb.calc', 'AI_GATEWAY_API_KEY', '')),
                'templatesSha256' => hash('sha256', (string)Option::get('prospektweb.calc', 'AI_PROMPT_TEMPLATES', '')),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function readIblock(int $iblockId): array
    {
        $sections = [];
        $sectionRows = \CIBlockSection::GetList(
            ['LEFT_MARGIN' => 'ASC', 'ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId],
            false,
            ['ID', 'NAME', 'CODE', 'ACTIVE', 'SORT', 'IBLOCK_SECTION_ID']
        );
        while ($row = $sectionRows->Fetch()) {
            $sections[] = [
                'id' => (int)($row['ID'] ?? 0),
                'name' => (string)($row['NAME'] ?? ''),
                'code' => (string)($row['CODE'] ?? ''),
                'active' => (string)($row['ACTIVE'] ?? ''),
                'sort' => (int)($row['SORT'] ?? 0),
                'parentId' => (int)($row['IBLOCK_SECTION_ID'] ?? 0),
            ];
        }

        $elements = [];
        $elementRows = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'ACTIVE', 'SORT', 'IBLOCK_SECTION_ID', 'PREVIEW_TEXT', 'DETAIL_TEXT']
        );
        while ($element = $elementRows->GetNextElement()) {
            $fields = $element->GetFields();
            $properties = [];
            foreach ((array)$element->GetProperties() as $code => $property) {
                $properties[(string)$code] = [
                    'VALUE' => $this->canonicalValue($property['~VALUE'] ?? $property['VALUE'] ?? null),
                    'DESCRIPTION' => $this->canonicalValue($property['DESCRIPTION'] ?? null),
                    'VALUE_XML_ID' => $this->canonicalValue($property['VALUE_XML_ID'] ?? null),
                ];
            }
            ksort($properties, SORT_STRING);
            $elements[] = [
                'id' => (int)($fields['ID'] ?? 0),
                'name' => (string)($fields['~NAME'] ?? $fields['NAME'] ?? ''),
                'code' => (string)($fields['CODE'] ?? ''),
                'active' => (string)($fields['ACTIVE'] ?? ''),
                'sort' => (int)($fields['SORT'] ?? 0),
                'sectionId' => (int)($fields['IBLOCK_SECTION_ID'] ?? 0),
                'previewText' => (string)($fields['~PREVIEW_TEXT'] ?? $fields['PREVIEW_TEXT'] ?? ''),
                'detailText' => (string)($fields['~DETAIL_TEXT'] ?? $fields['DETAIL_TEXT'] ?? ''),
                'properties' => $properties,
                'catalog' => $this->readCatalog((int)($fields['ID'] ?? 0)),
            ];
        }
        return ['sections' => $sections, 'elements' => $elements];
    }

    /** @return array<string,mixed>|null */
    private function readCatalog(int $elementId): ?array
    {
        $product = \CCatalogProduct::GetByID($elementId);
        if (!is_array($product)) {
            return null;
        }
        $prices = [];
        $rows = \CPrice::GetList(
            ['CATALOG_GROUP_ID' => 'ASC', 'QUANTITY_FROM' => 'ASC', 'ID' => 'ASC'],
            ['PRODUCT_ID' => $elementId]
        );
        while ($price = $rows->Fetch()) {
            $prices[] = [
                'id' => (int)($price['ID'] ?? 0),
                'typeId' => (int)($price['CATALOG_GROUP_ID'] ?? 0),
                'price' => isset($price['PRICE']) ? (float)$price['PRICE'] : null,
                'currency' => (string)($price['CURRENCY'] ?? ''),
                'quantityFrom' => isset($price['QUANTITY_FROM']) ? (int)$price['QUANTITY_FROM'] : null,
                'quantityTo' => isset($price['QUANTITY_TO']) ? (int)$price['QUANTITY_TO'] : null,
            ];
        }
        return [
            'vatId' => (int)($product['VAT_ID'] ?? 0),
            'vatIncluded' => (string)($product['VAT_INCLUDED'] ?? ''),
            'purchasingPrice' => isset($product['PURCHASING_PRICE']) ? (float)$product['PURCHASING_PRICE'] : null,
            'purchasingCurrency' => (string)($product['PURCHASING_CURRENCY'] ?? ''),
            'weight' => isset($product['WEIGHT']) ? (float)$product['WEIGHT'] : null,
            'length' => isset($product['LENGTH']) ? (float)$product['LENGTH'] : null,
            'width' => isset($product['WIDTH']) ? (float)$product['WIDTH'] : null,
            'height' => isset($product['HEIGHT']) ? (float)$product['HEIGHT'] : null,
            'prices' => $prices,
        ];
    }

    /** @param mixed $value @return mixed */
    private function canonicalValue($value)
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            return is_scalar($value) || $value === null ? $value : (string)$value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $nested) {
            $value[$key] = $this->canonicalValue($nested);
        }
        return $value;
    }

    /** @param array<string,int> $iblockIds @return int[] */
    public function affectedPresetIds(array $iblockIds): array
    {
        if (isset($this->adapters['affected_preset_ids'])) {
            $ids = call_user_func($this->adapters['affected_preset_ids'], $iblockIds);
            return is_array($ids) ? $ids : [];
        }
        $presetIblockId = (int)($iblockIds['CALC_PRESETS'] ?? 0);
        if ($presetIblockId <= 0) {
            throw new \RuntimeException('Pinned preset iblock is unavailable.', 409);
        }
        $ids = [];
        $rows = \CIBlockElement::GetList(['ID' => 'ASC'], ['IBLOCK_ID' => $presetIblockId], false, false, ['ID']);
        while ($row = $rows->Fetch()) {
            $id = (int)($row['ID'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        ksort($ids, SORT_NUMERIC);
        return array_values($ids);
    }
}
