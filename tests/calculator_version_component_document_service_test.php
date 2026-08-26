<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionBundleDocumentService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionFormDocumentService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorInputMappingService.php';
require_once dirname(__DIR__) . '/lib/Services/CatalogOutputMappingService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionSnapshotSourceService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionComponentDocumentService.php';

use Prospektweb\Calc\Services\CalculatorInputMappingService;
use Prospektweb\Calc\Services\CalculatorVersionBundleDocumentService;
use Prospektweb\Calc\Services\CalculatorVersionComponentDocumentService;
use Prospektweb\Calc\Services\CalculatorVersionFormDocumentService;
use Prospektweb\Calc\Services\CalculatorVersionSnapshotSourceService;
use Prospektweb\Calc\Services\CatalogOutputMappingService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$storage = [];
$bundles = new CalculatorVersionBundleDocumentService([
    'get' => static fn(string $name): string => (string)($GLOBALS['component_storage'][$name] ?? ''),
    'set' => static function (string $name, string $value): void { $GLOBALS['component_storage'][$name] = $value; },
    'delete' => static function (string $name): void { unset($GLOBALS['component_storage'][$name]); },
    'now' => static fn(): string => '2026-08-26T18:30:00+05:00',
]);
$GLOBALS['component_storage'] = &$storage;
$presetId = 12740;
$versionId = 'v_3333333333333333';
$documents = [
    'form' => [
        'contract' => CalculatorVersionFormDocumentService::CONTRACT,
        'formDefinition' => ['contract' => 'prospektweb.frontcalc.form-definition/v1'],
        'bindingDefinition' => ['contract' => 'prospektweb.frontcalc.binding-definition/v1'],
    ],
    'logic' => [
        'contract' => CalculatorVersionSnapshotSourceService::LOGIC_CONTRACT,
        'presetId' => $presetId,
        'graph' => ['presetId' => $presetId],
        'elements' => [],
    ],
    'storefronts' => [
        'contract' => 'prospektweb.frontcalc.storefront-definition/v2',
        'preset_id' => $presetId,
        'items' => [],
    ],
    'inputMappings' => [
        'contract' => CalculatorInputMappingService::CONTRACT,
        'presetId' => $presetId,
        'mappings' => [],
    ],
    'outputMappings' => [
        'contract' => CatalogOutputMappingService::CONTRACT,
        'presetId' => $presetId,
        'mappings' => [],
    ],
    'productAssignments' => [
        'contract' => CalculatorVersionSnapshotSourceService::PRODUCT_ASSIGNMENTS_CONTRACT,
        'presetId' => $presetId,
        'assignments' => [],
    ],
];
$initial = $bundles->save($presetId, $versionId, $documents);
$service = new CalculatorVersionComponentDocumentService($bundles);
$loaded = $service->load($presetId, $versionId, 'storefronts');
$assert($loaded['document'] == $documents['storefronts'], 'selected component readback mismatch');

$changed = $documents['storefronts'];
$changed['items'][] = ['id' => 'BASE', 'active' => true, 'product_ids' => []];
$saved = $service->saveDraft(
    $presetId,
    $versionId,
    'storefronts',
    $loaded['contentHash'],
    $loaded['componentHash'],
    $changed
);
$assert($saved['document'] == $changed, 'selected component was not saved');
$after = $bundles->load($presetId, $versionId);
$assert(is_array($after), 'bundle disappeared after component save');
foreach (CalculatorVersionBundleDocumentService::COMPONENTS as $component) {
    if ($component === 'storefronts') continue;
    $assert(
        $after['componentHashes'][$component] === $initial['componentHashes'][$component],
        'saving one component changed ' . $component
    );
}

$staleRejected = false;
try {
    $service->saveDraft(
        $presetId,
        $versionId,
        'storefronts',
        $loaded['contentHash'],
        $loaded['componentHash'],
        $changed
    );
} catch (RuntimeException $error) {
    $staleRejected = $error->getCode() === 409;
}
$assert($staleRejected, 'stale aggregate/component CAS must be rejected');

$invalidRejected = false;
try {
    $service->saveDraft(
        $presetId,
        $versionId,
        'storefronts',
        $saved['contentHash'],
        $saved['componentHash'],
        ['contract' => 'invalid', 'preset_id' => $presetId, 'items' => []]
    );
} catch (InvalidArgumentException $error) {
    $invalidRejected = true;
}
$assert($invalidRejected, 'component contract mismatch must be rejected');

echo "Calculator version component document service tests passed\n";
