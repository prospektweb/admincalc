<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Modules/AiModuleContract.php';

use Prospektweb\Calc\Modules\AiModuleContract;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$catalog = AiModuleContract::sanitizeCatalog([[
    'familyId' => 'digital_sheet_print',
    'version' => '1.0.0',
    'kind' => 'stage',
    'name' => 'Digital print',
    'description' => '',
    'contentHash' => '79cfe4fd843259e19ea1c144656a67800721b08c4a73bf7a6eeb2ce9305e9e62',
    'ports' => [
        ['code' => 'quantity', 'direction' => 'input', 'valueType' => 'number', 'required' => true],
        ['code' => 'setupCost', 'direction' => 'global-input', 'valueType' => 'number', 'required' => true],
        ['code' => 'result', 'direction' => 'output', 'valueType' => 'number', 'required' => true],
    ],
    'entityRoles' => [
        ['code' => 'printOperation', 'entityType' => 'operation', 'cardinality' => 'one', 'description' => ''],
    ],
    'constraints' => [],
    'tests' => [['name' => 'golden', 'inputs' => ['quantity' => 2], 'expectedOutputs' => ['result' => 4]]],
]], 'stage');

$proposal = [
    'schema' => AiModuleContract::PROPOSAL_SCHEMA,
    'familyId' => 'digital_sheet_print',
    'version' => '1.0.0',
    'contentHash' => $catalog[0]['contentHash'],
    'summary' => 'Use the published module',
    'mappings' => [
        'ports' => [
            ['portCode' => 'quantity', 'target' => ['kind' => 'source-ref', 'ref' => 'source_001']],
            ['portCode' => 'setupCost', 'target' => ['kind' => 'global-ref', 'ref' => 'setupCost']],
        ],
        'entityRoles' => [
            ['roleCode' => 'printOperation', 'selectorRef' => 'entity_001'],
        ],
    ],
    'previewRequired' => true,
    'applyAllowed' => false,
    'publishAllowed' => false,
];
$validated = AiModuleContract::validateProposal(
    $proposal,
    $catalog,
    ['source_001'],
    ['setupCost'],
    ['entity_001']
);
$assert($validated['previewRequired'] === true, 'AI attachment must require preview');
$assert($validated['applyAllowed'] === false, 'AI attachment must not apply');

$missingGlobalRejected = false;
try {
    $invalid = $proposal;
    array_pop($invalid['mappings']['ports']);
    AiModuleContract::validateProposal($invalid, $catalog, ['source_001'], ['setupCost'], ['entity_001']);
} catch (InvalidArgumentException $error) {
    $missingGlobalRejected = str_contains($error->getMessage(), 'Required module port');
}
$assert($missingGlobalRejected, 'missing required global mapping must be rejected');

$latestRejected = false;
try {
    $invalid = $proposal;
    $invalid['version'] = 'latest';
    AiModuleContract::validateProposal($invalid, $catalog, ['source_001'], ['setupCost'], ['entity_001']);
} catch (InvalidArgumentException $error) {
    $latestRejected = str_contains($error->getMessage(), 'exact');
}
$assert($latestRejected, 'latest version must be rejected');

$idRejected = false;
try {
    $invalid = $proposal;
    $invalid['mappings']['entityRoles'][0]['id'] = 42;
    AiModuleContract::validateProposal($invalid, $catalog, ['source_001'], ['setupCost'], ['entity_001']);
} catch (InvalidArgumentException $error) {
    $idRejected = str_contains($error->getMessage(), 'IDs');
}
$assert($idRejected, 'invented ID must be rejected');

echo "AI module contract tests passed\n";
