<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Modules/CanonicalJson.php';
require_once dirname(__DIR__) . '/lib/Modules/ModuleValidator.php';
require_once dirname(__DIR__) . '/lib/Modules/ModuleLifecyclePolicy.php';
require_once dirname(__DIR__) . '/lib/Modules/ModuleAccess.php';

use Prospektweb\Calc\Modules\CanonicalJson;
use Prospektweb\Calc\Modules\ModuleAccess;
use Prospektweb\Calc\Modules\ModuleLifecyclePolicy;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$draft = [
    'schema' => 'prospektweb.calc.module/v1',
    'familyId' => 'digital_print',
    'version' => '1.0.0',
    'kind' => 'stage',
    'status' => 'draft',
    'name' => 'Digital print',
    'content' => [
        'rootNodeId' => 'root',
        'nodes' => [[
            'nodeId' => 'root',
            'nodeType' => 'stage',
            'order' => 0,
            'logic' => ['version' => 1],
        ]],
    ],
    'ports' => [],
    'entityRoles' => [],
    'dependencies' => [],
    'tests' => [['name' => 'golden', 'inputs' => [], 'expectedOutputs' => []]],
    'contentHash' => str_repeat('0', 64),
    'provenance' => ['createdAt' => '2026-07-25T00:00:00Z', 'createdBy' => 'test'],
];
$draft['contentHash'] = CanonicalJson::moduleContentHash($draft);

ModuleLifecyclePolicy::assertPublishable($draft, [['passed' => true]]);
ModuleLifecyclePolicy::assertTransition('draft', 'published');
ModuleLifecyclePolicy::assertTransition('published', 'deprecated');
ModuleLifecyclePolicy::assertTransition('deprecated', 'archived');

try {
    ModuleLifecyclePolicy::assertContentMutable('published');
    $assert(false, 'published content must be immutable');
} catch (DomainException $error) {
    $assert(str_contains($error->getMessage(), 'immutable'), 'immutable error must be explicit');
}

try {
    ModuleLifecyclePolicy::assertPublishable($draft, [['passed' => false]]);
    $assert(false, 'failed golden test must block publication');
} catch (DomainException $error) {
    $assert(str_contains($error->getMessage(), 'failed'), 'publish failure must name the failed test');
}

try {
    ModuleLifecyclePolicy::assertTransition('archived', 'published');
    $assert(false, 'archived version must be terminal');
} catch (DomainException $error) {
    $assert(str_contains($error->getMessage(), 'forbidden'), 'transition error must be explicit');
}

ModuleLifecyclePolicy::assertRevision(4, 4);
try {
    ModuleLifecyclePolicy::assertRevision(5, 4);
    $assert(false, 'revision conflict must fail');
} catch (RuntimeException $error) {
    $assert(str_contains($error->getMessage(), 'Revision conflict'), 'CAS error must be explicit');
}

$assert(ModuleAccess::canByRights('view', false, 'R', false), 'read right can view library');
$assert(ModuleAccess::canByRights('draft.edit', true, 'W', false), 'catalog editor with W can edit drafts');
$assert(!ModuleAccess::canByRights('draft.edit', false, 'W', false), 'W cannot bypass edit_catalog');
$assert(!ModuleAccess::canByRights('version.publish', true, 'W', false), 'W cannot publish');
$assert(ModuleAccess::canByRights('version.publish', true, 'P', false), 'P can publish');
$assert(ModuleAccess::canByRights('snapshot.rollback', true, 'W', false), 'catalog editor with W can rollback an instance');
$assert(!ModuleAccess::canByRights('snapshot.rollback', false, 'W', false), 'rollback cannot bypass edit_catalog');
$assert(ModuleAccess::canByRights('snapshot.rollback', false, 'D', true), 'administrator can rollback');

echo "Calculation module lifecycle policy tests passed\n";
