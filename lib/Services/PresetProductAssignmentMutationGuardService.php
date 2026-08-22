<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Prospektweb\Calc\Config\ConfigManager;

/**
 * Prevent ordinary Bitrix element editing from bypassing CALC_PRESET CAS,
 * storefront detach and audit authority.
 */
final class PresetProductAssignmentMutationGuardService
{
    private const MESSAGE = 'Привязка CALC_PRESET изменяется только в Центре управления калькуляторами.';

    private static int $internalDepth = 0;

    /** @var array<string,callable> */
    private array $adapters;

    /** @param array<string,callable> $adapters */
    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    /** @return mixed */
    public static function runInternal(callable $mutation)
    {
        self::$internalDepth++;
        try {
            return $mutation();
        } finally {
            self::$internalDepth--;
        }
    }

    /** @param array<string,mixed> $fields */
    public static function onBeforeElementUpdate(array &$fields): bool
    {
        try {
            (new self())->assertElementUpdateAllowed($fields);
            return true;
        } catch (\Throwable $error) {
            self::publishBitrixError($error->getMessage());
            return false;
        }
    }

    /** @param array<string,mixed> $fields */
    public static function onBeforeElementAdd(array &$fields): bool
    {
        try {
            (new self())->assertElementAddAllowed($fields);
            return true;
        } catch (\Throwable $error) {
            self::publishBitrixError($error->getMessage());
            return false;
        }
    }

    /** @param mixed $values @param mixed $propertyCode */
    public static function onBeforeSetPropertyValues(
        int $elementId,
        int $iblockId,
        &$values,
        $propertyCode = false
    ): bool {
        try {
            (new self())->assertPropertyWriteAllowed($elementId, $iblockId, $values, $propertyCode);
            return true;
        } catch (\Throwable $error) {
            self::publishBitrixError($error->getMessage());
            return false;
        }
    }

    /** @param array<string|int,mixed> $values */
    public static function onBeforeSetPropertyValuesEx(
        int $elementId,
        int $iblockId,
        array &$values
    ): bool {
        try {
            (new self())->assertPropertyWriteAllowed($elementId, $iblockId, $values, false);
            return true;
        } catch (\Throwable $error) {
            self::publishBitrixError($error->getMessage());
            return false;
        }
    }

    /** @param array<string,mixed> $fields */
    public function assertElementUpdateAllowed(array $fields): void
    {
        if (self::$internalDepth > 0 || !array_key_exists('PROPERTY_VALUES', $fields)) {
            return;
        }
        $elementId = (int)($fields['ID'] ?? 0);
        if ($elementId <= 0) {
            return;
        }
        $iblockId = (int)($fields['IBLOCK_ID'] ?? 0);
        if ($iblockId <= 0) {
            $iblockId = $this->elementIblockId($elementId);
        }
        $this->assertPropertyWriteAllowed(
            $elementId,
            $iblockId,
            $fields['PROPERTY_VALUES'],
            false
        );
    }

    /** @param array<string,mixed> $fields */
    public function assertElementAddAllowed(array $fields): void
    {
        if (self::$internalDepth > 0
            || (int)($fields['IBLOCK_ID'] ?? 0) !== $this->productIblockId()
            || !is_array($fields['PROPERTY_VALUES'] ?? null)) {
            return;
        }
        $propertyId = $this->propertyId((int)$fields['IBLOCK_ID']);
        foreach (['CALC_PRESET', $propertyId, (string)$propertyId] as $key) {
            if (array_key_exists($key, $fields['PROPERTY_VALUES'])
                && $this->normalizeIds($fields['PROPERTY_VALUES'][$key]) !== []) {
                throw new \RuntimeException(self::MESSAGE, 409);
            }
        }
    }

    /** @param mixed $values @param mixed $propertyCode */
    public function assertPropertyWriteAllowed(
        int $elementId,
        int $iblockId,
        $values,
        $propertyCode = false
    ): void {
        if (self::$internalDepth > 0 || $elementId <= 0 || $iblockId !== $this->productIblockId()) {
            return;
        }
        $propertyId = $this->propertyId($iblockId);
        $submitted = null;
        if ($propertyCode === 'CALC_PRESET' || (int)$propertyCode === $propertyId) {
            $submitted = $values;
        } elseif (is_array($values)) {
            foreach (['CALC_PRESET', $propertyId, (string)$propertyId] as $key) {
                if (array_key_exists($key, $values)) {
                    $submitted = $values[$key];
                    break;
                }
            }
        }
        if ($submitted === null) {
            return;
        }

        $before = $this->currentPresetIds($iblockId, $elementId);
        $after = $this->normalizeIds($submitted);
        if ($before !== $after) {
            throw new \RuntimeException(self::MESSAGE, 409);
        }
    }

    private function productIblockId(): int
    {
        $id = isset($this->adapters['product_iblock_id'])
            ? (int)call_user_func($this->adapters['product_iblock_id'])
            : (int)(new ConfigManager())->getProductIblockId();
        return $id;
    }

    private function propertyId(int $iblockId): int
    {
        if (isset($this->adapters['property_id'])) {
            $propertyId = (int)call_user_func($this->adapters['property_id'], $iblockId);
        } else {
            $authority = (new PresetProductAssignmentPropertyAuthorityService())->resolve($iblockId);
            $propertyId = (int)$authority['propertyId'];
        }
        if ($propertyId <= 0) {
            throw new \RuntimeException('CALC_PRESET property authority is unavailable.', 409);
        }
        return $propertyId;
    }

    private function elementIblockId(int $elementId): int
    {
        if (isset($this->adapters['element_iblock_id'])) {
            return (int)call_user_func($this->adapters['element_iblock_id'], $elementId);
        }
        $row = \CIBlockElement::GetList(
            [],
            ['ID' => $elementId],
            false,
            ['nTopCount' => 1],
            ['ID', 'IBLOCK_ID']
        )->Fetch();
        return (int)($row['IBLOCK_ID'] ?? 0);
    }

    /** @return int[] */
    private function currentPresetIds(int $iblockId, int $elementId): array
    {
        if (isset($this->adapters['current_preset_ids'])) {
            return $this->normalizeIds(call_user_func(
                $this->adapters['current_preset_ids'],
                $iblockId,
                $elementId
            ));
        }
        $values = [];
        $cursor = \CIBlockElement::GetProperty(
            $iblockId,
            $elementId,
            ['sort' => 'asc', 'id' => 'asc'],
            ['ID' => $this->propertyId($iblockId)]
        );
        while ($cursor && ($row = $cursor->Fetch())) {
            $values[] = $row['VALUE'] ?? null;
        }
        return $this->normalizeIds($values);
    }

    /** @param mixed $value @return int[] */
    private function normalizeIds($value): array
    {
        $ids = [];
        $walk = static function ($item) use (&$walk, &$ids): void {
            if (is_array($item)) {
                if (array_key_exists('VALUE', $item)) {
                    $walk($item['VALUE']);
                    return;
                }
                foreach ($item as $child) {
                    $walk($child);
                }
                return;
            }
            if (is_int($item) || (is_string($item) && preg_match('/^[0-9]+$/D', trim($item)) === 1)) {
                $id = (int)$item;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        };
        $walk($value);
        ksort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    private static function publishBitrixError(string $message): void
    {
        $application = $GLOBALS['APPLICATION'] ?? null;
        if (is_object($application) && method_exists($application, 'ThrowException')) {
            $application->ThrowException($message);
        }
    }
}
