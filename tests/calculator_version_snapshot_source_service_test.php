<?php

declare(strict_types=1);

namespace Bitrix\Main {
    final class Loader
    {
        public static function includeModule(string $moduleId): bool
        {
            return false;
        }
    }
}

namespace {
    require_once __DIR__ . '/../lib/Services/CalculatorVersionFormDocumentService.php';
    require_once __DIR__ . '/../lib/Services/CalculatorVersionBundleDocumentService.php';
    require_once __DIR__ . '/../lib/Services/CalculatorVersionSnapshotSourceService.php';

    use Prospektweb\Calc\Services\CalculatorVersionBundleDocumentService;
    use Prospektweb\Calc\Services\CalculatorVersionFormDocumentService;
    use Prospektweb\Calc\Services\CalculatorVersionSnapshotSourceService;

    $assert = static function (bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
    };

    $calls = [];
    $source = new CalculatorVersionSnapshotSourceService([
        'logic' => static function (int $presetId) use (&$calls): array {
            $calls[] = 'logic';
            return ['contract' => CalculatorVersionSnapshotSourceService::LOGIC_CONTRACT, 'presetId' => $presetId];
        },
        'storefronts' => static function (int $presetId) use (&$calls): array {
            $calls[] = 'storefronts';
            return ['items' => [['id' => 'main', 'active' => true, 'product_ids' => [11]]]];
        },
        'inputMappings' => static function (int $presetId) use (&$calls): array {
            $calls[] = 'inputMappings';
            return ['presetId' => $presetId, 'mappings' => []];
        },
        'outputMappings' => static function (int $presetId) use (&$calls): array {
            $calls[] = 'outputMappings';
            return ['presetId' => $presetId, 'mappings' => []];
        },
        'productAssignments' => static function (int $presetId, array $storefronts) use (&$calls): array {
            $calls[] = 'productAssignments';
            return [
                'contract' => CalculatorVersionSnapshotSourceService::PRODUCT_ASSIGNMENTS_CONTRACT,
                'presetId' => $presetId,
                'assignments' => [['productId' => 11, 'storefrontId' => $storefronts['items'][0]['id']]],
            ];
        },
        'publicationMetadata' => static function (int $presetId) use (&$calls): array {
            $calls[] = 'publicationMetadata';
            return [
                'contract' => CalculatorVersionSnapshotSourceService::PUBLICATION_METADATA_CONTRACT,
                'presetId' => $presetId,
                'calculatorName' => 'Листовая печать',
            ];
        },
        'commercialPolicy' => static function (int $presetId) use (&$calls): array {
            $calls[] = 'commercialPolicy';
            return CalculatorVersionSnapshotSourceService::defaultCommercialPolicy($presetId);
        },
    ]);

    $snapshot = $source->capture(12740, [
        'formDefinition' => ['contract' => 'prospektweb.calc.form-definition/v1', 'fields' => []],
        'bindingDefinition' => ['contract' => 'prospektweb.calc.binding-definition/v1', 'mappings' => []],
    ]);

    $assert(array_keys($snapshot) === CalculatorVersionBundleDocumentService::COMPONENTS, 'snapshot must contain exactly all version components');
    $assert(($snapshot['form']['contract'] ?? null) === CalculatorVersionFormDocumentService::CONTRACT, 'form envelope contract is missing');
    $assert(($snapshot['logic']['presetId'] ?? null) === 12740, 'logic snapshot is missing');
    $assert(($snapshot['storefronts']['items'][0]['id'] ?? null) === 'main', 'storefront snapshot is missing');
    $assert(($snapshot['inputMappings']['presetId'] ?? null) === 12740, 'input mappings snapshot is missing');
    $assert(($snapshot['outputMappings']['presetId'] ?? null) === 12740, 'output mappings snapshot is missing');
    $assert(($snapshot['productAssignments']['assignments'][0]['storefrontId'] ?? null) === 'main', 'product assignments snapshot is missing');
    $assert(($snapshot['publicationMetadata']['calculatorName'] ?? null) === 'Листовая печать', 'publication metadata snapshot is missing');
    $assert(($snapshot['commercialPolicy']['deadlinePolicy']['mode'] ?? null) === 'basic', 'commercial policy snapshot is missing');
    $assert($calls === ['storefronts', 'logic', 'inputMappings', 'outputMappings', 'productAssignments', 'publicationMetadata', 'commercialPolicy'], 'snapshot authorities were not read exactly once');

    $isolatedLogic = $source->captureLogic(54321, 12740, 'v_4444444444444444');
    $assert(($isolatedLogic['presetId'] ?? null) === 12740, 'isolated logic must retain calculator identity');
    $assert(($isolatedLogic['workingPresetId'] ?? null) === 54321, 'isolated logic must retain physical working preset');
    $assert(($isolatedLogic['workingVersionId'] ?? null) === 'v_4444444444444444', 'isolated logic must retain owning version');

    $invalidFormRejected = false;
    try {
        $source->capture(12740, ['formDefinition' => []]);
    } catch (InvalidArgumentException $error) {
        $invalidFormRejected = str_contains($error->getMessage(), 'точный документ формы');
    }
    $assert($invalidFormRejected, 'incomplete form document must be rejected before reading other authorities');

    $logicSource = file_get_contents(__DIR__ . '/../lib/Services/CalculatorVersionSnapshotSourceService.php');
    $assert(is_string($logicSource), 'snapshot source implementation must be readable');
    $assert(
        str_contains($logicSource, 'array $_lockedAuthority')
        && str_contains($logicSource, 'use ($sourcePresetId, $authority)'),
        'authority lock callback must consume the authority snapshot as an array and use the captured service for graph readback'
    );

    echo "Calculator version snapshot source service tests passed\n";
}
