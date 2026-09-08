<?php
declare(strict_types=1);

// Real endpoint and HTTP server; Bitrix infrastructure/service doubles only.
// Full calculation, transaction and write semantics have separate integration tests.
$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/prospekt-document-http-' . bin2hex(random_bytes(8));
$www = $temporary . '/www'; $module = $temporary . '/module';
$server = null; $pipes = []; $checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException($message); };
$remove = static function (string $path) use (&$remove): void {
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $child = $path . '/' . $name;
        if (is_dir($child) && !is_link($child)) $remove($child); else unlink($child);
    }
    rmdir($path);
};
try {
    mkdir($www . '/bitrix/modules/main/include', 0700, true);
    mkdir($module . '/lib/Documents', 0700, true);
    foreach (['BitrixConnection', 'DocumentApplication', 'BitrixCoreGateway', 'BitrixResourceProvider', 'DocumentCatalogWriteService', 'BitrixDocumentCatalogWritePort', 'BitrixDocumentCatalogTargets'] as $file) {
        file_put_contents($module . '/lib/Documents/' . $file . '.php', '<?php');
    }
    $prolog = <<<'PHP'
<?php
namespace Bitrix\Main {
    class Loader {
        public static function includeModule(string $name): bool { return true; }
        public static function getLocal(string $path): string { return $GLOBALS['test_module']; }
    }
    class Application {
        public static function getConnection(): object { return $GLOBALS['test_connection']; }
    }
}
namespace Prospektweb\Calc\Config {
    class ConfigManager {
        public function getOption(string $key, string $default): string {
            if ($key !== 'DOCUMENT_RESOURCE_PROVIDER') throw new \LogicException('Wrong provider source');
            return 'bitrix:server';
        }
    }
}
namespace Prospektweb\Calc\Documents {
    class BitrixConnection {
        public function __construct(public object $native, public bool $repeatable = false) {}
    }
    class DocumentRepository {
        public function __construct(public BitrixConnection $db, public string $scope, public string $actor) { $GLOBALS['test_repository'] = $this; }
    }
    class BitrixCoreGateway { public function __invoke(array $request): array { return []; } }
    class BitrixDocumentCatalogWritePort {
        public function __construct(public BitrixConnection $db, public string $scope, public string $actor) {}
    }
    class BitrixDocumentCatalogTargets {
        public function __construct(public BitrixConnection $db, public string $scope, public string $actor, public string $provider) {}
        public function command(array $command): array {
            $repo = $GLOBALS['test_repository'];
            if ($this->db->repeatable || $this->db->native !== $GLOBALS['test_connection'] || $repo->db !== $this->db
                || $repo->scope !== $this->scope || $repo->actor !== $this->actor) throw new \LogicException('Wrong read selector wiring');
            if (($command['id'] ?? '') === 'conflict') throw new \RuntimeException('Product binding changed', 409);
            if (($command['id'] ?? '') === 'invalid') throw new \InvalidArgumentException('Invalid selector command');
            return ['scope' => $this->scope, 'actor' => $this->actor, 'provider' => $this->provider, 'command' => $command];
        }
    }
    class DocumentCatalogWriteService {
        public function __construct(public BitrixConnection $db, public string $scope, public string $actor,
            public string $provider, public BitrixDocumentCatalogWritePort $port, public BitrixCoreGateway $core) {}
        public function command(array $command): array {
            $repo = $GLOBALS['test_repository'];
            if (!$this->db->repeatable || $this->db->native !== $GLOBALS['test_connection'] || $repo->db !== $this->db
                || $this->port->db !== $this->db || $repo->scope !== $this->scope || $this->port->scope !== $this->scope
                || $repo->actor !== $this->actor || $this->port->actor !== $this->actor) throw new \LogicException('Wrong authority wiring');
            if (($command['id'] ?? '') === 'conflict') throw new \RuntimeException('Publication changed', 409);
            if (($command['id'] ?? '') === 'unavailable') throw new \RuntimeException('private backend diagnostic', 503);
            if (($command['id'] ?? '') === 'invalid') throw new \InvalidArgumentException('Invalid catalog command');
            return ['scope' => $this->scope, 'actor' => $this->actor, 'provider' => $this->provider,
                'sharedRepeatableConnection' => true, 'command' => $command];
        }
    }
}
namespace {
    class CSite {
        public static function GetByID(string $id): object { return new class($id) {
            public function __construct(private string $id) {}
            public function Fetch() { return in_array($this->id, ['s1', 's2'], true) ? ['LID' => $this->id] : false; }
        }; }
    }
    $APPLICATION = new class { public function RestartBuffer(): void {} };
    $USER = new class {
        public function IsAdmin(): bool { return ($_SERVER['HTTP_X_TEST_ADMIN'] ?? '') === 'yes'; }
        public function GetID(): int { return 17; }
    };
    function check_bitrix_sessid(): bool { return ($_POST['sessid'] ?? '') === 'valid'; }
    $GLOBALS['test_connection'] = new \stdClass();
}
PHP;
    file_put_contents($www . '/bitrix/modules/main/include/prolog_admin_before.php', $prolog);
    file_put_contents($www . '/documents.php', '<?php $GLOBALS["test_module"] = ' . var_export($module, true) . '; require ' . var_export($root . '/tools/documents.php', true) . ';');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException($error);
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $port = (int)substr(strrchr($address, ':'), 1);
    $server = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-d', 'post_max_size=12M', '-S', '127.0.0.1:' . $port, '-t', $www],
        [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/server.log', 'a'], 2 => ['file', $temporary . '/server.log', 'a']], $pipes, $temporary, null, ['bypass_shell' => true]);
    if (!is_resource($server)) throw new RuntimeException('Cannot start transport server');
    fclose($pipes[0]); $pipes = [];
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $error, .1);
        if ($probe) { fclose($probe); $ready = true; break; }
        usleep(50000);
    }
    $check($ready, 'HTTP server ready');
    $send = static function ($envelope, string $method = 'POST', bool $admin = true, string $sessid = 'valid', bool $rawJson = false) use ($port): array {
        $payload = is_string($envelope) ? $envelope : json_encode($envelope, JSON_THROW_ON_ERROR);
        $body = $rawJson ? $payload : http_build_query(['payload' => $payload, 'sessid' => $sessid], '', '&', PHP_QUERY_RFC3986);
        $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 8,
            'header' => 'Content-Type: ' . ($rawJson ? 'application/json' : 'application/x-www-form-urlencoded') . "\r\nX-Test-Admin: " . ($admin ? 'yes' : 'no') . "\r\nConnection: close\r\n", 'content' => $body]]);
        $raw = file_get_contents('http://127.0.0.1:' . $port . '/documents.php', false, $context);
        $headers = $http_response_header ?? []; preg_match('/\s(\d{3})\s/', $headers[0] ?? '', $status);
        return ['status' => (int)($status[1] ?? 0), 'body' => json_decode($raw, true, 64, JSON_THROW_ON_ERROR), 'headers' => implode("\n", $headers)];
    };
    $command = ['action' => 'previewCatalogWrite', 'id' => 'native-document', 'publicationId' => 's_' . str_repeat('a', 64), 'offerIds' => [102, 101]];
    $envelope = ['siteId' => 's1', 'command' => $command];
    $selector = ['action' => 'catalogWriteTargets', 'id' => 'native-document', 'publicationId' => $command['publicationId'], 'productId' => 42, 'afterId' => 100];
    $r = $send(['siteId' => 's2', 'command' => $selector]);
    $check($r['status'] === 200 && $r['body']['data']['command'] === $selector, 'Selector native identity, product and cursor forwarded intact');
    $check($r['body']['data']['scope'] === 'site:s2' && $r['body']['data']['actor'] === 'user:17' && $r['body']['data']['provider'] === 'bitrix:server', 'Selector server-owned scope');
    foreach (['actor', 'provider', 'prices', 'presetId'] as $field) {
        $r = $send(['siteId' => 's1', 'command' => $selector + [$field => 'hostile']]);
        $check($r['body']['data']['command'][$field] === 'hostile' && $r['body']['data']['actor'] === 'user:17', 'Selector retains extra fields for strict service rejection');
    }
    foreach ([['GET', true, 'valid', 405], ['POST', false, 'valid', 403], ['POST', true, 'stale', 403]] as [$method, $admin, $sessid, $status]) {
        $check($send(['siteId' => 's1', 'command' => $selector], $method, $admin, $sessid)['status'] === $status, 'Selector does not bypass authorization');
    }
    foreach ([['conflict', 409], ['invalid', 422]] as [$id, $status]) {
        $check($send(['siteId' => 's1', 'command' => array_replace($selector, ['id' => $id])])['status'] === $status, 'Selector errors translated');
    }
    foreach (['previewCatalogWrite', 'applyCatalogWrite'] as $action) {
        $command['action'] = $action;
        if ($action === 'applyCatalogWrite') $command['expectedFingerprint'] = str_repeat('b', 64);
        $response = $send(['siteId' => 's1', 'command' => $command]); $data = $response['body']['data'] ?? [];
        $check($response['status'] === 200 && $response['body']['success'] === true, 'Catalog action reaches service');
        $check($data['command'] === $command, 'Complete command forwarded without preset translation or lost fingerprint');
        $check($data['scope'] === 'site:s1' && $data['actor'] === 'user:17' && $data['provider'] === 'bitrix:server' && $data['sharedRepeatableConnection'], 'Server-owned authority and shared database');
        $check(str_contains(strtolower($response['headers']), 'cache-control: no-store, private'), 'No HTTP caching');
    }
    $response = $send(['siteId' => 's2', 'command' => $command]);
    $check($response['body']['data']['scope'] === 'site:s2', 'Validated selected site is forwarded');
    // Never silently strip hostile fields: real service must see/reject them.
    foreach (['actor', 'provider', 'prices', 'values', 'presetId', 'services'] as $field) {
        $response = $send(['siteId' => 's1', 'command' => $command + [$field => 'hostile']]);
        $check(($response['body']['data']['command'][$field] ?? null) === 'hostile', 'Extra command field reaches strict service validation');
        $check($response['body']['data']['actor'] === 'user:17' && $response['body']['data']['provider'] === 'bitrix:server', 'Extra command field cannot change trusted context');
    }
    foreach ([['GET', true, 'valid', 405, 'POST_REQUIRED'], ['POST', false, 'valid', 403, 'ADMIN_REQUIRED'], ['POST', true, 'stale', 403, 'INVALID_SESSION']] as [$method, $admin, $sessid, $status, $code]) {
        $r = $send($envelope, $method, $admin, $sessid);
        $check($r['status'] === $status && $r['body']['error'] === $code, 'Transport authorization guard');
    }
    foreach (['[]', '{', str_repeat('x', 8500001), ['siteId' => 'absent', 'command' => $command], ['siteId' => 's9', 'command' => $command], ['siteId' => 's1', 'command' => []], $envelope + ['actor' => 'user:1']] as $bad) {
        $r = $send($bad); $check($r['status'] === 422 && $r['body']['error'] === 'DOCUMENT_INVALID', 'Strict envelope and size rejection');
    }
    $check($send($envelope, 'POST', true, 'valid', true)['status'] === 403, 'Raw JSON does not bypass Bitrix form sessid');
    foreach ([['conflict', 409, 'REVISION_CONFLICT'], ['invalid', 422, 'DOCUMENT_INVALID'], ['unavailable', 503, 'DOCUMENT_UNAVAILABLE']] as [$id, $status, $code]) {
        $r = $send(['siteId' => 's1', 'command' => array_replace($command, ['id' => $id])]);
        $check($r['status'] === $status && $r['body']['error'] === $code, 'Service error translated to HTTP');
        $check(!str_contains(json_encode($r), 'private backend diagnostic'), 'Private backend detail hidden');
    }
    echo "PASS $checks document catalog HTTP transport checks\n";
} finally {
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if (is_dir($temporary)) $remove($temporary);
}
