<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Application;

/**
 * Resolves the one exact CALC_PRESET property owned by the configured product
 * iblock. Bitrix does not make property CODE unique, so every assignment read
 * and write must use this pinned property ID instead of a CODE alias.
 */
final class PresetProductAssignmentPropertyAuthorityService
{
    public const PROPERTY_CODE = 'CALC_PRESET';

    /** @var array<string,callable> */
    private array $adapters;

    /** @param array<string,callable> $adapters */
    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    /** @return array{productIblockId:int,propertyId:int,multiple:bool} */
    public function resolve(int $productIblockId, bool $forUpdate = false): array
    {
        if ($productIblockId <= 0 || $productIblockId > 9007199254740991) {
            throw new \InvalidArgumentException('Product iblock ID must be a safe positive integer.', 422);
        }

        if (isset($this->adapters['read_rows'])) {
            $rows = call_user_func($this->adapters['read_rows'], $productIblockId, $forUpdate);
            if (!is_array($rows) || !array_is_list($rows)) {
                throw new \RuntimeException('CALC_PRESET property authority readback is invalid.', 409);
            }
        } else {
            if (!class_exists(Application::class)) {
                throw new \RuntimeException('Database authority is unavailable for CALC_PRESET.', 409);
            }
            $connection = Application::getConnection();
            $helper = $connection->getSqlHelper();
            $cursor = $connection->query(
                'SELECT ID, IBLOCK_ID, CODE, MULTIPLE FROM b_iblock_property'
                . ' WHERE IBLOCK_ID=' . $productIblockId
                . " AND CODE='" . $helper->forSql(self::PROPERTY_CODE) . "'"
                . ' ORDER BY ID'
                . ($forUpdate ? ' FOR UPDATE' : '')
            );
            $rows = [];
            while (is_object($cursor) && method_exists($cursor, 'fetch') && ($row = $cursor->fetch())) {
                if (!is_array($row)) {
                    throw new \RuntimeException('CALC_PRESET property authority readback is invalid.', 409);
                }
                $rows[] = $row;
            }
        }

        // The non-binary SQL comparison intentionally returns every row that
        // is equivalent under the database collation. PHP then requires the
        // sole returned row to have the exact canonical spelling.
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new \RuntimeException('CALC_PRESET property authority is missing or ambiguous.', 409);
        }
        $row = $rows[0];
        $propertyId = (int)($row['ID'] ?? $row['id'] ?? 0);
        $iblockId = (int)($row['IBLOCK_ID'] ?? $row['iblock_id'] ?? 0);
        $code = (string)($row['CODE'] ?? $row['code'] ?? '');
        $multiple = (string)($row['MULTIPLE'] ?? $row['multiple'] ?? '');
        if ($propertyId <= 0
            || $iblockId !== $productIblockId
            || $code !== self::PROPERTY_CODE
            || !in_array($multiple, ['Y', 'N'], true)) {
            throw new \RuntimeException('CALC_PRESET property authority does not match the exact target.', 409);
        }

        return [
            'productIblockId' => $productIblockId,
            'propertyId' => $propertyId,
            'multiple' => $multiple === 'Y',
        ];
    }
}
