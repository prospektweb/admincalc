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
use Prospektweb\Calc\Services\ControlCenterEditorsService;
use Prospektweb\Calc\Services\Phase5aParityContractService;

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
$parseTemplateId = static function ($value, bool $allowEmpty): string {
    if ($allowEmpty && $value === null) {
        return '';
    }
    if (!is_string($value)) {
        throw new \InvalidArgumentException('templateId must be a valid template identifier');
    }
    if ($allowEmpty && $value === '') {
        return '';
    }
    if (preg_match('/^[a-f0-9]{16,32}$/D', $value) !== 1) {
        throw new \InvalidArgumentException('templateId must be a valid template identifier');
    }

    return $value;
};
$parseIndividualRevision = static function ($value): string {
    if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
        throw new \InvalidArgumentException('expectedRevision must be a SHA-256 revision');
    }

    return $value;
};
$parseAggregateRevision = static function ($value, string $field): string {
    if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
        throw new \InvalidArgumentException($field . ' must be a lowercase SHA-256 revision');
    }

    return $value;
};
$parseTarget = static function ($value, array $allowedTargets): string {
    if (!is_string($value) || !in_array($value, $allowedTargets, true)) {
        throw new \InvalidArgumentException('target is not supported');
    }

    return $value;
};
$parseSchema = static function ($value): array {
    if (!is_array($value) || $value === []) {
        throw new \InvalidArgumentException('schema must be a non-empty object');
    }
    if (array_keys($value) === range(0, count($value) - 1)) {
        throw new \InvalidArgumentException('schema must be a non-empty object');
    }
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new \InvalidArgumentException('schema must be valid JSON data');
    }
    if (strlen($encoded) > 60000) {
        throw new \InvalidArgumentException('schema must not exceed 60000 bytes');
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

    if ($action === 'validate_storefront_launch') {
        $assertAllowedRequestKeys(['action', 'sessid', 'productId']);
        $productId = $parsePositiveInt($request['productId'] ?? null, 'productId');

        $respond(200, [
            'success' => true,
            'data' => $service->validateStorefrontLaunch($productId),
        ]);
    }

    if ($action === 'storefront_load') {
        $assertAllowedRequestKeys(['action', 'sessid', 'productId', 'target', 'templateId']);
        $productId = $parseStrictPositiveInt($request['productId'] ?? null, 'productId');
        $target = $parseTarget($request['target'] ?? null, ['effective', 'product', 'template']);
        $templateId = $parseTemplateId($request['templateId'] ?? null, true);

        $respond(200, [
            'success' => true,
            'data' => $service->loadStorefrontWorkspace($productId, $target, $templateId),
        ]);
    }

    if ($action === 'storefront_validate') {
        $assertAllowedRequestKeys(['action', 'sessid', 'productId', 'target', 'schema']);
        $productId = $parseStrictPositiveInt($request['productId'] ?? null, 'productId');
        $target = $parseTarget($request['target'] ?? null, ['product', 'template']);
        $schema = $parseSchema($request['schema'] ?? null);

        $respond(200, [
            'success' => true,
            'data' => $service->validateStorefrontSchema($productId, $target, $schema),
        ]);
    }

    if ($action === 'storefront_save_template') {
        $assertAllowedRequestKeys([
            'action',
            'sessid',
            'productId',
            'templateId',
            'expectedRevision',
            'name',
            'sectionId',
            'schema',
        ]);
        $productId = $parseStrictPositiveInt($request['productId'] ?? null, 'productId');
        if (!array_key_exists('templateId', $request)) {
            throw new \InvalidArgumentException('templateId is required and may be null only for creation');
        }
        $templateId = $parseTemplateId($request['templateId'], true);
        $expectedRevision = $parseStrictNonNegativeInt($request['expectedRevision'] ?? null, 'expectedRevision');
        if (($templateId === '' && $expectedRevision !== 0)
            || ($templateId !== '' && $expectedRevision <= 0)) {
            throw new \InvalidArgumentException('expectedRevision does not match the template target');
        }
        $name = $request['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new \InvalidArgumentException('name must be a non-empty string');
        }
        $sectionId = $parseStrictNonNegativeInt($request['sectionId'] ?? null, 'sectionId');
        $schema = $parseSchema($request['schema'] ?? null);

        $respond(200, [
            'success' => true,
            'data' => $service->saveStorefrontTemplate(
                $productId,
                $templateId,
                $expectedRevision,
                $name,
                $sectionId,
                $schema
            ),
        ]);
    }

    if ($action === 'storefront_save_product') {
        $assertAllowedRequestKeys(['action', 'sessid', 'productId', 'expectedRevision', 'schema']);
        $productId = $parseStrictPositiveInt($request['productId'] ?? null, 'productId');
        $expectedRevision = $parseIndividualRevision($request['expectedRevision'] ?? null);
        $schema = $parseSchema($request['schema'] ?? null);

        $respond(200, [
            'success' => true,
            'data' => $service->saveStorefrontProduct($productId, $expectedRevision, $schema),
        ]);
    }

    if ($action === 'storefront_enable_inheritance') {
        $assertAllowedRequestKeys(['action', 'sessid', 'productId', 'expectedRevision']);
        $productId = $parseStrictPositiveInt($request['productId'] ?? null, 'productId');
        $expectedRevision = $parseIndividualRevision($request['expectedRevision'] ?? null);

        $respond(200, [
            'success' => true,
            'data' => $service->enableStorefrontInheritance($productId, $expectedRevision),
        ]);
    }

    if ($action === 'storefront_delete_template') {
        $assertAllowedRequestKeys(['action', 'sessid', 'productId', 'templateId', 'expectedRevision']);
        $productId = $parseStrictPositiveInt($request['productId'] ?? null, 'productId');
        $templateId = $parseTemplateId($request['templateId'] ?? null, false);
        $expectedRevision = $parseStrictPositiveInt($request['expectedRevision'] ?? null, 'expectedRevision');

        $respond(200, [
            'success' => true,
            'data' => $service->deleteStorefrontTemplate($productId, $templateId, $expectedRevision),
        ]);
    }

    if ($action === 'form_first_load') {
        $assertAllowedRequestKeys(['action', 'sessid', 'productId', 'presetId']);
        $productId = $parseStrictPositiveInt($request['productId'] ?? null, 'productId');
        $presetId = $parseStrictPositiveInt($request['presetId'] ?? null, 'presetId');

        $respond(200, [
            'success' => true,
            'data' => $service->loadFormFirstWorkspace($productId, $presetId),
        ]);
    }

    if ($action === 'form_first_save_draft') {
        $assertAllowedRequestKeys([
            'action',
            'sessid',
            'productId',
            'presetId',
            'expectedAggregateRevision',
            'formDefinition',
            'bindingDefinition',
        ]);
        $productId = $parseStrictPositiveInt($request['productId'] ?? null, 'productId');
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
                $productId,
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
            'productId',
            'presetId',
            'formDefinition',
            'bindingDefinition',
        ]);
        $productId = $parseStrictPositiveInt($request['productId'] ?? null, 'productId');
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
                $productId,
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
            'productId',
            'presetId',
            'expectedAggregateRevision',
            'expectedCompileHash',
        ]);
        $productId = $parseStrictPositiveInt($request['productId'] ?? null, 'productId');
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
                $productId,
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
            'productId',
            'presetId',
            'expectedAggregateRevision',
            'targetPublishedRevision',
        ]);
        $productId = $parseStrictPositiveInt($request['productId'] ?? null, 'productId');
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
                $productId,
                $presetId,
                $expectedAggregateRevision,
                $targetPublishedRevision
            ),
        ]);
    }

    if ($action === 'phase5a_parity_contract') {
        $assertAllowedRequestKeys(['action', 'sessid']);
        $respond(200, [
            'success' => true,
            'data' => (new Phase5aParityContractService())->build(),
        ]);
    }

    if ($action === 'phase5a_parity_compare') {
        $assertAllowedRequestKeys(['action', 'sessid', 'baseline', 'candidate']);
        $baseline = $parseEditorDocument($request['baseline'] ?? null, 'baseline');
        $candidate = $parseEditorDocument($request['candidate'] ?? null, 'candidate');
        $respond(200, [
            'success' => true,
            'data' => (new Phase5aParityContractService())->compare($baseline, $candidate),
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
