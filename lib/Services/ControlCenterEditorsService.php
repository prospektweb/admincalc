<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Application;
use Prospektweb\Calc\Config\ConfigManager;

/**
 * Server-side authority for calculator launches, preset product assignments,
 * and preset-owned form-first authoring used by the control center.
 */
final class ControlCenterEditorsService
{
    public const CONTRACT = 'prospektweb.control-center.editors/v1';
    public const FORM_FIRST_AUTHORING_CONTRACT = 'prospektweb.frontcalc.form-first-authoring/v1';
    public const FORM_FIRST_FIELD_DELETE_IMPACT_CONTRACT = 'prospektweb.calc.form-first-field-delete-impact/v1';
    public const PRESET_PRODUCT_IMPACT_CONTRACT = 'prospektweb.calc.preset-product-impact/v1';
    public const CALCULATOR_CATALOG_CONTRACT = 'prospektweb.calc.calculator-catalog/v1';

    private const MAX_CALCULATION_OFFERS = 500;
    private const MAX_EDITOR_DOCUMENT_BYTES = 60000;
    private const FORM_FIRST_AUTHORING_PROVIDER = '\\Prospektweb\\Frontcalc\\Service\\ControlCenterFormFirstAuthoringService';
    private const FORM_FIRST_AUTHORING_METHODS = [
        'loadFormFirstWorkspace',
        'newVersionFormTemplate',
        'saveFormFirstDraft',
        'previewFormFirst',
        'previewVersionFormFirst',
        'publishFormFirst',
        'rollbackFormFirst',
    ];
    private const FIELD_DELETE_BLOCKING_CATEGORIES = [
        'stage_inputs',
        'globals',
        'options_mappings',
        'catalog_input_mapping',
        'storefront_presentation',
    ];

    /** @var callable */
    private $presetLoader;

    /** @var callable */
    private $productIblockIdResolver;

    /** @var callable */
    private $frontcalcAvailabilityResolver;

    /** @var callable */
    private $formFirstAuthoringResolver;

    /** @var callable */
    private $dependencyContractResolver;

    /** @var callable */
    private $presetListLoader;

    /** @var callable */
    private $presetCreator;

    /** @var callable */
    private $presetUsageLoader;

    /** @var callable */
    private $storefrontPresetLoader;

    /** @var callable */
    private $presetProductCatalogLoader;

    /** @var callable */
    private $presetProductMutationHandler;

    /** @var callable */
    private $storefrontProductDetacher;

    /** @var callable */
    private $presetIdentityLoader;

    /** @var callable */
    private $formFieldReferenceResolver;

    /** @var callable */
    private $presetProductAssignmentLocker;

    /** @var callable */
    private $presetMutationCoordinator;

    /** @var callable */
    private $storefrontProductReadbackLoader;

    /** @var callable */
    private $activeStorefrontPublicationValidator;

    /** @var callable */
    private $presetActiveStateLoader;

    /** @var callable */
    private $presetActiveMutationHandler;

    /** @var callable */
    private $presetActiveLockedStateLoader;

    /** @var callable */
    private $storefrontProductAssignmentLoader;

    /** @var callable */
    private $presetProductPropertyAuthority;

    /** @var callable */
    private $presetCatalogLoader;

    public function __construct(
        ?callable $presetLoader = null,
        ?callable $productIblockIdResolver = null,
        ?callable $frontcalcAvailabilityResolver = null,
        ?callable $formFirstAuthoringResolver = null,
        ?callable $dependencyContractResolver = null,
        ?callable $presetListLoader = null,
        ?callable $presetCreator = null,
        ?callable $presetUsageLoader = null,
        ?callable $storefrontPresetLoader = null,
        ?callable $presetProductCatalogLoader = null,
        ?callable $presetProductMutationHandler = null,
        ?callable $storefrontProductDetacher = null,
        ?callable $presetIdentityLoader = null,
        ?callable $formFieldReferenceResolver = null,
        ?callable $presetProductAssignmentLocker = null,
        ?callable $presetMutationCoordinator = null,
        ?callable $storefrontProductReadbackLoader = null,
        ?callable $activeStorefrontPublicationValidator = null,
        ?callable $presetActiveStateLoader = null,
        ?callable $presetActiveMutationHandler = null,
        ?callable $presetActiveLockedStateLoader = null,
        ?callable $storefrontProductAssignmentLoader = null,
        ?callable $presetProductPropertyAuthority = null,
        ?callable $presetCatalogLoader = null
    ) {
        $usesDefaultStorefrontDetacher = $storefrontProductDetacher === null;
        $this->presetLoader = $presetLoader ?? static function (int $presetId): array {
            if (!Loader::includeModule('iblock')) {
                throw new \RuntimeException('The iblock module is not available');
            }

            return (new CatalogTreeService())->presetLoadOptions(['presetId' => $presetId]);
        };
        $this->productIblockIdResolver = $productIblockIdResolver ?? static function (): int {
            return (int)(new ConfigManager())->getProductIblockId();
        };
        $this->frontcalcAvailabilityResolver = $frontcalcAvailabilityResolver ?? static function (): bool {
            return ModuleManager::isModuleInstalled('prospektweb.frontcalc');
        };
        $this->formFirstAuthoringResolver = $formFirstAuthoringResolver ?? static function () {
            if (!Loader::includeModule('prospektweb.frontcalc')) {
                return null;
            }

            $providerClass = self::FORM_FIRST_AUTHORING_PROVIDER;
            return class_exists($providerClass) ? new $providerClass() : null;
        };
        $this->dependencyContractResolver = $dependencyContractResolver
            ?? static function (int $presetId): array {
                return (new FormFirstDependencyContractService())->buildPublicInputContract($presetId);
            };
        $this->presetListLoader = $presetListLoader ?? ($presetLoader !== null
            ? static function (): array {
                return [];
            }
            : static function (string $query = '', string $status = 'all', string $sort = 'updated_desc', int $page = 1, int $pageSize = 50, ?int $sectionId = null): array {
                if (!Loader::includeModule('iblock')) {
                    throw new \RuntimeException('The iblock module is not available');
                }
                $iblockId = (int)(new ConfigManager())->getIblockId('CALC_PRESETS');
                if ($iblockId <= 0) {
                    throw new \RuntimeException('The CALC_PRESETS iblock is not configured');
                }
                $filter = [
                    'IBLOCK_ID' => $iblockId,
                    '!%CODE' => PresetLifecycleMutationService::VERSION_WORKING_CODE_PREFIX,
                ];
                if ($status === 'active') {
                    $filter['ACTIVE'] = 'Y';
                } elseif ($status === 'archived') {
                    $filter['ACTIVE'] = 'N';
                }
                if ($query !== '') {
                    $search = ['%NAME' => $query];
                    if (ctype_digit($query)) {
                        $search = ['LOGIC' => 'OR', ['%NAME' => $query], ['ID' => (int)$query]];
                    }
                    $filter[] = $search;
                }
                if ($sectionId !== null) {
                    $filter['SECTION_ID'] = $sectionId > 0 ? $sectionId : false;
                    if ($sectionId > 0) {
                        $filter['INCLUDE_SUBSECTIONS'] = 'Y';
                    }
                }
                $order = match ($sort) {
                    'name_asc' => ['NAME' => 'ASC', 'ID' => 'ASC'],
                    'name_desc' => ['NAME' => 'DESC', 'ID' => 'DESC'],
                    'id_desc' => ['ID' => 'DESC'],
                    default => ['TIMESTAMP_X' => 'DESC', 'ID' => 'DESC'],
                };
                $rows = [];
                $cursor = \CIBlockElement::GetList(
                    $order,
                    $filter,
                    false,
                    ['nPageSize' => $pageSize, 'iNumPage' => $page],
                    ['ID', 'NAME', 'ACTIVE', 'SORT', 'TIMESTAMP_X', 'IBLOCK_SECTION_ID']
                );
                while ($cursor && ($row = $cursor->Fetch())) {
                    $id = (int)($row['ID'] ?? 0);
                    if ($id > 0) {
                        $rows[] = [
                            'id' => $id,
                            'name' => (string)($row['NAME'] ?? ''),
                            'active' => (string)($row['ACTIVE'] ?? 'N') === 'Y',
                            'sort' => (int)($row['SORT'] ?? 500),
                            'updatedAt' => (string)($row['TIMESTAMP_X'] ?? ''),
                            'sectionId' => (int)($row['IBLOCK_SECTION_ID'] ?? 0),
                        ];
                    }
                }
                return [
                    '_serverPaged' => true,
                    'rows' => $rows,
                    'total' => $cursor && method_exists($cursor, 'SelectedRowsCount')
                        ? (int)$cursor->SelectedRowsCount()
                        : count($rows),
                ];
            });
        $this->presetCatalogLoader = $presetCatalogLoader ?? ($presetLoader !== null
            ? static function (): array {
                return [
                    'contract' => self::CALCULATOR_CATALOG_CONTRACT,
                    'iblockId' => 1,
                    'revision' => str_repeat('0', 64),
                    'sections' => [],
                    'calculators' => [],
                    'calculatorCount' => 0,
                    'unsectionedCount' => 0,
                ];
            }
            : static function (): array {
                return (new CalculatorCatalogService())->snapshot();
            });
        $this->presetCreator = $presetCreator ?? static function (string $name, int $sectionId = 0): array {
            return (new PresetLifecycleMutationService())->createPreset($name, $sectionId);
        };
        $this->presetUsageLoader = $presetUsageLoader ?? ($presetLoader !== null
            ? static function (array $presetIds): array {
                return [];
            }
            : function (array $presetIds): array {
                return $this->loadRegistryUsage($presetIds);
            });
        $this->storefrontPresetLoader = $storefrontPresetLoader ?? ($presetLoader !== null
            ? $this->presetLoader
            : static function (int $presetId): array {
                if (!Loader::includeModule('iblock')) {
                    throw new \RuntimeException('The iblock module is not available');
                }

                return (new CatalogTreeService())->presetStorefrontOptions(['presetId' => $presetId]);
            });
        $this->presetProductCatalogLoader = $presetProductCatalogLoader
            ?? function (int $presetId, string $query, int $page, int $pageSize, ?array $propertyAuthority = null): array {
                return $this->loadPresetProductCatalogFromBitrix(
                    $presetId,
                    $query,
                    $page,
                    $pageSize,
                    $propertyAuthority
                );
            };
        $this->presetProductMutationHandler = $presetProductMutationHandler
            ?? function (
                int $presetId,
                array $productIds,
                string $expectedRevision,
                int $productIblockId,
                array $propertyAuthority
            ): array {
                return $this->mutatePresetProductsInBitrix(
                    $presetId,
                    $productIds,
                    $expectedRevision,
                    $productIblockId,
                    $propertyAuthority
                );
            };
        $this->presetProductAssignmentLocker = $presetProductAssignmentLocker
            ?? static function (int $productIblockId, callable $criticalSection) {
                return (new PresetProductAssignmentLockService())->withLock($productIblockId, $criticalSection);
            };
        $this->storefrontProductDetacher = $storefrontProductDetacher
            ?? static function (int $presetId, array $productIds): array {
                if (!$productIds) {
                    return [];
                }
                if (!Loader::includeModule('prospektweb.frontcalc')) {
                    throw new \RuntimeException('Module prospektweb.frontcalc is required to detach storefront assignments');
                }
                $class = '\\Prospektweb\\Frontcalc\\Service\\StorefrontRepository';
                if (!class_exists($class)) {
                    throw new \RuntimeException('Storefront vNext repository is unavailable');
                }
                return (new $class())->detachProducts($presetId, $productIds);
            };
        $this->presetIdentityLoader = $presetIdentityLoader ?? ($presetLoader !== null
            ? static function (int $presetId) use ($presetLoader): array {
                $raw = call_user_func($presetLoader, $presetId);
                $rawPreset = is_array($raw['preset'] ?? null) ? $raw['preset'] : [];
                if ((int)($rawPreset['id'] ?? 0) !== $presetId) {
                    throw new \InvalidArgumentException('Preset not found');
                }
                return ['id' => $presetId, 'name' => (string)($rawPreset['name'] ?? ('Пресет #' . $presetId))];
            }
            : static function (int $presetId): array {
                if (!Loader::includeModule('iblock')) {
                    throw new \RuntimeException('The iblock module is not available');
                }
                $iblockId = (int)(new ConfigManager())->getIblockId('CALC_PRESETS');
                if ($iblockId <= 0) {
                    throw new \RuntimeException('The CALC_PRESETS iblock is not configured');
                }
                $row = \CIBlockElement::GetList(
                    [],
                    ['ID' => $presetId, 'IBLOCK_ID' => $iblockId],
                    false,
                    ['nTopCount' => 1],
                    ['ID', 'NAME']
                )->Fetch();
                if (!$row) {
                    throw new \InvalidArgumentException('Preset not found');
                }
                return ['id' => $presetId, 'name' => (string)($row['NAME'] ?? ('Пресет #' . $presetId))];
            });
        $this->formFieldReferenceResolver = $formFieldReferenceResolver
            ?? static function (int $presetId, string $fieldId): array {
                return (new FormFirstDependencyContractService())->fieldReferences($presetId, $fieldId);
            };
        $this->presetMutationCoordinator = $presetMutationCoordinator
            ?? static function (
                int $presetId,
                array $metadata,
                callable $mutation,
                callable $authoritativeReadback
            ) {
                return (new PresetMutationCoordinatorService())->mutate(
                    $presetId,
                    $metadata,
                    $mutation,
                    $authoritativeReadback
                );
            };
        $this->storefrontProductReadbackLoader = $storefrontProductReadbackLoader
            ?? ($usesDefaultStorefrontDetacher
                ? static function (int $presetId): array {
                    if (!Loader::includeModule('prospektweb.frontcalc')) {
                        throw new \RuntimeException('Module prospektweb.frontcalc is required for storefront readback');
                    }
                    $class = '\\Prospektweb\\Frontcalc\\Service\\StorefrontRepository';
                    if (!class_exists($class)) {
                        throw new \RuntimeException('Storefront vNext repository is unavailable for readback');
                    }
                    return (new $class())->listStorefronts($presetId);
                }
                : static fn(int $presetId): array => [
                    'preset_id' => $presetId,
                    'items' => [],
                ]);
        $this->activeStorefrontPublicationValidator = $activeStorefrontPublicationValidator
            ?? static function (int $presetId): void {
                if (!Loader::includeModule('prospektweb.frontcalc')) {
                    throw new \RuntimeException(
                        'Module prospektweb.frontcalc is required to validate active storefronts'
                    );
                }
                $class = '\\Prospektweb\\Frontcalc\\Service\\ActiveStorefrontPublicationValidator';
                if (!class_exists($class)) {
                    throw new \RuntimeException('Active storefront publication validator is unavailable');
                }
                $validator = new $class();
                if (!is_callable([$validator, 'validate'])) {
                    throw new \RuntimeException('Active storefront publication validator is unavailable');
                }
                $validator->validate($presetId);
            };
        $this->presetActiveStateLoader = $presetActiveStateLoader
            ?? static function (int $presetId): array {
                if (!Loader::includeModule('iblock')) {
                    throw new \RuntimeException('The iblock module is not available');
                }
                $iblockId = (int)(new ConfigManager())->getIblockId('CALC_PRESETS');
                if ($iblockId <= 0) {
                    throw new \RuntimeException('The CALC_PRESETS iblock is not configured');
                }
                $row = \CIBlockElement::GetList(
                    [],
                    ['ID' => $presetId, 'IBLOCK_ID' => $iblockId],
                    false,
                    ['nTopCount' => 1],
                    ['ID', 'NAME', 'ACTIVE', 'TIMESTAMP_X']
                )->Fetch();
                if (!is_array($row)) {
                    throw new \InvalidArgumentException('Preset not found');
                }
                return [
                    'id' => (int)($row['ID'] ?? 0),
                    'name' => (string)($row['NAME'] ?? ''),
                    'active' => (string)($row['ACTIVE'] ?? 'N') === 'Y',
                    'updatedAt' => (string)($row['TIMESTAMP_X'] ?? ''),
                ];
            };
        $this->presetActiveMutationHandler = $presetActiveMutationHandler
            ?? static function (int $presetId, bool $active): void {
                if (!Loader::includeModule('iblock')) {
                    throw new \RuntimeException('The iblock module is not available');
                }
                $element = new \CIBlockElement();
                if (!$element->Update($presetId, ['ACTIVE' => $active ? 'Y' : 'N'])) {
                    throw new \RuntimeException('Не удалось изменить состояние пресета #' . $presetId);
                }
            };
        $this->presetActiveLockedStateLoader = $presetActiveLockedStateLoader
            ?? static function (int $presetId): array {
                if (!Loader::includeModule('iblock')) {
                    throw new \RuntimeException('The iblock module is not available');
                }
                $iblockId = (int)(new ConfigManager())->getIblockId('CALC_PRESETS');
                if ($iblockId <= 0) {
                    throw new \RuntimeException('The CALC_PRESETS iblock is not configured');
                }
                $connection = Application::getConnection();
                $row = $connection->query(
                    'SELECT ID, IBLOCK_ID, NAME, ACTIVE, TIMESTAMP_X FROM b_iblock_element'
                    . ' WHERE ID = ' . $presetId
                    . ' AND IBLOCK_ID = ' . $iblockId
                    . ' FOR UPDATE'
                )->fetch();
                if (!is_array($row)
                    || (int)($row['ID'] ?? $row['id'] ?? 0) !== $presetId
                    || (int)($row['IBLOCK_ID'] ?? $row['iblock_id'] ?? 0) !== $iblockId) {
                    throw new \InvalidArgumentException('Preset not found');
                }
                return [
                    'id' => $presetId,
                    'name' => (string)($row['NAME'] ?? $row['name'] ?? ''),
                    'active' => (string)($row['ACTIVE'] ?? $row['active'] ?? 'N') === 'Y',
                    'updatedAt' => (string)($row['TIMESTAMP_X'] ?? $row['timestamp_x'] ?? ''),
                ];
            };
        $this->presetProductPropertyAuthority = $presetProductPropertyAuthority
            ?? static function (int $productIblockId, bool $forUpdate, int $presetIblockId = 0): array {
                if ($presetIblockId <= 0) {
                    $presetIblockId = (int)(new ConfigManager())->getIblockId('CALC_PRESETS');
                }
                return (new PresetProductAssignmentPropertyAuthorityService())->resolve(
                    $productIblockId,
                    $presetIblockId,
                    $forUpdate
                );
            };
        $this->storefrontProductAssignmentLoader = $storefrontProductAssignmentLoader
            ?? ($presetLoader !== null
                ? function (int $presetId, array $productIds, int $productIblockId, ?array $propertyAuthority = null): array {
                    $snapshot = $this->loadStorefrontSnapshot($presetId);
                    $assignments = [];
                    foreach ($snapshot['products'] as $product) {
                        $productId = (int)($product['id'] ?? 0);
                        if ($productId > 0 && in_array($productId, $productIds, true)) {
                            $assignments[$productId] = [$presetId];
                        }
                    }
                    return $assignments;
                }
                : function (int $presetId, array $productIds, int $productIblockId, ?array $propertyAuthority = null): array {
                    $authority = $this->normalizePresetProductPropertyAuthority(
                        $propertyAuthority ?? $this->resolvePresetProductPropertyAuthority($productIblockId, true),
                        $productIblockId
                    );
                    return $this->loadExactProductAssignments(
                        $productIblockId,
                        $authority['propertyId'],
                        $productIds
                    );
                });
    }

    public function getCatalog(): array
    {
        $frontcalcAvailable = (bool)call_user_func($this->frontcalcAvailabilityResolver);
        $formFirstAuthoringAvailable = $frontcalcAvailable && $this->isFormFirstAuthoringAvailable();

        $registry = $this->getPresetRegistry('', 'all', 'updated_desc', 1, 50);

        return [
            'contract' => self::CONTRACT,
            'calculations' => $registry['rows'],
            'storefront' => [
                'formFirstAuthoringAvailable' => $formFirstAuthoringAvailable,
                'formFirstAuthoringContract' => self::FORM_FIRST_AUTHORING_CONTRACT,
            ],
        ];
    }

    /**
     * Lightweight server-paged registry. It deliberately does not load preset
     * snapshots or nested product/offer rows.
     */
    public function getPresetRegistry(
        string $query = '',
        string $status = 'all',
        string $sort = 'updated_desc',
        int $page = 1,
        int $pageSize = 50,
        ?int $sectionId = null
    ): array {
        $query = trim($query);
        if (!in_array($status, ['all', 'active', 'archived'], true)) {
            throw new \InvalidArgumentException('Unsupported preset registry status');
        }
        if (!in_array($sort, ['updated_desc', 'name_asc', 'name_desc', 'id_desc'], true)) {
            throw new \InvalidArgumentException('Unsupported preset registry sort');
        }
        if ($page <= 0 || $pageSize <= 0 || $pageSize > 100) {
            throw new \InvalidArgumentException('Invalid preset registry page');
        }
        if ($sectionId !== null && ($sectionId < 0 || $sectionId > 9007199254740991)) {
            throw new \InvalidArgumentException('Invalid calculator catalog section');
        }

        $serverTotal = null;
        $serverPaged = false;
        $rows = $this->listPresetRows(
            $query,
            $status,
            $sort,
            $page,
            $pageSize,
            $sectionId,
            $serverTotal,
            $serverPaged
        );
        if (!$serverPaged) {
            $rows = array_values(array_filter($rows, static function (array $row) use ($query, $status): bool {
            if ($status === 'active' && empty($row['active'])) {
                return false;
            }
            if ($status === 'archived' && !empty($row['active'])) {
                return false;
            }
            if ($query === '') {
                return true;
            }
            $source = (string)$row['name'] . ' ' . (string)$row['id'];
            if (function_exists('mb_strtolower') && function_exists('mb_strpos')) {
                $haystack = mb_strtolower($source, 'UTF-8');
                return mb_strpos($haystack, mb_strtolower($query, 'UTF-8'), 0, 'UTF-8') !== false;
            }
            return stripos($source, $query) !== false;
            }));

            usort($rows, static function (array $left, array $right) use ($sort): int {
            if ($sort === 'name_asc' || $sort === 'name_desc') {
                $result = strnatcasecmp((string)$left['name'], (string)$right['name']);
                return $sort === 'name_desc' ? -$result : $result;
            }
            if ($sort === 'id_desc') {
                return (int)$right['id'] <=> (int)$left['id'];
            }
            $result = strcmp((string)$right['updatedAt'], (string)$left['updatedAt']);
            return $result !== 0 ? $result : ((int)$right['id'] <=> (int)$left['id']);
            });
        }

        $total = $serverPaged ? max(0, (int)$serverTotal) : count($rows);
        $pageRows = $serverPaged ? $rows : array_slice($rows, ($page - 1) * $pageSize, $pageSize);
        $usage = call_user_func($this->presetUsageLoader, array_column($pageRows, 'id'));
        if (!is_array($usage)) {
            throw new \RuntimeException('The preset usage provider returned an invalid result');
        }
        $catalog = call_user_func($this->presetCatalogLoader);
        if (!is_array($catalog)
            || ($catalog['contract'] ?? null) !== self::CALCULATOR_CATALOG_CONTRACT
            || !is_array($catalog['sections'] ?? null)) {
            throw new \RuntimeException('The calculator catalog authority returned an invalid snapshot');
        }
        $sectionMap = [];
        foreach ($catalog['sections'] as $section) {
            if (!is_array($section)) {
                throw new \RuntimeException('The calculator catalog authority returned an invalid section');
            }
            $catalogSectionId = (int)($section['id'] ?? 0);
            if ($catalogSectionId <= 0 || isset($sectionMap[$catalogSectionId])) {
                throw new \RuntimeException('The calculator catalog authority returned an ambiguous section');
            }
            $sectionMap[$catalogSectionId] = $section;
        }
        if ($sectionId !== null && $sectionId > 0 && !isset($sectionMap[$sectionId])) {
            throw new \InvalidArgumentException('Calculator catalog section not found');
        }
        $normalizedRows = array_map(function (array $row) use ($usage, $sectionMap): array {
            $id = (int)$row['id'];
            $counts = is_array($usage[$id] ?? null) ? $usage[$id] : [];
            $rowSectionId = (int)($row['sectionId'] ?? 0);
            return [
                'presetId' => $id,
                'presetName' => (string)$row['name'],
                'active' => !empty($row['active']),
                'productCount' => max(0, (int)($counts['productCount'] ?? 0)),
                'offerCount' => max(0, (int)($counts['offerCount'] ?? 0)),
                'updatedAt' => (string)($row['updatedAt'] ?? ''),
                'revision' => $this->presetRegistryRevision($row),
                'sectionId' => $rowSectionId,
                'sectionPath' => $this->buildCalculatorSectionPath($rowSectionId, $sectionMap),
            ];
        }, $pageRows);

        return [
            'contract' => self::CONTRACT,
            'rows' => $normalizedRows,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'pageCount' => max(1, (int)ceil($total / $pageSize)),
            'query' => $query,
            'status' => $status,
            'sort' => $sort,
            'sectionId' => $sectionId,
        ];
    }

    public function loadPresetWorkspace(int $presetId): array
    {
        $snapshot = $this->loadStorefrontSnapshot($presetId);
        return [
            'contract' => self::CONTRACT,
            'presetId' => $presetId,
            'presetName' => $snapshot['presetName'],
            'productCount' => count($snapshot['products']),
            'offerCount' => $snapshot['offerCount'],
            'products' => $snapshot['products'],
        ];
    }

    public function getPresetProductCatalog(
        int $presetId,
        string $query = '',
        int $page = 1,
        int $pageSize = 50,
        ?array $propertyAuthority = null
    ): array {
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('Preset ID must be positive');
        }
        $query = trim($query);
        if ($this->stringLength($query) > 200) {
            throw new \InvalidArgumentException('Product query is too long');
        }
        if ($page <= 0 || $pageSize <= 0 || $pageSize > 100) {
            throw new \InvalidArgumentException('Invalid product catalog page');
        }

        $raw = call_user_func(
            $this->presetProductCatalogLoader,
            $presetId,
            $query,
            $page,
            $pageSize,
            $propertyAuthority
        );
        return $this->normalizePresetProductCatalog($raw, $presetId, $query, $page, $pageSize);
    }

    /** @param int[] $productIds */
    public function setPresetProducts(
        int $presetId,
        array $productIds,
        string $expectedRevision,
        string $expectedImpactFingerprint
    ): array
    {
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('Preset ID must be positive');
        }
        $this->assertSha256($expectedRevision, 'expectedRevision');
        $this->assertSha256($expectedImpactFingerprint, 'expectedImpactFingerprint');
        $normalizedProductIds = $this->normalizePresetProductIds($productIds);
        $expectedRevision = strtolower($expectedRevision);
        $expectedImpactFingerprint = strtolower($expectedImpactFingerprint);
        $raw = $this->withPresetProductAssignmentLock(
            function (int $productIblockId) use (
                $presetId,
                $normalizedProductIds,
                $expectedRevision,
                $expectedImpactFingerprint
            ): array {
                $propertyAuthority = null;
                return $this->withPresetMutation(
                    $presetId,
                    [
                        'action' => 'set_preset_products',
                        'entity_type' => 'preset_products',
                        'entity_id' => (string)$presetId,
                        'expected_revision' => $expectedRevision,
                        'product_ids' => $normalizedProductIds,
                    ],
                    function (?CalculatorMutationAuthorityService $calculatorAuthority = null) use (
                        $presetId,
                        $normalizedProductIds,
                        $expectedRevision,
                        $expectedImpactFingerprint,
                        $productIblockId,
                        &$propertyAuthority
                    ): array {
                        $lockedIblockIds = $calculatorAuthority instanceof CalculatorMutationAuthorityService
                            ? $calculatorAuthority->lockedIblockIds()
                            : [];
                        $propertyAuthority = $propertyAuthority
                            ?? $this->resolvePresetProductPropertyAuthority(
                                $productIblockId,
                                true,
                                (int)($lockedIblockIds['CALC_PRESETS'] ?? 0)
                            );
                        // The impact proof is consumed only while both the product-assignment
                        // lock and the preset mutation coordinator are held. This prevents a
                        // storefront changed after preview from being silently detached.
                        $lockedCatalog = $this->getPresetProductCatalog(
                            $presetId,
                            '',
                            1,
                            50,
                            $propertyAuthority
                        );
                        $lockedImpact = $this->buildPresetProductImpact(
                            $presetId,
                            $normalizedProductIds,
                            $expectedRevision,
                            $lockedCatalog
                        );
                        if (!hash_equals(
                            (string)$lockedImpact['impactFingerprint'],
                            $expectedImpactFingerprint
                        )) {
                            throw new \RuntimeException(
                                'Витрины или связи товаров изменились после предварительной проверки. '
                                . 'Обновите данные и подтвердите влияние заново.',
                                409
                            );
                        }
                        $mutationResult = call_user_func(
                            $this->presetProductMutationHandler,
                            $presetId,
                            $normalizedProductIds,
                            $expectedRevision,
                            $productIblockId,
                            $propertyAuthority
                        );
                        return $this->normalizePresetProductCatalog(
                            $mutationResult,
                            $presetId,
                            '',
                            1,
                            50
                        );
                    },
                    function (?CalculatorMutationAuthorityService $calculatorAuthority = null) use (
                        $presetId,
                        $productIblockId,
                        &$propertyAuthority
                    ): array {
                        $lockedIblockIds = $calculatorAuthority instanceof CalculatorMutationAuthorityService
                            ? $calculatorAuthority->lockedIblockIds()
                            : [];
                        $propertyAuthority = $propertyAuthority
                            ?? $this->resolvePresetProductPropertyAuthority(
                                $productIblockId,
                                true,
                                (int)($lockedIblockIds['CALC_PRESETS'] ?? 0)
                            );
                        return $this->getPresetProductCatalog(
                            $presetId,
                            '',
                            1,
                            50,
                            $propertyAuthority
                        );
                    }
                );
            }
        );

        return $raw;
    }

    /**
     * Read-only impact preview for an exact product-assignment revision.
     * No product or storefront data is changed until setPresetProducts receives
     * the same explicit IDs and revision after operator confirmation.
     *
     * @param int[] $productIds
     * @return array<string,mixed>
     */
    public function previewPresetProductImpact(
        int $presetId,
        array $productIds,
        string $expectedRevision
    ): array {
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('Preset ID must be positive');
        }
        $this->assertSha256($expectedRevision, 'expected_revision');
        $expectedRevision = strtolower($expectedRevision);
        $nextProductIds = $this->normalizePresetProductIds($productIds);
        $catalog = $this->getPresetProductCatalog($presetId, '', 1, 50);
        return $this->buildPresetProductImpact(
            $presetId,
            $nextProductIds,
            $expectedRevision,
            $catalog
        );
    }

    /**
     * Build the one canonical proof consumed by setPresetProducts. The hash
     * covers the requested assignment, the exact current assignment, the full
     * storefront readback and the precise detachments shown to the operator.
     *
     * @param int[] $nextProductIds
     * @param array<string,mixed> $catalog
     * @return array<string,mixed>
     */
    private function buildPresetProductImpact(
        int $presetId,
        array $nextProductIds,
        string $expectedRevision,
        array $catalog
    ): array {
        if (!hash_equals((string)$catalog['revision'], $expectedRevision)) {
            throw new \RuntimeException(
                'Связи товаров уже изменены в другой сессии. Обновите данные перед подтверждением.',
                409
            );
        }

        $currentProductIds = $this->normalizePresetProductIds(
            is_array($catalog['linkedProductIds'] ?? null) ? $catalog['linkedProductIds'] : []
        );
        $addedProductIds = array_values(array_diff($nextProductIds, $currentProductIds));
        $removedProductIds = array_values(array_diff($currentProductIds, $nextProductIds));
        sort($addedProductIds, SORT_NUMERIC);
        sort($removedProductIds, SORT_NUMERIC);
        $removedMap = array_fill_keys($removedProductIds, true);

        $storefrontReadback = call_user_func($this->storefrontProductReadbackLoader, $presetId);
        if (!is_array($storefrontReadback)) {
            throw new \RuntimeException('Не удалось прочитать витрины для предварительной проверки.');
        }
        $storefrontItems = is_array($storefrontReadback['items'] ?? null)
            ? $storefrontReadback['items']
            : null;
        if ($storefrontItems === null || !array_is_list($storefrontItems)) {
            throw new \RuntimeException('Не удалось прочитать витрины для предварительной проверки.');
        }
        if (array_key_exists('preset_id', $storefrontReadback)
            && (!is_int($storefrontReadback['preset_id']) || $storefrontReadback['preset_id'] !== $presetId)) {
            throw new \RuntimeException('Прочитаны витрины другого пресета.');
        }
        $affectedStorefronts = [];
        $canonicalStorefronts = [];
        $seenStorefrontIds = [];
        foreach ($storefrontItems as $position => $storefront) {
            if (!is_array($storefront)) {
                throw new \RuntimeException('Витрина #' . $position . ' имеет некорректный формат.');
            }
            $storefrontId = trim((string)($storefront['id'] ?? ''));
            if ($storefrontId === '' || isset($seenStorefrontIds[$storefrontId])) {
                throw new \RuntimeException('Витрина без уникального точного ID не может участвовать в preview.');
            }
            $seenStorefrontIds[$storefrontId] = true;
            if (!is_string($storefront['name'] ?? null)
                || !is_bool($storefront['active'] ?? null)
                || !is_int($storefront['revision'] ?? null)
                || $storefront['revision'] < 0
                || !is_array($storefront['product_ids'] ?? null)
                || !array_is_list($storefront['product_ids'])) {
                throw new \RuntimeException('Витрина ' . $storefrontId . ' имеет некорректное контрольное чтение.');
            }
            $storefrontProductIds = $this->normalizePresetProductIds($storefront['product_ids']);
            $canonicalStorefront = $storefront;
            $canonicalStorefront['id'] = $storefrontId;
            $canonicalStorefront['name'] = trim($storefront['name']) ?: $storefrontId;
            $canonicalStorefront['active'] = $storefront['active'];
            $canonicalStorefront['revision'] = $storefront['revision'];
            $canonicalStorefront['product_ids'] = $storefrontProductIds;
            $canonicalStorefronts[] = $this->canonicalizeImpactValue($canonicalStorefront);
            $affectedIds = [];
            foreach ($storefrontProductIds as $productId) {
                if (isset($removedMap[$productId])) {
                    $affectedIds[$productId] = $productId;
                }
            }
            if ($affectedIds === []) {
                continue;
            }
            ksort($affectedIds, SORT_NUMERIC);
            $affectedStorefronts[] = [
                'id' => $storefrontId,
                'name' => $canonicalStorefront['name'],
                'active' => $canonicalStorefront['active'],
                'revision' => $canonicalStorefront['revision'],
                'removedProductIds' => array_values($affectedIds),
            ];
        }
        usort(
            $canonicalStorefronts,
            static fn(array $left, array $right): int => strcmp((string)$left['id'], (string)$right['id'])
        );
        usort(
            $affectedStorefronts,
            static fn(array $left, array $right): int => strcmp((string)$left['id'], (string)$right['id'])
        );

        $canonicalReadback = $storefrontReadback;
        $canonicalReadback['items'] = $canonicalStorefronts;
        $canonicalReadback = $this->canonicalizeImpactValue($canonicalReadback);
        $proof = [
            'contract' => self::PRESET_PRODUCT_IMPACT_CONTRACT,
            'presetId' => $presetId,
            'expectedRevision' => $expectedRevision,
            'currentProductIds' => $currentProductIds,
            'nextProductIds' => $nextProductIds,
            'addedProductIds' => $addedProductIds,
            'removedProductIds' => $removedProductIds,
            'storefrontReadback' => $canonicalReadback,
            'affectedStorefronts' => $affectedStorefronts,
        ];
        $encodedProof = json_encode(
            $this->canonicalizeImpactValue($proof),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($encodedProof)) {
            throw new \RuntimeException('Не удалось сформировать точный отпечаток влияния.');
        }

        return [
            'contract' => self::PRESET_PRODUCT_IMPACT_CONTRACT,
            'presetId' => $presetId,
            'expectedRevision' => $expectedRevision,
            'impactFingerprint' => hash('sha256', $encodedProof),
            'nextProductIds' => $nextProductIds,
            'addedProductIds' => $addedProductIds,
            'removedProductIds' => $removedProductIds,
            'affectedStorefronts' => $affectedStorefronts,
        ];
    }

    /** @param mixed $value @return mixed */
    private function canonicalizeImpactValue($value)
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            if (is_scalar($value) || $value === null) {
                return $value;
            }
            throw new \RuntimeException('Контрольное чтение витрин содержит неподдерживаемое значение.');
        }
        if (array_is_list($value)) {
            return array_map(fn($item) => $this->canonicalizeImpactValue($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeImpactValue($item);
        }
        return $value;
    }

    /**
     * Serialize every mutation or proof that depends on CALC_PRESET product
     * assignments. The critical section receives the exact locked iblock ID.
     *
     * @return mixed
     */
    public function withPresetProductAssignmentLock(callable $criticalSection)
    {
        $productIblockId = (int)call_user_func($this->productIblockIdResolver);
        if ($productIblockId <= 0 || $productIblockId > 9007199254740991) {
            throw new \RuntimeException('Product iblock is not configured');
        }

        return call_user_func(
            $this->presetProductAssignmentLocker,
            $productIblockId,
            $criticalSection
        );
    }

    /**
     * Shared durable per-preset document boundary used by form, mapping,
     * storefront, product-assignment and single-preset activation writes.
     * The public domain result is returned unchanged.
     *
     * @param array<string,mixed> $metadata
     * @return mixed
     */
    public function withPresetMutation(
        int $presetId,
        array $metadata,
        callable $mutation,
        callable $authoritativeReadback
    ) {
        return call_user_func(
            $this->presetMutationCoordinator,
            $presetId,
            $metadata,
            $mutation,
            $authoritativeReadback
        );
    }

    /**
     * Prove the vNext storefront assignment against the current CALC_PRESET
     * authority immediately before repository mutation.
     *
     * @param int[] $productIds
     */
    public function assertStorefrontProductsBelongToPreset(
        int $presetId,
        array $productIds,
        int $lockedProductIblockId = 0,
        int $lockedPresetIblockId = 0
    ): void
    {
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('preset_id must be positive');
        }
        if (array_keys($productIds) !== ($productIds === [] ? [] : range(0, count($productIds) - 1))) {
            throw new \InvalidArgumentException('storefront.product_ids must be a JSON array');
        }
        $requested = [];
        foreach ($productIds as $position => $productId) {
            if (!is_int($productId) || $productId <= 0 || $productId > 9007199254740991) {
                throw new \InvalidArgumentException(
                    'storefront.product_ids[' . $position . '] must be a safe positive integer'
                );
            }
            if (isset($requested[$productId])) {
                throw new \InvalidArgumentException('storefront.product_ids must not contain duplicates');
            }
            $requested[$productId] = $productId;
        }
        if ($requested === []) {
            return;
        }
        ksort($requested, SORT_NUMERIC);

        $productIblockId = $lockedProductIblockId > 0
            ? $lockedProductIblockId
            : $this->resolveProductIblockId();
        $propertyAuthority = $this->resolvePresetProductPropertyAuthority(
            $productIblockId,
            $lockedProductIblockId > 0,
            $lockedPresetIblockId
        );
        $assignments = call_user_func(
            $this->storefrontProductAssignmentLoader,
            $presetId,
            array_values($requested),
            $productIblockId,
            $propertyAuthority
        );
        if (!is_array($assignments)) {
            throw new \RuntimeException('Storefront product assignment authority is invalid.');
        }
        $missing = [];
        foreach ($requested as $productId) {
            $presetIds = is_array($assignments[$productId] ?? null)
                ? array_values(array_unique(array_map('intval', $assignments[$productId])))
                : [];
            sort($presetIds, SORT_NUMERIC);
            if ($presetIds !== [$presetId]) {
                $missing[] = $productId;
            }
        }
        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'Storefront product_ids are not linked to preset #' . $presetId . ': #'
                . implode(', #', $missing)
            );
        }
    }

    /**
     * A product launch has one unambiguous calculator preset. The managed
     * CALC_PRESET property is an exact single-valued element link.
     *
     * @param int[] $requestedProductIds
     * @param array<int,int[]> $currentAssignments
     */
    public static function assertExclusivePresetAssignments(
        int $presetId,
        array $requestedProductIds,
        array $currentAssignments
    ): void {
        $requested = array_fill_keys(array_map('intval', $requestedProductIds), true);
        $conflicts = [];
        foreach ($currentAssignments as $productId => $presetIds) {
            $productId = (int)$productId;
            if ($productId <= 0 || !isset($requested[$productId])) {
                continue;
            }
            $foreign = [];
            foreach ($presetIds as $assignedPresetId) {
                $assignedPresetId = (int)$assignedPresetId;
                if ($assignedPresetId > 0 && $assignedPresetId !== $presetId) {
                    $foreign[$assignedPresetId] = $assignedPresetId;
                }
            }
            if ($foreign !== []) {
                ksort($foreign, SORT_NUMERIC);
                $conflicts[$productId] = array_values($foreign);
            }
        }
        if ($conflicts === []) {
            return;
        }
        ksort($conflicts, SORT_NUMERIC);
        $parts = [];
        foreach ($conflicts as $productId => $presetIds) {
            $parts[] = '#' . $productId . ' -> #' . implode(', #', $presetIds);
        }
        throw new \InvalidArgumentException(
            'Products already assigned to other presets: ' . implode('; ', $parts)
        );
    }

    /**
     * Compare the canonical JSON value, not PHP object identity. Empty
     * field_patches are deliberately represented as stdClass so they stay a
     * JSON object; a fresh authoritative read creates an equivalent, distinct
     * stdClass instance.
     *
     * @param mixed $readBack
     * @return array<string,mixed>
     */
    public static function assertStorefrontAuthoritativeReadback(array $saved, $readBack): array
    {
        if (!is_array($readBack)) {
            throw new \RuntimeException('Storefront authoritative save readback does not match the write');
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $savedPayload = json_encode($saved, $flags);
        $readBackPayload = json_encode($readBack, $flags);
        if (!is_string($savedPayload)
            || !is_string($readBackPayload)
            || !hash_equals($savedPayload, $readBackPayload)) {
            throw new \RuntimeException('Storefront authoritative save readback does not match the write');
        }
        return $readBack;
    }

    public function setPresetActive(int $presetId, string $expectedRevision, bool $active): array
    {
        if ($presetId <= 0 || $presetId > 9007199254740991) {
            throw new \InvalidArgumentException('presetId must be a safe positive integer');
        }
        $this->assertSha256($expectedRevision, 'expected_revision');
        $expectedRevision = strtolower($expectedRevision);

        $lockedState = null;
        return $this->withPresetMutation(
            $presetId,
            [
                'action' => 'set_preset_active',
                'entity_type' => 'preset_registry',
                'entity_id' => (string)$presetId,
                'expected_revision' => $expectedRevision,
                'product_ids' => [],
            ],
            function () use ($presetId, $expectedRevision, $active, &$lockedState): array {
                $before = is_array($lockedState)
                    ? $lockedState
                    : $this->loadPresetActiveState($presetId, true);
                if (!hash_equals((string)$before['revision'], $expectedRevision)) {
                    throw new \RuntimeException(
                        'Состояние калькулятора уже изменено в другой сессии. Обновите реестр.',
                        409
                    );
                }
                if ((bool)$before['active'] === $active) {
                    throw new \RuntimeException('Калькулятор уже находится в выбранном состоянии.', 409);
                }
                call_user_func($this->presetActiveMutationHandler, $presetId, $active);
                $after = $this->loadPresetActiveState($presetId, true);
                $lockedState = $after;
                if ((bool)$after['active'] !== $active
                    || hash_equals((string)$after['revision'], $expectedRevision)) {
                    throw new \RuntimeException('Контрольное чтение состояния калькулятора не подтвердило изменение.');
                }
                return [
                    'contract' => self::CONTRACT,
                    'presetId' => $presetId,
                    'presetName' => (string)$after['name'],
                    'active' => $active,
                    'updatedAt' => (string)$after['updatedAt'],
                    'revision' => (string)$after['revision'],
                ];
            },
            function () use ($presetId, &$lockedState): array {
                $lockedState = $this->loadPresetActiveState($presetId, true);
                return $lockedState;
            }
        );
    }

    public function duplicatePreset(int $presetId): array
    {
        $receipt = (new PresetLifecycleMutationService())->duplicatePreset($presetId);
        $newPresetId = (int)($receipt['newPresetId'] ?? 0);
        return [
            'contract' => self::CONTRACT,
            'presetId' => $newPresetId,
            'presetName' => trim((string)($receipt['presetName'] ?? '')) ?: ('Пресет #' . $newPresetId),
        ];
    }

    public function createStandalonePreset(string $name, int $sectionId = 0): array
    {
        $name = trim($name);
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($name === '' || $nameLength > 200) {
            throw new \InvalidArgumentException('Preset name must contain 1 to 200 characters');
        }
        if ($sectionId < 0 || $sectionId > 9007199254740991) {
            throw new \InvalidArgumentException('Calculator section ID must be a safe non-negative integer');
        }
        if ($sectionId > 0) {
            $catalog = call_user_func($this->presetCatalogLoader);
            $known = false;
            foreach ((array)($catalog['sections'] ?? []) as $section) {
                if (is_array($section) && (int)($section['id'] ?? 0) === $sectionId) {
                    $known = true;
                    break;
                }
            }
            if (!$known) {
                throw new \InvalidArgumentException('Calculator catalog section not found');
            }
        }
        $receipt = call_user_func($this->presetCreator, $name, $sectionId);
        if (!is_array($receipt)) {
            throw new \RuntimeException('The preset lifecycle authority returned an invalid receipt');
        }
        $presetId = (int)($receipt['presetId'] ?? 0);
        $presetName = trim((string)($receipt['presetName'] ?? ''));
        $identityRevision = strtolower(trim((string)($receipt['identityRevision'] ?? '')));
        if ($presetId <= 0 || $presetName === '' || preg_match('/^[a-f0-9]{64}$/D', $identityRevision) !== 1) {
            throw new \RuntimeException('The preset lifecycle authority returned an invalid receipt');
        }
        return [
            'contract' => self::CONTRACT,
            'presetId' => $presetId,
            'presetName' => $presetName,
            'revision' => $identityRevision,
        ];
    }

    /**
     * @param mixed[] $offerIds
     */
    public function validateCalculationLaunch(int $presetId, array $offerIds): array
    {
        $requestedOfferIds = $this->normalizeRequestedOfferIds($offerIds);

        $snapshot = $this->loadSnapshot($presetId);
        $serverOfferIds = [];
        $offerProductIds = [];
        foreach ($snapshot['products'] as $product) {
            $productId = (int)($product['id'] ?? 0);
            if ($productId <= 0 || !is_array($product['offers'] ?? null)) {
                continue;
            }
            foreach ($product['offers'] as $offer) {
                $offerId = (int)($offer['id'] ?? 0);
                if ($offerId <= 0 || isset($offerProductIds[$offerId])) {
                    throw new \RuntimeException('The authoritative catalog contains an invalid or duplicate offer');
                }
                $serverOfferIds[] = $offerId;
                $offerProductIds[$offerId] = $productId;
            }
        }
        if ($serverOfferIds === []) {
            throw new \InvalidArgumentException('The preset has no active catalog offers');
        }

        foreach ($requestedOfferIds as $offerId) {
            if (!isset($offerProductIds[$offerId])) {
                throw new \InvalidArgumentException(
                    'Offer ' . $offerId . ' is not active or does not belong to the preset catalog scope'
                );
            }
        }

        // The selection is reconstructed in the authoritative server order;
        // the browser array is never copied into the editor URL directly.
        $requestedOfferMap = array_fill_keys($requestedOfferIds, true);
        $validatedOfferIds = array_values(array_filter(
            $serverOfferIds,
            static function (int $offerId) use ($requestedOfferMap): bool {
                return isset($requestedOfferMap[$offerId]);
            }
        ));
        $serverProductIds = [];
        foreach ($validatedOfferIds as $offerId) {
            $serverProductIds[$offerProductIds[$offerId]] = true;
        }

        return [
            'contract' => self::CONTRACT,
            'focusPresetId' => $presetId,
            'presetName' => $snapshot['presetName'],
            'productIds' => array_map('intval', array_keys($serverProductIds)),
            'offerIds' => $validatedOfferIds,
        ];
    }

    public function validatePresetLaunch(int $presetId): array
    {
        $snapshot = $this->loadSnapshot($presetId);

        return [
            'contract' => self::CONTRACT,
            'focusPresetId' => $presetId,
            'presetName' => $snapshot['presetName'],
        ];
    }

    public function loadFormFirstWorkspace(int $presetId): array
    {
        $this->assertPresetFormAuthority($presetId);
        $dependencyContract = $this->resolveDependencyContract($presetId);

        return $this->assertFormFirstEditorResult(
            $this->requireFormFirstAuthoring()->loadFormFirstWorkspace(
                $presetId,
                $dependencyContract
            ),
            $presetId,
            'load',
            $dependencyContract['fingerprint']
        );
    }

    /** @return array<string,mixed> */
    public function newVersionFormTemplate(int $presetId): array
    {
        $this->assertPresetFormAuthority($presetId);
        $dependencyContract = $this->resolveDependencyContract($presetId);
        return $this->assertFormFirstEditorResult(
            $this->requireFormFirstAuthoring()->newVersionFormTemplate(
                $presetId,
                $dependencyContract
            ),
            $presetId,
            'new_version_template',
            $dependencyContract['fingerprint']
        );
    }

    /** @return array<string,mixed> */
    public function inspectFormFirstFieldDeletion(
        int $presetId,
        string $fieldId,
        ?string $propertyCode
    ): array {
        $this->assertPresetFormAuthority($presetId);
        $fieldId = trim($fieldId);
        // The impact check must also let an operator remove an invalid field
        // from an unsaved draft (for example, a numeric code entered before
        // validation). Save/publish keep the stricter semantic-id contract.
        if ($fieldId === '' || strlen($fieldId) > 100 || preg_match('/[\x00-\x1F\x7F]/', $fieldId) === 1) {
            throw new \InvalidArgumentException('fieldId must be a non-empty printable identifier');
        }
        $propertyCode = $propertyCode === null ? null : strtoupper(trim($propertyCode));
        if ($propertyCode !== null && preg_match('/^CALC_PROP_[A-Z0-9_]+$/D', $propertyCode) !== 1) {
            throw new \InvalidArgumentException('propertyCode must be a valid calculator property code or null');
        }

        $dependencyContract = $this->resolveDependencyContract($presetId);
        $blockers = [];
        if ($propertyCode !== null) {
            foreach ($dependencyContract['consumers'] as $consumer) {
                if ((string)$consumer['propertyCode'] === $propertyCode
                    && in_array((string)$consumer['category'], self::FIELD_DELETE_BLOCKING_CATEGORIES, true)) {
                    $blockers[] = $consumer;
                }
            }
        }
        foreach ((array)call_user_func($this->formFieldReferenceResolver, $presetId, $fieldId) as $reference) {
            if (!is_array($reference)
                || !in_array((string)($reference['category'] ?? ''), self::FIELD_DELETE_BLOCKING_CATEGORIES, true)
                || (string)($reference['fieldId'] ?? '') !== $fieldId) {
                throw new \RuntimeException('The current field reference authority is invalid');
            }
            // fieldId belongs to the internal reference-authority record. The
            // public delete-impact contract already carries it at the response
            // root, so expose only the exact dependency-consumer shape here.
            $blockers[] = [
                'propertyCode' => $propertyCode,
                'category' => (string)$reference['category'],
                'source' => (string)$reference['source'],
                'path' => (string)$reference['path'],
                'provenance' => (string)$reference['provenance'],
            ];
        }
        $deduplicatedBlockers = [];
        foreach ($blockers as $blocker) {
            $key = hash('sha256', (string)json_encode($blocker, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $deduplicatedBlockers[$key] = $blocker;
        }
        $blockers = array_values($deduplicatedBlockers);

        return [
            'contract' => self::FORM_FIRST_FIELD_DELETE_IMPACT_CONTRACT,
            'presetId' => $presetId,
            'fieldId' => $fieldId,
            'propertyCode' => $propertyCode,
            'removable' => $blockers === [],
            'blockers' => $blockers,
            'dependencyFingerprint' => (string)$dependencyContract['fingerprint'],
        ];
    }

    public function saveFormFirstDraft(
        int $presetId,
        string $expectedAggregateRevision,
        array $formDefinition,
        array $bindingDefinition
    ): array {
        $this->assertPresetFormAuthority($presetId);
        $this->assertSha256($expectedAggregateRevision, 'expectedAggregateRevision');
        $this->assertEditorDocument($formDefinition, 'formDefinition');
        $this->assertEditorDocument($bindingDefinition, 'bindingDefinition');
        return $this->withPresetMutation(
            $presetId,
            [
                'action' => 'form_first_save_draft',
                'entity_type' => 'form_first',
                'entity_id' => (string)$presetId,
                'expected_revision' => $expectedAggregateRevision,
                'product_ids' => [],
            ],
            function () use (
                $presetId,
                $expectedAggregateRevision,
                $formDefinition,
                $bindingDefinition
            ): array {
                $dependencyContract = $this->resolveDependencyContract($presetId);
                return $this->assertFormFirstEditorResult(
                    $this->requireFormFirstAuthoring()->saveFormFirstDraft(
                        $presetId,
                        $expectedAggregateRevision,
                        $formDefinition,
                        $bindingDefinition,
                        $dependencyContract
                    ),
                    $presetId,
                    'save_draft',
                    $dependencyContract['fingerprint']
                );
            },
            function () use ($presetId): array {
                return $this->loadFormFirstWorkspace($presetId);
            }
        );
    }

    public function previewFormFirst(
        int $presetId,
        array $formDefinition,
        array $bindingDefinition
    ): array {
        $this->assertPresetFormAuthority($presetId);
        $this->assertEditorDocument($formDefinition, 'formDefinition');
        $this->assertEditorDocument($bindingDefinition, 'bindingDefinition');
        $dependencyContract = $this->resolveDependencyContract($presetId);

        return $this->assertFormFirstEditorResult(
            $this->requireFormFirstAuthoring()->previewFormFirst(
                $presetId,
                $formDefinition,
                $bindingDefinition,
                $dependencyContract
            ),
            $presetId,
            'preview',
            $dependencyContract['fingerprint']
        );
    }

    public function previewVersionFormFirst(
        int $presetId,
        array $formDefinition,
        array $bindingDefinition,
        array $versionDocuments = []
    ): array {
        $this->assertPresetFormAuthority($presetId);
        $this->assertEditorDocument($formDefinition, 'formDefinition');
        $this->assertEditorDocument($bindingDefinition, 'bindingDefinition');
        $dependencyContract = $this->versionDependencyContract(
            $presetId,
            $formDefinition,
            $bindingDefinition,
            $versionDocuments
        );

        return $this->assertFormFirstEditorResult(
            $this->requireFormFirstAuthoring()->previewVersionFormFirst(
                $presetId,
                $formDefinition,
                $bindingDefinition,
                $dependencyContract
            ),
            $presetId,
            'preview',
            $dependencyContract['fingerprint']
        );
    }

    public function publishFormFirst(
        int $presetId,
        string $expectedAggregateRevision,
        string $expectedCompileHash
    ): array {
        $this->assertPresetFormAuthority($presetId);
        $this->assertSha256($expectedAggregateRevision, 'expectedAggregateRevision');
        $this->assertSha256($expectedCompileHash, 'expectedCompileHash');
        return $this->withPresetMutation(
            $presetId,
            [
                'action' => 'form_first_publish',
                'entity_type' => 'form_first',
                'entity_id' => (string)$presetId,
                'expected_revision' => $expectedAggregateRevision,
                'product_ids' => [],
            ],
            function () use ($presetId, $expectedAggregateRevision, $expectedCompileHash): array {
                $dependencyContract = $this->resolveDependencyContract($presetId);
                $result = $this->assertFormFirstEditorResult(
                    $this->requireFormFirstAuthoring()->publishFormFirst(
                        $presetId,
                        $expectedAggregateRevision,
                        $expectedCompileHash,
                        $dependencyContract
                    ),
                    $presetId,
                    'publish',
                    $dependencyContract['fingerprint']
                );
                call_user_func($this->activeStorefrontPublicationValidator, $presetId);
                return $result;
            },
            function () use ($presetId): array {
                return $this->loadFormFirstWorkspace($presetId);
            }
        );
    }

    public function rollbackFormFirst(
        int $presetId,
        string $expectedAggregateRevision,
        int $targetPublishedRevision
    ): array {
        $this->assertPresetFormAuthority($presetId);
        $this->assertSha256($expectedAggregateRevision, 'expectedAggregateRevision');
        if ($targetPublishedRevision < 0) {
            throw new \InvalidArgumentException('targetPublishedRevision must be a non-negative integer');
        }
        return $this->withPresetMutation(
            $presetId,
            [
                'action' => 'form_first_rollback',
                'entity_type' => 'form_first',
                'entity_id' => (string)$presetId,
                'expected_revision' => $expectedAggregateRevision,
                'product_ids' => [],
            ],
            function () use ($presetId, $expectedAggregateRevision, $targetPublishedRevision): array {
                $dependencyContract = $this->resolveDependencyContract($presetId);
                $result = $this->assertFormFirstEditorResult(
                    $this->requireFormFirstAuthoring()->rollbackFormFirst(
                        $presetId,
                        $expectedAggregateRevision,
                        $targetPublishedRevision,
                        $dependencyContract
                    ),
                    $presetId,
                    'rollback',
                    $dependencyContract['fingerprint']
                );
                call_user_func($this->activeStorefrontPublicationValidator, $presetId);
                return $result;
            },
            function () use ($presetId): array {
                return $this->loadFormFirstWorkspace($presetId);
            }
        );
    }

    /**
     * @return array{presetName:string,offerCount:int,products:array<int,array{id:int,name:string,offerCount:int,offers:array<int,array{id:int,name:string}>}>}
     */
    private function loadSnapshot(int $presetId): array
    {
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('Preset ID must be positive');
        }
        $raw = call_user_func($this->presetLoader, $presetId);
        if (!is_array($raw) || (string)($raw['status'] ?? '') !== 'ok') {
            throw new \RuntimeException('Unable to load the preset');
        }
        if ((int)($raw['preset']['id'] ?? 0) !== $presetId) {
            throw new \RuntimeException('The preset loader returned an unexpected preset');
        }

        $products = [];
        $offerCount = 0;
        foreach ((array)($raw['products'] ?? []) as $rawProduct) {
            if (!is_array($rawProduct)) {
                continue;
            }
            $productId = (int)($rawProduct['id'] ?? 0);
            $productName = trim((string)($rawProduct['name'] ?? ''));
            if ($productId <= 0 || $productName === '') {
                continue;
            }

            $offers = [];
            $seenOfferIds = [];
            foreach ((array)($rawProduct['offers'] ?? []) as $rawOffer) {
                if (!is_array($rawOffer)) {
                    continue;
                }
                $offerId = (int)($rawOffer['id'] ?? 0);
                if ($offerId <= 0 || isset($seenOfferIds[$offerId])) {
                    continue;
                }
                $seenOfferIds[$offerId] = true;
                $offers[] = [
                    'id' => $offerId,
                    'name' => trim((string)($rawOffer['name'] ?? '')) ?: 'ТП #' . $offerId,
                ];
            }

            $offerCount += count($offers);
            $products[] = [
                'id' => $productId,
                'name' => $productName,
                'offerCount' => count($offers),
                'offers' => $offers,
            ];
        }

        return [
            'presetName' => trim((string)($raw['preset']['name'] ?? '')) ?: 'Пресет #' . $presetId,
            'offerCount' => $offerCount,
            'products' => $products,
        ];
    }

    /**
     * The storefront uses every active product linked to the preset. The
     * catalog calculation may intentionally expose a narrower eligible set.
     *
     * @return array{presetName:string,offerCount:int,products:array<int,array{id:int,name:string,offerCount:int,offers:array<int,array{id:int,name:string}>}>}
     */
    private function loadStorefrontSnapshot(int $presetId): array
    {
        $originalLoader = $this->presetLoader;
        $this->presetLoader = $this->storefrontPresetLoader;
        try {
            return $this->loadSnapshot($presetId);
        } finally {
            $this->presetLoader = $originalLoader;
        }
    }

    /**
     * Load usage aggregates for one registry page in two grouped scans instead
     * of loading a full preset snapshot for every row.
     *
     * @param int[] $presetIds
     * @return array<int,array{productCount:int,offerCount:int}>
     */
    private function loadRegistryUsage(array $presetIds): array
    {
        $presetIds = array_values(array_unique(array_filter(array_map('intval', $presetIds), static function (int $id): bool {
            return $id > 0;
        })));
        if ($presetIds === []) {
            return [];
        }
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('The iblock module is not available');
        }
        $config = new ConfigManager();
        $productIblockId = (int)$config->getProductIblockId();
        $skuIblockId = (int)$config->getSkuIblockId();
        if ($productIblockId <= 0) {
            return [];
        }
        $propertyAuthority = $this->resolvePresetProductPropertyAuthority($productIblockId, false);
        $propertyId = $propertyAuthority['propertyId'];

        $usage = [];
        foreach ($presetIds as $presetId) {
            $usage[$presetId] = ['productCount' => 0, 'offerCount' => 0];
        }
        $productPresetMap = [];
        $productCursor = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            [
                'IBLOCK_ID' => $productIblockId,
                'PROPERTY_' . $propertyId => $presetIds,
            ],
            false,
            false,
            ['ID']
        );
        while ($productCursor && ($row = $productCursor->Fetch())) {
            $productId = (int)($row['ID'] ?? 0);
            if ($productId <= 0 || isset($productPresetMap[$productId])) {
                continue;
            }
            $assignedPresetIds = $this->loadProductPresetIds(
                $productIblockId,
                $propertyId,
                $productId
            );
            if (count($assignedPresetIds) !== 1) {
                throw new \RuntimeException(
                    'Product #' . $productId . ' has an ambiguous CALC_PRESET assignment.',
                    409
                );
            }
            $presetId = $assignedPresetIds[0];
            if (!isset($usage[$presetId])) {
                continue;
            }
            $productPresetMap[$productId] = $presetId;
            $usage[$presetId]['productCount']++;
        }

        if ($skuIblockId <= 0 || $productPresetMap === []) {
            return $usage;
        }
        $offerCursor = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            [
                'IBLOCK_ID' => $skuIblockId,
                'ACTIVE' => 'Y',
                'ACTIVE_DATE' => 'Y',
                'PROPERTY_CML2_LINK' => array_keys($productPresetMap),
            ],
            false,
            false,
            ['ID', 'PROPERTY_CML2_LINK']
        );
        while ($offerCursor && ($row = $offerCursor->Fetch())) {
            $productId = (int)($row['PROPERTY_CML2_LINK_VALUE'] ?? 0);
            $presetId = $productPresetMap[$productId] ?? 0;
            if ($presetId > 0 && isset($usage[$presetId])) {
                $usage[$presetId]['offerCount']++;
            }
        }

        return $usage;
    }

    /** @return array<int,array{id:int,name:string,active:bool,sort:int,updatedAt:string,sectionId:int}> */
    private function listPresetRows(
        string $query = '',
        string $status = 'all',
        string $sort = 'updated_desc',
        int $page = 1,
        int $pageSize = 50,
        ?int $sectionId = null,
        ?int &$serverTotal = null,
        bool &$serverPaged = false
    ): array {
        $rawResult = call_user_func($this->presetListLoader, $query, $status, $sort, $page, $pageSize, $sectionId);
        if (!is_array($rawResult)) {
            throw new \RuntimeException('The preset list provider returned an invalid result');
        }
        $serverPaged = !empty($rawResult['_serverPaged']);
        $serverTotal = $serverPaged ? max(0, (int)($rawResult['total'] ?? 0)) : null;
        $rawRows = $serverPaged ? ($rawResult['rows'] ?? null) : $rawResult;
        if (!is_array($rawRows)) {
            throw new \RuntimeException('The preset list provider returned invalid rows');
        }
        $rows = [];
        foreach ($rawRows as $rawRow) {
            if (!is_array($rawRow)) {
                continue;
            }
            $id = (int)($rawRow['id'] ?? $rawRow['ID'] ?? 0);
            if ($id <= 0 || isset($rows[$id])) {
                continue;
            }
            $rows[$id] = [
                'id' => $id,
                'name' => trim((string)($rawRow['name'] ?? $rawRow['NAME'] ?? '')) ?: 'Пресет #' . $id,
                'active' => array_key_exists('active', $rawRow)
                    ? !empty($rawRow['active'])
                    : (string)($rawRow['ACTIVE'] ?? 'Y') === 'Y',
                'sort' => (int)($rawRow['sort'] ?? $rawRow['SORT'] ?? 500),
                'updatedAt' => (string)($rawRow['updatedAt'] ?? $rawRow['TIMESTAMP_X'] ?? ''),
                'sectionId' => max(0, (int)($rawRow['sectionId'] ?? $rawRow['IBLOCK_SECTION_ID'] ?? 0)),
            ];
        }
        if (!$serverPaged) {
            ksort($rows, SORT_NUMERIC);
        }
        return array_values($rows);
    }

    /**
     * @param mixed $raw
     * @return array<string,mixed>
     */
    private function normalizePresetProductCatalog(
        $raw,
        int $presetId,
        string $query,
        int $page,
        int $pageSize
    ): array {
        if (!is_array($raw)) {
            throw new \RuntimeException('The preset product catalog provider returned an invalid result');
        }

        $linkedProductIds = [];
        foreach ((array)($raw['linkedProductIds'] ?? []) as $rawProductId) {
            $productId = (int)$rawProductId;
            if ($productId > 0) {
                $linkedProductIds[$productId] = $productId;
            }
        }
        ksort($linkedProductIds, SORT_NUMERIC);
        $linkedProductIds = array_values($linkedProductIds);

        $rows = [];
        foreach ((array)($raw['rows'] ?? []) as $rawRow) {
            if (!is_array($rawRow)) {
                continue;
            }
            $productId = (int)($rawRow['id'] ?? 0);
            $name = trim((string)($rawRow['name'] ?? ''));
            if ($productId <= 0 || $name === '' || isset($rows[$productId])) {
                continue;
            }
            $presetIds = [];
            foreach ((array)($rawRow['presetIds'] ?? []) as $rawPresetId) {
                $rowPresetId = (int)$rawPresetId;
                if ($rowPresetId > 0) {
                    $presetIds[$rowPresetId] = $rowPresetId;
                }
            }
            ksort($presetIds, SORT_NUMERIC);
            $rows[$productId] = [
                'id' => $productId,
                'name' => $name,
                'active' => array_key_exists('active', $rawRow) ? !empty($rawRow['active']) : true,
                'presetIds' => array_values($presetIds),
                'linked' => in_array($productId, $linkedProductIds, true),
            ];
        }

        $total = max(count($rows), (int)($raw['total'] ?? count($rows)));
        $normalizedPage = max(1, (int)($raw['page'] ?? $page));
        $normalizedPageSize = max(1, min(100, (int)($raw['pageSize'] ?? $pageSize)));
        $revision = strtolower(trim((string)($raw['revision'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $revision)) {
            $revision = hash('sha256', json_encode(
                ['presetId' => $presetId, 'linkedProductIds' => $linkedProductIds],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        }

        return [
            'contract' => self::CONTRACT,
            'presetId' => $presetId,
            'presetName' => trim((string)($raw['presetName'] ?? '')) ?: 'Пресет #' . $presetId,
            'productIblockId' => max(0, (int)($raw['productIblockId'] ?? $this->resolveProductIblockId())),
            'linkedProductIds' => $linkedProductIds,
            'linkedCount' => count($linkedProductIds),
            'revision' => $revision,
            'rows' => array_values($rows),
            'page' => $normalizedPage,
            'pageSize' => $normalizedPageSize,
            'total' => $total,
            'pageCount' => max(1, (int)ceil($total / $normalizedPageSize)),
            'query' => $query,
        ];
    }

    /** @return array<string,mixed> */
    private function loadPresetProductCatalogFromBitrix(
        int $presetId,
        string $query,
        int $page,
        int $pageSize,
        ?array $propertyAuthority = null
    ): array {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('The iblock module is not available');
        }
        $config = new ConfigManager();
        $productIblockId = (int)$config->getProductIblockId();
        $presetIblockId = (int)$config->getIblockId('CALC_PRESETS');
        if ($productIblockId <= 0 || $presetIblockId <= 0) {
            throw new \RuntimeException('Product or preset iblock is not configured');
        }
        $preset = \CIBlockElement::GetList(
            [],
            ['ID' => $presetId, 'IBLOCK_ID' => $presetIblockId],
            false,
            false,
            ['ID', 'NAME']
        )->Fetch();
        if (!$preset) {
            throw new \InvalidArgumentException('Пресет не найден');
        }
        $authority = $this->normalizePresetProductPropertyAuthority(
            $propertyAuthority ?? $this->resolvePresetProductPropertyAuthority($productIblockId, false),
            $productIblockId
        );
        $propertyId = $authority['propertyId'];

        $linkedProductIds = $this->loadLinkedProductIds($productIblockId, $propertyId, $presetId);
        $filter = [
            'IBLOCK_ID' => $productIblockId,
        ];
        if ($query !== '') {
            $filter[] = ctype_digit($query)
                ? ['LOGIC' => 'OR', ['ID' => (int)$query], ['%NAME' => $query]]
                : ['%NAME' => $query];
        }
        $cursor = \CIBlockElement::GetList(
            ['NAME' => 'ASC', 'ID' => 'ASC'],
            $filter,
            false,
            ['nPageSize' => $pageSize, 'iNumPage' => $page],
            ['ID', 'NAME', 'ACTIVE']
        );
        $rows = [];
        while ($cursor && ($row = $cursor->Fetch())) {
            $productId = (int)($row['ID'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $rows[] = [
                'id' => $productId,
                'name' => (string)($row['NAME'] ?? ''),
                'active' => (string)($row['ACTIVE'] ?? 'N') === 'Y',
                'presetIds' => $this->loadProductPresetIds($productIblockId, $propertyId, $productId),
            ];
        }
        if ($query === '' && $linkedProductIds !== []) {
            $loadedIds = array_fill_keys(array_map(static fn(array $row): int => (int)$row['id'], $rows), true);
            $missingLinkedIds = array_values(array_filter(
                $linkedProductIds,
                static fn(int $productId): bool => !isset($loadedIds[$productId])
            ));
            if ($missingLinkedIds !== []) {
                $linkedCursor = \CIBlockElement::GetList(
                    ['NAME' => 'ASC', 'ID' => 'ASC'],
                    ['IBLOCK_ID' => $productIblockId, 'ID' => $missingLinkedIds],
                    false,
                    false,
                    ['ID', 'NAME', 'ACTIVE']
                );
                while ($linkedCursor && ($linkedRow = $linkedCursor->Fetch())) {
                    $productId = (int)($linkedRow['ID'] ?? 0);
                    if ($productId <= 0 || isset($loadedIds[$productId])) {
                        continue;
                    }
                    $loadedIds[$productId] = true;
                    $rows[] = [
                        'id' => $productId,
                        'name' => (string)($linkedRow['NAME'] ?? ''),
                        'active' => (string)($linkedRow['ACTIVE'] ?? 'N') === 'Y',
                        'presetIds' => $this->loadProductPresetIds($productIblockId, $propertyId, $productId),
                    ];
                }
            }
        }
        $total = $cursor && method_exists($cursor, 'SelectedRowsCount')
            ? (int)$cursor->SelectedRowsCount()
            : count($rows);

        return [
            'presetName' => (string)($preset['NAME'] ?? ''),
            'productIblockId' => $productIblockId,
            'linkedProductIds' => $linkedProductIds,
            'revision' => $this->presetProductRevision($presetId, $linkedProductIds),
            'rows' => $rows,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => max($total, count($rows)),
        ];
    }

    /** @param int[] $productIds @return array<string,mixed> */
    private function mutatePresetProductsInBitrix(
        int $presetId,
        array $productIds,
        string $expectedRevision,
        int $productIblockId,
        array $propertyAuthority
    ): array {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('The iblock module is not available');
        }
        if ($productIblockId <= 0) {
            throw new \RuntimeException('Product iblock is not configured');
        }
        $authority = $this->normalizePresetProductPropertyAuthority(
            $propertyAuthority,
            $productIblockId
        );
        $propertyId = $authority['propertyId'];

        $currentProductIds = $this->loadLinkedProductIds($productIblockId, $propertyId, $presetId);
        if (!hash_equals($this->presetProductRevision($presetId, $currentProductIds), $expectedRevision)) {
            throw new \RuntimeException('Список товаров уже изменён в другой вкладке. Обновите его и повторите сохранение.', 409);
        }

        $requestedMap = array_fill_keys($productIds, true);
        $detachedProductIds = array_values(array_diff($currentProductIds, $productIds));
        if ($productIds) {
            $existingIds = [];
            $cursor = \CIBlockElement::GetList(
                ['ID' => 'ASC'],
                [
                    'IBLOCK_ID' => $productIblockId,
                    'ID' => $productIds,
                ],
                false,
                false,
                ['ID']
            );
            while ($cursor && ($row = $cursor->Fetch())) {
                $existingIds[(int)$row['ID']] = true;
            }
            if (count($existingIds) !== count($requestedMap)) {
                throw new \InvalidArgumentException('Один или несколько выбранных товаров больше не существуют');
            }
        }

        $affectedProductIds = array_values(array_unique(array_merge($currentProductIds, $productIds)));
        sort($affectedProductIds, SORT_NUMERIC);
        $currentAssignments = [];
        foreach ($affectedProductIds as $productId) {
            $currentAssignments[$productId] = $this->loadProductPresetIds(
                $productIblockId,
                $propertyId,
                $productId
            );
        }
        self::assertExclusivePresetAssignments($presetId, $productIds, $currentAssignments);
        $mutations = [];
        foreach ($affectedProductIds as $productId) {
            $originalPresetIds = $currentAssignments[$productId];
            $nextPresetIds = array_values(array_filter(
                $originalPresetIds,
                static fn(int $currentPresetId): bool => $currentPresetId !== $presetId
            ));
            if (isset($requestedMap[$productId])) {
                $nextPresetIds[] = $presetId;
            }
            $nextPresetIds = array_values(array_unique($nextPresetIds));
            sort($nextPresetIds, SORT_NUMERIC);
            if ($nextPresetIds !== $originalPresetIds) {
                $mutations[$productId] = [
                    'original' => $originalPresetIds,
                    'next' => $nextPresetIds,
                ];
            }
        }

        $applied = [];
        try {
            foreach ($mutations as $productId => $mutation) {
                PresetProductAssignmentMutationGuardService::runInternal(static function () use (
                    $productId,
                    $productIblockId,
                    $propertyId,
                    $mutation
                ): void {
                    \CIBlockElement::SetPropertyValuesEx(
                        (int)$productId,
                        $productIblockId,
                        [$propertyId => ($mutation['next'][0] ?? false)]
                    );
                });
                $readback = $this->loadProductPresetIds(
                    $productIblockId,
                    $propertyId,
                    (int)$productId
                );
                if ($readback !== $mutation['next']) {
                    throw new \RuntimeException('Не удалось подтвердить привязку товара #' . $productId);
                }
                $applied[] = (int)$productId;
            }
            call_user_func($this->storefrontProductDetacher, $presetId, $detachedProductIds);

            $linkedReadback = $this->loadLinkedProductIds($productIblockId, $propertyId, $presetId);
            if ($linkedReadback !== $productIds) {
                throw new \RuntimeException('Контрольное чтение привязок товаров не совпало с записью');
            }
            $storefrontReadback = call_user_func($this->storefrontProductReadbackLoader, $presetId);
            $storefrontItems = is_array($storefrontReadback['items'] ?? null)
                ? $storefrontReadback['items']
                : null;
            if ($storefrontItems === null) {
                throw new \RuntimeException('Контрольное чтение витринных калькуляторов недоступно');
            }
            $detachedMap = array_fill_keys($detachedProductIds, true);
            foreach ($storefrontItems as $storefront) {
                foreach (is_array($storefront['product_ids'] ?? null) ? $storefront['product_ids'] : [] as $productId) {
                    if (isset($detachedMap[(int)$productId])) {
                        throw new \RuntimeException(
                            'Товар #' . (int)$productId . ' остался привязан к витринному калькулятору'
                        );
                    }
                }
            }
        } catch (\Throwable $exception) {
            foreach (array_reverse($applied) as $productId) {
                $original = $mutations[$productId]['original'];
                PresetProductAssignmentMutationGuardService::runInternal(static function () use (
                    $productId,
                    $productIblockId,
                    $propertyId,
                    $original
                ): void {
                    \CIBlockElement::SetPropertyValuesEx(
                        $productId,
                        $productIblockId,
                        [$propertyId => ($original[0] ?? false)]
                    );
                });
            }
            throw $exception;
        }

        if (class_exists('\CIBlock') && method_exists('\CIBlock', 'clearIblockTagCache')) {
            \CIBlock::clearIblockTagCache($productIblockId);
        }

        return $this->loadPresetProductCatalogFromBitrix($presetId, '', 1, 50);
    }

    /** @return int[] */
    private function loadLinkedProductIds(int $productIblockId, int $propertyId, int $presetId): array
    {
        $ids = [];
        $cursor = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            [
                'IBLOCK_ID' => $productIblockId,
                'PROPERTY_' . $propertyId => $presetId,
            ],
            false,
            false,
            ['ID']
        );
        while ($cursor && ($row = $cursor->Fetch())) {
            $productId = (int)($row['ID'] ?? 0);
            if ($productId > 0) {
                $ids[$productId] = $productId;
            }
        }
        ksort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    /** @return int[] */
    private function loadProductPresetIds(int $productIblockId, int $propertyId, int $productId): array
    {
        $ids = [];
        $cursor = \CIBlockElement::GetProperty(
            $productIblockId,
            $productId,
            ['sort' => 'asc', 'id' => 'asc'],
            ['ID' => $propertyId]
        );
        while ($cursor && ($property = $cursor->Fetch())) {
            $presetId = (int)($property['VALUE'] ?? 0);
            if ($presetId > 0) {
                $ids[$presetId] = $presetId;
            }
        }
        ksort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    /** @param int[] $productIds @return array<int,int[]> */
    private function loadExactProductAssignments(int $productIblockId, int $propertyId, array $productIds): array
    {
        if ($productIblockId <= 0 || $productIds === []) {
            return [];
        }
        $existing = [];
        $cursor = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => $productIblockId, 'ID' => $productIds],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'ACTIVE', 'ACTIVE_FROM', 'ACTIVE_TO']
        );
        while ($cursor && ($row = $cursor->Fetch())) {
            $productId = (int)($row['ID'] ?? 0);
            if ($productId > 0) {
                // Publication activity is metadata only. Assignment authority
                // must survive inactive and expired catalog states.
                $existing[$productId] = $this->loadProductPresetIds(
                    $productIblockId,
                    $propertyId,
                    $productId
                );
            }
        }
        ksort($existing, SORT_NUMERIC);
        return $existing;
    }

    /** @param int[] $linkedProductIds */
    private function presetProductRevision(int $presetId, array $linkedProductIds): string
    {
        sort($linkedProductIds, SORT_NUMERIC);
        return hash('sha256', json_encode(
            ['presetId' => $presetId, 'linkedProductIds' => array_values($linkedProductIds)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    private function stringLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function resolveProductIblockId(): int
    {
        $productIblockId = (int)call_user_func($this->productIblockIdResolver);
        if ($productIblockId <= 0) {
            throw new \RuntimeException('The product iblock is not configured');
        }

        return $productIblockId;
    }

    /** @return array{productIblockId:int,presetIblockId:int,propertyId:int} */
    private function resolvePresetProductPropertyAuthority(
        int $productIblockId,
        bool $forUpdate,
        int $presetIblockId = 0
    ): array
    {
        $raw = call_user_func(
            $this->presetProductPropertyAuthority,
            $productIblockId,
            $forUpdate,
            $presetIblockId
        );
        if (!is_array($raw)) {
            throw new \RuntimeException('CALC_PRESET property authority is invalid.', 409);
        }
        if ($presetIblockId <= 0) {
            $presetIblockId = (int)($raw['presetIblockId'] ?? 0);
        }
        return $this->normalizePresetProductPropertyAuthority(
            $raw,
            $productIblockId,
            $presetIblockId
        );
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{productIblockId:int,presetIblockId:int,propertyId:int}
     */
    private function normalizePresetProductPropertyAuthority(
        array $raw,
        int $productIblockId,
        int $presetIblockId = 0
    ): array
    {
        if ($presetIblockId <= 0) {
            $presetIblockId = (int)($raw['presetIblockId'] ?? 0);
        }
        if ((int)($raw['productIblockId'] ?? 0) !== $productIblockId
            || (int)($raw['presetIblockId'] ?? 0) !== $presetIblockId
            || $presetIblockId <= 0
            || (int)($raw['propertyId'] ?? 0) <= 0
        ) {
            throw new \RuntimeException('CALC_PRESET property authority is invalid.', 409);
        }
        return [
            'productIblockId' => $productIblockId,
            'presetIblockId' => $presetIblockId,
            'propertyId' => (int)$raw['propertyId'],
        ];
    }

    private function assertPresetFormAuthority(int $presetId): void
    {
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('Preset ID must be positive');
        }
        if (!(bool)call_user_func($this->frontcalcAvailabilityResolver)) {
            throw new \RuntimeException('The storefront calculator module is not installed');
        }
        $preset = call_user_func($this->presetIdentityLoader, $presetId);
        if (!is_array($preset) || (int)($preset['id'] ?? 0) !== $presetId) {
            throw new \RuntimeException('The preset identity authority is invalid');
        }
    }

    /**
     * @param array<int,array<string,mixed>> $sectionMap
     * @return array<int,array{id:int,name:string}>
     */
    private function buildCalculatorSectionPath(int $sectionId, array $sectionMap): array
    {
        if ($sectionId <= 0) {
            return [];
        }
        $path = [];
        $visited = [];
        while ($sectionId > 0) {
            if (isset($visited[$sectionId]) || !isset($sectionMap[$sectionId])) {
                throw new \RuntimeException('Calculator registry contains an invalid section path');
            }
            $visited[$sectionId] = true;
            $section = $sectionMap[$sectionId];
            $path[] = [
                'id' => $sectionId,
                'name' => trim((string)($section['name'] ?? '')),
            ];
            $sectionId = max(0, (int)($section['parentId'] ?? 0));
        }
        return array_reverse($path);
    }

    private function assertSha256(string $value, string $field): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException($field . ' must be a lowercase SHA-256 revision');
        }
    }

    /** @return array{id:int,name:string,active:bool,updatedAt:string,revision:string} */
    private function loadPresetActiveState(int $presetId, bool $forUpdate = false): array
    {
        $raw = call_user_func(
            $forUpdate ? $this->presetActiveLockedStateLoader : $this->presetActiveStateLoader,
            $presetId
        );
        if (!is_array($raw)
            || (int)($raw['id'] ?? 0) !== $presetId
            || !is_bool($raw['active'] ?? null)) {
            throw new \RuntimeException('Preset registry authority returned an invalid state.');
        }
        $state = [
            'id' => $presetId,
            'name' => trim((string)($raw['name'] ?? '')) ?: 'Пресет #' . $presetId,
            'active' => (bool)$raw['active'],
            'updatedAt' => (string)($raw['updatedAt'] ?? ''),
        ];
        $state['revision'] = $this->presetRegistryRevision($state);
        return $state;
    }

    /** @param array<string,mixed> $row */
    private function presetRegistryRevision(array $row): string
    {
        $presetId = (int)($row['id'] ?? 0);
        if ($presetId <= 0 || !is_bool($row['active'] ?? null)) {
            throw new \RuntimeException('Preset registry row cannot produce an exact revision.');
        }
        return hash('sha256', json_encode(
            [
                'presetId' => $presetId,
                'active' => (bool)$row['active'],
                'updatedAt' => (string)($row['updatedAt'] ?? ''),
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    /** @param mixed[] $productIds @return int[] */
    private function normalizePresetProductIds(array $productIds): array
    {
        if (!array_is_list($productIds) || count($productIds) > 1000) {
            throw new \InvalidArgumentException('productIds must be a JSON array with at most 1000 items');
        }
        $normalized = [];
        foreach ($productIds as $productId) {
            if (!is_int($productId) || $productId <= 0 || $productId > 9007199254740991) {
                throw new \InvalidArgumentException('productIds must contain positive integer IDs');
            }
            $normalized[$productId] = $productId;
        }
        ksort($normalized, SORT_NUMERIC);
        return array_values($normalized);
    }

    /** @return array<string,mixed> */
    private function versionDependencyContract(
        int $presetId,
        array $formDefinition,
        array $bindingDefinition,
        array $versionDocuments
    ): array
    {
        $categories = [
            'ui',
            'catalog_input_mapping',
            'stage_inputs',
            'globals',
            'options_mappings',
            'basket',
            'storefront_presentation',
        ];
        $consumers = [];
        $propertyByField = [];
        $append = static function (
            string $propertyCode,
            string $category,
            string $source,
            string $path
        ) use (&$consumers, $categories): void {
            $propertyCode = strtoupper(trim($propertyCode));
            if (preg_match('/^CALC_PROP_[A-Z0-9_]+$/D', $propertyCode) !== 1
                || !in_array($category, $categories, true)) {
                return;
            }
            $consumer = [
                'propertyCode' => $propertyCode,
                'category' => $category,
                'source' => $source,
                'path' => $path,
                'provenance' => 'declared',
            ];
            $key = implode('|', array_values($consumer));
            $consumers[$key] = $consumer;
        };
        foreach ((array)($bindingDefinition['bindings'] ?? []) as $index => $binding) {
            if (!is_array($binding)
                || (string)($binding['target']['kind'] ?? '') !== 'property') {
                continue;
            }
            $fieldId = trim((string)($binding['fieldId'] ?? ''));
            $propertyCode = strtoupper(trim((string)($binding['target']['propertyCode'] ?? '')));
            if ($fieldId === '' || preg_match('/^CALC_PROP_[A-Z0-9_]+$/D', $propertyCode) !== 1) {
                continue;
            }
            $propertyByField[$fieldId] = $propertyCode;
            $append($propertyCode, 'ui', 'prospektweb.frontcalc.form-definition/v1', 'bindingDefinition.bindings.' . $index);
            $append($propertyCode, 'basket', 'prospektweb.frontcalc.calculation-session/v1', 'selection.' . $fieldId);
        }
        foreach ((array)($versionDocuments['inputMappings']['mappings'] ?? []) as $index => $mapping) {
            $fieldId = is_array($mapping) ? trim((string)($mapping['target']['field_id'] ?? '')) : '';
            if (isset($propertyByField[$fieldId])) {
                $append(
                    $propertyByField[$fieldId],
                    'catalog_input_mapping',
                    'prospektweb.calc.calculator-input-mapping/v1',
                    'inputMappings.mappings.' . $index . '.target.field_id'
                );
            }
        }
        $storefrontRows = [];
        if (is_array($versionDocuments['storefronts']['base'] ?? null)) {
            $storefrontRows[] = ['id' => 'base', 'row' => $versionDocuments['storefronts']['base']];
        }
        foreach ((array)($versionDocuments['storefronts']['items'] ?? []) as $index => $storefront) {
            if (is_array($storefront)) {
                $storefrontRows[] = [
                    'id' => trim((string)($storefront['id'] ?? '')) ?: (string)$index,
                    'row' => $storefront,
                ];
            }
        }
        foreach ($storefrontRows as $storefront) {
            $patches = $storefront['row']['presentation']['field_patches'] ?? [];
            if ($patches instanceof \stdClass) {
                $patches = get_object_vars($patches);
            }
            foreach (is_array($patches) ? array_keys($patches) : [] as $fieldId) {
                if (is_string($fieldId) && isset($propertyByField[$fieldId])) {
                    $append(
                        $propertyByField[$fieldId],
                        'storefront_presentation',
                        'prospektweb.frontcalc.storefront-definition/v2',
                        'storefronts.' . $storefront['id'] . '.presentation.field_patches.' . $fieldId
                    );
                }
            }
        }
        $scanLogic = static function ($value, string $path = 'logic') use (&$scanLogic, $append): void {
            if (is_array($value)) {
                foreach ($value as $key => $child) {
                    $scanLogic($child, $path . '.' . (string)$key);
                }
                return;
            }
            if (!is_scalar($value)
                || preg_match_all('/\bCALC_PROP_[A-Z0-9_]+\b/', (string)$value, $matches) < 1) {
                return;
            }
            $lowerPath = strtolower($path);
            $category = str_contains($lowerPath, 'global')
                ? 'globals'
                : (str_contains($lowerPath, 'option') ? 'options_mappings' : 'stage_inputs');
            foreach (array_unique($matches[0]) as $propertyCode) {
                $append($propertyCode, $category, 'prospektweb.calc.version-logic-snapshot/v1', $path);
            }
        };
        $scanLogic(is_array($versionDocuments['logic'] ?? null) ? $versionDocuments['logic'] : []);
        ksort($consumers, SORT_STRING);
        $consumers = array_values($consumers);
        $requiredPropertyCodes = [];
        foreach ($consumers as $consumer) {
            if (in_array($consumer['category'], ['stage_inputs', 'globals', 'options_mappings'], true)) {
                $requiredPropertyCodes[$consumer['propertyCode']] = true;
            }
        }
        $requiredPropertyCodes = array_keys($requiredPropertyCodes);
        sort($requiredPropertyCodes, SORT_STRING);
        $categoryStatus = [];
        foreach ($categories as $category) {
            $count = count(array_filter(
                $consumers,
                static fn(array $consumer): bool => $consumer['category'] === $category
            ));
            $categoryStatus[$category] = [
                'scanned' => true,
                'count' => $count,
                'sourceMode' => 'declared',
            ];
        }
        $contract = [
            'contract' => 'prospektweb.calc.preset-public-inputs/v1',
            'presetId' => $presetId,
            'requiredPropertyCodes' => $requiredPropertyCodes,
            'consumers' => $consumers,
            'categoryStatus' => $categoryStatus,
        ];
        $contract['fingerprint'] = $this->canonicalHash($contract);
        return $contract;
    }

    /** @return array<string,mixed> */
    private function resolveDependencyContract(int $presetId): array
    {
        try {
            $contract = call_user_func($this->dependencyContractResolver, $presetId);
        } catch (\Throwable $exception) {
            throw new \RuntimeException('The current form-first dependency authority is unavailable');
        }
        if (!is_array($contract)
            || (string)($contract['contract'] ?? '') !== 'prospektweb.calc.preset-public-inputs/v1'
            || !is_int($contract['presetId'] ?? null)
            || (int)$contract['presetId'] !== $presetId
            || !is_array($contract['requiredPropertyCodes'] ?? null)
            || !is_array($contract['consumers'] ?? null)
            || !is_array($contract['categoryStatus'] ?? null)
            || !is_string($contract['fingerprint'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', (string)$contract['fingerprint']) !== 1) {
            throw new \RuntimeException('The current form-first dependency authority is invalid');
        }
        $requiredCategories = [
            'ui',
            'catalog_input_mapping',
            'stage_inputs',
            'globals',
            'options_mappings',
            'basket',
            'storefront_presentation',
        ];
        foreach ($requiredCategories as $category) {
            $status = $contract['categoryStatus'][$category] ?? null;
            if (!is_array($status)
                || ($status['scanned'] ?? false) !== true
                || !is_int($status['count'] ?? null)
                || (int)$status['count'] < 0
                || !in_array((string)($status['sourceMode'] ?? ''), ['discovered', 'declared'], true)) {
                throw new \RuntimeException('The current form-first dependency authority is incomplete');
            }
        }

        if (array_keys($contract['categoryStatus']) !== $requiredCategories) {
            throw new \RuntimeException('The current form-first dependency authority has unexpected categories');
        }
        $normalizedRequiredCodes = [];
        foreach ($contract['requiredPropertyCodes'] as $propertyCode) {
            if (!is_string($propertyCode)
                || preg_match('/^CALC_PROP_[A-Z0-9_]+$/D', $propertyCode) !== 1) {
                throw new \RuntimeException('The current form-first dependency authority has invalid required codes');
            }
            $normalizedRequiredCodes[$propertyCode] = true;
        }
        $normalizedRequiredCodes = array_keys($normalizedRequiredCodes);
        sort($normalizedRequiredCodes, SORT_STRING);
        if ($contract['requiredPropertyCodes'] !== $normalizedRequiredCodes) {
            throw new \RuntimeException('The current form-first dependency authority required codes are not canonical');
        }
        $consumerCounts = array_fill_keys($requiredCategories, 0);
        $previousConsumerKey = null;
        foreach ($contract['consumers'] as $consumer) {
            if (!is_array($consumer)
                || !is_string($consumer['propertyCode'] ?? null)
                || preg_match('/^CALC_PROP_[A-Z0-9_]+$/D', (string)$consumer['propertyCode']) !== 1
                || !in_array((string)($consumer['category'] ?? ''), $requiredCategories, true)
                || trim((string)($consumer['source'] ?? '')) === ''
                || trim((string)($consumer['path'] ?? '')) === ''
                || !in_array((string)($consumer['provenance'] ?? ''), ['discovered', 'declared'], true)) {
                throw new \RuntimeException('The current form-first dependency authority has an invalid consumer');
            }
            $consumerKey = implode('|', [
                $consumer['propertyCode'],
                $consumer['category'],
                $consumer['source'],
                $consumer['path'],
                $consumer['provenance'],
            ]);
            if ($previousConsumerKey !== null && strcmp($previousConsumerKey, $consumerKey) >= 0) {
                throw new \RuntimeException('The current form-first dependency authority consumers are not canonical');
            }
            $previousConsumerKey = $consumerKey;
            $consumerCounts[$consumer['category']]++;
        }
        foreach ($requiredCategories as $category) {
            if ($contract['categoryStatus'][$category]['count'] !== $consumerCounts[$category]) {
                throw new \RuntimeException('The current form-first dependency authority counts are inconsistent');
            }
        }

        $canonical = $contract;
        unset($canonical['fingerprint']);
        if (!hash_equals((string)$contract['fingerprint'], $this->canonicalHash($canonical))) {
            throw new \RuntimeException('The current form-first dependency authority fingerprint is invalid');
        }

        return $contract;
    }

    private function canonicalHash(array $value): string
    {
        $encoded = json_encode(
            $this->sortRecursively($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($encoded)) {
            throw new \RuntimeException('Unable to fingerprint the form-first dependency authority');
        }

        return hash('sha256', $encoded);
    }

    private function sortRecursively($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    private function assertEditorDocument(array $document, string $field): void
    {
        if ($document === [] || array_keys($document) === range(0, count($document) - 1)) {
            throw new \InvalidArgumentException($field . ' must be a non-empty object');
        }
        $encoded = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \InvalidArgumentException($field . ' must be valid JSON data');
        }
        if (strlen($encoded) > self::MAX_EDITOR_DOCUMENT_BYTES) {
            throw new \InvalidArgumentException(
                $field . ' must not exceed ' . self::MAX_EDITOR_DOCUMENT_BYTES . ' bytes'
            );
        }
    }

    /** @return object */
    private function requireFormFirstAuthoring()
    {
        return $this->requireProviderMethods(self::FORM_FIRST_AUTHORING_METHODS);
    }

    private function isFormFirstAuthoringAvailable(): bool
    {
        try {
            $this->requireFormFirstAuthoring();
            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /** @param string[] $methods @return object */
    private function requireProviderMethods(array $methods)
    {
        try {
            $provider = call_user_func($this->formFirstAuthoringResolver);
        } catch (\Throwable $exception) {
            throw new \RuntimeException('The native storefront editor is unavailable');
        }

        if (!is_object($provider)) {
            throw new \RuntimeException('The native storefront editor is unavailable');
        }
        foreach ($methods as $method) {
            if (!is_callable([$provider, $method])) {
                throw new \RuntimeException('The native storefront editor is unavailable');
            }
        }

        return $provider;
    }

    private function assertFormFirstEditorResult(
        $result,
        int $expectedPresetId,
        string $expectedOperation,
        string $expectedDependencyFingerprint
    ): array
    {
        if (!is_array($result)
            || (string)($result['contract'] ?? '') !== self::FORM_FIRST_AUTHORING_CONTRACT) {
            throw new \RuntimeException('The form-first editor returned an incompatible response');
        }
        $product = $result['product'] ?? null;
        if ($product !== null
            || array_key_exists('productId', $result)
            || array_key_exists('catalog', $result)
            || (!is_array($result['preset'] ?? null)
                || !is_int($result['preset']['id'] ?? null)
                || (int)$result['preset']['id'] !== $expectedPresetId)
            || (is_array($result['preset'] ?? null)
                && (!is_int($result['preset']['id'] ?? null)
                    || (int)$result['preset']['id'] !== $expectedPresetId))
            || !is_int($result['presetId'] ?? null)
            || (int)$result['presetId'] !== $expectedPresetId
            || !is_string($result['operation'] ?? null)
            || (string)$result['operation'] !== $expectedOperation
            || !is_string($result['dependencyFingerprint'] ?? null)
            || !hash_equals($expectedDependencyFingerprint, (string)$result['dependencyFingerprint'])
            || !is_string($result['aggregateRevision'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', (string)$result['aggregateRevision']) !== 1
            || !is_array($result['formDefinition'] ?? null)
            || (string)($result['formDefinition']['contract'] ?? '')
                !== 'prospektweb.frontcalc.form-definition/v1'
            || !is_array($result['bindingDefinition'] ?? null)
            || (string)($result['bindingDefinition']['contract'] ?? '')
                !== 'prospektweb.frontcalc.binding-definition/v1'
            || !is_array($result['history'] ?? null)
            || !is_array($result['compile'] ?? null)
            || !is_bool($result['compile']['valid'] ?? null)
            || !is_string($result['compile']['hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', (string)$result['compile']['hash']) !== 1) {
            throw new \RuntimeException('The form-first editor returned invalid revision or document types');
        }
        foreach ($result['history'] as $history) {
            if (!is_array($history)
                || !is_int($history['revision'] ?? null)
                || (int)$history['revision'] < 0
                || !is_int($history['formRevision'] ?? null)
                || (int)$history['formRevision'] < 0
                || !is_int($history['bindingRevision'] ?? null)
                || (int)$history['bindingRevision'] < 0
                || !is_string($history['compileHash'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', (string)$history['compileHash']) !== 1) {
                throw new \RuntimeException('The form-first editor returned invalid history revision types');
            }
        }

        return $result;
    }

    /**
     * @param mixed[] $offerIds
     * @return int[]
     */
    private function normalizeRequestedOfferIds(array $offerIds): array
    {
        if ($offerIds === []) {
            throw new \InvalidArgumentException('Select at least one offer');
        }
        if (count($offerIds) > self::MAX_CALCULATION_OFFERS) {
            throw new \InvalidArgumentException('Too many offers selected for one editor session');
        }

        $normalized = [];
        foreach ($offerIds as $offerId) {
            if (!is_int($offerId) || $offerId <= 0 || $offerId > 9007199254740991) {
                throw new \InvalidArgumentException('Offer IDs must be safe positive integers');
            }
            if (isset($normalized[$offerId])) {
                throw new \InvalidArgumentException('Offer IDs must not contain duplicates');
            }
            $normalized[$offerId] = $offerId;
        }

        return array_values($normalized);
    }

}
