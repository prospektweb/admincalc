<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/CalculatorVersionFormDocumentService.php';

use Prospektweb\Calc\Services\CalculatorVersionFormDocumentService;

$store = [];
$clock = 0;
$service = new CalculatorVersionFormDocumentService([
    'get' => static function (string $name) use (&$store): string { return $store[$name] ?? ''; },
    'set' => static function (string $name, string $value) use (&$store): void { $store[$name] = $value; },
    'delete' => static function (string $name) use (&$store): void { unset($store[$name]); },
    'now' => static function () use (&$clock): string { return '2026-08-26T12:00:' . str_pad((string)$clock++, 2, '0', STR_PAD_LEFT) . '+05:00'; },
]);

$legacy = [
    'formDefinition' => ['contract' => 'form/v1', 'fields' => [['fieldId' => 'volume']]],
    'bindingDefinition' => ['contract' => 'binding/v1', 'bindings' => []],
];
$base = $service->ensure(12740, 'v_1111111111111111', null, $legacy);
if (($base['formDefinition']['fields'][0]['fieldId'] ?? '') !== 'volume') throw new RuntimeException('legacy seed failed');

$clone = $service->ensure(12740, 'v_2222222222222222', 'v_1111111111111111', $legacy);
if ($clone['formDefinition'] !== $base['formDefinition']) throw new RuntimeException('clone failed');

$changed = $clone['formDefinition'];
$changed['fields'][0]['fieldId'] = 'quantity';
$saved = $service->saveDraft(12740, 'v_2222222222222222', $clone['revision'], $changed, $clone['bindingDefinition']);
if (($saved['formDefinition']['fields'][0]['fieldId'] ?? '') !== 'quantity') throw new RuntimeException('isolated save failed');
$baseAgain = $service->ensure(12740, 'v_1111111111111111', null, $legacy);
if (($baseAgain['formDefinition']['fields'][0]['fieldId'] ?? '') !== 'volume') throw new RuntimeException('base document was mutated');

$emptyLegacy = [
    'formDefinition' => ['contract' => 'form/v1', 'fields' => []],
    'bindingDefinition' => ['contract' => 'binding/v1', 'bindings' => []],
];
$empty = $service->ensure(12740, 'v_3333333333333333', null, $emptyLegacy);
$seededDraft = $service->ensure(12740, 'v_3333333333333333', null, $legacy, true);
if (($seededDraft['formDefinition']['fields'][0]['fieldId'] ?? '') !== 'volume') throw new RuntimeException('empty draft system seed failed');
if ($seededDraft['revision'] === $empty['revision']) throw new RuntimeException('empty draft seed did not advance revision');

$conflict = false;
try {
    $service->saveDraft(12740, 'v_2222222222222222', $clone['revision'], $changed, $clone['bindingDefinition']);
} catch (RuntimeException $error) {
    $conflict = $error->getCode() === 409;
}
if (!$conflict) throw new RuntimeException('stale document revision was accepted');

$service->delete(12740, 'v_2222222222222222');
$reseeded = $service->ensure(12740, 'v_2222222222222222', null, $legacy);
if (($reseeded['formDefinition']['fields'][0]['fieldId'] ?? '') !== 'volume') throw new RuntimeException('delete failed');

echo "Calculator version form document service tests passed\n";
