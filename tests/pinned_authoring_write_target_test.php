<?php

declare(strict_types=1);

final class PinnedAuthoringPropertyCursor
{
    /** @var array<int,array<string,mixed>> */
    private array $rows;

    /** @param array<int,array<string,mixed>> $rows */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    /** @return array<string,mixed>|false */
    public function Fetch()
    {
        return array_shift($this->rows) ?? false;
    }
}

if (!class_exists('CIBlockElement', false)) {
    final class CIBlockElement
    {
        /** @var array<int,array<string,mixed>> */
        public static array $events = [];

        public static function GetProperty(int $iblockId, int $elementId, array $order, array $filter): object
        {
            self::$events[] = [
                'kind' => 'read',
                'iblockId' => $iblockId,
                'elementId' => $elementId,
                'code' => (string)($filter['CODE'] ?? ''),
            ];
            return new PinnedAuthoringPropertyCursor([['VALUE' => 10]]);
        }

        /** @param array<string,mixed> $values */
        public static function SetPropertyValuesEx(int $elementId, int $iblockId, array $values): void
        {
            self::$events[] = [
                'kind' => 'write',
                'iblockId' => $iblockId,
                'elementId' => $elementId,
                'values' => $values,
            ];
        }
    }
}

require_once dirname(__DIR__) . '/lib/Config/ConfigManager.php';
require_once dirname(__DIR__) . '/lib/Services/PresetEnrichmentService.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$cache = (new ReflectionClass(\Prospektweb\Calc\Config\ConfigManager::class))->getProperty('iblockCache');
$cache->setAccessible(true);
$cache->setValue(null, [
    'CALC_PRESETS' => 111,
    'CALC_STAGES' => 222,
]);

\Prospektweb\Calc\Services\PresetEnrichmentService::addStageToPresetPinned(12740, 7001, 901);
\Prospektweb\Calc\Services\PresetEnrichmentService::updateStagePropertyPinned(7001, 'INPUTS', ['safe'], 902);

$targets = array_map(
    static fn(array $event): int => (int)$event['iblockId'],
    CIBlockElement::$events
);
$assert(
    $targets === [901, 901, 902],
    'all reads and writes target locked pinned iblocks B, never poisoned cached iblocks A'
);
$assert(
    $cache->getValue() === ['CALC_PRESETS' => 111, 'CALC_STAGES' => 222],
    'pinned write helpers do not consult or rewrite the process-static ConfigManager cache'
);

fwrite(STDOUT, "OK\n");
