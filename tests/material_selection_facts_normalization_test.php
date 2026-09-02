<?php

require_once dirname(__DIR__) . '/lib/Calculator/InitPayloadService.php';

use Prospektweb\Calc\Calculator\InitPayloadService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$method = new ReflectionMethod(InitPayloadService::class, 'normalizeMultiplePropertyValues');

$assert($method->invoke(null, 'GRAMMAGE_G_M2') === ['GRAMMAGE_G_M2'], 'single property value remains available');
$assert(
    $method->invoke(null, [415 => 'GRAMMAGE_G_M2', 416 => 'BRIGHTNESS_PCT'])
        === [415 => 'GRAMMAGE_G_M2', 416 => 'BRIGHTNESS_PCT'],
    'multiple parameter values keep their property-value keys'
);
$assert(
    $method->invoke(null, ['VALUE' => [415 => 'GRAMMAGE_G_M2', 416 => 'BRIGHTNESS_PCT']])
        === [415 => 'GRAMMAGE_G_M2', 416 => 'BRIGHTNESS_PCT'],
    'Bitrix wrapped values are normalized without an Array string cast'
);
$assert($method->invoke(null, [52, 53]) === [52, 53], 'multiple supplier links remain numeric candidates');
$assert($method->invoke(null, null) === [], 'empty property has no facts');

echo "Material selection fact normalization checks passed\n";
