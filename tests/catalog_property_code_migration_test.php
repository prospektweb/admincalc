<?php

require_once dirname(__DIR__) . '/lib/Install/CatalogPropertyCodeMigrationService.php';

use Prospektweb\Calc\Install\CatalogPropertyCodeMigrationService;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAILED: {$message}\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$source = json_encode([
    'offerPropertyCodes' => ['CALC_COLORS', 'CALC_COLOR', 'CALC_PROP_FORMAT'],
    'rows' => [
        ['key' => 'offer:CALC_COLORS', 'sourcePath' => 'offer.properties.CALC_COLORS.VALUE'],
        ['key' => 'offer:CALC_COLOR', 'code' => 'CALC_COLOR'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$rewritten = CatalogPropertyCodeMigrationService::rewriteJsonReferences($source);
$decoded = json_decode((string)$rewritten, true);
assertSameValue(
    ['CALC_PROP_COLOR', 'CALC_PROP_COLOR', 'CALC_PROP_FORMAT'],
    $decoded['offerPropertyCodes'] ?? null,
    'both legacy color aliases must be rewritten'
);
assertSameValue(
    'offer.properties.CALC_PROP_COLOR.VALUE',
    $decoded['rows'][0]['sourcePath'] ?? null,
    'mapping paths must use the new property code'
);
assertSameValue(
    'CALC_PROP_COLOR',
    $decoded['rows'][1]['code'] ?? null,
    'short legacy code must use the new property code'
);
assertSameValue(
    ['offer:CALC_PROP_COLOR', 'offer:CALC_PROP_COLOR'],
    array_column($decoded['rows'] ?? [], 'key'),
    'mapping values containing either legacy alias must be rewritten'
);

$htmlEncoded = htmlspecialchars($source, ENT_QUOTES | ENT_HTML5, 'UTF-8');
assertSameValue(
    $rewritten,
    CatalogPropertyCodeMigrationService::rewriteJsonReferences($htmlEncoded),
    'HTML-wrapped Bitrix property JSON must be migrated'
);
assertSameValue(
    null,
    CatalogPropertyCodeMigrationService::rewriteJsonReferences('not-json'),
    'invalid JSON must be left untouched'
);
assertSameValue(
    null,
    CatalogPropertyCodeMigrationService::rewriteJsonReferences('{"code":"CALC_PROP_COLOR"}'),
    'already migrated JSON must be left untouched'
);

$serviceSource = file_get_contents(dirname(__DIR__) . '/lib/Install/CatalogPropertyCodeMigrationService.php');
$installerSource = file_get_contents(dirname(__DIR__) . '/install/step3.php');
$payloadSource = file_get_contents(dirname(__DIR__) . '/lib/Calculator/InitPayloadService.php');
foreach ([$serviceSource, $installerSource, $payloadSource] as $sourceText) {
    if (!is_string($sourceText)) {
        fwrite(STDERR, "FAILED: migration source unavailable\n");
        exit(1);
    }
}

foreach (['CALC_COLORS', 'CALC_COLOR', 'CALC_PROP_COLOR'] as $code) {
    if (strpos($serviceSource, $code) === false || strpos($installerSource, $code) === false) {
        fwrite(STDERR, "FAILED: compatibility code {$code} is missing\n");
        exit(1);
    }
}
if (
    strpos($serviceSource, "Update((int)\$legacy['ID'], ['CODE' => self::TARGET_CODE])") === false
    || strpos($serviceSource, 'migrateStageOptionReferences()') === false
    || strpos($payloadSource, 'prepareNeutralInitPayloadReadOnly') === false
    || strpos($payloadSource, 'CatalogPropertyCodeMigrationService') !== false
) {
    fwrite(STDERR, "FAILED: property migration is not explicit or neutral INIT still mutates schema\n");
    exit(1);
}
foreach (['USER_TYPE_SETTINGS', 'DIRECTORY_ITEMS', 'UF_XML_ID', 'UF_FILE'] as $contractToken) {
    if (strpos($payloadSource, $contractToken) === false) {
        fwrite(STDERR, "FAILED: directory payload contract {$contractToken} is missing\n");
        exit(1);
    }
}

echo "Catalog property code migration tests passed\n";
