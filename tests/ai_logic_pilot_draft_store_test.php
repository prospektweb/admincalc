<?php

require_once dirname(__DIR__) . '/lib/Services/AiLogicPilotDraftStore.php';

use Prospektweb\Calc\Services\AiLogicPilotDraftStore;

final class AiLogicPilotTestUser
{
    public function IsAdmin(): bool { return true; }
    public function GetID(): int { return 77; }
}

$USER = new AiLogicPilotTestUser();
$root = sys_get_temp_dir() . '/pw-ai-pilot-test-' . bin2hex(random_bytes(6));
$store = new AiLogicPilotDraftStore($root);
$baseCompileHash = str_repeat('a', 64);
$contentHash = str_repeat('d', 64);
$draft = [
    'schema' => 'prospektweb.calc.ai-logic-pilot-draft/v1',
    'draftId' => 'draft_logic_001',
    'context' => ['presetId' => 12740, 'versionKey' => 'v_test', 'baseCompileHash' => $baseCompileHash, 'requestToken' => 'request_001'],
];

try {
    $empty = $store->load(['presetId' => 12740, 'versionKey' => 'v_test', 'baseCompileHash' => $baseCompileHash, 'expectedContentHash' => $contentHash]);
    if (($empty['found'] ?? true) !== false) throw new RuntimeException('Fresh store must be empty.');
    $saved = $store->save([
        'presetId' => 12740,
        'versionKey' => 'v_test',
        'baseCompileHash' => $baseCompileHash,
        'expectedContentHash' => $contentHash,
        'draft' => $draft,
        'decisions' => ['draft_logic_001' => 'approved'],
        'replacements' => ['draft_logic_001' => ['realKind' => 'calculator', 'realId' => 42, 'expectedRevision' => str_repeat('c', 64)]],
        'expectedDraftRevision' => 0,
        'clientRevision' => 1,
    ]);
    if (($saved['revision'] ?? 0) !== 1 || ($saved['draftRevision'] ?? 0) !== 1) throw new RuntimeException('First revision was not saved.');
    $loaded = $store->load(['presetId' => 12740, 'versionKey' => 'v_test', 'baseCompileHash' => $baseCompileHash, 'expectedContentHash' => $contentHash]);
    if (($loaded['draft']['context']['requestToken'] ?? '') !== 'request_001') throw new RuntimeException('Stored proposal changed.');
    if (($loaded['decisions']['draft_logic_001'] ?? '') !== 'approved') throw new RuntimeException('Stored decision changed.');
    if (($loaded['replacements']['draft_logic_001']['realKind'] ?? '') !== 'calculator') throw new RuntimeException('Stored replacement changed.');
    $changedSnapshot = $store->load(['presetId' => 12740, 'versionKey' => 'v_test', 'baseCompileHash' => str_repeat('b', 64), 'expectedContentHash' => $contentHash]);
    if (($changedSnapshot['found'] ?? true) !== false) throw new RuntimeException('Draft from an older form snapshot was restored.');
    $changedBundle = $store->load(['presetId' => 12740, 'versionKey' => 'v_test', 'baseCompileHash' => $baseCompileHash, 'expectedContentHash' => str_repeat('e', 64)]);
    if (($changedBundle['found'] ?? true) !== false) throw new RuntimeException('Draft from another full bundle was restored.');
    try {
        $store->save([
            'presetId' => 12740,
            'versionKey' => 'v_test',
            'baseCompileHash' => $baseCompileHash,
            'expectedContentHash' => $contentHash,
            'draft' => $draft,
            'decisions' => ['draft_logic_001' => 'rejected'],
            'expectedDraftRevision' => 0,
            'clientRevision' => 2,
        ]);
        throw new RuntimeException('Stale draft revision overwrote a newer decision.');
    } catch (RuntimeException $expected) {
        if (!str_contains($expected->getMessage(), 'другой вкладке')) throw $expected;
    }
    $next = $store->save([
        'presetId' => 12740,
        'versionKey' => 'v_test',
        'baseCompileHash' => $baseCompileHash,
        'expectedContentHash' => $contentHash,
        'draft' => $draft,
        'decisions' => ['draft_logic_001' => 'rejected'],
        'expectedDraftRevision' => 1,
        'clientRevision' => 2,
    ]);
    if (($next['draftRevision'] ?? 0) !== 2 || ($next['decisions']['draft_logic_001'] ?? '') !== 'rejected') throw new RuntimeException('Current draft revision was not saved.');
    $newContentHash = str_repeat('e', 64);
    $rebased = $store->save([
        'presetId' => 12740,
        'versionKey' => 'v_test',
        'baseCompileHash' => $baseCompileHash,
        'expectedContentHash' => $newContentHash,
        'draft' => $draft,
        'decisions' => ['draft_logic_001' => 'approved'],
        'expectedDraftRevision' => 0,
        'clientRevision' => 1,
    ]);
    if (($rebased['draftRevision'] ?? 0) !== 1 || ($rebased['expectedContentHash'] ?? '') !== $newContentHash) throw new RuntimeException('A fresh bundle context could not replace an incompatible stored draft.');
    try {
        $store->save(['presetId' => 12741, 'versionKey' => 'v_test', 'baseCompileHash' => $baseCompileHash, 'expectedContentHash' => $contentHash, 'draft' => $draft, 'decisions' => [], 'expectedDraftRevision' => 0, 'clientRevision' => 2]);
        throw new RuntimeException('Cross-preset draft was accepted.');
    } catch (InvalidArgumentException $expected) {
    }
    echo "AI logic pilot draft store checks passed\n";
} finally {
    if (is_dir($root)) {
        foreach (glob($root . '/*') ?: [] as $file) @unlink($file);
        @unlink($root . '/.htaccess');
        @rmdir($root);
    }
}
