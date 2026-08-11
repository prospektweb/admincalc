<?php

declare(strict_types=1);

$toolPath = dirname(__DIR__) . '/tools/catalog_calc_property_migration.php';
$source = (string)file_get_contents($toolPath);

$assertions = [
    'Bitrix prolog is required' => strpos($source, '/bitrix/modules/main/include/prolog_before.php') !== false,
    'GET panel is explicit' => strpos($source, "if (\$requestMethod === 'GET')") !== false,
    'GET panel is restricted to administrators' => strpos($source, "!\$USER || !\$USER->IsAdmin()") !== false,
    'session token uses the standard hidden Bitrix field' => strpos($source, 'bitrix_sessid_post()') !== false,
    'session token is not rendered as visible text' => strpos($source, '<?= bitrix_sessid()') === false
        && strpos($source, 'echo bitrix_sessid()') === false,
    'panel does not submit before operator action' => strpos($source, 'setTimeout(') === false
        && strpos($source, 'DOMContentLoaded') === false,
    'POST remains the only migration method' => strpos($source, "if (\$requestMethod !== 'POST')") !== false,
    'JSON response content type remains present' => strpos($source, "header('Content-Type: application/json; charset=UTF-8')") !== false,
    'fingerprint can be entered and posted' => strpos($source, 'id="expected-fingerprint"') !== false
        && strpos($source, 'name="expectedFingerprint"') !== false,
    'HTML submissions are explicitly marked' => strpos($source, 'name="migrationUiSubmission" value="1"') !== false,
    'native POST preserves the clicked action' => strpos($source, 'id="action-value" type="hidden" name="action"') !== false
        && strpos($source, "document.getElementById('action-value').value = action;") !== false,
    'mutations carry a separate confirmation token' => strpos($source, 'name="confirmAction"') !== false
        && strpos($source, "'error' => 'confirmation_required'") !== false
        && strpos($source, 'hash_equals($action') !== false,
    'validated HTML submissions use an explicit native POST' => strpos($source, 'event.preventDefault();') !== false
        && strpos($source, 'form.submit();') !== false,
    'execute cannot receive semantic fixes from the HTML panel' => strpos(
        $source,
        "!in_array(\$action, ['audit', 'verify', 'cutover'], true)"
    ) !== false,
    'semantic apply and rollback are visually separated' => strpos($source, 'class="rollback danger"') !== false,
];

$requiredActions = [
    'audit',
    'snapshot',
    'materialize_base_offers',
    'execute',
    'verify',
    'cutover',
    'apply_semantic_fixes',
    'rollback_semantic_fixes',
];
foreach ($requiredActions as $action) {
    $assertions['panel exposes action ' . $action] = strpos($source, "'{$action}' =>") !== false;
}

$failed = [];
foreach ($assertions as $label => $passed) {
    if (!$passed) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "catalog calc property migration tool static test failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo 'catalog calc property migration tool static test passed (' . count($assertions) . " assertions)\n";
