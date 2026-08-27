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
use Prospektweb\Calc\Calculator\InitPayloadService;
use Prospektweb\Calc\Services\CalculatorCatalogService;
use Prospektweb\Calc\Services\CalculatorInputMappingService;
use Prospektweb\Calc\Services\CalculatorInputSourceCatalogService;
use Prospektweb\Calc\Services\CalculatorMutationAuthorityService;
use Prospektweb\Calc\Services\CalculatorVersionBundleDocumentService;
use Prospektweb\Calc\Services\CalculatorVersionComponentDocumentService;
use Prospektweb\Calc\Services\CalculatorVersionFormDocumentService;
use Prospektweb\Calc\Services\CalculatorVersionRegistryService;
use Prospektweb\Calc\Services\CalculatorVersionRuntimePublicationService;
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

if (!Loader::includeModule('iblock')) {
    $respond(500, [
        'success' => false,
        'errorCode' => 'IBLOCK_MODULE_NOT_INSTALLED',
        'error' => 'Module iblock is not installed',
    ]);
}

$action = $request['action'] ?? 'catalog';
$service = new ControlCenterEditorsService();
$versionBundles = new CalculatorVersionBundleDocumentService();
$versionComponents = new CalculatorVersionComponentDocumentService($versionBundles);
$versionSources = new CalculatorVersionSnapshotSourceService();
$versionRuntimePublications = new CalculatorVersionRuntimePublicationService($versionBundles);
$versionForms = new CalculatorVersionFormDocumentService();
$versionRegistry = new CalculatorVersionRegistryService([
    'bundle_meta' => static function (int $presetId, string $versionId) use ($versionBundles): ?array {
        $bundle = $versionBundles->load($presetId, $versionId);
        return $bundle === null ? null : [
            'contentHash' => $bundle['contentHash'],
            'componentHashes' => $bundle['componentHashes'],
            'readiness' => $bundle['readiness'],
        ];
    },
    'delete_version_documents' => static function (
        int $presetId,
        string $versionId
    ) use ($versionBundles, $versionForms): void {
        $bundle = $versionBundles->load($presetId, $versionId);
        $logic = is_array($bundle['documents']['logic'] ?? null)
            ? $bundle['documents']['logic']
            : [];
        $workingPresetId = (int)($logic['workingPresetId'] ?? 0);
        $workingVersionId = (string)($logic['workingVersionId'] ?? '');
        if ($workingPresetId > 0
            && $workingPresetId !== $presetId
            && hash_equals($versionId, $workingVersionId)) {
            (new PresetLifecycleMutationService())->deleteVersionWorkingPreset(
                $workingPresetId,
                $presetId,
                $versionId
            );
        }
        $versionForms->delete($presetId, $versionId);
        $versionBundles->delete($presetId, $versionId);
    },
]);
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
$assertVersionEditable = static function (array $state): void {
    if (($state['row']['status'] ?? null) === 'ARCHIVED') {
        throw new \InvalidArgumentException('Скрытая версия доступна только для просмотра. Верните её в список перед изменением.');
    }
};
$versionFormWorkspace = static function (
    int $presetId,
    string $versionId,
    string $operation
) use ($service, $versionForms, $versionState): array {
    $state = $versionState($presetId, $versionId);
    $legacy = $state['context']['legacy'];
    $isEditable = ($state['row']['status'] ?? null) !== 'ARCHIVED';
    if (!$versionForms->has($presetId, $versionId)
        && (array)($legacy['compile']['diff'] ?? []) !== []) {
        throw new \RuntimeException('У перенесённой версии сохранился исполняемый снимок, но её исходная форма недоступна для точного редактирования. Создайте версию на основе версии с полным bundle.', 409);
    }
    if (!$isEditable && !$versionForms->has($presetId, $versionId)) {
        throw new \RuntimeException('У скрытой версии нет точного документа формы для просмотра.', 409);
    }
    $document = $versionForms->ensure(
        $presetId,
        $versionId,
        is_string($state['row']['basedOnVersionId'] ?? null) ? $state['row']['basedOnVersionId'] : null,
        $legacy,
        $isEditable
    );
    $preview = $service->previewVersionFormFirst(
        $presetId,
        $document['formDefinition'],
        $document['bindingDefinition']
    );
    $legacy['operation'] = $operation;
    $legacy['aggregateRevision'] = $document['revision'];
    $legacy['formDefinition'] = $document['formDefinition'];
    $legacy['bindingDefinition'] = $document['bindingDefinition'];
    $legacy['published'] = null;
    $legacy['history'] = [];
    $legacy['dependencyFingerprint'] = $preview['dependencyFingerprint'];
    $legacy['coverage'] = $preview['coverage'];
    $legacy['compile'] = $preview['compile'];
    return $legacy;
};
$ensureVersionBundle = static function (
    int $presetId,
    string $versionId,
    ?string $sourceVersionId,
    array $legacy,
    bool $allowRebuild = false
) use ($versionBundles, $versionForms, $versionSources): array {
    $existing = $versionBundles->load($presetId, $versionId);
    if ($existing !== null) {
        if (($existing['readiness']['complete'] ?? false) === true || !$allowRebuild) {
            return $existing;
        }
        $documents = $existing['documents'];
        // Legacy bundles may lack the two v2 components. Existing versioned
        // documents, including an intentionally incomplete blank version,
        // must never be overwritten from mutable live authorities.
        if (!is_array($documents['publicationMetadata'] ?? null)) {
            $documents['publicationMetadata'] = $versionSources->publicationMetadata($presetId);
        }
        if (!is_array($documents['commercialPolicy'] ?? null)) {
            $documents['commercialPolicy'] = $versionSources->commercialPolicy($presetId);
        }
        return $versionBundles->save($presetId, $versionId, $documents);
    }
    if ($sourceVersionId !== null && $versionBundles->has($presetId, $sourceVersionId)) {
        $source = $versionBundles->load($presetId, $sourceVersionId);
        if ($source === null) {
            throw new \RuntimeException('Полный снимок исходной версии отсутствует.', 409);
        }
        $documents = $source['documents'];
        if (($source['readiness']['complete'] ?? false) !== true) {
            if (!$allowRebuild) {
                throw new \RuntimeException('Исходная версия требует пересборки полного bundle.', 409);
            }
            if (!is_array($documents['publicationMetadata'] ?? null)) {
                $documents['publicationMetadata'] = $versionSources->publicationMetadata($presetId);
            }
            if (!is_array($documents['commercialPolicy'] ?? null)) {
                $documents['commercialPolicy'] = $versionSources->commercialPolicy($presetId);
            }
        }
        return $versionBundles->save($presetId, $versionId, $documents);
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
$presetElementExists = static function (int $presetId): bool {
    if ($presetId <= 0 || !Loader::includeModule('iblock')) {
        return false;
    }
    $row = \CIBlockElement::GetList(
        [],
        ['ID' => $presetId],
        false,
        ['nTopCount' => 1],
        ['ID']
    )->Fetch();
    return is_array($row) && (int)($row['ID'] ?? 0) === $presetId;
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
    // An active storefront may intentionally reuse the base presentation and
    // exist only as an explicit product assignment target.
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
        $listing = $storefrontRepository()->listStorefronts($presetId);
        $settingsClass = '\\Prospektweb\\Frontcalc\\Service\\PublicCalculatorCatalogService';
        if (!class_exists($settingsClass)) {
            throw new \RuntimeException('Public calculator catalog settings are unavailable.');
        }
        $listing['base_public'] = (bool)(new $settingsClass())->settings($presetId)['show_base'];
        $respond(200, [
            'success' => true,
            'data' => $listing,
        ]);
    }

    if ($action === 'storefront_base_public_save') {
        $assertAllowedRequestKeys(['action', 'sessid', 'preset_id', 'show_base']);
        $presetId = $parseStrictPositiveInt($request['preset_id'] ?? null, 'preset_id');
        if (!is_bool($request['show_base'] ?? null)) {
            throw new \InvalidArgumentException('show_base must be boolean');
        }
        if (!Loader::includeModule('prospektweb.frontcalc')) {
            throw new \RuntimeException('Module prospektweb.frontcalc is required.');
        }
        $settingsClass = '\\Prospektweb\\Frontcalc\\Service\\PublicCalculatorCatalogService';
        $respond(200, [
            'success' => true,
            'data' => (new $settingsClass())->saveSettings($presetId, (bool)$request['show_base']),
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

    if ($action === 'preset_delete_preview') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $respond(200, [
            'success' => true,
            'data' => (new PresetLifecycleMutationService())->previewCascadeDelete($presetId),
        ]);
    }

    if ($action === 'preset_delete_cascade') {
        $assertAllowedRequestKeys([
            'action',
            'sessid',
            'presetId',
            'expectedDeletionRevision',
            'confirmationName',
        ]);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $expectedDeletionRevision = $request['expectedDeletionRevision'] ?? null;
        $confirmationName = $request['confirmationName'] ?? null;
        if (!is_string($expectedDeletionRevision) || !is_string($confirmationName)) {
            throw new \InvalidArgumentException('Deletion revision and exact calculator name are required.');
        }
        $respond(200, [
            'success' => true,
            'data' => (new PresetLifecycleMutationService())->deletePresetCascade(
                $presetId,
                $expectedDeletionRevision,
                $confirmationName
            ),
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
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId', 'expectedRegistryRevision', 'name', 'creationMode', 'basedOnVersionId', 'expectedContentHash']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $expectedRegistryRevision = $parseAggregateRevision($request['expectedRegistryRevision'] ?? null, 'expectedRegistryRevision');
        $name = $request['name'] ?? null;
        $creationMode = $request['creationMode'] ?? null;
        $basedOnVersionId = $request['basedOnVersionId'] ?? null;
        $expectedContentHash = $request['expectedContentHash'] ?? null;
        if (!is_string($name)
            || !is_string($creationMode)
            || !in_array($creationMode, ['blank', 'clone'], true)
            || ($basedOnVersionId !== null && !is_string($basedOnVersionId))
            || ($expectedContentHash !== null && !is_string($expectedContentHash))) {
            throw new \InvalidArgumentException('Режим создания версии и название заданы некорректно.');
        }
        if (($creationMode === 'blank' && ($basedOnVersionId !== null || $expectedContentHash !== null))
            || ($creationMode === 'clone' && ($basedOnVersionId === null || $expectedContentHash === null))) {
            throw new \InvalidArgumentException('Чистая версия не принимает источник, а копия требует точную исходную версию и её hash.');
        }
        $createdWorkspace = $versionRegistry->coordinateVersionMutation(
            $presetId,
            static function () use (
                $presetId,
                $expectedRegistryRevision,
                $name,
                $creationMode,
                $basedOnVersionId,
                $expectedContentHash,
                $versionContext,
                $versionRegistry,
                $versionForms,
                $versionBundles,
                $versionSources,
                $service
            ): array {
                $context = $versionContext($presetId);
                $before = $versionRegistry->loadWorkspace(
                    $presetId,
                    $context['presetName'],
                    $context['legacy'],
                    $context['actor']
                );
                $beforeIds = array_fill_keys(array_map(
                    static fn(array $row): string => (string)$row['versionId'],
                    $before['versions']
                ), true);
                if ($creationMode === 'clone') {
                    $baseRow = null;
                    foreach ($before['versions'] as $row) {
                        if (($row['versionId'] ?? null) === $basedOnVersionId) {
                            $baseRow = $row;
                            break;
                        }
                    }
                    if (!is_array($baseRow)) {
                        throw new \InvalidArgumentException('Исходная версия не найдена.');
                    }
                    $baseBundle = $versionBundles->load($presetId, $basedOnVersionId);
                    if ($baseBundle === null
                        || ($baseBundle['readiness']['complete'] ?? false) !== true
                        || !is_string($expectedContentHash)
                        || preg_match('/^[a-f0-9]{64}$/D', $expectedContentHash) !== 1
                        || !hash_equals((string)$baseBundle['contentHash'], $expectedContentHash)) {
                        throw new \RuntimeException('Исходная версия неполна или изменилась в другой вкладке. Исправьте её либо повторите создание копии.', 409);
                    }
                    $bundleForm = $baseBundle['documents']['form'] ?? null;
                    if (!is_array($bundleForm)
                        || !is_array($bundleForm['formDefinition'] ?? null)
                        || !is_array($bundleForm['bindingDefinition'] ?? null)) {
                        throw new \RuntimeException('В полном bundle исходной версии отсутствует документ формы.', 409);
                    }
                }
                $workspace = $versionRegistry->createVersion(
                    $presetId,
                    $expectedRegistryRevision,
                    $name,
                    $basedOnVersionId,
                    $context['presetName'],
                    $context['legacy'],
                    $context['actor']
                );
                $createdRow = null;
                foreach ($workspace['versions'] as $row) {
                    if (!isset($beforeIds[(string)$row['versionId']])) {
                        $createdRow = $row;
                        break;
                    }
                }
                if (!is_array($createdRow)) {
                    throw new \RuntimeException('Сервер не определил созданную версию.');
                }
                $createdVersionId = (string)$createdRow['versionId'];
                if ($creationMode === 'clone') {
                    $bundleForm = $baseBundle['documents']['form'];
                    $versionForms->create(
                        $presetId,
                        $createdVersionId,
                        $bundleForm['formDefinition'],
                        $bundleForm['bindingDefinition']
                    );
                    $copiedBundle = $versionBundles->copy($presetId, $basedOnVersionId, $createdVersionId);
                    if (!hash_equals((string)$expectedContentHash, (string)$copiedBundle['contentHash'])) {
                        throw new \RuntimeException('Контрольное чтение копии версии не совпало с источником.', 409);
                    }
                } else {
                    $template = $service->newVersionFormTemplate($presetId);
                    $formDocument = $versionForms->create(
                        $presetId,
                        $createdVersionId,
                        $template['formDefinition'],
                        $template['bindingDefinition']
                    );
                    $versionBundles->save(
                        $presetId,
                        $createdVersionId,
                        $versionSources->blankVersion($presetId, $formDocument)
                    );
                }
                $workspace = $versionRegistry->loadWorkspace(
                    $presetId,
                    $context['presetName'],
                    $context['legacy'],
                    $context['actor']
                );
                $workspace['createdVersionId'] = $createdVersionId;
                return $workspace;
            }
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

    if ($action === 'version_delete' || $action === 'version_delete_draft') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId', 'expectedRegistryRevision', 'versionId']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $expectedRegistryRevision = $parseAggregateRevision($request['expectedRegistryRevision'] ?? null, 'expectedRegistryRevision');
        $versionId = $request['versionId'] ?? null;
        if (!is_string($versionId)) {
            throw new \InvalidArgumentException('versionId is required');
        }
        $context = $versionContext($presetId);
        $nextWorkspace = $versionRegistry->deleteInactiveVersions(
            $presetId,
            $expectedRegistryRevision,
            [$versionId],
            $context['presetName'],
            $context['legacy'],
            $context['actor']
        );
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

    if ($action === 'version_form_preview') {
        $assertAllowedRequestKeys([
            'action', 'sessid', 'presetId', 'versionId', 'formDefinition', 'bindingDefinition',
        ]);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $versionId = $request['versionId'] ?? null;
        if (!is_string($versionId)) {
            throw new \InvalidArgumentException('versionId is required');
        }
        $state = $versionState($presetId, $versionId);
        $assertVersionEditable($state);
        $document = $versionForms->ensure(
            $presetId,
            $versionId,
            is_string($state['row']['basedOnVersionId'] ?? null) ? $state['row']['basedOnVersionId'] : null,
            $state['context']['legacy'],
            true
        );
        $formDefinition = $parseEditorDocument(
            $requestWithJsonNodeKinds['formDefinition'] ?? $request['formDefinition'] ?? null,
            'formDefinition'
        );
        $bindingDefinition = $parseEditorDocument(
            $requestWithJsonNodeKinds['bindingDefinition'] ?? $request['bindingDefinition'] ?? null,
            'bindingDefinition'
        );
        $preview = $service->previewVersionFormFirst($presetId, $formDefinition, $bindingDefinition);
        $respond(200, [
            'success' => true,
            'data' => [
                'contract' => $preview['contract'],
                'operation' => 'preview',
                'presetId' => $presetId,
                'aggregateRevision' => $document['revision'],
                'coverage' => $preview['coverage'],
                'compile' => $preview['compile'],
            ],
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
        $loadedComponent = $versionRegistry->coordinateVersionMutation(
            $presetId,
            static function () use (
                $presetId,
                $versionId,
                $component,
                $versionState,
                $ensureVersionBundle,
                $versionComponents
            ): array {
                $state = $versionState($presetId, $versionId);
                $allowRebuild = ($state['row']['status'] ?? null) !== 'ARCHIVED';
                $ensureVersionBundle(
                    $presetId,
                    $versionId,
                    is_string($state['row']['basedOnVersionId'] ?? null) ? $state['row']['basedOnVersionId'] : null,
                    $state['context']['legacy'],
                    $allowRebuild
                );
                return $versionComponents->load($presetId, $versionId, $component);
            }
        );
        $respond(200, [
            'success' => true,
            'data' => $loadedComponent,
        ]);
    }

    if ($action === 'version_input_mapping_validate') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId', 'versionId', 'mapping']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $versionId = $request['versionId'] ?? null;
        $mapping = $request['mapping'] ?? null;
        if (!is_string($versionId) || !is_array($mapping)) {
            throw new \InvalidArgumentException('versionId and mapping are required');
        }
        $validation = $versionRegistry->coordinateVersionMutation(
            $presetId,
            static function () use (
                $presetId,
                $versionId,
                $mapping,
                $versionState,
                $ensureVersionBundle,
                $versionComponents
            ): array {
                $state = $versionState($presetId, $versionId);
                $allowRebuild = ($state['row']['status'] ?? null) !== 'ARCHIVED';
                $ensureVersionBundle(
                    $presetId,
                    $versionId,
                    is_string($state['row']['basedOnVersionId'] ?? null) ? $state['row']['basedOnVersionId'] : null,
                    $state['context']['legacy'],
                    $allowRebuild
                );
                return $versionComponents->validateInputMappings($presetId, $versionId, $mapping);
            }
        );
        $respond(200, [
            'success' => true,
            'data' => $validation,
        ]);
    }

    if ($action === 'version_component_save' || $action === 'version_component_save_draft') {
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
            throw new \InvalidArgumentException('Version component save request is incomplete');
        }
        $savedComponent = $versionRegistry->coordinateVersionMutation(
            $presetId,
            static function () use (
                $presetId,
                $versionId,
                $component,
                $expectedContentHash,
                $expectedComponentHash,
                $document,
                $versionState,
                $assertVersionEditable,
                $versionRuntimePublications,
                $ensureVersionBundle,
                $versionComponents
            ): array {
                $state = $versionState($presetId, $versionId);
                $assertVersionEditable($state);
                $versionRuntimePublications->freezeLegacyActiveForEditing($presetId, $versionId);
                $ensureVersionBundle(
                    $presetId,
                    $versionId,
                    is_string($state['row']['basedOnVersionId'] ?? null) ? $state['row']['basedOnVersionId'] : null,
                    $state['context']['legacy'],
                    true
                );
                return $versionComponents->saveDraft(
                    $presetId,
                    $versionId,
                    $component,
                    $expectedContentHash,
                    $expectedComponentHash,
                    $document
                );
            }
        );
        $respond(200, [
            'success' => true,
            'data' => $savedComponent,
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
        $launch = $versionRegistry->coordinateVersionMutation(
            $presetId,
            static function () use (
                $presetId,
                $versionId,
                $mode,
                $versionState,
                $ensureVersionBundle,
                $versionRuntimePublications,
                $presetElementExists,
                $versionSources,
                $versionComponents
            ): array {
                $state = $versionState($presetId, $versionId);
                $isEditable = ($state['row']['status'] ?? null) !== 'ARCHIVED';
                if ($mode !== ($isEditable ? 'edit' : 'readonly')) {
                    throw new \InvalidArgumentException($isEditable
                        ? 'Версия логики должна открываться в режиме редактирования.'
                        : 'Скрытая версия логики доступна только для просмотра.');
                }
                $bundle = $ensureVersionBundle(
                    $presetId,
                    $versionId,
                    is_string($state['row']['basedOnVersionId'] ?? null) ? $state['row']['basedOnVersionId'] : null,
                    $state['context']['legacy'],
                    $isEditable
                );
                $logic = $bundle['documents']['logic'];
                $workingPresetId = (int)($logic['workingPresetId'] ?? 0);
                $workingVersionId = (string)($logic['workingVersionId'] ?? '');
                if ($isEditable && ($workingPresetId <= 0 || $workingVersionId !== $versionId)) {
                    $versionRuntimePublications->freezeLegacyActiveForEditing($presetId, $versionId);
                    $blankInitialization = ($logic['initializationMode'] ?? null) === 'blank';
                    $historicalWorkingPresetMissing = $workingPresetId > 0 && !$presetElementExists($workingPresetId);
                    if ($blankInitialization) {
                        $created = (new PresetLifecycleMutationService())->createVersionWorkingPreset(
                            'Рабочая логика «' . (string)$state['context']['presetName'] . '»',
                            $presetId,
                            $versionId
                        );
                        $workingPresetId = (int)($created['presetId'] ?? 0);
                    } else {
                        $sourcePresetId = $workingPresetId > 0 && !$historicalWorkingPresetMissing
                            ? $workingPresetId
                            : $presetId;
                        $clone = (new PresetLifecycleMutationService())->duplicateVersionWorkingPreset(
                            $sourcePresetId,
                            $presetId,
                            $versionId
                        );
                        $workingPresetId = (int)($clone['newPresetId'] ?? 0);
                    }
                    if ($workingPresetId <= 0) {
                        throw new \RuntimeException('Не удалось создать чистый изолированный граф логики версии.', 409);
                    }
                    $logic = $versionSources->captureLogic($workingPresetId, $presetId, $versionId);
                    if ($historicalWorkingPresetMissing && !$blankInitialization) {
                        $stageOrderPlan = CalculatorVersionSnapshotSourceService::recoveryStageOrderPlan(
                            $bundle['documents']['logic'],
                            $logic
                        );
                        $detailHandler = new \Prospektweb\Calc\Services\DetailHandler();
                        foreach ($stageOrderPlan as $order) {
                            if (($order['alreadyOrdered'] ?? false) === true) continue;
                            $result = $detailHandler->changeSortStage((int)$order['detailId'], (array)$order['stageIds']);
                            if (($result['status'] ?? null) !== 'ok') {
                                throw new \RuntimeException(
                                    'Не удалось восстановить исторический порядок этапов: '
                                    . (string)($result['message'] ?? 'неизвестная ошибка'),
                                    409
                                );
                            }
                        }
                        $logic = $versionSources->captureLogic($workingPresetId, $presetId, $versionId);
                        foreach (CalculatorVersionSnapshotSourceService::recoveryStageOrderPlan(
                            $bundle['documents']['logic'],
                            $logic
                        ) as $verifiedOrder) {
                            if (($verifiedOrder['alreadyOrdered'] ?? false) !== true) {
                                throw new \RuntimeException('Исторический порядок этапов не подтверждён после сохранения.', 409);
                            }
                        }
                    }
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
                    $workingVersionId = $versionId;
                } elseif ($isEditable && $workingPresetId !== $presetId) {
                    $marker = (new PresetLifecycleMutationService())->markVersionWorkingPreset(
                        $workingPresetId,
                        $presetId,
                        $versionId
                    );
                    if (($marker['changed'] ?? false) === true) {
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
                }
                if ($workingPresetId <= 0 || ($workingVersionId !== $versionId && !$isEditable)) {
                    throw new \RuntimeException(
                        'У этой версии нет отдельного графа логики для точного просмотра. Создайте новую версию на её основе.',
                        409
                    );
                }
                return [
                    'presetId' => $presetId,
                    'focusPresetId' => $workingPresetId,
                    'presetName' => (string)$state['context']['presetName'],
                    'versionId' => $versionId,
                    'mode' => $mode,
                    'contentHash' => (string)$bundle['contentHash'],
                    'logicHash' => (string)$bundle['componentHashes']['logic'],
                ];
            }
        );
        $respond(200, [
            'success' => true,
            'data' => $launch,
        ]);
    }

    if ($action === 'version_logic_init') {
        $assertAllowedRequestKeys([
            'action', 'sessid', 'presetId', 'versionId', 'workingPresetId', 'mode',
            'expectedContentHash', 'expectedLogicHash', 'siteId',
        ]);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $workingPresetId = $parseStrictPositiveInt($request['workingPresetId'] ?? null, 'workingPresetId');
        $versionId = $request['versionId'] ?? null;
        $mode = $request['mode'] ?? null;
        $expectedContentHash = $request['expectedContentHash'] ?? null;
        $expectedLogicHash = $request['expectedLogicHash'] ?? null;
        $siteId = trim((string)($request['siteId'] ?? ''));
        if (!is_string($versionId)
            || !is_string($mode)
            || !in_array($mode, ['edit', 'readonly'], true)
            || !is_string($expectedContentHash)
            || !is_string($expectedLogicHash)
            || preg_match('/^[a-f0-9]{64}$/D', $expectedContentHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $expectedLogicHash) !== 1
            || $siteId === '') {
            throw new \InvalidArgumentException('Version logic INIT context is invalid');
        }

        $state = $versionState($presetId, $versionId);
        $isEditable = ($state['row']['status'] ?? null) !== 'ARCHIVED';
        if ($mode !== ($isEditable ? 'edit' : 'readonly')) {
            throw new \InvalidArgumentException($isEditable
                ? 'Версия логики должна открываться в режиме редактирования.'
                : 'Скрытая версия логики доступна только для просмотра.');
        }
        $bundle = $versionBundles->load($presetId, $versionId);
        if ($bundle === null
            || !hash_equals((string)$bundle['contentHash'], $expectedContentHash)
            || !hash_equals((string)$bundle['componentHashes']['logic'], $expectedLogicHash)) {
            throw new \RuntimeException('Снимок выбранной версии изменился. Откройте редактор повторно.', 409);
        }
        $logic = $bundle['documents']['logic'];
        if ((int)($logic['workingPresetId'] ?? 0) !== $workingPresetId
            || (string)($logic['workingVersionId'] ?? '') !== $versionId) {
            throw new \RuntimeException('Изолированный граф не принадлежит выбранной версии.', 409);
        }

        $form = $bundle['documents']['form'];
        $preview = $service->previewVersionFormFirst(
            $presetId,
            $form['formDefinition'],
            $form['bindingDefinition']
        );
        $compile = is_array($preview['compile'] ?? null) ? $preview['compile'] : [];
        $compileHash = (string)($compile['hash'] ?? '');
        $runtimeSnapshot = is_array($compile['runtimeSchema'] ?? null)
            ? $compile['runtimeSchema']
            : null;
        if ($runtimeSnapshot === null || preg_match('/^[a-f0-9]{64}$/D', $compileHash) !== 1) {
            throw new \RuntimeException('Не удалось собрать форму выбранной версии для редактора логики.', 409);
        }
        $publicationRevision = 1;
        $runtimeSnapshot['_form_first'] = [
            'publishedRevision' => $publicationRevision,
            'compileHash' => $compileHash,
        ];
        $authoring = [
            'formDefinition' => $form['formDefinition'],
            'bindingDefinition' => $form['bindingDefinition'],
            'publication' => [
                'revision' => $publicationRevision,
                'compileHash' => $compileHash,
            ],
        ];

        $respond(200, [
            'success' => true,
            'data' => (new InitPayloadService())->prepareVersionEditorInitPayloadReadOnly(
                $presetId,
                $workingPresetId,
                $versionId,
                $siteId,
                $authoring,
                $runtimeSnapshot,
                $bundle['documents']['inputMappings'],
                $bundle['documents']['outputMappings'],
                $bundle['documents']['commercialPolicy']
            ),
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
        $savedLogic = $versionRegistry->coordinateVersionMutation(
            $presetId,
            static function () use (
                $presetId,
                $versionId,
                $workingPresetId,
                $expectedContentHash,
                $expectedLogicHash,
                $versionState,
                $assertVersionEditable,
                $versionRuntimePublications,
                $versionComponents,
                $versionSources
            ): array {
                $state = $versionState($presetId, $versionId);
                $assertVersionEditable($state);
                $versionRuntimePublications->freezeLegacyActiveForEditing($presetId, $versionId);
                $current = $versionComponents->load($presetId, $versionId, 'logic');
                if ((int)($current['document']['workingPresetId'] ?? 0) !== $workingPresetId
                    || (string)($current['document']['workingVersionId'] ?? '') !== $versionId) {
                    throw new \RuntimeException('Редактор логики не принадлежит выбранной версии.', 409);
                }
                return $versionComponents->saveDraft(
                    $presetId,
                    $versionId,
                    'logic',
                    $expectedContentHash,
                    $expectedLogicHash,
                    $versionSources->captureLogic($workingPresetId, $presetId, $versionId)
                );
            }
        );
        $respond(200, [
            'success' => true,
            'data' => $savedLogic,
        ]);
    }

    if ($action === 'version_form_save' || $action === 'version_form_save_draft') {
        $assertAllowedRequestKeys([
            'action', 'sessid', 'presetId', 'versionId', 'expectedAggregateRevision',
            'formDefinition', 'bindingDefinition', 'inputMappings', 'expectedInputMappingsHash',
        ]);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $versionId = $request['versionId'] ?? null;
        $expectedVersionRevision = $parseAggregateRevision($request['expectedAggregateRevision'] ?? null, 'expectedAggregateRevision');
        if (!is_string($versionId)
            || !is_array($request['formDefinition'] ?? null)
            || !is_array($request['bindingDefinition'] ?? null)) {
            throw new \InvalidArgumentException('versionId, formDefinition and bindingDefinition are required');
        }
        $savedFormWorkspace = $versionRegistry->coordinateVersionMutation(
            $presetId,
            static function () use (
                $presetId,
                $versionId,
                $expectedVersionRevision,
                $request,
                $versionState,
                $assertVersionEditable,
                $versionRuntimePublications,
                $service,
                $versionForms,
                $ensureVersionBundle,
                $parseAggregateRevision,
                $versionComponents,
                $versionBundles,
                $versionFormWorkspace
            ): array {
        $state = $versionState($presetId, $versionId);
        $assertVersionEditable($state);
        $versionRuntimePublications->freezeLegacyActiveForEditing($presetId, $versionId);
        $service->previewVersionFormFirst(
            $presetId,
            $request['formDefinition'],
            $request['bindingDefinition']
        );
        $versionForms->ensure(
            $presetId,
            $versionId,
            is_string($state['row']['basedOnVersionId'] ?? null) ? $state['row']['basedOnVersionId'] : null,
            $state['context']['legacy'],
            true
        );
        // Assemble and validate the exact complete bundle before advancing
        // the form CAS revision. A source/capture failure must not leave the UI
        // with a successfully saved form followed by a stale-revision conflict.
        $prospectiveForm = [
            'contract' => CalculatorVersionFormDocumentService::CONTRACT,
            'formDefinition' => $request['formDefinition'],
            'bindingDefinition' => $request['bindingDefinition'],
        ];
        $existingBundle = $ensureVersionBundle(
            $presetId,
            $versionId,
            is_string($state['row']['basedOnVersionId'] ?? null) ? $state['row']['basedOnVersionId'] : null,
            $state['context']['legacy'],
            true
        );
        $components = $existingBundle['documents'];
        $components['form'] = $prospectiveForm;
        if (array_key_exists('inputMappings', $request)) {
            if (!is_array($request['inputMappings'] ?? null)) {
                throw new \InvalidArgumentException('inputMappings must be an object');
            }
            $expectedInputMappingsHash = $parseAggregateRevision(
                $request['expectedInputMappingsHash'] ?? null,
                'expectedInputMappingsHash'
            );
            $currentInputMappingsHash = (string)($existingBundle['componentHashes']['inputMappings'] ?? '');
            if (!hash_equals($currentInputMappingsHash, $expectedInputMappingsHash)) {
                throw new \RuntimeException(
                    'Связи Bitrix изменены в другой вкладке. Перезагрузите редактор формы.',
                    409
                );
            }
            $mappingValidation = $versionComponents->validateInputMappings(
                $presetId,
                $versionId,
                $request['inputMappings'],
                $prospectiveForm
            );
            if (($mappingValidation['valid'] ?? false) !== true) {
                $mappingIssues = is_array($mappingValidation['issues'] ?? null) ? $mappingValidation['issues'] : [];
                $firstIssue = $mappingIssues[0]['message'] ?? 'Проверьте выбранное поле и свойство Bitrix.';
                throw new \RuntimeException('Связь Bitrix не сохранена: ' . (string)$firstIssue, 409);
            }
            $components['inputMappings'] = $mappingValidation['mapping'];
        }
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
        // Form and complete bundle share the outer version transaction. Any
        // write failure rolls both documents back atomically.
        $versionBundles->save($presetId, $versionId, $components);
        return $versionFormWorkspace($presetId, $versionId, 'save');
            }
        );
        $respond(200, [
            'success' => true,
            'data' => $savedFormWorkspace,
        ]);
    }

    if ($action === 'version_publish_activate') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId', 'expectedRegistryRevision', 'versionId', 'expectedContentHash']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $expectedRegistryRevision = $parseAggregateRevision($request['expectedRegistryRevision'] ?? null, 'expectedRegistryRevision');
        $versionId = $request['versionId'] ?? null;
        $expectedContentHash = $request['expectedContentHash'] ?? null;
        if (!is_string($versionId) || !is_string($expectedContentHash)) {
            throw new \InvalidArgumentException('versionId and expectedContentHash are required');
        }
        $context = $versionContext($presetId);
        $legacy = $context['legacy'];
        $state = $versionState($presetId, $versionId);
        $document = $versionForms->ensure(
            $presetId,
            $versionId,
            is_string($state['row']['basedOnVersionId'] ?? null) ? $state['row']['basedOnVersionId'] : null,
            $legacy
        );
        $preview = $service->previewVersionFormFirst(
            $presetId,
            $document['formDefinition'],
            $document['bindingDefinition']
        );
        if (($preview['coverage']['valid'] ?? false) !== true
            || ($preview['compile']['valid'] ?? false) !== true
            || !is_string($preview['compile']['hash'] ?? null)) {
            throw new \InvalidArgumentException('Версия не прошла проверку формы и связей. Перейдите к исправлению ошибок.');
        }
        // The version already owns every component. Activation validates
        // and seals that exact bundle; recapturing shared runtime here would
        // silently discard version-scoped storefront/mapping/product edits.
        $storedBundle = $versionRegistry->coordinateVersionMutation(
            $presetId,
            static function () use (
                $presetId,
                $versionId,
                $versionState,
                $ensureVersionBundle
            ): array {
                $lockedState = $versionState($presetId, $versionId);
                return $ensureVersionBundle(
                    $presetId,
                    $versionId,
                    is_string($lockedState['row']['basedOnVersionId'] ?? null)
                        ? $lockedState['row']['basedOnVersionId']
                        : null,
                    $lockedState['context']['legacy'],
                    true
                );
            }
        );
        $versionBundles->inspect($storedBundle['documents']);
        if (($storedBundle['readiness']['complete'] ?? false) !== true) {
            throw new \RuntimeException(
                'Публикация остановлена: полный bundle версии требует пересборки. Перейдите к версии и исправьте её компоненты.',
                409
            );
        }
        $respond(200, [
            'success' => true,
            'data' => $versionRegistry->coordinatedActivateVersion(
                $presetId,
                $expectedRegistryRevision,
                $versionId,
                $expectedContentHash,
                $context['presetName'],
                $legacy,
                $context['actor'],
                static function () use (
                    $service,
                    $presetId,
                    $legacy,
                    $document,
                    $versionRuntimePublications,
                    $versionId
                ): array {
                    $service->saveFormFirstDraft(
                        $presetId,
                        (string)$legacy['aggregateRevision'],
                        $document['formDefinition'],
                        $document['bindingDefinition']
                    );
                    // Form/binding changes may legitimately advance the
                    // dependency fingerprint (for example UI consumers). The
                    // save result was compiled against the pre-write contract,
                    // so reload the composite authority before publication.
                    $current = $service->loadFormFirstWorkspace($presetId);
                    $currentPreview = $service->previewFormFirst(
                        $presetId,
                        $document['formDefinition'],
                        $document['bindingDefinition']
                    );
                    if (($currentPreview['coverage']['valid'] ?? false) !== true
                        || ($currentPreview['compile']['valid'] ?? false) !== true
                        || !is_string($currentPreview['compile']['hash'] ?? null)) {
                        throw new \RuntimeException(
                            'Публикация остановлена: форма больше не проходит проверку с актуальными зависимостями.',
                            409
                        );
                    }
                    $published = $service->publishFormFirst(
                        $presetId,
                        (string)$current['aggregateRevision'],
                        (string)$currentPreview['compile']['hash']
                    );
                    $runtime = $versionRuntimePublications->activate($presetId, $versionId);
                    return ['published' => $published, 'runtime' => $runtime];
                }
            ),
        ]);
    }

    if ($action === 'version_activate') {
        $assertAllowedRequestKeys(['action', 'sessid', 'presetId', 'expectedRegistryRevision', 'versionId', 'expectedContentHash']);
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');
        $expectedRegistryRevision = $parseAggregateRevision($request['expectedRegistryRevision'] ?? null, 'expectedRegistryRevision');
        $versionId = $request['versionId'] ?? null;
        $expectedContentHash = $request['expectedContentHash'] ?? null;
        if (!is_string($versionId) || !is_string($expectedContentHash)) {
            throw new \InvalidArgumentException('versionId and expectedContentHash are required');
        }
        $state = $versionState($presetId, $versionId);
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
        if (($storedBundle['readiness']['complete'] ?? false) !== true) {
            throw new \RuntimeException(
                'Безопасная активация остановлена: версия создана до полного bundle v2 и требует пересборки.',
                409
            );
        }
        $preview = $service->previewVersionFormFirst($presetId, $document['formDefinition'], $document['bindingDefinition']);
        if (($preview['coverage']['valid'] ?? false) !== true
            || ($preview['compile']['valid'] ?? false) !== true
            || !is_string($preview['compile']['hash'] ?? null)) {
            throw new \InvalidArgumentException('Версия больше не проходит проверку с текущими зависимостями калькулятора.');
        }
        $respond(200, [
            'success' => true,
            'data' => $versionRegistry->coordinatedActivateVersion(
                $presetId,
                $expectedRegistryRevision,
                $versionId,
                $expectedContentHash,
                $context['presetName'],
                $legacy,
                $context['actor'],
                static function () use (
                    $service,
                    $presetId,
                    $legacy,
                    $document,
                    $versionRuntimePublications,
                    $versionId
                ): array {
                    $service->saveFormFirstDraft(
                        $presetId,
                        (string)$legacy['aggregateRevision'],
                        $document['formDefinition'],
                        $document['bindingDefinition']
                    );
                    $current = $service->loadFormFirstWorkspace($presetId);
                    $currentPreview = $service->previewFormFirst(
                        $presetId,
                        $document['formDefinition'],
                        $document['bindingDefinition']
                    );
                    if (($currentPreview['coverage']['valid'] ?? false) !== true
                        || ($currentPreview['compile']['valid'] ?? false) !== true
                        || !is_string($currentPreview['compile']['hash'] ?? null)) {
                        throw new \RuntimeException(
                            'Активация остановлена: форма больше не проходит проверку с актуальными зависимостями.',
                            409
                        );
                    }
                    $published = $service->publishFormFirst(
                        $presetId,
                        (string)$current['aggregateRevision'],
                        (string)$currentPreview['compile']['hash']
                    );
                    $runtime = $versionRuntimePublications->activate($presetId, $versionId);
                    return ['published' => $published, 'runtime' => $runtime];
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
        'error' => 'Unable to prepare the editor workspace: ' . $exception->getMessage(),
    ]);
}
