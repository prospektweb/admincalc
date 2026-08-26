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
use Prospektweb\Calc\Services\CalculatorCatalogService;
use Prospektweb\Calc\Services\CalculatorInputMappingService;
use Prospektweb\Calc\Services\CalculatorInputSourceCatalogService;
use Prospektweb\Calc\Services\CalculatorMutationAuthorityService;
use Prospektweb\Calc\Services\CalculatorVersionBundleDocumentService;
use Prospektweb\Calc\Services\CalculatorVersionComponentDocumentService;
use Prospektweb\Calc\Services\CalculatorVersionFormDocumentService;
use Prospektweb\Calc\Services\CalculatorVersionRegistryService;
use Prospektweb\Calc\Services\CalculatorVersionSnapshotSourceService;
use Prospektweb\Calc\Services\CatalogOutputMappingService;
use Prospektweb\Calc\Services\ControlCenterEditorsService;
use Prospektweb\Calc\Services\PresetSectionSelectorService;
use Prospektweb\Calc\Services\PresetLifecycleMutationService;

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
$versionBundles = new CalculatorVersionBundleDocumentService();
$versionComponents = new CalculatorVersionComponentDocumentService($versionBundles);
$versionSources = new CalculatorVersionSnapshotSourceService();
$versionRegistry = new CalculatorVersionRegistryService([
    'bundle_meta' => static function (int $presetId, string $versionId) use ($versionBundles): ?array {
        $bundle = $versionBundles->load($presetId, $versionId);
        return $bundle === null ? null : [
            'contentHash' => $bundle['contentHash'],
            'componentHashes' => $bundle['componentHashes'],
        ];
    },
]);
$versionForms = new CalculatorVersionFormDocumentService();
$currentActor = static function () use ($USER): array {
    $id = (int)$USER->GetID();
    $name = trim((string)$USER->GetFullName());
    if ($name === '') {
        $name = trim((string)$USER->GetLogin());
    }
    return ['id' => $id, 'name' => $name !== '' ? $name : 'Пользователь #' . $id];
};
$versionContext = static function (int $presetId) use ($service, $currentActor): array {
    $legacy = $service->loadFormFirstWorkspace($presetId);
    $identity = $service->validatePresetLaunch($presetId);
    return [
        'legacy' => $legacy,
        // Form-first providers may legitimately return a technical fallback
        // such as "Пресет #12740". Version metadata must follow the canonical
        // calculator identity used by the registry and launch boundary.
        'presetName' => (string)$identity['presetName'],
        'actor' => $currentActor(),
    ];
};
$versionState = static function (int $presetId, string $versionId) use ($versionContext, $versionRegistry): array {
    $context = $versionContext($presetId);
    $workspace = $versionRegistry->loadWorkspace(
        $presetId,
        $context['presetName'],
        $context['legacy'],
        $context['actor']
    );
    foreach ($workspace['versions'] as $row) {
        if (($row['versionId'] ?? null) === $versionId) {
            return ['context' => $context, 'registry' => $workspace, 'row' => $row];
        }
    }
    throw new \InvalidArgumentException('Версия калькулятора не найдена.');
};
$versionFormWorkspace = static function (
    int $presetId,
    string $versionId,
    string $operation
) use ($service, $versionForms, $versionState): array {
    $state = $versionState($presetId, $versionId);
    $legacy = $state['context']['legacy'];
    if (($state['row']['status'] ?? null) !== 'DRAFT'
        && !$versionForms->has($presetId, $versionId)
        && (array)($legacy['compile']['diff'] ?? []) !== []) {
        throw new \RuntimeException('У перенесённой опубликованной версии сохранился исполняемый снимок, но её исходная форма недоступна для точного просмотра. Создайте черновик на основе текущего черновика или активируйте новую версию.', 409);
    }
    $document = $versionForms->ensure(
        $presetId,
        $versionId,
        is_string($state['row']['basedOnVersionId'] ?? null) ? $state['row']['basedOnVersionId'] : null,
        $legacy
    );
    $preview = $service->previewFormFirst(
        $presetId,
        $document['formDefinition'],
        $document['bindingDefinition']
    );
    $legacy['operation'] = $operation;
    $legacy['aggregateRevision'] = $document['revision'];
    $legacy['formDefinition'] = $document['formDefinition'];
    $legacy['bindingDefinition'] = $document['bindingDefinition'];
    $legacy['coverage'] = $preview['coverage'];
    $legacy['compile'] = $preview['compile'];
    return $legacy;
};
$ensureVersionBundle = static function (
    int $presetId,
    string $versionId,
    ?string $sourceVersionId,
    array $legacy
) use ($versionBundles, $versionForms, $versionSources): array {
    $existing = $versionBundles->load($presetId, $versionId);
    if ($existing !== null) return $existing;
    if ($sourceVersionId !== null && $versionBundles->has($presetId, $sourceVersionId)) {
        return $versionBundles->copy($presetId, $sourceVersionId, $versionId);
    }
    $formDocument = $versionForms->ensure($presetId, $versionId, $sourceVersionId, $legacy);
    return $versionBundles->save(
        $presetId,
        $versionId,
        $versionSources->capture($presetId, $formDocument)
    );
};
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
    // Front storage uses STOREFRONT_V2_ITEM_<id> in a 50-byte option name:
    // the canonical identifier boundary is therefore exactly 31 ASCII bytes.
    if (!is_string($value) || preg_match('/^[a-z0-9][a-z0-9_.-]{0,30}$/D', $value) !== 1) {
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
        $assertAllowedRequestKeys(['action', 'sessid', 'query', 'status', 'sort', 'page', 'pageSize', 'sectionId']);
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
        $sectionId = array_key_exists('sectionId', $request)
            ? $parseStrictNonNegativeInt($request['sectionId'], 'sectionId')
            : null;
        $respond(200, [
            'success' => true,
            'data' => $service->getPresetRegistry($query, $status, $sort, $page, $pageSize, $sectionId),
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

    if ($action === 'calculator_catalog') {
        $assertAllowedRequestKeys(['action', 'sessid']);
        $respond(200, [
            'success' => true,
            'data' => (new CalculatorCatalogService())->snapshot(),
        ]);
    }

    if ($action === 'calculator_section_create') {
        $assertAllowedRequestKeys(['action', 'sessid', 'name', 'parentId', 'expected_revision']);
        $name = $request['name'] ?? null;
        $expectedRevision = $request['expected_revision'] ?? null;
        if (!is_string($name) || !is_string($expectedRevision)) {
            throw new \InvalidArgumentException('name and expected_revision are required');
        }
        $parentId = $parseStrictNonNegativeInt($request['parentId'] ?? 0, 'parentId');
        $respond(200, [
            'success' => true,
            'data' => (new CalculatorCatalogService())->createSection($name, $parentId, $expectedRevision),
        ]);
    }

    if ($action === 'calculator_section_rename') {
        $assertAllowedRequestKeys(['action', 'sessid', 'sectionId', 'name', 'expected_revision']);
        $sectionId = $parseStrictPositiveInt($request['sectionId'] ?? null, 'sectionId');
        $name = $request['name'] ?? null;
        $expectedRevision = $request['expected_revision'] ?? null;
        if (!is_string($name) || !is_string($expectedRevision)) {
            throw new \InvalidArgumentException('name and expected_revision are required');
        }
        $respond(200, [
            'success' => true,
            'data' => (new CalculatorCatalogService())->renameSection($sectionId, $name, $expectedRevision),
        ]);
    }

    if ($action === 'calculator_section_delete') {
        $assertAllowedRequestKeys(['action', 'sessid', 'sectionId', 'expected_revision']);
        $sectionId = $parseStrictPositiveInt($request['sectionId'] ?? null, 'sectionId');
        $expectedRevision = $request['expected_revision'] ?? null;
        if (!is_string($expectedRevision)) {
            throw new \InvalidArgumentException('expected_revision is required');
        }
        $respond(200, [
            'success' => true,
            'data' => (new CalculatorCatalogService())->deleteSection($sectionId, $expectedRevision),
        ]);
    }

    if ($action === 'calculator_move') {
        $assertAllowedRequestKeys(['action', 'sessid', 'calculatorId', 'sectionId', 'expected_revision']);
        $calculatorId = $parseStrictPositiveInt($request['calculatorId'] ?? null, 'calculatorId');
        $sectionId = $parseStrictNonNegativeInt($request['sectionId'] ?? 0, 'sectionId');
        $expectedRevision = $request['expected_revision'] ?? null;
        if (!is_string($expectedRevision)) {
            throw new \InvalidArgumentException('expected_revision is required');
        }
        $respond(200, [
            'success' => true,
            'data' => (new CalculatorCatalogService())->moveCalculator(
                $calculatorId,
                $sectionId,
                $expectedRevision
            ),
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
                    static function (?CalculatorMutationAuthorityService $calculatorAuthority = null) use (
                        $service,
                        $presetId,
                        $productIds,
                        $lockedProductIblockId,
                        $definition,
                        $validateStorefrontPresentation,
                        $repository
                    ): array {
                        $lockedIblockIds = $calculatorAuthority instanceof CalculatorMutationAuthorityService
                            ? $calculatorAuthority->lockedIblockIds()
                            : [];
                        $service->assertStorefrontProductsBelongToPreset(
                            $presetId,
                            $productIds,
                            $lockedProductIblockId,
                            (int)($lockedIblockIds['CALC_PRESETS'] ?? 0)
                        );
                        $validateStorefrontPresentation($presetId, $definition);
                        $saved = $repository->save($definition);
                        $readBack = $repository->get((string)$saved['id']);
                        return ControlCenterEditorsService::assertStorefrontAuthoritativeReadback(
                            $saved,
                            $readBack
                        );
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
        $assertAllowedRequestKeys(['action', 'sessid', 'name', 'sectionId']);
        $name = $request['name'] ?? null;
        if (!is_string($name)) {
            throw new \InvalidArgumentException('name must be a string');
        }
        $sectionId = $parseStrictNonNegativeInt($request['sectionId'] ?? 0, 'sectionId');
        $respond(200, [
            'success' => true,
            'data' => $service->createStandalonePreset($name, $sectionId),
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

    if ($action === 'version_registry') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $context = $versionContext($presetId);
        $respond(200, [
            'success' => true,
            'data' => $versionRegistry->loadWorkspace(
                $presetId,
                $context['presetName'],
                $context['legacy'],
                $context['actor']
            ),
        ]);
    }

    if ($action === 'version_create') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId', 'expectedRegistryRevision', 'name', 'basedOnVersionId']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $expectedRegistryRevision = $parseAggregateRevision($request['expectedRegistryRevision'] ?? null, 'expectedRegistryRevision');
        $name = $request['name'] ?? null;
        $basedOnVersionId = $request['basedOnVersionId'] ?? null;
        if (!is_string($name) || ($basedOnVersionId !== null && !is_string($basedOnVersionId))) {
            throw new \InvalidArgumentException('name and optional basedOnVersionId are required');
        }
        $context = $versionContext($presetId);
        $before = $versionRegistry->loadWorkspace(
            $presetId,
            $context['presetName'],
            $context['legacy'],
            $context['actor']
        );
        $beforeIds = array_fill_keys(array_map(static fn(array $row): string => (string)$row['versionId'], $before['versions']), true);
        if ($basedOnVersionId !== null) {
            $baseRow = null;
            foreach ($before['versions'] as $row) {
                if (($row['versionId'] ?? null) === $basedOnVersionId) {
                    $baseRow = $row;
                    break;
                }
            }
            if (is_array($baseRow)
                && ($baseRow['status'] ?? null) !== 'DRAFT'
                && !$versionForms->has($presetId, $basedOnVersionId)
                && (array)($context['legacy']['compile']['diff'] ?? []) !== []) {
                throw new \InvalidArgumentException('Нельзя точно клонировать перенесённую опубликованную версию: её исходная форма отсутствует. Выберите текущий черновик.');
            }
        }
        $createdWorkspace = $versionRegistry->createDraft(
            $presetId,
            $expectedRegistryRevision,
            $name,
            $basedOnVersionId,
            $context['presetName'],
            $context['legacy'],
            $context['actor']
        );
        $createdRow = null;
        foreach ($createdWorkspace['versions'] as $row) {
            if (!isset($beforeIds[(string)$row['versionId']])) {
                $createdRow = $row;
                break;
            }
        }
        if (!is_array($createdRow)) {
            throw new \RuntimeException('Сервер не определил созданный черновик версии.');
        }
        $versionForms->ensure(
            $presetId,
            (string)$createdRow['versionId'],
            is_string($createdRow['basedOnVersionId'] ?? null) ? $createdRow['basedOnVersionId'] : null,
            $context['legacy']
        );
        $ensureVersionBundle(
            $presetId,
            (string)$createdRow['versionId'],
            is_string($createdRow['basedOnVersionId'] ?? null) ? $createdRow['basedOnVersionId'] : null,
            $context['legacy']
        );
        $createdWorkspace = $versionRegistry->loadWorkspace(
            $presetId,
            $context['presetName'],
            $context['legacy'],
            $context['actor']
        );
        $respond(200, [
            'success' => true,
            'data' => $createdWorkspace,
        ]);
    }

    if ($action === 'version_rename') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId', 'expectedRegistryRevision', 'versionId', 'name']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $expectedRegistryRevision = $parseAggregateRevision($request['expectedRegistryRevision'] ?? null, 'expectedRegistryRevision');
        $versionId = $request['versionId'] ?? null;
        $name = $request['name'] ?? null;
        if (!is_string($versionId) || !is_string($name)) {
            throw new \InvalidArgumentException('versionId and name are required');
        }
        $context = $versionContext($presetId);
        $respond(200, [
            'success' => true,
            'data' => $versionRegistry->renameVersion(
                $presetId,
                $expectedRegistryRevision,
                $versionId,
                $name,
                $context['presetName'],
                $context['legacy'],
                $context['actor']
            ),
        ]);
    }

    if ($action === 'version_delete_draft') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId', 'expectedRegistryRevision', 'versionId']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $expectedRegistryRevision = $parseAggregateRevision($request['expectedRegistryRevision'] ?? null, 'expectedRegistryRevision');
        $versionId = $request['versionId'] ?? null;
        if (!is_string($versionId)) {
            throw new \InvalidArgumentException('versionId is required');
        }
        $context = $versionContext($presetId);
        $nextWorkspace = $versionRegistry->deleteDraft(
            $presetId,
            $expectedRegistryRevision,
            $versionId,
            $context['presetName'],
            $context['legacy'],
            $context['actor']
        );
        $versionForms->delete($presetId, $versionId);
        $versionBundles->delete($presetId, $versionId);
        $respond(200, [
            'success' => true,
            'data' => $nextWorkspace,
        ]);
    }

    if ($action === 'version_archive' || $action === 'version_restore') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId', 'expectedRegistryRevision', 'versionId']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $expectedRegistryRevision = $parseAggregateRevision($request['expectedRegistryRevision'] ?? null, 'expectedRegistryRevision');
        $versionId = $request['versionId'] ?? null;
        if (!is_string($versionId)) {
            throw new \InvalidArgumentException('versionId is required');
        }
        $context = $versionContext($presetId);
        $respond(200, [
            'success' => true,
            'data' => $versionRegistry->archivePublished(
                $presetId,
                $expectedRegistryRevision,
                $versionId,
                $action === 'version_restore',
                $context['presetName'],
                $context['legacy'],
                $context['actor']
            ),
        ]);
    }

    if ($action === 'version_form_load') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId', 'versionId']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $versionId = $request['versionId'] ?? null;
        if (!is_string($versionId)) {
            throw new \InvalidArgumentException('versionId is required');
        }
        $respond(200, [
            'success' => true,
            'data' => $versionFormWorkspace($presetId, $versionId, 'load'),
        ]);
    }

    if ($action === 'version_component_load') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId', 'versionId', 'component']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $versionId = $request['versionId'] ?? null;
        $component = $request['component'] ?? null;
        if (!is_string($versionId) || !is_string($component)) {
            throw new \InvalidArgumentException('versionId and component are required');
        }
        $versionState($presetId, $versionId);
        $respond(200, [
            'success' => true,
            'data' => $versionComponents->load($presetId, $versionId, $component),
        ]);
    }

    if ($action === 'version_component_save_draft') {
        $assertAllowedRequestKeys([
            'action', 'sessid', 'presetId', 'versionId', 'component',
            'expectedContentHash', 'expectedComponentHash', 'document',
        ]);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $versionId = $request['versionId'] ?? null;
        $component = $request['component'] ?? null;
        $expectedContentHash = $request['expectedContentHash'] ?? null;
        $expectedComponentHash = $request['expectedComponentHash'] ?? null;
        $document = $request['document'] ?? null;
        if (!is_string($versionId)
            || !is_string($component)
            || !is_string($expectedContentHash)
            || !is_string($expectedComponentHash)
            || !is_array($document)) {
            throw new \InvalidArgumentException('Version component draft request is incomplete');
        }
        $state = $versionState($presetId, $versionId);
        if (($state['row']['status'] ?? null) !== 'DRAFT') {
            throw new \InvalidArgumentException('Изменять можно только компонент черновика версии.');
        }
        $respond(200, [
            'success' => true,
            'data' => $versionComponents->saveDraft(
                $presetId,
                $versionId,
                $component,
                $expectedContentHash,
                $expectedComponentHash,
                $document
            ),
        ]);
    }

    if ($action === 'version_logic_launch') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId', 'versionId', 'mode']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $versionId = $request['versionId'] ?? null;
        $mode = $request['mode'] ?? null;
        if (!is_string($versionId) || !is_string($mode) || !in_array($mode, ['edit', 'readonly'], true)) {
            throw new \InvalidArgumentException('Version logic launch context is invalid');
        }
        $state = $versionState($presetId, $versionId);
        $isDraft = ($state['row']['status'] ?? null) === 'DRAFT';
        if (($mode === 'edit') !== $isDraft) {
            throw new \InvalidArgumentException($isDraft
                ? 'Черновик логики должен открываться в режиме редактирования.'
                : 'Опубликованная логика доступна только для просмотра.');
        }
        $bundle = $ensureVersionBundle(
            $presetId,
            $versionId,
            is_string($state['row']['basedOnVersionId'] ?? null) ? $state['row']['basedOnVersionId'] : null,
            $state['context']['legacy']
        );
        $logic = $bundle['documents']['logic'];
        $workingPresetId = (int)($logic['workingPresetId'] ?? 0);
        $workingVersionId = (string)($logic['workingVersionId'] ?? '');
        if ($isDraft && ($workingPresetId <= 0 || $workingVersionId !== $versionId)) {
            $sourcePresetId = $workingPresetId > 0 ? $workingPresetId : $presetId;
            $clone = (new PresetLifecycleMutationService())->duplicatePreset($sourcePresetId);
            $workingPresetId = (int)($clone['newPresetId'] ?? 0);
            if ($workingPresetId <= 0) {
                throw new \RuntimeException('Не удалось создать изолированный граф логики черновика.', 409);
            }
            $logic = $versionSources->captureLogic($workingPresetId, $presetId, $versionId);
            $saved = $versionComponents->saveDraft(
                $presetId,
                $versionId,
                'logic',
                (string)$bundle['contentHash'],
                (string)$bundle['componentHashes']['logic'],
                $logic
            );
            $bundle['contentHash'] = $saved['contentHash'];
            $bundle['componentHashes']['logic'] = $saved['componentHash'];
        }
        if ($workingPresetId <= 0 || ($workingVersionId !== $versionId && !$isDraft)) {
            throw new \RuntimeException(
                'У этой исторической версии нет отдельного графа логики для точного просмотра. Создайте черновик на её основе.',
                409
            );
        }
        $respond(200, [
            'success' => true,
            'data' => [
                'presetId' => $presetId,
                'focusPresetId' => $workingPresetId,
                'presetName' => (string)$state['context']['presetName'],
                'versionId' => $versionId,
                'mode' => $mode,
                'contentHash' => (string)$bundle['contentHash'],
                'logicHash' => (string)$bundle['componentHashes']['logic'],
            ],
        ]);
    }

    if ($action === 'version_logic_sync') {
        $assertAllowedRequestKeys([
            'action', 'sessid', 'presetId', 'versionId', 'workingPresetId',
            'expectedContentHash', 'expectedLogicHash',
        ]);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $workingPresetId = $parseStrictPositiveInt($request['workingPresetId'] ?? null, 'workingPresetId');
        $versionId = $request['versionId'] ?? null;
        $expectedContentHash = $request['expectedContentHash'] ?? null;
        $expectedLogicHash = $request['expectedLogicHash'] ?? null;
        if (!is_string($versionId) || !is_string($expectedContentHash) || !is_string($expectedLogicHash)) {
            throw new \InvalidArgumentException('Version logic sync context is incomplete');
        }
        $state = $versionState($presetId, $versionId);
        if (($state['row']['status'] ?? null) !== 'DRAFT') {
            throw new \InvalidArgumentException('Синхронизировать можно только логику черновика.');
        }
        $current = $versionComponents->load($presetId, $versionId, 'logic');
        if ((int)($current['document']['workingPresetId'] ?? 0) !== $workingPresetId
            || (string)($current['document']['workingVersionId'] ?? '') !== $versionId) {
            throw new \RuntimeException('Редактор логики не принадлежит выбранному черновику.', 409);
        }
        $respond(200, [
            'success' => true,
            'data' => $versionComponents->saveDraft(
                $presetId,
                $versionId,
                'logic',
                $expectedContentHash,
                $expectedLogicHash,
                $versionSources->captureLogic($workingPresetId, $presetId, $versionId)
            ),
        ]);
    }

    if ($action === 'version_form_save_draft') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId', 'versionId', 'expectedAggregateRevision', 'formDefinition', 'bindingDefinition']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $versionId = $request['versionId'] ?? null;
        $expectedVersionRevision = $parseAggregateRevision($request['expectedAggregateRevision'] ?? null, 'expectedAggregateRevision');
        if (!is_string($versionId)
            || !is_array($request['formDefinition'] ?? null)
            || !is_array($request['bindingDefinition'] ?? null)) {
            throw new \InvalidArgumentException('versionId, formDefinition and bindingDefinition are required');
        }
        $state = $versionState($presetId, $versionId);
        if (($state['row']['status'] ?? null) !== 'DRAFT') {
            throw new \InvalidArgumentException('Редактировать можно только черновик версии.');
        }
        $service->previewFormFirst(
            $presetId,
            $request['formDefinition'],
            $request['bindingDefinition']
        );
        $versionForms->ensure(
            $presetId,
            $versionId,
            is_string($state['row']['basedOnVersionId'] ?? null) ? $state['row']['basedOnVersionId'] : null,
            $state['context']['legacy']
        );
        // Assemble and validate the exact six-component bundle before advancing
        // the form CAS revision. A source/capture failure must not leave the UI
        // with a successfully saved form followed by a stale-revision conflict.
        $prospectiveForm = [
            'contract' => CalculatorVersionFormDocumentService::CONTRACT,
            'formDefinition' => $request['formDefinition'],
            'bindingDefinition' => $request['bindingDefinition'],
        ];
        $existingBundle = $versionBundles->load($presetId, $versionId);
        if ($existingBundle !== null) {
            $components = $existingBundle['documents'];
        } else {
            $sourceVersionId = is_string($state['row']['basedOnVersionId'] ?? null)
                ? $state['row']['basedOnVersionId']
                : null;
            $sourceBundle = $sourceVersionId !== null
                ? $versionBundles->load($presetId, $sourceVersionId)
                : null;
            $components = $sourceBundle !== null
                ? $sourceBundle['documents']
                : $versionSources->capture($presetId, $prospectiveForm);
        }
        $components['form'] = $prospectiveForm;
        $versionBundles->inspect($components);
        $savedForm = $versionForms->saveDraft(
            $presetId,
            $versionId,
            $expectedVersionRevision,
            $request['formDefinition'],
            $request['bindingDefinition']
        );
        $components['form'] = [
            'contract' => CalculatorVersionFormDocumentService::CONTRACT,
            'formDefinition' => $savedForm['formDefinition'],
            'bindingDefinition' => $savedForm['bindingDefinition'],
        ];
        $versionBundles->save($presetId, $versionId, $components);
        $respond(200, [
            'success' => true,
            'data' => $versionFormWorkspace($presetId, $versionId, 'save_draft'),
        ]);
    }

    if ($action === 'version_publish_activate') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId', 'expectedRegistryRevision', 'versionId']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $expectedRegistryRevision = $parseAggregateRevision($request['expectedRegistryRevision'] ?? null, 'expectedRegistryRevision');
        $versionId = $request['versionId'] ?? null;
        if (!is_string($versionId)) {
            throw new \InvalidArgumentException('versionId is required');
        }
        $context = $versionContext($presetId);
        $legacy = $context['legacy'];
        $state = $versionState($presetId, $versionId);
        if (($state['row']['status'] ?? null) !== 'DRAFT') {
            throw new \InvalidArgumentException('Опубликовать и активировать можно только черновик версии.');
        }
        $document = $versionForms->ensure(
            $presetId,
            $versionId,
            is_string($state['row']['basedOnVersionId'] ?? null) ? $state['row']['basedOnVersionId'] : null,
            $legacy
        );
        $preview = $service->previewFormFirst(
            $presetId,
            $document['formDefinition'],
            $document['bindingDefinition']
        );
        if (($preview['coverage']['valid'] ?? false) !== true
            || ($preview['compile']['valid'] ?? false) !== true
            || !is_string($preview['compile']['hash'] ?? null)) {
            throw new \InvalidArgumentException('Черновик не прошёл проверку формы и связей. Перейдите к исправлению ошибок.');
        }
        // The draft already owns all six components. Publication validates
        // and seals that exact bundle; recapturing shared runtime here would
        // silently discard version-scoped storefront/mapping/product edits.
        $storedBundle = $ensureVersionBundle(
            $presetId,
            $versionId,
            is_string($state['row']['basedOnVersionId'] ?? null) ? $state['row']['basedOnVersionId'] : null,
            $legacy
        );
        $versionBundles->inspect($storedBundle['documents']);
        $effectiveNow = $versionBundles->inspect($versionSources->capture($presetId, $document));
        $drifted = [];
        foreach (CalculatorVersionBundleDocumentService::COMPONENTS as $component) {
            if ($component === 'form') continue;
            if (!hash_equals(
                (string)$storedBundle['componentHashes'][$component],
                (string)$effectiveNow['componentHashes'][$component]
            )) {
                $drifted[] = $component;
            }
        }
        if ($drifted !== []) {
            throw new \RuntimeException(
                'Публикация остановлена: выбранный черновик отличается от рабочего runtime в компонентах '
                . implode(', ', $drifted)
                . '. Полная материализация bundle будет добавлена отдельным этапом; смешанная версия не будет активирована.',
                409
            );
        }
        $respond(200, [
            'success' => true,
            'data' => $versionRegistry->coordinatedPublishAndActivateDraft(
                $presetId,
                $expectedRegistryRevision,
                $versionId,
                $context['presetName'],
                $legacy,
                $context['actor'],
                static function () use ($service, $presetId, $legacy, $preview, $document): array {
                    $saved = $service->saveFormFirstDraft(
                        $presetId,
                        (string)$legacy['aggregateRevision'],
                        $document['formDefinition'],
                        $document['bindingDefinition']
                    );
                    return $service->publishFormFirst(
                        $presetId,
                        (string)$saved['aggregateRevision'],
                        (string)$preview['compile']['hash']
                    );
                }
            ),
        ]);
    }

    if ($action === 'version_activate') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId', 'expectedRegistryRevision', 'versionId']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $expectedRegistryRevision = $parseAggregateRevision($request['expectedRegistryRevision'] ?? null, 'expectedRegistryRevision');
        $versionId = $request['versionId'] ?? null;
        if (!is_string($versionId)) {
            throw new \InvalidArgumentException('versionId is required');
        }
        $state = $versionState($presetId, $versionId);
        if (($state['row']['status'] ?? null) !== 'PUBLISHED') {
            throw new \InvalidArgumentException('Повторно активировать можно только опубликованную версию.');
        }
        $context = $state['context'];
        $legacy = $context['legacy'];
        if (!$versionForms->has($presetId, $versionId)) {
            throw new \RuntimeException('Точный документ этой перенесённой версии отсутствует; повторная активация недоступна.', 409);
        }
        $document = $versionForms->ensure($presetId, $versionId, null, $legacy);
        $storedBundle = $versionBundles->load($presetId, $versionId);
        if ($storedBundle === null) {
            throw new \RuntimeException('У версии отсутствует полный снимок формы, логики, витрин, сопоставлений и товаров.', 409);
        }
        $versionBundles->inspect($storedBundle['documents']);
        $effectiveNow = $versionBundles->inspect($versionSources->capture($presetId, $document));
        $drifted = [];
        foreach (CalculatorVersionBundleDocumentService::COMPONENTS as $component) {
            if ($component === 'form') continue;
            if (!hash_equals(
                (string)$storedBundle['componentHashes'][$component],
                (string)$effectiveNow['componentHashes'][$component]
            )) {
                $drifted[] = $component;
            }
        }
        if ($drifted !== []) {
            throw new \RuntimeException(
                'Безопасная активация остановлена: runtime отличается от снимка версии в компонентах '
                . implode(', ', $drifted)
                . '. Материализация старого полного bundle ещё не выполнена; смешанная версия не будет опубликована.',
                409
            );
        }
        $preview = $service->previewFormFirst($presetId, $document['formDefinition'], $document['bindingDefinition']);
        if (($preview['coverage']['valid'] ?? false) !== true
            || ($preview['compile']['valid'] ?? false) !== true
            || !is_string($preview['compile']['hash'] ?? null)) {
            throw new \InvalidArgumentException('Версия больше не проходит проверку с текущими зависимостями калькулятора.');
        }
        $respond(200, [
            'success' => true,
            'data' => $versionRegistry->coordinatedActivatePublished(
                $presetId,
                $expectedRegistryRevision,
                $versionId,
                $context['presetName'],
                $legacy,
                $context['actor'],
                static function () use ($service, $presetId, $legacy, $preview, $document): array {
                    $saved = $service->saveFormFirstDraft(
                        $presetId,
                        (string)$legacy['aggregateRevision'],
                        $document['formDefinition'],
                        $document['bindingDefinition']
                    );
                    return $service->publishFormFirst(
                        $presetId,
                        (string)$saved['aggregateRevision'],
                        (string)$preview['compile']['hash']
                    );
                }
            ),
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
