<?php

$options = (string)file_get_contents(__DIR__ . '/../../../options.php');
$ajax = (string)file_get_contents(__DIR__ . '/../ajax/frontcalc.php');
$editor = (string)file_get_contents(__DIR__ . '/../admin/editor.php');

function volume_grid_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

volume_grid_assert(strpos($options, "'VOLUME_GRID_VALUES', 'VOLUME_GRID_TAIL_STEP'") !== false && strpos($options, 'Option::set($module_id, $optionName') !== false, 'base grid and tail step must be saved as main module options');
volume_grid_assert(strpos($ajax, "'volume_grid' => frontcalc_get_volume_grid_config()") !== false, 'volume grid must be included in the public payload');
volume_grid_assert(strpos($ajax, "'price_rounding_rules' => frontcalc_get_price_rounding_rules") !== false, 'Bitrix rounding rules must be included in the public payload');
volume_grid_assert(strpos($editor, "(\$max - \$min) % \$step !== 0") !== false, 'calculator save must validate that step reaches maximum');
volume_grid_assert(strpos($editor, "(int)\$rangeMax - (int)\$rangeMin") !== false, 'area-dependent ranges must validate their step too');

echo "Volume grid static tests passed\n";
