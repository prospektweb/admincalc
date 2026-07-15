<?php

namespace Prospektweb\Calc\Integration;

use Bitrix\Main\Config\Option;

final class OptionMigrator
{
    public const TARGET_MODULE_ID = 'prospektweb.calc';

    private const LEGACY_MODULE_IDS = [
        'prospektweb.frontcalc',
        'prospektweb.propvalmanager',
        'prospektweb.offerfilter',
        'prospektweb.layoutfiles',
    ];

    /**
     * Copies legacy settings without overwriting values already configured in
     * the main module. The method is intentionally idempotent.
     *
     * @return array<string, array{copied:string[], skipped:string[]}>
     */
    public function migrate(): array
    {
        $target = Option::getForModule(self::TARGET_MODULE_ID);
        $report = [];

        foreach (self::LEGACY_MODULE_IDS as $legacyModuleId) {
            $report[$legacyModuleId] = ['copied' => [], 'skipped' => []];

            foreach (Option::getForModule($legacyModuleId) as $name => $value) {
                if (array_key_exists($name, $target)) {
                    $report[$legacyModuleId]['skipped'][] = (string)$name;
                    continue;
                }

                Option::set(self::TARGET_MODULE_ID, (string)$name, (string)$value);
                $target[$name] = $value;
                $report[$legacyModuleId]['copied'][] = (string)$name;
            }
        }

        Option::set(self::TARGET_MODULE_ID, 'CONSOLIDATION_OPTIONS_MIGRATED', date('c'));

        return $report;
    }
}
