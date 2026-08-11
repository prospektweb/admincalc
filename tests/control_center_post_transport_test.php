<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$temporaryRoot = rtrim(sys_get_temp_dir(), '/\\')
    . DIRECTORY_SEPARATOR
    . 'prospektweb-control-center-transport-'
    . bin2hex(random_bytes(8));
$documentRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'www';
$prologDirectory = $documentRoot . DIRECTORY_SEPARATOR . 'bitrix'
    . DIRECTORY_SEPARATOR . 'modules'
    . DIRECTORY_SEPARATOR . 'main'
    . DIRECTORY_SEPARATOR . 'include';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $entryPath = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($entryPath)) {
            $removeTree($entryPath);
        } else {
            @unlink($entryPath);
        }
    }
    @rmdir($path);
};

$server = null;
$pipes = [];

try {
    if (!mkdir($prologDirectory, 0700, true) && !is_dir($prologDirectory)) {
        throw new RuntimeException('Unable to create transport-test document root');
    }

    $prolog = <<<'PHP'
<?php
namespace Bitrix\Main\Config {
    class Option
    {
        public static function get(string $moduleId, string $name, $default = '')
        {
            return $default;
        }
    }
}

namespace Bitrix\Main {
    class Loader
    {
        public static function includeModule(string $moduleId): bool
        {
            return $moduleId === 'prospektweb.calc';
        }
    }
}

namespace Prospektweb\Calc\Services {
    class ControlCenterSettingsService
    {
        public function getSettings(): array
        {
            return ['transport' => 'ok', 'revision' => 'test'];
        }

        public function saveSettings(array $settings, string $revision): array
        {
            return ['transport' => 'ok', 'settings' => $settings, 'revision' => $revision];
        }
    }

    class ModuleCapabilityRegistryService
    {
        public function getCatalog(): array
        {
            return [
                'contract' => 'prospektweb.control-plane/catalog/v1',
                'revision' => str_repeat('a', 64),
                'summary' => [],
                'modules' => [],
                'transport' => 'ok',
            ];
        }

        public function setCapability(string $capabilityId, bool $enabled, string $revision, int $userId): array
        {
            return [
                'contract' => 'prospektweb.control-plane/catalog/v1',
                'revision' => $revision,
                'summary' => [],
                'modules' => [],
                'transport' => 'ok',
                'capabilityId' => $capabilityId,
                'enabled' => $enabled,
                'userId' => $userId,
            ];
        }
    }
}

namespace {
    class TransportTestApplication
    {
        public function RestartBuffer(): void
        {
        }
    }

    class TransportTestUser
    {
        public function IsAdmin(): bool
        {
            return true;
        }

        public function GetID(): int
        {
            return 1;
        }
    }

    function check_bitrix_sessid(): bool
    {
        return isset($_POST['sessid'])
            && is_scalar($_POST['sessid'])
            && hash_equals('valid', (string)$_POST['sessid']);
    }

    $APPLICATION = new TransportTestApplication();
    $USER = new TransportTestUser();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !check_bitrix_sessid()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'errorCode' => 'PROLOG_INVALID_SESSION']);
        exit;
    }
}
PHP;

    if (file_put_contents($prologDirectory . DIRECTORY_SEPARATOR . 'prolog_admin_before.php', $prolog) === false) {
        throw new RuntimeException('Unable to create fake Bitrix prolog');
    }

    foreach ([
        'settings.php' => $root . '/tools/control_center_settings.php',
        'modules.php' => $root . '/tools/control_center_modules.php',
        'batch.php' => $root . '/tools/batch_recalculate.php',
    ] as $wrapperName => $endpointPath) {
        $wrapper = '<?php require ' . var_export($endpointPath, true) . ';';
        if (file_put_contents($documentRoot . DIRECTORY_SEPARATOR . $wrapperName, $wrapper) === false) {
            throw new RuntimeException('Unable to create endpoint wrapper: ' . $wrapperName);
        }
    }

    $socket = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketErrorMessage);
    if (!is_resource($socket)) {
        throw new RuntimeException('Unable to reserve test port: ' . $socketErrorMessage);
    }
    $socketName = (string)stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int)substr(strrchr($socketName, ':'), 1);

    $serverLog = $temporaryRoot . DIRECTORY_SEPARATOR . 'server.log';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $serverLog, 'a'],
        2 => ['file', $serverLog, 'a'],
    ];
    $server = proc_open(
        [PHP_BINARY, '-d', 'display_errors=1', '-S', '127.0.0.1:' . $port, '-t', $documentRoot],
        $descriptors,
        $pipes,
        $temporaryRoot,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($server)) {
        throw new RuntimeException('Unable to start PHP transport-test server');
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
        unset($pipes[0]);
    }

    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @fsockopen('127.0.0.1', $port, $probeError, $probeErrorMessage, 0.1);
        if (is_resource($probe)) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    $assert($ready, 'PHP transport-test server did not start');

    $post = static function (string $path, string $contentType, string $body) use ($port): array {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: {$contentType}\r\n"
                    . 'Content-Length: ' . strlen($body) . "\r\n"
                    . "Connection: close\r\n",
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 5,
            ],
        ]);
        $responseBody = file_get_contents('http://127.0.0.1:' . $port . '/' . $path, false, $context);
        $headers = $http_response_header ?? [];
        $status = 0;
        if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $matches)) {
            $status = (int)$matches[1];
        }
        $decoded = is_string($responseBody) ? json_decode($responseBody, true) : null;

        return ['status' => $status, 'body' => $decoded, 'raw' => $responseBody];
    };

    $form = static function (array $fields): string {
        return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    };

    $settingsForm = $post('settings.php', 'application/x-www-form-urlencoded', $form([
        'sessid' => 'valid',
        'payload' => json_encode(['action' => 'get'], JSON_UNESCAPED_SLASHES),
    ]));
    $assert($settingsForm['status'] === 200 && ($settingsForm['body']['data']['transport'] ?? '') === 'ok', 'Settings form payload must pass prolog and decode');

    $settingsJson = $post('settings.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'get',
    ], JSON_UNESCAPED_SLASHES));
    $assert($settingsJson['status'] === 200 && ($settingsJson['body']['data']['transport'] ?? '') === 'ok', 'Settings raw JSON must remain compatible before prolog');

    $settingsFlatSave = $post('settings.php', 'application/x-www-form-urlencoded', $form([
        'sessid' => 'valid',
        'action' => 'save',
        'revision' => 'r1',
        'settings' => json_encode(['history' => ['enabled' => true]], JSON_UNESCAPED_SLASHES),
    ]));
    $assert($settingsFlatSave['status'] === 200 && ($settingsFlatSave['body']['data']['settings']['history']['enabled'] ?? false) === true, 'Flat settings form must decode its JSON settings object');

    $settingsInvalid = $post('settings.php', 'application/x-www-form-urlencoded', $form([
        'sessid' => 'valid',
        'payload' => '[]',
    ]));
    $assert($settingsInvalid['status'] === 400 && ($settingsInvalid['body']['errorCode'] ?? '') === 'INVALID_JSON', 'Settings form payload must reject non-object JSON');

    $settingsInvalidFlat = $post('settings.php', 'application/x-www-form-urlencoded', $form([
        'sessid' => 'valid',
        'action' => 'save',
        'settings' => '[]',
    ]));
    $assert($settingsInvalidFlat['status'] === 400 && ($settingsInvalidFlat['body']['errorCode'] ?? '') === 'INVALID_JSON', 'Flat settings form must reject non-object settings JSON');

    $modulesForm = $post('modules.php', 'application/x-www-form-urlencoded', $form([
        'sessid' => 'valid',
        'payload' => json_encode(['action' => 'get'], JSON_UNESCAPED_SLASHES),
    ]));
    $assert($modulesForm['status'] === 200 && ($modulesForm['body']['data']['transport'] ?? '') === 'ok', 'Modules form payload must pass prolog and decode');

    $modulesJson = $post('modules.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'get',
    ], JSON_UNESCAPED_SLASHES));
    $assert($modulesJson['status'] === 200 && ($modulesJson['body']['data']['transport'] ?? '') === 'ok', 'Modules raw JSON must remain compatible before prolog');

    $modulesSet = $post('modules.php', 'application/x-www-form-urlencoded', $form([
        'sessid' => 'valid',
        'payload' => json_encode([
            'action' => 'set',
            'revision' => str_repeat('a', 64),
            'capabilityId' => 'storefront.property_descriptions',
            'enabled' => false,
        ], JSON_UNESCAPED_SLASHES),
    ]));
    $assert($modulesSet['status'] === 200
        && ($modulesSet['body']['data']['capabilityId'] ?? '') === 'storefront.property_descriptions'
        && ($modulesSet['body']['data']['enabled'] ?? true) === false,
        'Modules set payload must preserve boolean state and synchronized field names');

    $modulesInvalid = $post('modules.php', 'application/x-www-form-urlencoded', $form([
        'sessid' => 'valid',
        'payload' => '[]',
    ]));
    $assert($modulesInvalid['status'] === 400 && ($modulesInvalid['body']['errorCode'] ?? '') === 'INVALID_JSON', 'Modules form payload must reject non-object JSON');

    $batchForm = $post('batch.php', 'application/x-www-form-urlencoded', $form([
        'sessid' => 'valid',
        'payload' => json_encode(['action' => 'transport-test'], JSON_UNESCAPED_SLASHES),
    ]));
    $assert($batchForm['status'] === 400 && ($batchForm['body']['errorCode'] ?? '') === 'UNSUPPORTED_ACTION', 'Batch form payload must pass prolog and decode');

    $batchJson = $post('batch.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'transport-test',
    ], JSON_UNESCAPED_SLASHES));
    $assert($batchJson['status'] === 400 && ($batchJson['body']['errorCode'] ?? '') === 'UNSUPPORTED_ACTION', 'Batch raw JSON must remain compatible before prolog');

    $batchInvalid = $post('batch.php', 'application/x-www-form-urlencoded', $form([
        'sessid' => 'valid',
        'payload' => '[]',
    ]));
    $assert($batchInvalid['status'] === 400 && ($batchInvalid['body']['errorCode'] ?? '') === 'INVALID_JSON', 'Batch form payload must reject non-object JSON');

    echo "Control center POST transport tests passed\n";
} finally {
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $removeTree($temporaryRoot);
}
