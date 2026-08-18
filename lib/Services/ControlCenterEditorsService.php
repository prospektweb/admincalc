<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Prospektweb\Calc\Config\ConfigManager;

/**
 * Server-side authority for editor launches and the native storefront-editor
 * adapter used by the control center.
 *
 * Presets own their form and calculation graph. Products are optional catalog
 * adapters and are re-resolved only when a product-scoped operation is used.
 */
final class ControlCenterEditorsService
{
    public const CONTRACT = 'prospektweb.control-center.editors/v1';
    public const STOREFRONT_EDITOR_CONTRACT = 'prospektweb.frontcalc.storefront-editor/v1';
    public const FORM_FIRST_AUTHORING_CONTRACT = 'prospektweb.frontcalc.form-first-authoring/v1';
    public const FOCUS_PRESET_ID = 12740;

    private const MAX_CALCULATION_OFFERS = 500;
    private const MAX_EDITOR_DOCUMENT_BYTES = 60000;
    private const STOREFRONT_EDITOR_PROVIDER = '\\Prospektweb\\Frontcalc\\Service\\ControlCenterStorefrontEditorService';
    private const STOREFRONT_EDITOR_METHODS = [
        'loadWorkspace',
        'validateSchema',
        'saveTemplate',
        'saveProduct',
        'enableInheritance',
        'deleteTemplate',
    ];
    private const FORM_FIRST_AUTHORING_METHODS = [
        'loadFormFirstWorkspace',
        'saveFormFirstDraft',
        'previewFormFirst',
        'publishFormFirst',
        'rollbackFormFirst',
    ];

    /** @var callable */
    private $presetLoader;

    /** @var callable */
    private $productIblockIdResolver;

    /** @var callable */
    private $frontcalcAvailabilityResolver;

    /** @var callable */
    private $frontcalcEditorResolver;

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

    public function __construct(
        ?callable $presetLoader = null,
        ?callable $productIblockIdResolver = null,
        ?callable $frontcalcAvailabilityResolver = null,
        ?callable $frontcalcEditorResolver = null,
        ?callable $dependencyContractResolver = null,
        ?callable $presetListLoader = null,
        ?callable $presetCreator = null,
        ?callable $presetUsageLoader = null,
        ?callable $storefrontPresetLoader = null
    ) {
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
        $this->frontcalcEditorResolver = $frontcalcEditorResolver ?? static function () {
            if (!Loader::includeModule('prospektweb.frontcalc')) {
                return null;
            }

            $providerClass = self::STOREFRONT_EDITOR_PROVIDER;
            return class_exists($providerClass) ? new $providerClass() : null;
        };
        $this->dependencyContractResolver = $dependencyContractResolver
            ?? static function (int $presetId, array $allowedProductIds): array {
                return (new Phase5aParityContractService())->buildPublicInputContract(
                    $presetId,
                    $allowedProductIds
                );
            };
        $this->presetListLoader = $presetListLoader ?? ($presetLoader !== null
            ? static function (): array {
                return [['id' => self::FOCUS_PRESET_ID, 'name' => 'Пресет #' . self::FOCUS_PRESET_ID]];
            }
            : static function (string $query = '', string $status = 'all', string $sort = 'updated_desc', int $page = 1, int $pageSize = 50): array {
                if (!Loader::includeModule('iblock')) {
                    throw new \RuntimeException('The iblock module is not available');
                }
                $iblockId = (int)(new ConfigManager())->getIblockId('CALC_PRESETS');
                if ($iblockId <= 0) {
                    throw new \RuntimeException('The CALC_PRESETS iblock is not configured');
                }
                $filter = ['IBLOCK_ID' => $iblockId];
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
                    ['ID', 'NAME', 'ACTIVE', 'SORT', 'TIMESTAMP_X']
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
        $this->presetCreator = $presetCreator ?? static function (string $name): int {
            if (!Loader::includeModule('iblock')) {
                throw new \RuntimeException('The iblock module is not available');
            }
            $iblockId = (int)(new ConfigManager())->getIblockId('CALC_PRESETS');
            return (new \Prospektweb\Calc\Calculator\BundleHandler())
                ->createStandalonePreset($name, $iblockId);
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
    }

    public function getCatalog(): array
    {
        $snapshot = $this->loadStorefrontSnapshot(self::FOCUS_PRESET_ID);
        $productIblockId = $this->resolveProductIblockId();
        $frontcalcAvailable = (bool)call_user_func($this->frontcalcAvailabilityResolver);
        $visualEditorAvailable = $frontcalcAvailable && $this->isStorefrontEditorAvailable();
        $formFirstAuthoringAvailable = $frontcalcAvailable && $this->isFormFirstAuthoringAvailable();

        $storefrontProducts = [];
        foreach ($snapshot['products'] as $product) {
            $storefrontProducts[] = [
                'id' => $product['id'],
                'name' => $product['name'],
                'presetIds' => [self::FOCUS_PRESET_ID],
                'offerCount' => $product['offerCount'],
            ];
        }

        $registry = $this->getPresetRegistry('', 'all', 'updated_desc', 1, 50);

        return [
            'contract' => self::CONTRACT,
            'focusPresetId' => self::FOCUS_PRESET_ID,
            'calculations' => $registry['rows'],
            'storefront' => [
                'available' => $frontcalcAvailable,
                'visualEditorAvailable' => $visualEditorAvailable,
                'visualEditorContract' => self::STOREFRONT_EDITOR_CONTRACT,
                'formFirstAuthoringAvailable' => $formFirstAuthoringAvailable,
                'formFirstAuthoringContract' => self::FORM_FIRST_AUTHORING_CONTRACT,
                'formFirstPilotProductIds' => [4267],
                'productIblockId' => $productIblockId,
                'products' => $storefrontProducts,
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
        int $pageSize = 50
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

        $serverTotal = null;
        $serverPaged = false;
        $rows = $this->listPresetRows($query, $status, $sort, $page, $pageSize, $serverTotal, $serverPaged);
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
        $normalizedRows = array_map(static function (array $row) use ($usage): array {
            $id = (int)$row['id'];
            $counts = is_array($usage[$id] ?? null) ? $usage[$id] : [];
            return [
                'presetId' => $id,
                'presetName' => (string)$row['name'],
                'active' => !empty($row['active']),
                'productCount' => max(0, (int)($counts['productCount'] ?? 0)),
                'offerCount' => max(0, (int)($counts['offerCount'] ?? 0)),
                'updatedAt' => (string)($row['updatedAt'] ?? ''),
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

    /** @param int[] $presetIds */
    public function setPresetActive(array $presetIds, bool $active): array
    {
        $presetIds = array_values(array_unique(array_map('intval', $presetIds)));
        if ($presetIds === [] || count($presetIds) > 100 || min($presetIds) <= 0) {
            throw new \InvalidArgumentException('Select from 1 to 100 presets');
        }
        if (!$active && in_array(self::FOCUS_PRESET_ID, $presetIds, true)) {
            throw new \InvalidArgumentException('Рабочий пресет 12740 нельзя архивировать');
        }
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('The iblock module is not available');
        }
        $iblockId = (int)(new ConfigManager())->getIblockId('CALC_PRESETS');
        $element = new \CIBlockElement();
        foreach ($presetIds as $presetId) {
            $exists = \CIBlockElement::GetList([], ['ID' => $presetId, 'IBLOCK_ID' => $iblockId], false, false, ['ID'])->Fetch();
            if (!$exists || !$element->Update($presetId, ['ACTIVE' => $active ? 'Y' : 'N'])) {
                throw new \RuntimeException('Не удалось изменить состояние пресета #' . $presetId);
            }
        }
        return ['contract' => self::CONTRACT, 'presetIds' => $presetIds, 'active' => $active];
    }

    public function duplicatePreset(int $presetId): array
    {
        $this->loadSnapshot($presetId);
        $newPresetId = (new \Prospektweb\Calc\Calculator\BundleHandler())->clonePreset($presetId);
        if ($newPresetId <= 0) {
            throw new \RuntimeException('Не удалось создать копию пресета');
        }
        $snapshot = $this->loadSnapshot($newPresetId);
        return [
            'contract' => self::CONTRACT,
            'presetId' => $newPresetId,
            'presetName' => $snapshot['presetName'],
        ];
    }

    public function createStandalonePreset(string $name): array
    {
        $name = trim($name);
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($name === '' || $nameLength > 200) {
            throw new \InvalidArgumentException('Preset name must contain 1 to 200 characters');
        }
        $presetId = (int)call_user_func($this->presetCreator, $name);
        if ($presetId <= 0) {
            throw new \RuntimeException('The preset creator returned an invalid ID');
        }
        $snapshot = $this->loadSnapshot($presetId);
        return [
            'contract' => self::CONTRACT,
            'presetId' => $presetId,
            'presetName' => $snapshot['presetName'],
        ];
    }

    /**
     * @param mixed[] $offerIds
     */
    public function validateCalculationLaunch(int $presetId, array $offerIds): array
    {
        $requestedOfferIds = $this->normalizeRequestedOfferIds($offerIds);

        $snapshot = $this->loadSnapshot($presetId);
        $supportedProductIds = $presetId === self::FOCUS_PRESET_ID
            ? array_fill_keys(StandaloneCatalogSelectionMapper::supportedProductIds(), true)
            : null;
        $serverOfferIds = [];
        $offerProductIds = [];
        foreach ($snapshot['products'] as $product) {
            $productId = (int)($product['id'] ?? 0);
            if ($productId <= 0
                || ($supportedProductIds !== null && !isset($supportedProductIds[$productId]))
                || !is_array($product['offers'] ?? null)) {
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

    public function validateStorefrontLaunch(int $productId): array
    {
        $authority = $this->resolveStorefrontAuthority($productId);

        return [
            'contract' => self::CONTRACT,
            'focusPresetId' => self::FOCUS_PRESET_ID,
            'productIblockId' => $this->resolveProductIblockId(),
            'productId' => $productId,
            'productName' => $authority['productName'],
        ];
    }

    public function loadStorefrontWorkspace(int $productId, string $target = 'effective', string $templateId = ''): array
    {
        $authority = $this->resolveStorefrontAuthority($productId);
        if (!in_array($target, ['effective', 'product', 'template'], true)) {
            throw new \InvalidArgumentException('Unsupported storefront editor target');
        }
        if ($target === 'template' && $templateId === '') {
            throw new \InvalidArgumentException('templateId is required for the template target');
        }
        if ($target !== 'template' && $templateId !== '') {
            throw new \InvalidArgumentException('templateId is allowed only for the template target');
        }

        return $this->assertStorefrontEditorResult(
            $this->requireStorefrontEditor()->loadWorkspace(
                $productId,
                $target,
                $templateId,
                $authority['allowedProductIds']
            )
        );
    }

    public function validateStorefrontSchema(int $productId, string $target, array $schema): array
    {
        $authority = $this->resolveStorefrontAuthority($productId);
        if (!in_array($target, ['product', 'template'], true)) {
            throw new \InvalidArgumentException('Unsupported storefront editor target');
        }
        $this->assertEditorDocument($schema, 'schema');

        return $this->assertStorefrontEditorResult(
            $this->requireStorefrontEditor()->validateSchema(
                $productId,
                $target,
                $schema,
                $authority['allowedProductIds']
            )
        );
    }

    public function saveStorefrontTemplate(
        int $productId,
        string $templateId,
        int $expectedRevision,
        string $name,
        int $sectionId,
        array $schema
    ): array {
        $authority = $this->resolveStorefrontAuthority($productId);
        $this->assertEditorDocument($schema, 'schema');

        return $this->assertStorefrontEditorResult(
            $this->requireStorefrontEditor()->saveTemplate(
                $productId,
                $templateId,
                $expectedRevision,
                $name,
                $sectionId,
                $schema,
                $authority['allowedProductIds']
            )
        );
    }

    public function saveStorefrontProduct(
        int $productId,
        string $expectedRevision,
        array $schema
    ): array {
        $authority = $this->resolveStorefrontAuthority($productId);
        $this->assertEditorDocument($schema, 'schema');

        return $this->assertStorefrontEditorResult(
            $this->requireStorefrontEditor()->saveProduct(
                $productId,
                $expectedRevision,
                $schema,
                $authority['allowedProductIds']
            )
        );
    }

    public function enableStorefrontInheritance(int $productId, string $expectedRevision): array
    {
        $authority = $this->resolveStorefrontAuthority($productId);

        return $this->assertStorefrontEditorResult(
            $this->requireStorefrontEditor()->enableInheritance(
                $productId,
                $expectedRevision,
                $authority['allowedProductIds']
            )
        );
    }

    public function deleteStorefrontTemplate(int $productId, string $templateId, int $expectedRevision): array
    {
        $authority = $this->resolveStorefrontAuthority($productId);

        return $this->assertStorefrontEditorResult(
            $this->requireStorefrontEditor()->deleteTemplate(
                $productId,
                $templateId,
                $expectedRevision,
                $authority['allowedProductIds']
            )
        );
    }

    public function loadFormFirstWorkspace(int $productId, int $presetId): array
    {
        $authority = $this->resolvePresetFormAuthority($presetId, $productId);
        $dependencyContract = $this->resolveDependencyContract($presetId, $authority['allowedProductIds']);

        return $this->assertFormFirstEditorResult(
            $this->requireFormFirstAuthoring()->loadFormFirstWorkspace(
                $productId,
                $presetId,
                $authority['allowedProductIds'],
                $dependencyContract
            ),
            $productId,
            $presetId,
            'load',
            $dependencyContract['fingerprint']
        );
    }

    public function saveFormFirstDraft(
        int $productId,
        int $presetId,
        string $expectedAggregateRevision,
        array $formDefinition,
        array $bindingDefinition
    ): array {
        $authority = $this->resolvePresetFormAuthority($presetId, $productId);
        $this->assertSha256($expectedAggregateRevision, 'expectedAggregateRevision');
        $this->assertEditorDocument($formDefinition, 'formDefinition');
        $this->assertEditorDocument($bindingDefinition, 'bindingDefinition');
        $dependencyContract = $this->resolveDependencyContract($presetId, $authority['allowedProductIds']);

        return $this->assertFormFirstEditorResult(
            $this->requireFormFirstAuthoring()->saveFormFirstDraft(
                $productId,
                $presetId,
                $expectedAggregateRevision,
                $formDefinition,
                $bindingDefinition,
                $authority['allowedProductIds'],
                $dependencyContract
            ),
            $productId,
            $presetId,
            'save_draft',
            $dependencyContract['fingerprint']
        );
    }

    public function previewFormFirst(
        int $productId,
        int $presetId,
        array $formDefinition,
        array $bindingDefinition
    ): array {
        $authority = $this->resolvePresetFormAuthority($presetId, $productId);
        $this->assertEditorDocument($formDefinition, 'formDefinition');
        $this->assertEditorDocument($bindingDefinition, 'bindingDefinition');
        $dependencyContract = $this->resolveDependencyContract($presetId, $authority['allowedProductIds']);

        return $this->assertFormFirstEditorResult(
            $this->requireFormFirstAuthoring()->previewFormFirst(
                $productId,
                $presetId,
                $formDefinition,
                $bindingDefinition,
                $authority['allowedProductIds'],
                $dependencyContract
            ),
            $productId,
            $presetId,
            'preview',
            $dependencyContract['fingerprint']
        );
    }

    public function publishFormFirst(
        int $productId,
        int $presetId,
        string $expectedAggregateRevision,
        string $expectedCompileHash
    ): array {
        $authority = $this->resolvePresetFormAuthority($presetId, $productId);
        $this->assertSha256($expectedAggregateRevision, 'expectedAggregateRevision');
        $this->assertSha256($expectedCompileHash, 'expectedCompileHash');
        $dependencyContract = $this->resolveDependencyContract($presetId, $authority['allowedProductIds']);

        return $this->assertFormFirstEditorResult(
            $this->requireFormFirstAuthoring()->publishFormFirst(
                $productId,
                $presetId,
                $expectedAggregateRevision,
                $expectedCompileHash,
                $authority['allowedProductIds'],
                $dependencyContract
            ),
            $productId,
            $presetId,
            'publish',
            $dependencyContract['fingerprint']
        );
    }

    public function rollbackFormFirst(
        int $productId,
        int $presetId,
        string $expectedAggregateRevision,
        int $targetPublishedRevision
    ): array {
        $authority = $this->resolvePresetFormAuthority($presetId, $productId);
        $this->assertSha256($expectedAggregateRevision, 'expectedAggregateRevision');
        if ($targetPublishedRevision < 0) {
            throw new \InvalidArgumentException('targetPublishedRevision must be a non-negative integer');
        }
        $dependencyContract = $this->resolveDependencyContract($presetId, $authority['allowedProductIds']);

        return $this->assertFormFirstEditorResult(
            $this->requireFormFirstAuthoring()->rollbackFormFirst(
                $productId,
                $presetId,
                $expectedAggregateRevision,
                $targetPublishedRevision,
                $authority['allowedProductIds'],
                $dependencyContract
            ),
            $productId,
            $presetId,
            'rollback',
            $dependencyContract['fingerprint']
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
     * catalog-write adapter may intentionally expose a narrower allowlist.
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

        $usage = [];
        foreach ($presetIds as $presetId) {
            $usage[$presetId] = ['productCount' => 0, 'offerCount' => 0];
        }
        $productPresetMap = [];
        $productCursor = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            [
                'IBLOCK_ID' => $productIblockId,
                'ACTIVE' => 'Y',
                'ACTIVE_DATE' => 'Y',
                'PROPERTY_CALC_PRESET' => $presetIds,
            ],
            false,
            false,
            ['ID', 'PROPERTY_CALC_PRESET']
        );
        while ($productCursor && ($row = $productCursor->Fetch())) {
            $productId = (int)($row['ID'] ?? 0);
            $presetId = (int)($row['PROPERTY_CALC_PRESET_VALUE'] ?? 0);
            if ($productId <= 0 || !isset($usage[$presetId])) {
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

    /** @return array<int,array{id:int,name:string,active:bool,sort:int,updatedAt:string}> */
    private function listPresetRows(
        string $query = '',
        string $status = 'all',
        string $sort = 'updated_desc',
        int $page = 1,
        int $pageSize = 50,
        ?int &$serverTotal = null,
        bool &$serverPaged = false
    ): array {
        $rawResult = call_user_func($this->presetListLoader, $query, $status, $sort, $page, $pageSize);
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
            ];
        }
        if (!$serverPaged && $query === '' && !isset($rows[self::FOCUS_PRESET_ID])) {
            $focus = $this->loadSnapshot(self::FOCUS_PRESET_ID);
            $rows[self::FOCUS_PRESET_ID] = [
                'id' => self::FOCUS_PRESET_ID,
                'name' => $focus['presetName'],
                'active' => true,
                'sort' => 500,
                'updatedAt' => '',
            ];
        }
        if (!$serverPaged) {
            ksort($rows, SORT_NUMERIC);
        }
        return array_values($rows);
    }

    private function resolveProductIblockId(): int
    {
        $productIblockId = (int)call_user_func($this->productIblockIdResolver);
        if ($productIblockId <= 0) {
            throw new \RuntimeException('The product iblock is not configured');
        }

        return $productIblockId;
    }

    /**
     * Resolve the current active product scope immediately before every
     * provider call. The allowlist is deliberately not cached between actions:
     * a product disabled or unlinked after a browser catalog load must fail
     * closed on the following read, validation or mutation.
     *
     * @return array{productName:string,allowedProductIds:int[]}
     */
    private function resolveStorefrontAuthority(int $productId): array
    {
        if ($productId <= 0) {
            throw new \InvalidArgumentException('Select a product');
        }
        if (!(bool)call_user_func($this->frontcalcAvailabilityResolver)) {
            throw new \RuntimeException('The storefront calculator module is not installed');
        }

        $snapshot = $this->loadStorefrontSnapshot(self::FOCUS_PRESET_ID);
        $allowedProductIds = [];
        $productName = '';
        foreach ($snapshot['products'] as $product) {
            $allowedProductId = (int)$product['id'];
            $allowedProductIds[] = $allowedProductId;
            if ($allowedProductId === $productId) {
                $productName = (string)$product['name'];
            }
        }
        if ($productName === '') {
            throw new \InvalidArgumentException(
                'Product ' . $productId . ' is not linked to preset ' . self::FOCUS_PRESET_ID
            );
        }

        return [
            'productName' => $productName,
            'allowedProductIds' => $allowedProductIds,
        ];
    }

    /** @return array{presetName:string,allowedProductIds:int[]} */
    private function resolvePresetFormAuthority(int $presetId, int $productId = 0): array
    {
        if ($presetId <= 0 || $productId < 0) {
            throw new \InvalidArgumentException('Preset ID must be positive and product ID cannot be negative');
        }
        if (!(bool)call_user_func($this->frontcalcAvailabilityResolver)) {
            throw new \RuntimeException('The storefront calculator module is not installed');
        }
        $snapshot = $this->loadSnapshot($presetId);
        $allowedProductIds = array_values(array_map(static function (array $product): int {
            return (int)$product['id'];
        }, $snapshot['products']));
        if ($productId > 0 && !in_array($productId, $allowedProductIds, true)) {
            throw new \InvalidArgumentException('Product is not linked to the selected preset');
        }
        return [
            'presetName' => $snapshot['presetName'],
            'allowedProductIds' => $allowedProductIds,
        ];
    }

    private function assertSha256(string $value, string $field): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException($field . ' must be a lowercase SHA-256 revision');
        }
    }

    /** @param int[] $allowedProductIds @return array<string,mixed> */
    private function resolveDependencyContract(int $presetId, array $allowedProductIds): array
    {
        try {
            $contract = call_user_func(
                $this->dependencyContractResolver,
                $presetId,
                $allowedProductIds
            );
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
            'passive_context',
            'stage_inputs',
            'globals',
            'options_mappings',
            'routes',
            'basket',
            'seo_display',
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

    private function isStorefrontEditorAvailable(): bool
    {
        try {
            $this->requireProviderMethods(self::STOREFRONT_EDITOR_METHODS);
            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /** @return object */
    private function requireStorefrontEditor()
    {
        return $this->requireProviderMethods(self::STOREFRONT_EDITOR_METHODS);
    }

    /** @return object */
    private function requireFormFirstAuthoring()
    {
        return $this->requireProviderMethods(array_merge(
            self::STOREFRONT_EDITOR_METHODS,
            self::FORM_FIRST_AUTHORING_METHODS
        ));
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
            $provider = call_user_func($this->frontcalcEditorResolver);
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

    private function assertStorefrontEditorResult($result): array
    {
        if (!is_array($result)
            || (string)($result['contract'] ?? '') !== self::STOREFRONT_EDITOR_CONTRACT) {
            throw new \RuntimeException('The native storefront editor returned an incompatible response');
        }

        return $result;
    }

    private function assertFormFirstEditorResult(
        $result,
        int $expectedProductId,
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
        if (($expectedProductId > 0
                && (!is_array($product)
                    || !is_int($product['id'] ?? null)
                    || (int)$product['id'] !== $expectedProductId))
            || ($expectedProductId === 0 && $product !== null)
            || ($expectedProductId === 0 && (!is_array($result['preset'] ?? null)
                || !is_int($result['preset']['id'] ?? null)
                || (int)$result['preset']['id'] !== $expectedPresetId))
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
