<?php

require_once dirname(__DIR__) . '/lib/Services/AiFormPilotProposalService.php';

use Prospektweb\Calc\Services\AiFormPilotProposalService;

function aiFormPilotFail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function aiFormPilotProposal(): array
{
    $field = static function (string $id, string $type): array {
        return [
            'fieldId' => $id, 'type' => $type, 'multiple' => false, 'label' => $id,
            'help' => '', 'publicVisible' => true, 'unit' => null, 'min' => null,
            'max' => null, 'step' => null, 'required' => false, 'defaultValue' => null,
            'visibleWhen' => null, 'requiredWhen' => null, 'dependentFieldIds' => [],
            'options' => [], 'dimensionInputs' => [], 'presetValues' => [],
        ];
    };
    $method = $field('print-method', 'select');
    $method['required'] = true;
    $method['defaultValue'] = 'digital';
    $method['options'] = [
        ['id' => 'digital', 'label' => 'Цифровая печать', 'help' => ''],
        ['id' => 'offset', 'label' => 'Офсетная печать', 'help' => ''],
    ];
    $method['dependentFieldIds'] = ['ink-coverage'];
    $coverage = $field('ink-coverage', 'number');
    $coverage['unit'] = '%'; $coverage['min'] = 0; $coverage['max'] = 100; $coverage['step'] = 1; $coverage['defaultValue'] = 20;
$coverage['visibleWhen'] = ['fieldId' => 'print-method', 'operator' => 'equals', 'value' => 'digital'];
    $coverage['presetValues'] = [['id' => 'coverage-20', 'label' => '20%', 'value' => 20]];
    return [
        'schema' => AiFormPilotProposalService::PROPOSAL_SCHEMA,
        'level' => 'professional',
        'summary' => 'Профессиональная форма печати.',
        'assumptions' => ['Листовой способ печати.'],
        'volumePresets' => [500, 10, 100, 1000],
        'volumeDefault' => 100,
        'sections' => [[
            'id' => 'print', 'title' => 'Печать', 'description' => '', 'displayMode' => 'accordion',
            'initiallyOpen' => true, 'showTitle' => true, 'visibleWhen' => null, 'fields' => [$method, $coverage],
        ]],
    ];
}

$service = new AiFormPilotProposalService();
$clean = $service->sanitizeRequest(['level' => 'professional', 'wishes' => 'Подготовить подробную форму листовой печати.', 'calculatorName' => 'Листовая печать']);
if ($clean['level'] !== 'professional') aiFormPilotFail('request level was changed');
$prompt = $service->buildSystemPrompt($clean);
foreach (['без связи с Bitrix', 'dependentFieldIds', 'процент заливки', AiFormPilotProposalService::PROPOSAL_SCHEMA] as $needle) {
    if (strpos($prompt, $needle) === false) aiFormPilotFail('prompt misses ' . $needle);
}
$parsed = $service->validateProposal(aiFormPilotProposal(), 'professional');
if ($parsed['volumePresets'] !== [10, 100, 500, 1000]) aiFormPilotFail('volumes were not normalized');

$withOmittedOptionalKeys = aiFormPilotProposal();
$withOmittedOptionalKeys['sections'][0]['fields'][] = [
    'fieldId' => 'custom-size',
    'type' => 'dimensions',
    'label' => 'Произвольный размер',
    'dimensionInputs' => [
        ['fieldId' => 'width', 'name' => 'Ширина', 'defaultValue' => 210],
        ['id' => 'height', 'label' => 'Высота', 'unit' => 'мм'],
    ],
];
$normalizedOptional = $service->validateProposal($withOmittedOptionalKeys, 'professional');
$sizeField = $normalizedOptional['sections'][0]['fields'][2] ?? null;
if (($sizeField['publicVisible'] ?? null) !== true || ($sizeField['dimensionInputs'][0]['unit'] ?? null) !== '') {
    aiFormPilotFail('omitted optional model keys were not normalized safely');
}
if (($sizeField['dimensionInputs'][0]['id'] ?? null) !== 'width' || ($sizeField['dimensionInputs'][0]['label'] ?? null) !== 'Ширина') {
    aiFormPilotFail('safe dimension aliases were not normalized');
}

$unknown = aiFormPilotProposal();
$unknown['bitrixPropertyId'] = 42;
try {
    $service->validateProposal($unknown, 'professional');
    aiFormPilotFail('unknown Bitrix key was accepted');
} catch (RuntimeException $error) {
    if (strpos($error->getMessage(), 'неизвестные') === false) aiFormPilotFail('unknown key failed for the wrong reason');
}

$cyclic = aiFormPilotProposal();
$cyclic['sections'][0]['fields'][1]['dependentFieldIds'] = ['print-method'];
try {
    $service->validateProposal($cyclic, 'professional');
    aiFormPilotFail('dependency cycle was accepted');
} catch (RuntimeException $error) {
    if (strpos($error->getMessage(), 'цикл') === false) aiFormPilotFail('cycle failed for the wrong reason');
}

echo "AI form pilot contract checks passed\n";
