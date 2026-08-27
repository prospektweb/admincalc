<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionBundleDocumentService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionSnapshotSourceService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionComponentDocumentService.php';
require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionRuntimePublicationService.php';

use Prospektweb\Calc\Services\CalculatorVersionBundleDocumentService;
use Prospektweb\Calc\Services\CalculatorVersionRuntimePublicationService;
use Prospektweb\Calc\Services\CalculatorVersionSnapshotSourceService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$bundleStorage = [];
$pointerStorage = [];
$bundles = new CalculatorVersionBundleDocumentService([
    'get' => static fn(string $name): string => (string)($GLOBALS['runtime_bundle_storage'][$name] ?? ''),
    'set' => static function (string $name, string $value): void { $GLOBALS['runtime_bundle_storage'][$name] = $value; },
    'delete' => static function (string $name): void { unset($GLOBALS['runtime_bundle_storage'][$name]); },
    'now' => static fn(): string => '2026-08-27T09:00:00+05:00',
]);
$GLOBALS['runtime_bundle_storage'] = &$bundleStorage;
$documents = [];
foreach (CalculatorVersionBundleDocumentService::COMPONENTS as $component) {
    $documents[$component] = ['contract' => 'test/' . $component, 'presetId' => 12740];
}
$documents['publicationMetadata'] = [
    'contract' => CalculatorVersionSnapshotSourceService::PUBLICATION_METADATA_CONTRACT,
    'presetId' => 12740,
    'calculatorName' => 'Листовая печать',
];
$documents['commercialPolicy'] = CalculatorVersionSnapshotSourceService::defaultCommercialPolicy(12740);
$bundles->save(12740, 'v_4444444444444444', $documents);

$service = new CalculatorVersionRuntimePublicationService($bundles, [
    'get' => static fn(string $name): string => (string)($GLOBALS['runtime_pointer_storage'][$name] ?? ''),
    'set' => static function (string $name, string $value): void { $GLOBALS['runtime_pointer_storage'][$name] = $value; },
    'now' => static fn(): string => '2026-08-27T09:01:00+05:00',
]);
$GLOBALS['runtime_pointer_storage'] = &$pointerStorage;
$active = $service->activate(12740, 'v_4444444444444444');
$assert($active['calculatorName'] === 'Листовая печать', 'active pointer must use version metadata name');
$assert(($active['readiness']['complete'] ?? false) === true, 'active pointer must expose only a complete bundle');
$readiness = $service->readiness(12740);
$assert($readiness['ready'] === true && $readiness['versionId'] === 'v_4444444444444444', 'readiness must pin the exact version');

$invalidPolicyDocuments = $documents;
$invalidPolicyDocuments['commercialPolicy']['deadlinePolicy']['basic']['urgent']['effortPercent'] = -1;
$bundles->save(12740, 'v_5555555555555555', $invalidPolicyDocuments);
$invalidPolicyRejected = false;
try {
    $service->activate(12740, 'v_5555555555555555');
} catch (RuntimeException $error) {
    $invalidPolicyRejected = $error->getCode() === 409
        && str_contains($error->getMessage(), 'Исправьте вкладку «Сроки»');
}
$assert($invalidPolicyRejected, 'activation must reject an invalid deadline policy with an actionable editor destination');

$documents['logic']['changed'] = true;
$bundles->save(12740, 'v_4444444444444444', $documents);
$assert($service->readiness(12740)['ready'] === false, 'a mutated bundle must invalidate the active pointer');

echo "Calculator version runtime publication service tests passed\n";
