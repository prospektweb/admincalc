<?php
/** CLI-only signed live verification; optional CAS update of the unpublished pilot draft. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath($argv[1] ?? ''); $directory = realpath($argv[2] ?? ''); $mode = $argv[3] ?? '--verify';
if (!$root || !$directory || str_starts_with($directory . '/', $root . '/') || !is_file($root . '/bitrix/modules/main/include/prolog_before.php') || !in_array($mode, ['--verify', '--save-revision-2'], true)) throw new RuntimeException('Existing site root, private import directory and explicit mode required.');
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['REQUEST_METHOD'] = 'GET';
define('STOP_STATISTICS', true); define('NO_KEEP_STATISTIC', true);
require $root . '/bitrix/modules/main/include/prolog_before.php';
if (!\Bitrix\Main\Loader::includeModule('prospektweb.calc')) throw new RuntimeException('Calculator module unavailable.');
$module = $root . '/bitrix/modules/prospektweb.calc';
require_once $module . '/lib/Services/CalcServerRequestSigner.php';
$sourceHash = '61791207acb91446debf1057f30c14d070c5d9069b44116991b21b6fdce59192';
$oldDocumentHash = '7062bfcf8482d9d10778212da78bbd5182e15f682526d22d37e0b25fa2a99751';
$newDocumentHash = 'a4066135f4e8bf7d450eff4c54d4e4d25c966d209056e9d0e0a11faf2db590a1';
$id = 'db538f5a-4cb1-8c8f-a113-93120de3f035';
$activeHash = static fn() => (new \Prospektweb\Calc\Services\CalculatorVersionRuntimePublicationService())->resolve(12740)['contentHash'] ?? null;
if ($activeHash() !== $sourceHash) throw new RuntimeException('Source pilot changed; refresh import and parity checks.');
$json = file_get_contents($directory . '/document.canonical.json');
$resources = file_get_contents($directory . '/resourceSnapshots.json');
$checks = json_decode(file_get_contents($directory . '/checks.json'), true, 64, JSON_THROW_ON_ERROR);
if (hash('sha256', $json) !== $newDocumentHash || json_decode($json, true, 64, JSON_THROW_ON_ERROR)['id'] !== $id || count($checks) !== 4) throw new RuntimeException('Pinned import or checks mismatch.');
$clientId = trim((string)(getenv('PROSPEKTWEB_CALC_SERVER_CLIENT_ID') ?: getenv('PROSPEKTWEB_FRONTCALC_CLIENT_ID') ?: 'prospektprint-production'));
$secret = trim((string)(getenv('PROSPEKTWEB_CALC_SERVER_SHARED_SECRET') ?: getenv('PROSPEKTWEB_FRONTCALC_SHARED_SECRET') ?: ''));
if ($secret === '') {
    $secretFile = trim((string)(getenv('PROSPEKTWEB_CALC_SERVER_SECRET_FILE') ?: getenv('PROSPEKTWEB_FRONTCALC_SECRET_FILE') ?: dirname($root) . '/.frontcalc-secret'));
    if (!is_file($secretFile) || !is_readable($secretFile)) throw new RuntimeException('Configured signing secret is unavailable.');
    $secret = trim(file_get_contents($secretFile));
}
$signer = new \Prospektweb\Calc\Services\CalcServerRequestSigner($clientId, $secret); unset($secret);
$path = '/v1/calculators/preview';
$send = static function (string $body, array $headers) use ($path): array {
    $curl = curl_init('https://prospektweb-calc-server-13bd.twc1.net' . $path);
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
    $started = hrtime(true); $response = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $elapsed = (hrtime(true) - $started) / 1e6;
    if ($response === false) { curl_close($curl); throw new RuntimeException('Document preview transport failed.'); }
    curl_close($curl);
    return [$status, json_decode($response, true, 64, JSON_THROW_ON_ERROR), round($elapsed, 3)];
};
$equal = static function ($expected, $actual) use (&$equal): bool {
    if ((is_int($expected) || is_float($expected)) && (is_int($actual) || is_float($actual))) return abs($expected - $actual) <= max(1e-9, abs($expected) * 1e-12);
    if (is_array($expected) && is_array($actual)) {
        if (array_keys($expected) !== array_keys($actual)) return false;
        foreach ($expected as $key => $value) if (!$equal($value, $actual[$key])) return false;
        return true;
    }
    return $expected === $actual;
};
$report = [];
foreach ($checks as $index => $check) {
    $body = '{"contract":"prospektweb.calculator/technical-preview-v1","document":' . $json . ',"resources":' . $resources . ',"values":' . json_encode($check['values'], JSON_THROW_ON_ERROR) . '}';
    $headers = $signer->headers($body, 'POST', $path);
    if ($index === 0 && $send($body, [])[0] !== 401) throw new RuntimeException('Unsigned request was not rejected.');
    [$status, $response, $elapsed] = $send($body, $headers);
    if ($status !== $check['status']) throw new RuntimeException('Unexpected preview status for ' . $check['name'] . ': ' . $status . ' ' . json_encode($response['error'] ?? null));
    if ($status === 200 && (($response['documentHash'] ?? '') !== $newDocumentHash || !$equal($check['expected'], $response['result'] ?? null))) throw new RuntimeException('Live/local result or document hash differs: ' . $check['name']);
    if ($index === 0 && $send($body, $headers)[0] !== 409) throw new RuntimeException('Replayed signature was not rejected.');
    $report[] = ['name' => $check['name'], 'status' => $status, 'elapsedMs' => $elapsed, 'basePrice' => $response['result']['basePrice'] ?? null];
}
if ($activeHash() !== $sourceHash) throw new RuntimeException('Source changed during verification.');
$revision = null;
if ($mode === '--save-revision-2') {
    require_once $module . '/lib/Documents/BitrixConnection.php'; require_once $module . '/lib/Documents/DocumentRepository.php';
    $repo = new \Prospektweb\Calc\Documents\DocumentRepository(new \Prospektweb\Calc\Documents\BitrixConnection(\Bitrix\Main\Application::getConnection()), 'site:s1', 'migration:document-core-20260907');
    $before = $repo->load($id);
    if ($before['activePublication'] !== null) throw new RuntimeException('Draft is already published.');
    if ($before['revision'] === 1 && $before['bodyHash'] === $oldDocumentHash) {
        $backupPath = $directory . '/draft-revision-1.backup.json';
        if (is_file($backupPath)) { if (hash_file('sha256', $backupPath) !== $oldDocumentHash) throw new RuntimeException('Existing backup differs.'); }
        else { $handle = fopen($backupPath, 'x'); if (!$handle || fwrite($handle, $before['bodyJson']) !== strlen($before['bodyJson'])) throw new RuntimeException('Backup failed.'); fclose($handle); chmod($backupPath, 0600); }
        $saved = $repo->save($id, 1, $json);
    } elseif ($before['revision'] === 2 && $before['bodyHash'] === $newDocumentHash) $saved = $before;
    else throw new RuntimeException('Unexpected current revision; automatic overwrite prohibited.');
    $read = $repo->load($id); $old = $repo->load($id, 1);
    if ($read['revision'] !== 2 || $read['bodyJson'] !== $json || $old['bodyHash'] !== $oldDocumentHash || $read['activePublication'] !== null) throw new RuntimeException('Revision/readback verification failed.');
    $revision = $read['revision'];
}
echo json_encode(['cases' => $report, 'unsignedRejected' => true, 'replayRejected' => true, 'documentHash' => $newDocumentHash, 'savedRevision' => $revision,
    'activeDocumentPublication' => null, 'oldPilotUnchanged' => $activeHash() === $sourceHash,
    'artifactHashes' => ['document' => hash('sha256', $json), 'resources' => hash('sha256', $resources), 'checks' => hash_file('sha256', $directory . '/checks.json'), 'verifier' => hash_file('sha256', __FILE__)]], JSON_THROW_ON_ERROR) . "\n";
