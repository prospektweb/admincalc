<?php

declare(strict_types=1);

namespace Bitrix\Main {
    final class Loader
    {
        public static function includeModule(string $moduleId): bool
        {
            return $moduleId === 'prospektweb.frontcalc';
        }
    }
}

namespace Prospektweb\Calc\Services {
    final class CatalogCalculationScenarioService
    {
        public function preview(
            int $presetId,
            array $offers,
            array $authoring,
            array $runtimeSchema,
            ?array $mapping = null
        ): array {
            $offer = $offers[0];
            return [
                'ready' => true,
                'hasTargets' => true,
                'revision' => (int)$mapping['revision'],
                'scenarios' => [[
                    'contract' => 'prospektweb.calc.catalog-scenario/v2',
                    'scenarioId' => 'offer:' . $offer['id'],
                    'presetId' => $presetId,
                    'source' => 'catalog-input-mapping',
                    'publicationRevision' => (int)$authoring['publication']['revision'],
                    'publicationCompileHash' => (string)$authoring['publication']['compileHash'],
                    'inputMappingRevision' => (int)$mapping['revision'],
                    'target' => [
                        'productId' => (int)$offer['productId'],
                        'offerId' => (int)$offer['id'],
                        'name' => (string)$offer['name'],
                    ],
                    'quantity' => 100,
                    'values' => ['volume' => 100],
                ]],
                'errors' => [],
            ];
        }
    }
}

namespace Prospektweb\Frontcalc\Service {
    final class FormFirstAuthoringStore
    {
    }
}

namespace {
    require_once dirname(__DIR__) . '/lib/Calculator/InitPayloadService.php';

    use Prospektweb\Calc\Calculator\InitPayloadService;

    $assert = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    };

    $presetId = 41;
    $compileHash = str_repeat('a', 64);
    $authoring = [
        'formDefinition' => [
            'contract' => 'prospektweb.frontcalc.form-definition/v1',
            'presetId' => $presetId,
            'fields' => [],
        ],
        'bindingDefinition' => [
            'contract' => 'prospektweb.frontcalc.binding-definition/v1',
            'presetId' => $presetId,
            'bindings' => [],
        ],
        'publication' => ['revision' => 7, 'compileHash' => $compileHash],
    ];
    $inputMapping = [
        'contract' => 'prospektweb.calc.calculator-input-mapping/v1',
        'preset_id' => $presetId,
        'revision' => 3,
        'mappings' => [],
    ];
    $outputMapping = [
        'contract' => 'prospektweb.calc.catalog-output-mapping/v1',
        'preset_id' => $presetId,
        'revision' => 5,
        'mappings' => [[
            'source_path' => 'result.purchasePrice',
            'target_path' => 'catalog.offer.purchasingPrice',
        ]],
    ];

    $service = new InitPayloadService();
    $method = new ReflectionMethod($service, 'buildEditorRuntime');
    $method->setAccessible(true);
    $runtime = $method->invoke(
        $service,
        $presetId,
        [['id' => 501, 'productId' => 101, 'name' => 'Offer 501']],
        ['id' => 101],
        'catalog',
        $authoring,
        [
            'contract' => 'prospektweb.frontcalc.runtime-schema/v2',
            '_form_first' => [
                'publishedRevision' => 7,
                'compileHash' => $compileHash,
            ],
        ],
        $inputMapping,
        $outputMapping
    );

    $assert(array_keys($runtime) === [
        'contract',
        'launchContext',
        'formDefinition',
        'bindingDefinition',
        'publication',
        'calculatorInputMapping',
        'catalogScenarios',
        'catalogInputMapping',
        'catalogOutputMapping',
        'catalogWriteback',
    ], 'editorRuntime has the exact v2 top-level shape');
    $assert($runtime['contract'] === 'prospektweb.calc.editor-runtime/v2', 'editor runtime contract');
    $assert($runtime['launchContext'] === [
        'contract' => 'prospektweb.calc.launch-context/v2',
        'mode' => 'catalog',
        'presetId' => 41,
        'productIds' => [101],
        'offerIds' => [501],
    ], 'launch context exact v2 shape');
    $assert(array_keys($runtime['catalogInputMapping']) === ['ready', 'hasTargets', 'revision', 'errors'], 'catalog input status exact shape');
    $assert($runtime['catalogInputMapping'] === ['ready' => true, 'hasTargets' => true, 'revision' => 3, 'errors' => []], 'catalog input status values');
    $assert(array_keys($runtime['catalogWriteback']) === ['ready', 'revision', 'errors'], 'catalog writeback exact shape');
    $assert($runtime['catalogWriteback'] === ['ready' => true, 'revision' => 5, 'errors' => []], 'catalog writeback status values');
    $assert($runtime['catalogScenarios'][0]['contract'] === 'prospektweb.calc.catalog-scenario/v2', 'scenario contract v2');
    $assert(array_keys($runtime['catalogScenarios'][0]) === [
        'contract',
        'scenarioId',
        'presetId',
        'source',
        'publicationRevision',
        'publicationCompileHash',
        'inputMappingRevision',
        'target',
        'quantity',
        'values',
    ], 'scenario exact v2 shape');
    $assert($runtime['catalogScenarios'][0]['source'] === 'catalog-input-mapping', 'scenario provenance');

    echo "Init payload editor runtime contract tests passed\n";
}
