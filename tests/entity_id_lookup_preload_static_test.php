<?php

$source = file_get_contents(dirname(__DIR__) . '/lib/Calculator/InitPayloadService.php');
if (!is_string($source)
    || !str_contains($source, 'extractEntityIdLookupTypesFromStages')
    || !str_contains($source, 'loadActiveElementIds')
    || !str_contains($source, "['IBLOCK_ID' => \$iblockId, 'ACTIVE' => 'Y']")
    || !str_contains($source, "\$normalized['candidates'] ?? null) !== []")
    || !str_contains($source, "\$normalized['comparisons'][0]['parameter_code'] ?? '') !== 'entity.id'")) {
    fwrite(STDERR, "FAIL: ID-only entity selection must preload every active element of the fallback kind.\n");
    exit(1);
}

fwrite(STDOUT, "OK\n");
