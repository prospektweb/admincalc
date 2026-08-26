<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Prospektweb\Calc\Calculator\ElementDataService;

/** Read-only assembler of the six authorities that make an executable calculator. */
final class CalculatorVersionSnapshotSourceService
{
    public const LOGIC_CONTRACT = 'prospektweb.calc.version-logic-snapshot/v1';
    public const PRODUCT_ASSIGNMENTS_CONTRACT = 'prospektweb.calc.version-product-assignments/v1';

    /** @var array<string,callable> */
    private array $adapters;

    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    /**
     * @param array<string,mixed> $formDocument
     * @return array<string,mixed>
     */
    public function capture(int $presetId, array $formDocument): array
    {
        if ($presetId <= 0
            || !is_array($formDocument['formDefinition'] ?? null)
            || !is_array($formDocument['bindingDefinition'] ?? null)) {
            throw new \InvalidArgumentException('Для полного снимка требуется точный документ формы версии.');
        }
        $storefronts = $this->storefronts($presetId);
        return [
            'form' => [
                'contract' => CalculatorVersionFormDocumentService::CONTRACT,
                'formDefinition' => $formDocument['formDefinition'],
                'bindingDefinition' => $formDocument['bindingDefinition'],
            ],
            'logic' => $this->logic($presetId),
            'storefronts' => $storefronts,
            'inputMappings' => $this->inputMappings($presetId),
            'outputMappings' => $this->outputMappings($presetId),
            'productAssignments' => $this->productAssignments($presetId, $storefronts),
        ];
    }

    /** @return array<string,mixed> */
    private function logic(int $presetId): array
    {
        if (isset($this->adapters['logic'])) {
            $value = call_user_func($this->adapters['logic'], $presetId);
            if (!is_array($value)) throw new \RuntimeException('Logic snapshot adapter returned invalid data.');
            return $value;
        }
        $authority = new CalculatorMutationAuthorityService();
        return $authority->withAuthorityLock(
            $presetId,
            static function (bool $_protection, array $iblockIds, CalculatorMutationAuthorityService $lockedAuthority) use ($presetId): array {
                $graph = $lockedAuthority->readLockedPresetGraph($presetId);
                $loader = new ElementDataService($iblockIds);
                $requests = [[
                    'iblockId' => (int)$iblockIds['CALC_PRESETS'],
                    'iblockType' => null,
                    'ids' => [$presetId],
                    'includeParent' => false,
                ]];
                foreach ([
                    'details' => ['CALC_DETAILS', $graph['detailIds']],
                    'stages' => ['CALC_STAGES', $graph['stageIds']],
                    'settings' => ['CALC_SETTINGS', $graph['settingsIds']],
                ] as [$iblockCode, $ids]) {
                    if ($ids === []) continue;
                    $requests[] = [
                        'iblockId' => (int)$iblockIds[$iblockCode],
                        'iblockType' => null,
                        'ids' => $ids,
                        'includeParent' => false,
                    ];
                }
                $payload = $loader->prepareRefreshPayload($requests);
                return [
                    'contract' => self::LOGIC_CONTRACT,
                    'presetId' => $presetId,
                    'graph' => $graph,
                    'elements' => array_values($payload),
                ];
            }
        );
    }

    /** @return array<string,mixed> */
    private function storefronts(int $presetId): array
    {
        if (isset($this->adapters['storefronts'])) {
            $value = call_user_func($this->adapters['storefronts'], $presetId);
            if (!is_array($value)) throw new \RuntimeException('Storefront snapshot adapter returned invalid data.');
            return $value;
        }
        if (!Loader::includeModule('prospektweb.frontcalc')) {
            throw new \RuntimeException('Модуль prospektweb.frontcalc недоступен для полного снимка витрин.');
        }
        $class = '\\Prospektweb\\Frontcalc\\Service\\StorefrontRepository';
        if (!class_exists($class)) throw new \RuntimeException('Хранилище витрин недоступно.');
        return (new $class())->listStorefronts($presetId);
    }

    /** @return array<string,mixed> */
    private function inputMappings(int $presetId): array
    {
        if (isset($this->adapters['inputMappings'])) {
            $value = call_user_func($this->adapters['inputMappings'], $presetId);
            if (!is_array($value)) throw new \RuntimeException('Input mapping snapshot adapter returned invalid data.');
            return $value;
        }
        return (new CalculatorInputMappingService())->load($presetId);
    }

    /** @return array<string,mixed> */
    private function outputMappings(int $presetId): array
    {
        if (isset($this->adapters['outputMappings'])) {
            $value = call_user_func($this->adapters['outputMappings'], $presetId);
            if (!is_array($value)) throw new \RuntimeException('Output mapping snapshot adapter returned invalid data.');
            return $value;
        }
        return (new CatalogOutputMappingService())->load($presetId);
    }

    /** @param array<string,mixed> $storefronts @return array<string,mixed> */
    private function productAssignments(int $presetId, array $storefronts): array
    {
        if (isset($this->adapters['productAssignments'])) {
            $value = call_user_func($this->adapters['productAssignments'], $presetId, $storefronts);
            if (!is_array($value)) throw new \RuntimeException('Product assignment snapshot adapter returned invalid data.');
            return $value;
        }
        $catalog = (new ControlCenterEditorsService())->getPresetProductCatalog($presetId, '', 1, 1);
        $storefrontByProduct = [];
        foreach ((array)($storefronts['items'] ?? []) as $storefront) {
            if (!is_array($storefront) || empty($storefront['active'])) continue;
            $storefrontId = (string)($storefront['id'] ?? '');
            foreach ((array)($storefront['product_ids'] ?? []) as $productId) {
                $productId = (int)$productId;
                if ($productId <= 0 || isset($storefrontByProduct[$productId])) {
                    throw new \RuntimeException('Товар имеет неоднозначное назначение активной витрины.', 409);
                }
                $storefrontByProduct[$productId] = $storefrontId;
            }
        }
        $assignments = [];
        foreach ((array)($catalog['linkedProductIds'] ?? []) as $productId) {
            $productId = (int)$productId;
            if ($productId > 0) {
                $assignments[] = [
                    'productId' => $productId,
                    'storefrontId' => $storefrontByProduct[$productId] ?? 'BASE',
                ];
            }
        }
        usort($assignments, static fn(array $left, array $right): int => $left['productId'] <=> $right['productId']);
        return [
            'contract' => self::PRODUCT_ASSIGNMENTS_CONTRACT,
            'presetId' => $presetId,
            'sourceRevision' => (string)($catalog['revision'] ?? ''),
            'assignments' => $assignments,
        ];
    }
}
