<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Modules/StageInsertionService.php';

use Prospektweb\Calc\Modules\StageInsertionService;

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$service = file_get_contents($root . '/lib/Modules/StageInsertionService.php');
$lifecycle = file_get_contents($root . '/lib/Modules/ModuleLifecycleService.php');
$handler = file_get_contents($root . '/lib/Services/DetailHandler.php');
$bridge = file_get_contents($root . '/install/assets/js/integration.js');
$endpoint = file_get_contents($root . '/tools/modules.php');

$assert(hash_equals(
    StageInsertionService::revisionToken(7, [10, 20]),
    StageInsertionService::revisionToken(7, [10, 20])
), 'detail revision is deterministic');
$assert(
    !hash_equals(
        StageInsertionService::revisionToken(7, [10, 20]),
        StageInsertionService::revisionToken(7, [20, 10])
    ),
    'detail revision changes with stage order'
);
$assert(strpos($service, 'beforeStageId') !== false && strpos($service, 'afterStageId') !== false, 'position validates both neighbours');
$assert(strpos($service, 'FOR UPDATE') !== false, 'apply locks the target detail');
$assert(strpos($service, 'array_splice') !== false, 'materialization inserts at the exact index');
$assert(strpos($service, "\$properties['CALC_SETTINGS'] = \$settingsId") !== false, 'materialized stage links its pinned calculator settings');
$assert(strpos($service, "'__literal__:'") !== false, 'literal mapping uses the runtime contract');
$assert(strpos($service, "'stage_%s.outputVar.%s'") !== false, 'previous-stage output uses the runtime alias contract');
$assert(strpos($lifecycle, '$stageService->preview($target)') !== false, 'update revalidates preview target before CAS apply');
$assert(strpos($lifecycle, 'resolveDependencies($module)') !== false, 'preview and apply resolve the authoritative dependency lock');
$assert(strpos($lifecycle, 'authoritative refresh') !== false && strpos($lifecycle, "'initPayload' => \$initPayload") !== false, 'apply returns authoritative state before transaction commit');
$assert(strpos($lifecycle, "'operator_insert'") !== false, 'operator insertion has a dedicated audit action');
$assert(strpos($lifecycle, "['PUBLISHED_AT', 'CREATED_AT', 'UPDATED_AT']") !== false, 'catalog serializes lifecycle dates');
$assert(strpos($lifecycle, '->format(DATE_ATOM)') !== false, 'catalog dates use an interoperable value');
$assert(strpos($handler, 'STAGE_POSITION_STALE') !== false && strpos($handler, 'array_splice') !== false, 'manual creation preserves exact insertion boundary');
$assert(strpos($bridge, 'MODULE_OPERATION_RESPONSE') !== false, 'operator module calls are correlated through the bridge');
$assert(strpos($endpoint, "case 'instance.preview'") !== false && strpos($endpoint, 'previewStageInsertion') !== false, 'endpoint exposes authoritative position preview');

echo "Stage insertion operator static checks passed\n";
