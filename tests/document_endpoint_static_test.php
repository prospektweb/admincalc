<?php
declare(strict_types=1);
$source = file_get_contents(dirname(__DIR__) . '/tools/documents.php');
$checks = 0;
foreach (['POST_REQUIRED', 'ADMIN_REQUIRED', 'INVALID_SESSION', 'check_bitrix_sessid()', 'CSite::GetByID', "'site:' . \$siteId", "'user:' . (int)\$USER->GetID()", 'Cache-Control: no-store, private'] as $anchor) {
    if (!str_contains($source, $anchor)) { throw new RuntimeException('Missing document HTTP authority: ' . $anchor); }
    $checks++;
}
if (strpos($source, 'check_bitrix_sessid()') > strpos($source, 'new \\Prospektweb\\Calc\\Documents\\DocumentApplication') || str_contains($source, 'DocumentSchema::install')) { throw new RuntimeException('Access checks must precede commands; DDL cannot run in the endpoint.'); }
echo 'PASS ' . ($checks + 1) . " document endpoint boundary checks\n";
