<?php

declare(strict_types=1);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$source = (string)file_get_contents(dirname(__DIR__) . '/lib/Handlers/AdminHandler.php');
$methodStart = strpos($source, 'protected static function addCalculatorButton');
$nextMethod = strpos($source, 'private static function ', ($methodStart ?: 0) + 1);
$body = substr(
    $source,
    $methodStart ?: 0,
    ($nextMethod ?: strlen($source)) - ($methodStart ?: 0)
);

$lookup = "\$configManager->getIblockId('CALC_PRESETS')";
$tryStart = strpos($body, 'try {');
$lookupPosition = strpos($body, $lookup);
$catchPosition = strpos($body, 'catch (\\Throwable $error)');

$assert($methodStart !== false, 'calculator admin decoration method exists');
$assert($tryStart !== false, 'optional calculator authority lookup starts inside a try block');
$assert($lookupPosition !== false, 'preset authority lookup remains explicit');
$assert($catchPosition !== false, 'calculator authority failures are isolated from Bitrix admin');
$assert(
    $tryStart < $lookupPosition && $lookupPosition < $catchPosition,
    'preset authority lookup is covered by the host-admin resilience boundary'
);
$assert(
    str_contains(substr($body, $catchPosition), '$presetIblockId = 0;'),
    'authority failure disables only optional calculator controls'
);

foreach (['makeCalcPresetAssignmentReadOnly', 'onTabControlBegin', 'onBeforeEndBufferContent'] as $methodName) {
    $start = strpos($source, 'function ' . $methodName);
    $end = strpos($source, "\n    }", $start ?: 0);
    $methodBody = substr($source, $start ?: 0, ($end ?: strlen($source)) - ($start ?: 0));
    $assert($start !== false, $methodName . ' exists');
    $assert(
        str_contains($methodBody, 'catch (\\Throwable $error)'),
        $methodName . ' isolates optional calculator authority failures from Bitrix admin'
    );
}

$typesStart = strpos($source, 'private static function getModuleIblockTypes');
$typesEnd = strpos($source, 'public static function onBuildGlobalMenu', $typesStart ?: 0);
$typesBody = substr($source, $typesStart ?: 0, ($typesEnd ?: strlen($source)) - ($typesStart ?: 0));
$assert(
    str_contains($typesBody, "(string)\$row['IBLOCK_TYPE_ID']")
        && !str_contains($typesBody, "'CALC_STAGES' => 'calculator_catalog'"),
    'admin links use the actual Bitrix grouping without making it runtime authority'
);

fwrite(STDOUT, "Admin handler runtime resilience static tests passed\n");
