<?php

declare(strict_types=1);

define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('PUBLIC_AJAX_MODE', true);

$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? '');
$requestContentType = strtolower(trim((string)strtok((string)($_SERVER['CONTENT_TYPE'] ?? ''), ';')));
$request = [];
$requestWithJsonNodeKinds = [];
$requestError = null;

$decodeJsonObject = static function ($value): ?array {
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    if ($value === '' || substr($value, 0, 1) !== '{') {
        return null;
    }

    $decoded = json_decode($value, true);
    return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
};
$decodeJsonObjectPreservingNodes = static function ($value): ?array {
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    if ($value === '' || substr($value, 0, 1) !== '{') {
        return null;
    }

    $decoded = json_decode($value);
    return json_last_error() === JSON_ERROR_NONE && $decoded instanceof \stdClass
        ? get_object_vars($decoded)
        : null;
};

if ($requestMethod === 'POST') {
    $isFormRequest = $requestContentType === 'application/x-www-form-urlencoded'
        || array_key_exists('payload', $_POST);

    if ($isFormRequest) {
        if (array_key_exists('payload', $_POST)) {
            $request = $decodeJsonObject($_POST['payload']);
            $requestWithJsonNodeKinds = $decodeJsonObjectPreservingNodes($_POST['payload']) ?? [];
            if ($request === null) {
                $request = [];
                $requestError = 'Request payload must be a JSON object';
            }
        } else {
            $request = $_POST;
        }
    } else {
        $rawRequestBody = (string)file_get_contents('php://input');
        $request = $decodeJsonObject($rawRequestBody);
        $requestWithJsonNodeKinds = $decodeJsonObjectPreservingNodes($rawRequestBody) ?? [];
        if ($request === null) {
            $request = [];
            $requestError = 'Request body must be a JSON object';
        }
    }
}

if (empty($_REQUEST['sessid']) && isset($request['sessid']) && is_scalar($request['sessid'])) {
    $requestSessid = (string)$request['sessid'];
    $_REQUEST['sessid'] = $requestSessid;
    if (empty($_POST['sessid'])) {
        $_POST['sessid'] = $requestSessid;
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Prospektweb\Calc\Services\CalculatorInputMappingService;
use Prospektweb\Calc\Services\CalculatorInputSourceCatalogService;
use Prospektweb\Calc\Services\CatalogOutputMappingService;
use Prospektweb\Calc\Services\ControlCenterEditorsService;
use Prospektweb\Calc\Services\PresetSectionSelectorService;

global $APPLICATION, $USER;

$APPLICATION->RestartBuffer();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

$respond = static function (int $statusCode, array $payload): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    die();
};

if ($requestMethod !== 'POST') {
    header('Allow: POST');
    $respond(405, [
        'success' => false,
        'errorCode' => 'METHOD_NOT_ALLOWED',
        'error' => 'Only POST is allowed',
    ]);
}

if ($requestError !== null) {
    $respond(400, [
        'success' => false,
        'errorCode' => 'INVALID_JSON',
        'error' => $requestError,
    ]);
}

if (!check_bitrix_sessid()) {
    $respond(403, [
        'success' => false,
        'errorCode' => 'INVALID_SESSION',
        'error' => 'Invalid session',
    ]);
}

if (!$USER || !$USER->IsAdmin()) {
    $respond(403, [
        'success' => false,
        'errorCode' => 'ADMIN_REQUIRED',
        'error' => 'Admin access required',
    ]);
}

if (!Loader::includeModule('prospektweb.calc')) {
    $respond(500, [
        'success' => false,
        'errorCode' => 'MODULE_NOT_INSTALLED',
        'error' => 'Module prospektweb.calc is not installed',
    ]);
}

$action = $request['action'] ?? 'catalog';
$service = new ControlCenterEditorsService();
$assertAllowedRequestKeys = static function (array $allowedKeys) use ($request): void {
    foreach (array_keys($request) as $requestKey) {
        if (!is_string($requestKey) || !in_array($requestKey, $allowedKeys, true)) {
            throw new \InvalidArgumentException('Request contains unsupported fields');
        }
    }
};
$parsePositiveInt = static function ($value, string $field): int {
    if (is_int($value)) {
        $parsed = $value;
    } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)) {
        $parsed = (int)$value;
        if ((string)$parsed !== $value) {
            throw new \InvalidArgumentException($field . ' must be a safe positive integer');
        }
    } else {
        throw new \InvalidArgumentException($field . ' must be a safe positive integer');
    }
    if ($parsed <= 0 || $parsed > 9007199254740991) {
        throw new \InvalidArgumentException($field . ' must be a safe positive integer');
    }

    return $parsed;
};
$parseStrictPositiveInt = static function ($value, string $field): int {
    if (!is_int($value) || $value <= 0 || $value > 9007199254740991) {
        throw new \InvalidArgumentException($field . ' must be a safe positive integer');
    }

    return $value;
};
$parseStrictNonNegativeInt = static function ($value, string $field): int {
    if (!is_int($value) || $value < 0 || $value > 9007199254740991) {
        throw new \InvalidArgumentException($field . ' must be a safe non-negative integer');
    }

    return $value;
};
$parseAggregateRevision = static function ($value, string $field): string {
    if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
        throw new \InvalidArgumentException($field . ' must be a lowercase SHA-256 revision');
    }

    return $value;
};
$parseEditorDocument = static function ($value, string $field): array {
    if ($value instanceof \stdClass) {
        $value = get_object_vars($value);
    }
    if (!is_array($value) || $value === []) {
        throw new \InvalidArgumentException($field . ' must be a non-empty object');
    }
    if (array_keys($value) === range(0, count($value) - 1)) {
        throw new \InvalidArgumentException($field . ' must be a non-empty object');
    }
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new \InvalidArgumentException($field . ' must be valid JSON data');
    }
    if (strlen($encoded) > 60000) {
        throw new \InvalidArgumentException($field . ' must not exceed 60000 bytes');
    }

    return $value;
};
$parseInputMappingDocument = static function ($value): array {
    if (!is_array($value) || $value === [] || array_keys($value) === range(0, count($value) - 1)) {
        throw new \InvalidArgumentException('mapping must be a non-empty JSON object');
    }
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || strlen($encoded) > 131072) {
        throw new \InvalidArgumentException('mapping must be valid JSON data not exceeding 131072 bytes');
    }

    return $value;
};
$parseStorefrontId = static function ($value): string {
    if (!is_string($value) || preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/D', $value) !== 1) {
        throw new \InvalidArgumentException('id must be a valid storefront identifier');
    }
    return $value;
};
$parseStorefrontDefinition = static function ($value): array {
    if (!is_array($value) || $value === [] || array_keys($value) === range(0, count($value) - 1)) {
        throw new \InvalidArgumentException('storefront must be a non-empty JSON object');
    }
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || strlen($encoded) > 131072) {
        throw new \InvalidArgumentException('storefront must be valid JSON data not exceeding 131072 bytes');
    }
    return $value;
};
$storefrontRepository = static function () {
    if (!Loader::includeModule('prospektweb.frontcalc')) {
        throw new \RuntimeException('Module prospektweb.frontcalc is not installed');
    }
    $class = '\\Prospektweb\\Frontcalc\\Service\\StorefrontRepository';
    if (!class_exists($class)) {
        throw new \RuntimeException('Storefront vNext repository is unavailable');
    }
    return new $class();
};
$validateStorefrontPresentation = static function (int $presetId, array $definition): void {
    $presentation = is_array($definition['presentation'] ?? null) ? $definition['presentation'] : [];
    $fieldPatches = is_array($presentation['field_patches'] ?? null)
        ? $presentation['field_patches']
        : [];
    if ($fieldPatches === []) {
        if (($definition['active'] ?? false) === true) {
            throw new \InvalidArgumentException('Активная витрина должна изменять представление базовой формы.');
        }
        return;
    }
    if (!Loader::includeModule('prospektweb.frontcalc')) {
        throw new \RuntimeException('Module prospektweb.frontcalc is required to validate storefront presentation');
    }
    $storeClass = '\\Prospektweb\\Frontcalc\\Service\\FormFirstAuthoringStore';
    $projectorClass = '\\Prospektweb\\Frontcalc\\Service\\StorefrontPresentationProjector';
    if (!class_exists($storeClass)
        || !is_callable([$storeClass, 'publishedBundleForPreset'])
        || !class_exists($projectorClass)) {
        throw new \RuntimeException('Published form storefront validation is unavailable');
    }
    $publishedBundle = $storeClass::publishedBundleForPreset($presetId);
    $authoring = is_array($publishedBundle['authoring'] ?? null) ? $publishedBundle['authoring'] : null;
    $snapshot = is_array($publishedBundle['snapshot'] ?? null) ? $publishedBundle['snapshot'] : null;
    if (!is_array($authoring) || !is_array($snapshot)) {
        throw new \InvalidArgumentException('Storefront field patches require an exact published preset form.');
    }
    $publication = is_array($authoring['publication'] ?? null) ? $authoring['publication'] : [];
    $runtimeMeta = is_array($snapshot['_form_first'] ?? null) ? $snapshot['_form_first'] : [];
    if ((int)($publication['revision'] ?? 0) <= 0
        || (int)($publication['revision'] ?? 0) !== (int)($runtimeMeta['publishedRevision'] ?? -1)
        || !is_string($publication['compileHash'] ?? null)
        || !hash_equals((string)$publication['compileHash'], (string)($runtimeMeta['compileHash'] ?? ''))) {
        throw new \RuntimeException('Published preset form changed during storefront validation', 409);
    }
    // The projector is the runtime authority for unknown fields, absent
    // bindings and required/conditionally-required fields hidden by a patch.
    $projected = (new $projectorClass())->apply($snapshot, $authoring, $definition);
    if (($definition['active'] ?? false) === true
        && ($projected['fields'] ?? null) === ($snapshot['fields'] ?? null)) {
        throw new \InvalidArgumentException('Активная витрина не содержит отличий от базовой формы.');
    }
};

try {
    if (!is_string($action)) {
        throw new \InvalidArgumentException('action must be a string');
    }

    if ($action === 'catalog') {
        $assertAllowedRequestKeys(['action', 'sessid']);
        $respond(200, [
            'success' => true,
            'data' => $service->getCatalog(),
        ]);
    }

    if ($action === 'registry') {
        $assertAllowedRequestKeys(['action', 'sessid', 'query', 'status', 'sort', 'page', 'pageSize']);
        $query = $request['query'] ?? '';
        $status = $request['status'] ?? 'all';
        $sort = $request['sort'] ?? 'updated_desc';
        if (!is_string($query) || !is_string($status) || !is_string($sort)) {
            throw new \InvalidArgumentException('Registry query, status and sort must be strings');
        }
        if (strlen($query) > 200) {
            throw new \InvalidArgumentException('Registry query is too long');
        }
        $page = $parseStrictPositiveInt($request['page'] ?? 1, 'page');
        $pageSize = $parseStrictPositiveInt($request['pageSize'] ?? 50, 'pageSize');
        $respond(200, [
            'success' => true,
            'data' => $service->getPresetRegistry($query, $status, $sort, $page, $pageSize),
        ]);
    }

    if ($action === 'preset_load') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $respond(200, [
            'success' => true,
            'data' => $service->loadPresetWorkspace($presetId),
        ]);
    }

    if ($action === 'preset_product_catalog') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId', 'query', 'page', 'pageSize']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $query = $request['query'] ?? '';
        if (!is_string($query)) {
            throw new \InvalidArgumentException('query must be a string');
        }
        $page = $parseStrictPositiveInt($request['page'] ?? 1, 'page');
        $pageSize = $parseStrictPositiveInt($request['pageSize'] ?? 50, 'pageSize');
        $respond(200, [
            'success' => true,
            'data' => $service->getPresetProductCatalog($presetId, $query, $page, $pageSize),
        ]);
    }

    if ($action === 'set_preset_products') {
        $assertAllowedRequestKeys([
            'action',
            'sessid',
            'presetId',
            'productIds',
            'expectedRevision',
            'impactFingerprint',
        ]);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $productIds = $request['productIds'] ?? null;
        $expectedRevision = $request['expectedRevision'] ?? null;
        $impactFingerprint = $request['impactFingerprint'] ?? null;
        if (!is_array($productIds)
            || !is_string($expectedRevision)
            || !is_string($impactFingerprint)) {
            throw new \InvalidArgumentException(
                'productIds, expectedRevision and impactFingerprint are required'
            );
        }
        $normalizedProductIds = [];
        foreach ($productIds as $productId) {
            $normalizedProductIds[] = $parseStrictPositiveInt($productId, 'productId');
        }
        $respond(200, [
            'success' => true,
            'data' => $service->setPresetProducts(
                $presetId,
                $normalizedProductIds,
                $expectedRevision,
                $impactFingerprint
            ),
        ]);
    }

    if ($action === 'preset_products_impact') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId', 'productIds', 'expectedRevision']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $productIds = $request['productIds'] ?? null;
        $expectedRevision = $request['expectedRevision'] ?? null;
        if (!is_array($productIds) || !is_string($expectedRevision)) {
            throw new \InvalidArgumentException('productIds and expectedRevision are required');
        }
        $normalizedProductIds = [];
        foreach ($productIds as $productId) {
            $normalizedProductIds[] = $parseStrictPositiveInt($productId, 'productId');
        }
        $respond(200, [
            'success' => true,
            'data' => $service->previewPresetProductImpact($presetId, $normalizedProductIds, $expectedRevision),
        ]);
    }

    if ($action === 'calculator_input_source_catalog') {
        $assertAllowedRequestKeys(['action', 'sessid', 'preset_id']);
        $presetId = $parseStrictPositiveInt($request['preset_id'] ?? null, 'preset_id');
        $respond(200, [
            'success' => true,
            'data' => (new CalculatorInputSourceCatalogService())->load($presetId),
        ]);
    }

    if ($action === 'calculator_input_mapping_load') {
        $assertAllowedRequestKeys(['action', 'sessid', 'preset_id']);
        $presetId = $parseStrictPositiveInt($request['preset_id'] ?? null, 'preset_id');
        $respond(200, [
            'success' => true,
            'data' => (new CalculatorInputMappingService())->load($presetId),
        ]);
    }

    if ($action === 'calculator_input_mapping_validate') {
        $assertAllowedRequestKeys(['action', 'sessid', 'mapping']);
        $mapping = $parseInputMappingDocument($request['mapping'] ?? null);
        $presetId = $parseStrictPositiveInt($mapping['preset_id'] ?? null, 'mapping.preset_id');
        $respond(200, [
            'success' => true,
            'data' => (new CalculatorInputMappingService())->validate($presetId, $mapping),
        ]);
    }

    if ($action === 'calculator_input_mapping_save') {
        $assertAllowedRequestKeys(['action', 'sessid', 'expected_revision', 'mapping']);
        $mapping = $parseInputMappingDocument($request['mapping'] ?? null);
        $presetId = $parseStrictPositiveInt($mapping['preset_id'] ?? null, 'mapping.preset_id');
        $expectedRevision = $parseStrictNonNegativeInt(
            $request['expected_revision'] ?? null,
            'expected_revision'
        );
        $respond(200, [
            'success' => true,
            'data' => (new CalculatorInputMappingService())->save(
                $presetId,
                $expectedRevision,
                $mapping
            ),
        ]);
    }

    if ($action === 'catalog_output_mapping_load') {
        $assertAllowedRequestKeys(['action', 'sessid', 'preset_id']);
        $presetId = $parseStrictPositiveInt($request['preset_id'] ?? null, 'preset_id');
        $respond(200, [
            'success' => true,
            'data' => (new CatalogOutputMappingService())->load($presetId),
        ]);
    }

    if ($action === 'catalog_output_mapping_validate') {
        $assertAllowedRequestKeys(['action', 'sessid', 'mapping']);
        $mapping = $parseInputMappingDocument($request['mapping'] ?? null);
        $presetId = $parseStrictPositiveInt($mapping['preset_id'] ?? null, 'mapping.preset_id');
        $respond(200, [
            'success' => true,
            'data' => (new CatalogOutputMappingService())->validate($presetId, $mapping),
        ]);
    }

    if ($action === 'catalog_output_mapping_save') {
        $assertAllowedRequestKeys(['action', 'sessid', 'expected_revision', 'mapping']);
        $mapping = $parseInputMappingDocument($request['mapping'] ?? null);
        $presetId = $parseStrictPositiveInt($mapping['preset_id'] ?? null, 'mapping.preset_id');
        $expectedRevision = $parseStrictNonNegativeInt(
            $request['expected_revision'] ?? null,
            'expected_revision'
        );
        $respond(200, [
            'success' => true,
            'data' => (new CatalogOutputMappingService())->save(
                $presetId,
                $expectedRevision,
                $mapping
            ),
        ]);
    }

    if ($action === 'preset_sections') {
        $assertAllowedRequestKeys(['action', 'sessid', 'preset_id']);
        $presetId = $parseStrictPositiveInt($request['preset_id'] ?? null, 'preset_id');
        $respond(200, [
            'success' => true,
            'data' => (new PresetSectionSelectorService())->listSections($presetId),
        ]);
    }

    if ($action === 'preset_section_preview') {
        $assertAllowedRequestKeys(['action', 'sessid', 'preset_id', 'section_id']);
        $presetId = $parseStrictPositiveInt($request['preset_id'] ?? null, 'preset_id');
        $sectionId = $parseStrictPositiveInt($request['section_id'] ?? null, 'section_id');
        $respond(200, [
            'success' => true,
            'data' => (new PresetSectionSelectorService())->preview($presetId, $sectionId),
        ]);
    }

    if ($action === 'storefront_list') {
        $assertAllowedRequestKeys(['action', 'sessid', 'preset_id']);
        $presetId = $parseStrictPositiveInt($request['preset_id'] ?? null, 'preset_id');
        $respond(200, [
            'success' => true,
            'data' => $storefrontRepository()->listStorefronts($presetId),
        ]);
    }

    if ($action === 'storefront_get') {
        $assertAllowedRequestKeys(['action', 'sessid', 'preset_id', 'id']);
        $presetId = $parseStrictPositiveInt($request['preset_id'] ?? null, 'preset_id');
        $storefrontId = $parseStorefrontId($request['id'] ?? null);
        $definition = $storefrontRepository()->get($storefrontId);
        if (!is_array($definition) || (int)($definition['preset_id'] ?? 0) !== $presetId) {
            throw new \InvalidArgumentException('Storefront not found in the requested preset');
        }
        $respond(200, ['success' => true, 'data' => $definition]);
    }

    if ($action === 'storefront_save') {
        $assertAllowedRequestKeys(['action', 'sessid', 'expected_revision', 'storefront']);
        $expectedRevision = $parseStrictNonNegativeInt($request['expected_revision'] ?? null, 'expected_revision');
        $definition = $parseStorefrontDefinition($request['storefront'] ?? null);
        if (!is_int($definition['revision'] ?? null) || (int)$definition['revision'] !== $expectedRevision) {
            throw new \InvalidArgumentException('storefront.revision must match expected_revision');
        }
        $presetId = $parseStrictPositiveInt($definition['preset_id'] ?? null, 'storefront.preset_id');
        $productIds = $definition['product_ids'] ?? null;
        if (!is_array($productIds)
            || array_keys($productIds) !== ($productIds === [] ? [] : range(0, count($productIds) - 1))) {
            throw new \InvalidArgumentException('storefront.product_ids must be a JSON array');
        }
        $repository = $storefrontRepository();
        $storefrontId = $parseStorefrontId($definition['id'] ?? null);
        $savedStorefront = $service->withPresetProductAssignmentLock(
            static function (int $lockedProductIblockId) use (
                $service,
                $presetId,
                $productIds,
                $definition,
                $expectedRevision,
                $storefrontId,
                $validateStorefrontPresentation,
                $repository
            ): array {
                return $service->withPresetMutation(
                    $presetId,
                    [
                        'action' => 'storefront_save',
                        'entity_type' => 'storefront',
                        'entity_id' => $storefrontId,
                        'expected_revision' => $expectedRevision,
                        'product_ids' => $productIds,
                    ],
                    static function () use (
                        $service,
                        $presetId,
                        $productIds,
                        $lockedProductIblockId,
                        $definition,
                        $validateStorefrontPresentation,
                        $repository
                    ): array {
                        $service->assertStorefrontProductsBelongToPreset(
                            $presetId,
                            $productIds,
                            $lockedProductIblockId
                        );
                        $validateStorefrontPresentation($presetId, $definition);
                        $saved = $repository->save($definition);
                        $readBack = $repository->get((string)$saved['id']);
                        if (!is_array($readBack) || $readBack !== $saved) {
                            throw new \RuntimeException('Storefront authoritative save readback does not match the write');
                        }
                        return $readBack;
                    },
                    static function () use ($repository, $storefrontId) {
                        return $repository->get($storefrontId);
                    }
                );
            }
        );
        $respond(200, [
            'success' => true,
            'data' => $savedStorefront,
        ]);
    }

    if ($action === 'storefront_delete') {
        $assertAllowedRequestKeys(['action', 'sessid', 'preset_id', 'id', 'expected_revision']);
        $presetId = $parseStrictPositiveInt($request['preset_id'] ?? null, 'preset_id');
        $storefrontId = $parseStorefrontId($request['id'] ?? null);
        $expectedRevision = $parseStrictPositiveInt($request['expected_revision'] ?? null, 'expected_revision');
        $repository = $storefrontRepository();
        $existing = $repository->get($storefrontId);
        if (!is_array($existing) || (int)($existing['preset_id'] ?? 0) !== $presetId) {
            throw new \InvalidArgumentException('Storefront not found in the requested preset');
        }
        $deleted = $service->withPresetMutation(
            $presetId,
            [
                'action' => 'storefront_delete',
                'entity_type' => 'storefront',
                'entity_id' => $storefrontId,
                'expected_revision' => $expectedRevision,
                'product_ids' => is_array($existing['product_ids'] ?? null) ? $existing['product_ids'] : [],
            ],
            static function () use ($repository, $storefrontId, $expectedRevision): array {
                $deleted = $repository->delete($storefrontId, $expectedRevision);
                if ($repository->get($storefrontId) !== null) {
                    throw new \RuntimeException('Deleted storefront remains present during authoritative readback');
                }
                return $deleted;
            },
            static function () use ($repository, $storefrontId) {
                return $repository->get($storefrontId);
            }
        );
        if ((int)($deleted['preset_id'] ?? 0) !== $presetId) {
            throw new \RuntimeException('Deleted storefront readback does not match the requested preset');
        }
        if ($repository->get($storefrontId) !== null) {
            throw new \RuntimeException('Deleted storefront remains present after authoritative readback');
        }
        $respond(200, [
            'success' => true,
            'data' => [
                'contract' => \Prospektweb\Frontcalc\Service\StorefrontRepository::CONTRACT,
                'preset_id' => $presetId,
                'id' => $storefrontId,
                'deleted' => true,
                'revision' => $expectedRevision,
            ],
        ]);
    }

    if ($action === 'duplicate_preset') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $respond(200, [
            'success' => true,
            'data' => $service->duplicatePreset($presetId),
        ]);
    }

    if ($action === 'set_preset_active') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId', 'expected_revision', 'active']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $expectedRevision = $request['expected_revision'] ?? null;
        $active = $request['active'] ?? null;
        if (!is_string($expectedRevision) || !is_bool($active)) {
            throw new \InvalidArgumentException('expected_revision and active are required');
        }
        $respond(200, [
            'success' => true,
            'data' => $service->setPresetActive($presetId, $expectedRevision, $active),
        ]);
    }

    if ($action === 'create_preset') {
        $assertAllowedRequestKeys(['action', 'sessid', 'name']);
        $name = $request['name'] ?? null;
        if (!is_string($name)) {
            throw new \InvalidArgumentException('name must be a string');
        }
        $respond(200, [
            'success' => true,
            'data' => $service->createStandalonePreset($name),
        ]);
    }

    if ($action === 'validate_calculation_launch') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId', 'offerIds']);
        $presetId = $parsePositiveInt($request['presetId'] ?? null, 'presetId');
        $offerIds = $request['offerIds'] ?? null;
        if (!is_array($offerIds)) {
            throw new \InvalidArgumentException('presetId and offerIds are required');
        }

        $respond(200, [
            'success' => true,
            'data' => $service->validateCalculationLaunch($presetId, $offerIds),
        ]);
    }

    if ($action === 'validate_preset_launch') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId']);
        $presetId = $parsePositiveInt($request['presetId'] ?? null, 'presetId');

        $respond(200, [
            'success' => true,
            'data' => $service->validatePresetLaunch($presetId),
        ]);
    }

    if ($action === 'form_first_load') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');

        $respond(200, [
            'success' => true,
            'data' => $service->loadFormFirstWorkspace($presetId),
        ]);
    }

    if ($action === 'form_first_field_delete_impact') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId', 'fieldId', 'propertyCode']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        if (!is_string($request['fieldId'] ?? null)) {
            throw new \InvalidArgumentException('fieldId must be a string');
        }
        $propertyCode = $request['propertyCode'] ?? null;
        if ($propertyCode !== null && !is_string($propertyCode)) {
            throw new \InvalidArgumentException('propertyCode must be a string or null');
        }

        $respond(200, [
            'success' => true,
            'data' => $service->inspectFormFirstFieldDeletion(
                $presetId,
                (string)$request['fieldId'],
                $propertyCode
            ),
        ]);
    }

    if ($action === 'form_first_save_draft') {
        $assertAllowedRequestKeys([
            'action',
            'sessid',
            'presetId',
            'expectedAggregateRevision',
            'formDefinition',
            'bindingDefinition',
        ]);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $expectedAggregateRevision = $parseAggregateRevision(
            $request['expectedAggregateRevision'] ?? null,
            'expectedAggregateRevision'
        );
        $formDefinition = $parseEditorDocument(
            $requestWithJsonNodeKinds['formDefinition'] ?? $request['formDefinition'] ?? null,
            'formDefinition'
        );
        $bindingDefinition = $parseEditorDocument(
            $requestWithJsonNodeKinds['bindingDefinition'] ?? $request['bindingDefinition'] ?? null,
            'bindingDefinition'
        );

        $respond(200, [
            'success' => true,
            'data' => $service->saveFormFirstDraft(
                $presetId,
                $expectedAggregateRevision,
                $formDefinition,
                $bindingDefinition
            ),
        ]);
    }

    if ($action === 'form_first_preview') {
        $assertAllowedRequestKeys([
            'action',
            'sessid',
            'presetId',
            'formDefinition',
            'bindingDefinition',
        ]);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $formDefinition = $parseEditorDocument(
            $requestWithJsonNodeKinds['formDefinition'] ?? $request['formDefinition'] ?? null,
            'formDefinition'
        );
        $bindingDefinition = $parseEditorDocument(
            $requestWithJsonNodeKinds['bindingDefinition'] ?? $request['bindingDefinition'] ?? null,
            'bindingDefinition'
        );

        $respond(200, [
            'success' => true,
            'data' => $service->previewFormFirst(
                $presetId,
                $formDefinition,
                $bindingDefinition
            ),
        ]);
    }

    if ($action === 'form_first_publish') {
        $assertAllowedRequestKeys([
            'action',
            'sessid',
            'presetId',
            'expectedAggregateRevision',
            'expectedCompileHash',
        ]);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $expectedAggregateRevision = $parseAggregateRevision(
            $request['expectedAggregateRevision'] ?? null,
            'expectedAggregateRevision'
        );
        $expectedCompileHash = $parseAggregateRevision(
            $request['expectedCompileHash'] ?? null,
            'expectedCompileHash'
        );

        $respond(200, [
            'success' => true,
            'data' => $service->publishFormFirst(
                $presetId,
                $expectedAggregateRevision,
                $expectedCompileHash
            ),
        ]);
    }

    if ($action === 'form_first_rollback') {
        $assertAllowedRequestKeys([
            'action',
            'sessid',
            'presetId',
            'expectedAggregateRevision',
            'targetPublishedRevision',
        ]);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $expectedAggregateRevision = $parseAggregateRevision(
            $request['expectedAggregateRevision'] ?? null,
            'expectedAggregateRevision'
        );
        $targetPublishedRevision = $parseStrictNonNegativeInt(
            $request['targetPublishedRevision'] ?? null,
            'targetPublishedRevision'
        );

        $respond(200, [
            'success' => true,
            'data' => $service->rollbackFormFirst(
                $presetId,
                $expectedAggregateRevision,
                $targetPublishedRevision
            ),
        ]);
    }

    $respond(400, [
        'success' => false,
        'errorCode' => 'UNSUPPORTED_ACTION',
        'error' => 'Unsupported action',
    ]);
} catch (\InvalidArgumentException $exception) {
    $respond(422, [
        'success' => false,
        'errorCode' => 'VALIDATION_ERROR',
        'error' => $exception->getMessage(),
    ]);
} catch (\RuntimeException $exception) {
    $respond(409, [
        'success' => false,
        'errorCode' => $exception->getCode() === 409 ? 'REVISION_CONFLICT' : 'EDITOR_UNAVAILABLE',
        'error' => $exception->getMessage(),
    ]);
} catch (\Throwable $exception) {
    $respond(500, [
        'success' => false,
        'errorCode' => 'INTERNAL_ERROR',
        'error' => 'Unable to prepare the editor workspace',
    ]);
}
