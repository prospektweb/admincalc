<?php

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', false);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Prospektweb\Calc\Install\CatalogCalcPropertyMigrationService;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

$respond = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $respond(['status' => 'error', 'error' => 'method_not_allowed'], 405);
}

$request = $_POST;
if ($request === []) {
    $rawBody = file_get_contents('php://input');
    $decoded = json_decode((string)$rawBody, true);
    if (is_array($decoded)) {
        $request = $decoded;
    }
}
if (isset($request['sessid']) && is_scalar($request['sessid'])) {
    // check_bitrix_sessid() reads the named request variable.  Copy only this
    // one non-secret token from a JSON body; it is never returned or logged.
    $_REQUEST['sessid'] = (string)$request['sessid'];
}

global $USER;
if (!$USER || !$USER->IsAdmin() || !check_bitrix_sessid()) {
    $respond(['status' => 'error', 'error' => 'access_denied'], 403);
}
if (!Loader::includeModule('prospektweb.calc')) {
    $respond(['status' => 'error', 'error' => 'module_not_loaded'], 503);
}

$action = strtolower(trim((string)($request['action'] ?? '')));
$presetId = isset($request['presetId'])
    ? (int)$request['presetId']
    : CatalogCalcPropertyMigrationService::DEFAULT_PRESET_ID;
$semanticFixes = filter_var(
    $request['applySemanticFixes'] ?? false,
    FILTER_VALIDATE_BOOLEAN,
    FILTER_NULL_ON_FAILURE
);
if ($semanticFixes === null) {
    $respond(['status' => 'error', 'error' => 'invalid_semantic_fix_flag'], 400);
}

try {
    $service = new CatalogCalcPropertyMigrationService();
    switch ($action) {
        case 'audit':
            $result = $service->audit($presetId, $semanticFixes);
            break;
        case 'snapshot':
            $result = $service->snapshot($presetId, $semanticFixes);
            break;
        case 'audit_catalog_display':
            $result = $service->auditCatalogDisplay($presetId);
            break;
        case 'rollback_catalog_display':
            $result = $service->rollbackCatalogDisplay(
                $presetId,
                trim((string)($request['expectedPatchedSha256'] ?? ''))
            );
            break;
        case 'execute':
            $result = $service->execute(
                $presetId,
                trim((string)($request['expectedFingerprint'] ?? '')),
                $semanticFixes
            );
            break;
        case 'apply_semantic_fixes':
            $result = $service->applySemanticFixes(
                $presetId,
                trim((string)($request['expectedFingerprint'] ?? ''))
            );
            break;
        case 'rollback_semantic_fixes':
            $result = $service->rollbackSemanticFixes(
                $presetId,
                trim((string)($request['expectedFingerprint'] ?? ''))
            );
            break;
        case 'materialize_base_offers':
            $result = $service->materializeBaseOffers(
                $presetId,
                trim((string)($request['expectedFingerprint'] ?? ''))
            );
            break;
        case 'verify':
            $result = $service->verify($presetId, $semanticFixes);
            break;
        case 'cutover':
            $result = $service->cutover(
                $presetId,
                trim((string)($request['expectedFingerprint'] ?? '')),
                $semanticFixes
            );
            break;
        case 'rollback_base_offers':
            $result = $service->rollbackBaseOffers(
                $presetId,
                trim((string)($request['expectedFingerprint'] ?? ''))
            );
            break;
        default:
            $respond(['status' => 'error', 'error' => 'unknown_action'], 400);
    }
    $respond(['status' => 'ok', 'data' => $result]);
} catch (InvalidArgumentException $error) {
    $respond(['status' => 'error', 'error' => 'invalid_request', 'message' => $error->getMessage()], 400);
} catch (Throwable $error) {
    $respond(['status' => 'error', 'error' => 'migration_failed', 'message' => $error->getMessage()], 409);
}
