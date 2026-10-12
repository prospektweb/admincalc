<?php
declare(strict_types=1);
$source=file_get_contents(dirname(__DIR__).'/lib/Documents/BitrixCoreGateway.php');
foreach (["getOption('CALC_SERVER_CLIENT_ID', '')", 'Invalid configured calculation server client identity.', "getenv('PROSPEKTWEB_CALC_SERVER_CLIENT_ID')", "'/v1/calculators/commands'", 'CURLOPT_SSL_VERIFYPEER => true'] as $required) {
    if (!str_contains($source,$required)) throw new RuntimeException('Missing native client boundary: '.$required);
}
echo "document_core_client_config_test: PASS\n";
