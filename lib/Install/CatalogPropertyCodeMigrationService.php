<?php

namespace Prospektweb\Calc\Install;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

/**
 * Idempotent compatibility migration for calculator-owned catalog properties.
 *
 * Updating the property CODE keeps the Bitrix property ID, HL directory
 * settings and all offer values intact. JSON references stored in stage option
 * mappings are rewritten in the same pass.
 */
class CatalogPropertyCodeMigrationService
{
    private const MODULE_ID = 'prospektweb.calc';
    private const TARGET_CODE = 'CALC_PROP_COLOR';
    // Longest alias first: CALC_COLOR is a prefix of CALC_COLORS.
    private const LEGACY_CODES = ['CALC_COLORS', 'CALC_COLOR'];
    private const STAGE_OPTION_PROPERTIES = [
        'OPTIONS_OPERATION',
        'OPTIONS_MATERIAL',
        'OPTIONS_EQUIPMENT',
        'OPTIONS_CALCULATOR',
    ];

    public function migrateForOffers(array $offerIds): array
    {
        if (!Loader::includeModule('iblock')) {
            return ['status' => 'skipped', 'reason' => 'iblock_module_unavailable'];
        }

        $skuIblockId = $this->resolveOfferIblockId($offerIds);
        if ($skuIblockId <= 0) {
            return ['status' => 'skipped', 'reason' => 'offer_iblock_not_found'];
        }

        $target = \CIBlockProperty::GetList([], [
            'IBLOCK_ID' => $skuIblockId,
            'CODE' => self::TARGET_CODE,
        ])->Fetch();
        if ($target) {
            return [
                'status' => 'ok',
                'propertyId' => (int)$target['ID'],
                'changed' => false,
                // Retry reference repair after an interrupted earlier migration.
                'updatedStages' => $this->migrateStageOptionReferences(),
            ];
        }

        $legacy = null;
        foreach (self::LEGACY_CODES as $legacyCode) {
            $candidate = \CIBlockProperty::GetList([], [
                'IBLOCK_ID' => $skuIblockId,
                'CODE' => $legacyCode,
            ])->Fetch();
            if ($candidate) {
                $legacy = $candidate;
                break;
            }
        }
        if (!$legacy) {
            return ['status' => 'skipped', 'reason' => 'legacy_property_not_found'];
        }

        $propertyApi = new \CIBlockProperty();
        if (!$propertyApi->Update((int)$legacy['ID'], ['CODE' => self::TARGET_CODE])) {
            throw new \RuntimeException(
                'Не удалось переименовать свойство ' . (string)$legacy['CODE']
                . ': ' . (string)$propertyApi->LAST_ERROR
            );
        }

        return [
            'status' => 'ok',
            'propertyId' => (int)$legacy['ID'],
            'oldCode' => (string)$legacy['CODE'],
            'newCode' => self::TARGET_CODE,
            'updatedStages' => $this->migrateStageOptionReferences(),
            'changed' => true,
        ];
    }

    public static function rewriteJsonReferences(string $raw): ?string
    {
        $decodedRaw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $payload = json_decode($decodedRaw, true);
        if (!is_array($payload)) {
            return null;
        }

        $changed = false;
        $rewritten = self::rewriteValue($payload, $changed);
        if (!$changed) {
            return null;
        }

        $json = json_encode($rewritten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Не удалось сериализовать ссылки свойства цвета');
        }

        return $json;
    }

    private function resolveOfferIblockId(array $offerIds): int
    {
        foreach ($offerIds as $offerId) {
            $element = \CIBlockElement::GetByID((int)$offerId)->Fetch();
            if ($element && (int)($element['IBLOCK_ID'] ?? 0) > 0) {
                return (int)$element['IBLOCK_ID'];
            }
        }
        return 0;
    }

    private function migrateStageOptionReferences(): int
    {
        $stagesIblockId = (int)Option::get(self::MODULE_ID, 'IBLOCK_CALC_STAGES', 0);
        if ($stagesIblockId <= 0) {
            return 0;
        }

        $updatedStages = 0;
        $elements = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => $stagesIblockId],
            false,
            false,
            ['ID']
        );
        while ($element = $elements->Fetch()) {
            $stageId = (int)($element['ID'] ?? 0);
            if ($stageId <= 0) {
                continue;
            }

            $updates = [];
            foreach (self::STAGE_OPTION_PROPERTIES as $propertyCode) {
                $property = \CIBlockElement::GetProperty(
                    $stagesIblockId,
                    $stageId,
                    ['sort' => 'asc'],
                    ['CODE' => $propertyCode]
                )->Fetch();
                $raw = $this->extractPropertyText($property ?: []);
                if ($raw === '') {
                    continue;
                }
                $rewritten = self::rewriteJsonReferences($raw);
                if ($rewritten !== null) {
                    $updates[$propertyCode] = $rewritten;
                }
            }

            if ($updates) {
                \CIBlockElement::SetPropertyValuesEx($stageId, $stagesIblockId, $updates);
                $updatedStages++;
            }
        }

        return $updatedStages;
    }

    private function extractPropertyText(array $property): string
    {
        $value = $property['~VALUE'] ?? $property['VALUE'] ?? '';
        if (is_array($value) && array_key_exists('TEXT', $value)) {
            return (string)$value['TEXT'];
        }
        return is_scalar($value) ? (string)$value : '';
    }

    private static function rewriteValue($value, bool &$changed)
    {
        if (is_string($value)) {
            $rewritten = str_replace(self::LEGACY_CODES, self::TARGET_CODE, $value);
            if ($rewritten !== $value) {
                $changed = true;
            }
            return $rewritten;
        }
        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            $rewrittenKey = is_string($key)
                ? str_replace(self::LEGACY_CODES, self::TARGET_CODE, $key)
                : $key;
            if ($rewrittenKey !== $key) {
                $changed = true;
            }
            $result[$rewrittenKey] = self::rewriteValue($item, $changed);
        }
        return $result;
    }
}
