<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

/** Site writeback allowlist, independent from iblocks, preset options and the portable core. */
final class DocumentOutputMappings
{
    public const PAIRS = [
        'result.purchasePrice' => 'catalog.offer.purchasingPrice',
        'result.priceTypes' => 'catalog.offer.priceTypes',
        'result.dimensions.weight' => 'catalog.offer.weight',
        'result.dimensions.length' => 'catalog.offer.length',
        'result.dimensions.width' => 'catalog.offer.width',
        'result.dimensions.height' => 'catalog.offer.height',
        'runtime.provenance' => 'catalog.offer.provenance',
    ];

    public static function validate($mappings): void
    {
        if (!is_array($mappings) || !array_is_list($mappings)
            || (count($mappings) !== 0 && count($mappings) !== count(self::PAIRS))) {
            throw new \InvalidArgumentException('Выходные сопоставления должны быть пустым списком либо содержать все семь пар.');
        }
        $seen = [];
        foreach ($mappings as $mapping) {
            if (!$mapping instanceof \stdClass) { throw new \InvalidArgumentException('Ожидается объект выходного сопоставления.'); }
            $keys = array_keys(get_object_vars($mapping)); sort($keys);
            if ($keys !== ['source_path', 'target_path'] || !is_string($mapping->source_path) || !is_string($mapping->target_path)
                || (self::PAIRS[$mapping->source_path] ?? null) !== $mapping->target_path || isset($seen[$mapping->target_path])) {
                throw new \InvalidArgumentException('Запрещённое или повторное выходное сопоставление.');
            }
            $seen[$mapping->target_path] = true;
        }
    }
}
