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
    public const PUBLICATION_METADATA_CONTRACT = 'prospektweb.calc.version-publication-metadata/v1';
    public const COMMERCIAL_POLICY_CONTRACT = 'prospektweb.calc.commercial-policy/v1';

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
        $logic = $this->captureLogic($presetId);
        $inputMappings = $this->inputMappings($presetId);
        $outputMappings = $this->outputMappings($presetId);
        $productAssignments = $this->productAssignments($presetId, $storefronts);
        $versionedStorefronts = $this->withoutProductAssignmentCopies($storefronts);
        $publicationMetadata = $this->publicationMetadata($presetId);
        $commercialPolicy = $this->commercialPolicy($presetId);
        return [
            'form' => [
                'contract' => CalculatorVersionFormDocumentService::CONTRACT,
                'formDefinition' => $formDocument['formDefinition'],
                'bindingDefinition' => $formDocument['bindingDefinition'],
            ],
            'logic' => $logic,
            'storefronts' => $versionedStorefronts,
            'inputMappings' => $inputMappings,
            'outputMappings' => $outputMappings,
            'productAssignments' => $productAssignments,
            'publicationMetadata' => $publicationMetadata,
            'commercialPolicy' => $commercialPolicy,
        ];
    }

    /** @return array<string,mixed> */
    public function captureLogic(
        int $sourcePresetId,
        ?int $calculatorPresetId = null,
        ?string $workingVersionId = null
    ): array
    {
        if (isset($this->adapters['logic'])) {
            $value = call_user_func($this->adapters['logic'], $sourcePresetId);
            if (!is_array($value)) throw new \RuntimeException('Logic snapshot adapter returned invalid data.');
        } else {
            $authority = new CalculatorMutationAuthorityService();
            $value = $authority->withAuthorityLock(
                $sourcePresetId,
                static function (bool $_protection, array $iblockIds, array $_lockedAuthority) use ($sourcePresetId, $authority): array {
                    $graph = $authority->readLockedPresetGraph($sourcePresetId);
                    $loader = new ElementDataService($iblockIds);
                    $requests = [[
                        'iblockId' => (int)$iblockIds['CALC_PRESETS'],
                        'iblockType' => null,
                        'ids' => [$sourcePresetId],
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
                        'presetId' => $sourcePresetId,
                        'graph' => $graph,
                        'elements' => array_values($payload),
                    ];
                }
            );
        }
        $calculatorPresetId = $calculatorPresetId ?? $sourcePresetId;
        $value['presetId'] = $calculatorPresetId;
        if ($calculatorPresetId !== $sourcePresetId) {
            $value['workingPresetId'] = $sourcePresetId;
            if ($workingVersionId !== null) $value['workingVersionId'] = $workingVersionId;
        } else {
            unset($value['workingPresetId'], $value['workingVersionId']);
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private function storefronts(int $presetId): array
    {
        if (isset($this->adapters['storefronts'])) {
            $value = call_user_func($this->adapters['storefronts'], $presetId);
            if (!is_array($value)) throw new \RuntimeException('Storefront snapshot adapter returned invalid data.');
            return $this->normalizeStorefronts($presetId, $value, (bool)($value['base_public'] ?? true));
        }
        if (!Loader::includeModule('prospektweb.frontcalc')) {
            throw new \RuntimeException('Модуль prospektweb.frontcalc недоступен для полного снимка витрин.');
        }
        $class = '\\Prospektweb\\Frontcalc\\Service\\StorefrontRepository';
        if (!class_exists($class)) throw new \RuntimeException('Хранилище витрин недоступно.');
        $listing = (new $class())->listStorefronts($presetId);
        $settingsClass = '\\Prospektweb\\Frontcalc\\Service\\PublicCalculatorCatalogService';
        $basePublic = class_exists($settingsClass)
            ? (bool)(new $settingsClass())->settings($presetId)['show_base']
            : true;
        return $this->normalizeStorefronts($presetId, $listing, $basePublic);
    }

    /** @param array<string,mixed> $listing @return array<string,mixed> */
    private function normalizeStorefronts(int $presetId, array $listing, bool $basePublic): array
    {
        $normalize = static function (array $row, bool $base = false) use ($presetId, $basePublic): array {
            $active = $base ? true : (bool)($row['active'] ?? false);
            return [
                'contract' => 'prospektweb.frontcalc.storefront-definition/v2',
                'id' => $base ? 'BASE' : trim((string)($row['id'] ?? '')),
                'preset_id' => $presetId,
                'name' => trim((string)($row['name'] ?? ($base ? 'Базовая витрина' : ''))),
                'active' => $active,
                'public' => $base ? (bool)($row['public'] ?? $basePublic) : (bool)($row['public'] ?? $active),
                'public_sort' => max(0, (int)($row['public_sort'] ?? ($base ? 100 : 500))),
                'default_product_id' => max(0, (int)($row['default_product_id'] ?? 0)),
                'revision' => max(0, (int)($row['revision'] ?? 0)),
                'presentation' => is_array($row['presentation'] ?? null)
                    ? $row['presentation']
                    : ['field_patches' => new \stdClass()],
                'product_ids' => array_values(array_unique(array_map('intval', (array)($row['product_ids'] ?? [])))),
            ];
        };
        $items = [];
        foreach ((array)($listing['items'] ?? []) as $row) {
            if (!is_array($row) || trim((string)($row['id'] ?? '')) === '') continue;
            $items[] = $normalize($row);
        }
        $base = $normalize(is_array($listing['base'] ?? null) ? $listing['base'] : [], true);
        return [
            'contract' => 'prospektweb.frontcalc.storefront-definition/v2',
            'preset_id' => $presetId,
            'base_public' => $base['public'],
            'base' => $base,
            'items' => $items,
        ];
    }

    /** @param array<string,mixed> $storefronts @return array<string,mixed> */
    private function withoutProductAssignmentCopies(array $storefronts): array
    {
        if (is_array($storefronts['base'] ?? null)) $storefronts['base']['product_ids'] = [];
        foreach ((array)($storefronts['items'] ?? []) as $index => $row) {
            if (is_array($row)) $storefronts['items'][$index]['product_ids'] = [];
        }
        return $storefronts;
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

    /** @return array<string,mixed> */
    public function publicationMetadata(int $presetId): array
    {
        if (isset($this->adapters['publicationMetadata'])) {
            $value = call_user_func($this->adapters['publicationMetadata'], $presetId);
            if (!is_array($value)) {
                throw new \RuntimeException('Publication metadata snapshot adapter returned invalid data.');
            }
            return $value;
        }
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Инфоблоки недоступны для снимка публичных метаданных калькулятора.');
        }
        $config = new \Prospektweb\Calc\Config\ConfigManager();
        $iblockId = (int)$config->getIblockId('CALC_PRESETS');
        $cursor = $iblockId > 0 ? \CIBlockElement::GetList(
            [],
            ['ID' => $presetId, 'IBLOCK_ID' => $iblockId],
            false,
            ['nTopCount' => 2],
            ['ID', 'IBLOCK_ID', 'NAME', 'SORT', 'ACTIVE', 'IBLOCK_SECTION_ID']
        ) : null;
        $row = $cursor ? $cursor->Fetch() : false;
        $duplicate = $cursor ? $cursor->Fetch() : false;
        if (!is_array($row) || $duplicate !== false || (int)($row['ID'] ?? 0) !== $presetId) {
            throw new \RuntimeException('Не удалось получить однозначные публичные метаданные калькулятора.', 409);
        }
        $name = trim((string)($row['NAME'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Название калькулятора не заполнено.', 409);
        }
        return [
            'contract' => self::PUBLICATION_METADATA_CONTRACT,
            'presetId' => $presetId,
            'calculatorName' => $name,
            'sectionId' => max(0, (int)($row['IBLOCK_SECTION_ID'] ?? 0)),
            'sort' => (int)($row['SORT'] ?? 500),
            'active' => (string)($row['ACTIVE'] ?? 'N') === 'Y',
        ];
    }

    /** @return array<string,mixed> */
    public function commercialPolicy(int $presetId): array
    {
        if (isset($this->adapters['commercialPolicy'])) {
            $value = call_user_func($this->adapters['commercialPolicy'], $presetId);
            if (!is_array($value)) {
                throw new \RuntimeException('Commercial policy snapshot adapter returned invalid data.');
            }
            return $value;
        }
        return self::defaultCommercialPolicy($presetId);
    }

    /** @return array<string,mixed> */
    public static function defaultCommercialPolicy(int $presetId): array
    {
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('Commercial policy presetId must be positive.');
        }
        return [
            'contract' => self::COMMERCIAL_POLICY_CONTRACT,
            'presetId' => $presetId,
            'deadlinePolicy' => [
                'mode' => 'basic',
                'effortBasis' => 'productionMinutes',
                'basic' => [
                    'urgent' => ['effortPercent' => 0.0, 'markupPercent' => 0.0, 'discountPercent' => 0.0],
                    'strict' => ['effortPercent' => 0.0, 'markupPercent' => 0.0, 'discountPercent' => 0.0],
                    'flexible' => ['effortPercent' => 0.0, 'markupPercent' => 0.0, 'discountPercent' => 0.0],
                ],
                'ranges' => [],
                'fallback' => 'basic',
            ],
        ];
    }
}
