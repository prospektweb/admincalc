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
            return in_array($moduleId, ['prospektweb.calc', 'prospektweb.frontcalc'], true);
        }
    }
}

namespace Prospektweb\Frontcalc\Service {
    class StorefrontRepository
    {
        public const CONTRACT = 'prospektweb.frontcalc.storefront-definition/v2';

        private array $records = [];

        private array $deleted = [];

        public function listStorefronts(int $presetId): array
        {
            return ['contract' => self::CONTRACT, 'preset_id' => $presetId, 'items' => []];
        }

        public function get(string $id): ?array
        {
            if (isset($this->deleted[$id])) {
                return null;
            }
            return $this->records[$id] ?? $this->definition($id, 4);
        }

        public function save(array $definition): array
        {
            if (($definition['name'] ?? '') === 'Must not reach repository') {
                throw new \RuntimeException('repository save invoked after semantic rejection');
            }
            if (($definition['name'] ?? '') === 'Conflict') {
                throw new \RuntimeException('Storefront revision conflict', 409);
            }
            $definition['revision'] = (int)$definition['revision'] + 1;
            $this->records[(string)$definition['id']] = $definition;
            return $definition;
        }

        public function delete(string $id, int $expectedRevision): array
        {
            if ($id === 'must-not-delete') {
                throw new \RuntimeException('delete was called before preset verification', 409);
            }
            $definition = $this->get($id) ?? $this->definition($id, $expectedRevision);
            $this->deleted[$id] = true;
            unset($this->records[$id]);
            return $definition;
        }

        private function definition(string $id, int $revision): array
        {
            return [
                'contract' => self::CONTRACT,
                'id' => $id,
                'preset_id' => 41,
                'name' => 'Main storefront',
                'active' => true,
                'revision' => $revision,
                'presentation' => ['field_patches' => []],
                'product_ids' => [11],
            ];
        }
    }

    class FormFirstAuthoringStore
    {
        public static function publishedBundleForPreset(int $presetId): ?array
        {
            $authoring = [
                'formDefinition' => ['contract' => 'prospektweb.frontcalc.form-definition/v1'],
                'bindingDefinition' => ['contract' => 'prospektweb.frontcalc.binding-definition/v1'],
                'publication' => ['revision' => 3, 'compileHash' => str_repeat('a', 64)],
            ];
            $snapshot = [
                '_form_first' => ['publishedRevision' => 3, 'compileHash' => str_repeat('a', 64)],
                'fields' => ['paper' => ['label' => 'Paper']],
            ];
            return ['authoring' => $authoring, 'snapshot' => $snapshot];
        }
    }

    class StorefrontPresentationProjector
    {
        public function apply(array $snapshot, array $authoring, ?array $storefront): array
        {
            if (isset($storefront['presentation']['field_patches']['unknown.field'])) {
                throw new \InvalidArgumentException('Unknown storefront presentation field: unknown.field');
            }
            foreach (($storefront['presentation']['field_patches'] ?? []) as $fieldId => $patch) {
                foreach ($patch as $key => $value) {
                    $snapshot['fields'][$fieldId][$key] = $value;
                }
            }
            return $snapshot;
        }
    }
}

namespace Prospektweb\Calc\Services {
    class CalculatorVersionBundleDocumentService
    {
        public function __construct(array $adapters = []) {}
        public function load(int $presetId, string $versionId): ?array { return null; }
    }

    class CalculatorVersionSnapshotSourceService
    {
        public function __construct(array $adapters = []) {}
    }

    class CalculatorVersionComponentDocumentService
    {
        public function __construct(?CalculatorVersionBundleDocumentService $bundles = null) {}
    }

    class CalculatorVersionRegistryService
    {
        public function __construct(array $adapters = []) {}
    }

    class CalculatorVersionFormDocumentService
    {
        public function __construct(array $adapters = []) {}
    }

    class CalcServerRequestDeadline
    {
        public const MAX_BUDGET_MILLISECONDS = 300000;

        public function __construct(int $budgetMilliseconds = self::MAX_BUDGET_MILLISECONDS, ?callable $clock = null, ?int $startedAtNanoseconds = null)
        {
        }

        public function assertAvailable(): void
        {
        }
    }

    class CalcServerRequestDeadlineExceeded extends \RuntimeException
    {
        public const ERROR_CODE = 'CALC_SERVER_REQUEST_DEADLINE_EXCEEDED';
    }

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

    class ControlCenterEditorsService
    {
        public static function assertStorefrontAuthoritativeReadback(array $saved, $readBack): array
        {
            if (!is_array($readBack)
                || json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    !== json_encode($readBack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) {
                throw new \RuntimeException('Storefront authoritative save readback does not match the write');
            }
            return $readBack;
        }

        public function getCatalog(): array
        {
            return [
                'contract' => 'prospektweb.control-center.editors/v1',
                'calculations' => [],
                'storefront' => [
                    'formFirstAuthoringAvailable' => true,
                    'formFirstAuthoringContract' => 'prospektweb.frontcalc.form-first-authoring/v1',
                ],
                'transport' => 'ok',
            ];
        }

        public function assertStorefrontProductsBelongToPreset(int $presetId, array $productIds): void
        {
            $missing = array_values(array_diff($productIds, [11]));
            if ($missing !== []) {
                throw new \InvalidArgumentException(
                    'Storefront product_ids are not linked to preset #' . $presetId . ': #'
                    . implode(', #', $missing)
                );
            }
        }

        public function withPresetProductAssignmentLock(callable $criticalSection)
        {
            return $criticalSection(7);
        }

        public function withPresetMutation(
            int $presetId,
            array $metadata,
            callable $mutation,
            callable $authoritativeReadback
        ) {
            $authoritativeReadback();
            $result = $mutation();
            $authoritativeReadback();
            return $result;
        }

        public function validateCalculationLaunch(int $presetId, array $offerIds): array
        {
            return [
                'contract' => 'prospektweb.control-center.editors/v1',
                'focusPresetId' => $presetId,
                'productIds' => [10],
                'offerIds' => $offerIds,
                'transport' => 'ok',
            ];
        }

        public function loadFormFirstWorkspace(int $presetId): array
        {
            return $this->formFirstResult('form_first_load', compact('presetId'));
        }

        public function inspectFormFirstFieldDeletion(
            int $presetId,
            string $fieldId,
            ?string $propertyCode
        ): array {
            return [
                'contract' => 'prospektweb.calc.form-first-field-delete-impact/v1',
                'presetId' => $presetId,
                'fieldId' => $fieldId,
                'propertyCode' => $propertyCode,
                'removable' => true,
                'blockers' => [],
                'dependencyFingerprint' => str_repeat('d', 64),
            ];
        }

        public function saveFormFirstDraft(
            int $presetId,
            string $expectedAggregateRevision,
            array $formDefinition,
            array $bindingDefinition
        ): array {
            if ($expectedAggregateRevision === str_repeat('f', 64)) {
                throw new \RuntimeException('Aggregate changed', 409);
            }
            $field = $formDefinition['fields'][0] ?? null;
            $nodeKinds = null;
            if ($field instanceof \stdClass) {
                $nodeKinds = [
                    'emptyObject' => gettype($field->emptyObject ?? null),
                    'emptyList' => gettype($field->emptyList ?? null),
                    'numericKeyObject' => gettype($field->numericKeyObject ?? null),
                ];
            }
            return $this->formFirstResult('form_first_save_draft', compact(
                'presetId',
                'expectedAggregateRevision',
                'formDefinition',
                'bindingDefinition',
                'nodeKinds'
            ));
        }

        public function previewFormFirst(
            int $presetId,
            array $formDefinition,
            array $bindingDefinition
        ): array {
            return $this->formFirstResult('form_first_preview', compact(
                'presetId',
                'formDefinition',
                'bindingDefinition'
            ));
        }

        public function publishFormFirst(
            int $presetId,
            string $expectedAggregateRevision,
            string $expectedCompileHash
        ): array {
            return $this->formFirstResult('form_first_publish', compact(
                'presetId',
                'expectedAggregateRevision',
                'expectedCompileHash'
            ));
        }

        public function rollbackFormFirst(
            int $presetId,
            string $expectedAggregateRevision,
            int $targetPublishedRevision
        ): array {
            return $this->formFirstResult('form_first_rollback', compact(
                'presetId',
                'expectedAggregateRevision',
                'targetPublishedRevision'
            ));
        }

        private function formFirstResult(string $operation, array $extra): array
        {
            return array_merge([
                'contract' => 'prospektweb.frontcalc.form-first-authoring/v1',
                'operation' => $operation,
                'transport' => 'ok',
            ], $extra);
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
        'editors.php' => $root . '/tools/control_center_editors.php',
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

    $editorsCatalog = $post('editors.php', 'application/x-www-form-urlencoded', $form([
        'sessid' => 'valid',
        'payload' => json_encode(['action' => 'catalog'], JSON_UNESCAPED_SLASHES),
    ]));
    $assert($editorsCatalog['status'] === 200
        && !array_key_exists('focusPresetId', $editorsCatalog['body']['data'] ?? [])
        && array_keys($editorsCatalog['body']['data']['storefront'] ?? [])
            === ['formFirstAuthoringAvailable', 'formFirstAuthoringContract']
        && ($editorsCatalog['body']['data']['storefront']['formFirstAuthoringAvailable'] ?? false) === true
        && ($editorsCatalog['body']['data']['storefront']['formFirstAuthoringContract'] ?? '')
            === 'prospektweb.frontcalc.form-first-authoring/v1'
        && ($editorsCatalog['body']['data']['transport'] ?? '') === 'ok',
        'Editors catalog must expose only the active preset-owned form capability');

    $editorsCalculation = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'validate_calculation_launch',
        'presetId' => 41,
        'offerIds' => [101],
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsCalculation['status'] === 200
        && ($editorsCalculation['body']['data']['offerIds'] ?? []) === [101],
        'Editors raw JSON must pass the selective list through server validation');

    $storefrontId = 'main-storefront';
    $storefrontDefinition = [
        'contract' => 'prospektweb.frontcalc.storefront-definition/v2',
        'id' => $storefrontId,
        'preset_id' => 41,
        'name' => 'Main storefront',
        'active' => true,
        'revision' => 0,
        'presentation' => ['field_patches' => [
            'paper' => ['label' => 'Choose paper'],
        ]],
        'product_ids' => [11],
    ];

    $editorsStorefrontList = $post('editors.php', 'application/x-www-form-urlencoded', $form([
        'sessid' => 'valid',
        'payload' => json_encode(['action' => 'storefront_list', 'preset_id' => 41], JSON_UNESCAPED_SLASHES),
    ]));
    $assert($editorsStorefrontList['status'] === 200
        && ($editorsStorefrontList['body']['data']['contract'] ?? '') === 'prospektweb.frontcalc.storefront-definition/v2'
        && ($editorsStorefrontList['body']['data']['preset_id'] ?? 0) === 41
        && ($editorsStorefrontList['body']['data']['items'] ?? null) === [],
        'Storefront list must preserve the vNext preset-owned envelope');

    $editorsStorefrontGet = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_get',
        'preset_id' => 41,
        'id' => $storefrontId,
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontGet['status'] === 200
        && ($editorsStorefrontGet['body']['data']['id'] ?? '') === $storefrontId
        && ($editorsStorefrontGet['body']['data']['product_ids'] ?? []) === [11],
        'Storefront get must preserve the independent definition identity');

    $editorsStorefrontSave = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_save',
        'expected_revision' => 0,
        'storefront' => $storefrontDefinition,
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontSave['status'] === 200
        && ($editorsStorefrontSave['body']['data']['id'] ?? '') === $storefrontId
        && ($editorsStorefrontSave['body']['data']['revision'] ?? 0) === 1,
        'Storefront save must preserve exact vNext definition and CAS revision');

    $semanticallyInvalidStorefront = $storefrontDefinition;
    $semanticallyInvalidStorefront['name'] = 'Must not reach repository';
    $semanticallyInvalidStorefront['presentation']['field_patches'] = [
        'unknown.field' => ['label' => 'Unknown'],
    ];
    $editorsStorefrontSemanticError = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_save',
        'expected_revision' => 0,
        'storefront' => $semanticallyInvalidStorefront,
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontSemanticError['status'] === 422
        && ($editorsStorefrontSemanticError['body']['errorCode'] ?? '') === 'VALIDATION_ERROR'
        && str_contains(
            (string)($editorsStorefrontSemanticError['body']['error'] ?? ''),
            'Unknown storefront presentation field'
        ),
        'Storefront semantic projection must reject unknown fields before repository save');

    $outOfPresetStorefront = $storefrontDefinition;
    $outOfPresetStorefront['product_ids'] = [11, 12];
    $editorsStorefrontOutOfPreset = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_save',
        'expected_revision' => 0,
        'storefront' => $outOfPresetStorefront,
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontOutOfPreset['status'] === 422
        && ($editorsStorefrontOutOfPreset['body']['errorCode'] ?? '') === 'VALIDATION_ERROR'
        && ($editorsStorefrontOutOfPreset['body']['error'] ?? '')
            === 'Storefront product_ids are not linked to preset #41: #12',
        'Storefront save must reject product IDs outside current CALC_PRESET authority');

    $editorsStorefrontDelete = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_delete',
        'preset_id' => 41,
        'id' => $storefrontId,
        'expected_revision' => 4,
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontDelete['status'] === 200
        && ($editorsStorefrontDelete['body']['data']['deleted'] ?? false) === true
        && ($editorsStorefrontDelete['body']['data']['revision'] ?? 0) === 4,
        'Storefront delete must return the exact vNext CAS acknowledgement');

    $editorsStorefrontForeignDelete = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_delete',
        'preset_id' => 999,
        'id' => 'must-not-delete',
        'expected_revision' => 4,
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontForeignDelete['status'] === 422
        && ($editorsStorefrontForeignDelete['body']['errorCode'] ?? '') === 'VALIDATION_ERROR',
        'Storefront delete must verify preset ownership before repository mutation');

    $formDefinition = ['version' => 1, 'fields' => [['id' => 'quantity', 'type' => 'number']]];
    $bindingDefinition = [
        'version' => 1,
        'bindings' => [['fieldId' => 'quantity', 'target' => 'CALC_PROP_VOLUME']],
    ];
    $aggregateRevision = str_repeat('c', 64);
    $compileHash = str_repeat('d', 64);

    $formFirstLoad = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'form_first_load',
        'presetId' => 41,
    ], JSON_UNESCAPED_SLASHES));
    $assert($formFirstLoad['status'] === 200
        && ($formFirstLoad['body']['data']['operation'] ?? '') === 'form_first_load'
        && ($formFirstLoad['body']['data']['presetId'] ?? 0) === 41,
        'Form-first load must preserve the exact preset ID without product scope');

    $formFirstProductScoped = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'form_first_load',
        'presetId' => 41,
        'productId' => 4267,
    ], JSON_UNESCAPED_SLASHES));
    $assert(
        $formFirstProductScoped['status'] === 422
            && ($formFirstProductScoped['body']['errorCode'] ?? '') === 'VALIDATION_ERROR',
        'Preset-owned form authoring must reject the removed product-scoped request key'
    );

    $formFirstDeleteImpact = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'form_first_field_delete_impact',
        'presetId' => 41,
        'fieldId' => 'volume',
        'propertyCode' => 'CALC_PROP_VOLUME',
    ], JSON_UNESCAPED_SLASHES));
    $assert($formFirstDeleteImpact['status'] === 200
        && ($formFirstDeleteImpact['body']['data']['contract'] ?? '') === 'prospektweb.calc.form-first-field-delete-impact/v1'
        && ($formFirstDeleteImpact['body']['data']['fieldId'] ?? '') === 'volume'
        && ($formFirstDeleteImpact['body']['data']['propertyCode'] ?? '') === 'CALC_PROP_VOLUME',
        'Field deletion impact must preserve the exact field and property identity');

    $formFirstSave = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'form_first_save_draft',
        'presetId' => 41,
        'expectedAggregateRevision' => $aggregateRevision,
        'formDefinition' => $formDefinition,
        'bindingDefinition' => $bindingDefinition,
    ], JSON_UNESCAPED_SLASHES));
    $assert($formFirstSave['status'] === 200
        && ($formFirstSave['body']['data']['operation'] ?? '') === 'form_first_save_draft'
        && ($formFirstSave['body']['data']['expectedAggregateRevision'] ?? '') === $aggregateRevision,
        'Form-first draft save must preserve CAS and both typed documents');

    $nodeKindsPayload = '{'
        . '"sessid":"valid",'
        . '"action":"form_first_save_draft",'
        . '"presetId":41,'
        . '"expectedAggregateRevision":"' . $aggregateRevision . '",'
        . '"formDefinition":{'
            . '"version":1,'
            . '"fields":[{"id":"opaque","type":"number",'
                . '"emptyObject":{},'
                . '"emptyList":[],'
                . '"numericKeyObject":{"0":"zero","2":"two"}'
            . '}]'
        . '},'
        . '"bindingDefinition":{"version":1,"bindings":[]}'
        . '}';
    $nodeKindsSave = $post('editors.php', 'application/json', $nodeKindsPayload);
    $assert(
        $nodeKindsSave['status'] === 200
            && ($nodeKindsSave['body']['data']['nodeKinds'] ?? null) === [
            'emptyObject' => 'object',
            'emptyList' => 'array',
            'numericKeyObject' => 'object',
        ]
            && strpos((string)$nodeKindsSave['raw'], '"emptyObject":{}') !== false
            && strpos((string)$nodeKindsSave['raw'], '"emptyList":[]') !== false
            && strpos((string)$nodeKindsSave['raw'], '"numericKeyObject":{"0":"zero","2":"two"}') !== false,
        'Form-first transport must preserve nested {}, [] and numeric-key object identity before provider delegation'
    );

    $formFirstPreview = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'form_first_preview',
        'presetId' => 41,
        'formDefinition' => $formDefinition,
        'bindingDefinition' => $bindingDefinition,
    ], JSON_UNESCAPED_SLASHES));
    $assert($formFirstPreview['status'] === 200
        && ($formFirstPreview['body']['data']['operation'] ?? '') === 'form_first_preview',
        'Form-first preview must preserve both typed documents without a CAS write token');

    $formFirstPublish = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'form_first_publish',
        'presetId' => 41,
        'expectedAggregateRevision' => $aggregateRevision,
        'expectedCompileHash' => $compileHash,
    ], JSON_UNESCAPED_SLASHES));
    $assert($formFirstPublish['status'] === 200
        && ($formFirstPublish['body']['data']['operation'] ?? '') === 'form_first_publish'
        && ($formFirstPublish['body']['data']['expectedCompileHash'] ?? '') === $compileHash,
        'Form-first publish must preserve both exact lowercase SHA-256 guards');

    $formFirstRollback = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'form_first_rollback',
        'presetId' => 41,
        'expectedAggregateRevision' => $aggregateRevision,
        'targetPublishedRevision' => 0,
    ], JSON_UNESCAPED_SLASHES));
    $assert($formFirstRollback['status'] === 200
        && ($formFirstRollback['body']['data']['targetPublishedRevision'] ?? -1) === 0,
        'Form-first rollback must transport the pre-form-first revision zero');

    $formFirstInvalidPreset = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'form_first_load',
        'presetId' => '41',
    ], JSON_UNESCAPED_SLASHES));
    $assert($formFirstInvalidPreset['status'] === 422,
        'Form-first requests must reject a string preset ID before provider delegation');

    $formFirstInvalidRevision = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'form_first_publish',
        'presetId' => 41,
        'expectedAggregateRevision' => 'invalid',
        'expectedCompileHash' => $compileHash,
    ], JSON_UNESCAPED_SLASHES));
    $assert($formFirstInvalidRevision['status'] === 422,
        'Form-first writes must reject malformed aggregate revisions');

    $formFirstOversizedBinding = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'form_first_preview',
        'presetId' => 41,
        'formDefinition' => $formDefinition,
        'bindingDefinition' => ['version' => 1, 'padding' => str_repeat('x', 60001)],
    ], JSON_UNESCAPED_SLASHES));
    $assert($formFirstOversizedBinding['status'] === 422,
        'Form-first bindings must be rejected above the 60 KB transport cap');

    $formFirstConflict = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'form_first_save_draft',
        'presetId' => 41,
        'expectedAggregateRevision' => str_repeat('f', 64),
        'formDefinition' => $formDefinition,
        'bindingDefinition' => $bindingDefinition,
    ], JSON_UNESCAPED_SLASHES));
    $assert($formFirstConflict['status'] === 409
        && ($formFirstConflict['body']['errorCode'] ?? '') === 'REVISION_CONFLICT',
        'Form-first CAS conflicts must retain the stable HTTP 409 mapping');

    $editorsStorefrontExtraField = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_list',
        'preset_id' => 41,
        'unexpected' => true,
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontExtraField['status'] === 422
        && ($editorsStorefrontExtraField['body']['errorCode'] ?? '') === 'VALIDATION_ERROR',
        'Storefront requests must reject keys outside the exact action allowlist');

    $editorsStorefrontListDefinition = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_save',
        'expected_revision' => 0,
        'storefront' => [['contract' => 'prospektweb.frontcalc.storefront-definition/v2']],
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontListDefinition['status'] === 422
        && ($editorsStorefrontListDefinition['body']['errorCode'] ?? '') === 'VALIDATION_ERROR',
        'Storefront definition must be a JSON object rather than a list');

    $oversizedStorefront = $storefrontDefinition;
    $oversizedStorefront['presentation']['field_patches'] = ['paper' => ['help' => str_repeat('x', 131073)]];
    $editorsStorefrontOversized = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_save',
        'expected_revision' => 0,
        'storefront' => $oversizedStorefront,
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontOversized['status'] === 422
        && ($editorsStorefrontOversized['body']['errorCode'] ?? '') === 'VALIDATION_ERROR',
        'Storefront definition must be rejected above its transport cap');

    $editorsStorefrontInvalidRevision = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_save',
        'expected_revision' => '0',
        'storefront' => $storefrontDefinition,
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontInvalidRevision['status'] === 422
        && ($editorsStorefrontInvalidRevision['body']['errorCode'] ?? '') === 'VALIDATION_ERROR',
        'Storefront mutations must retain strict integer revisions');

    $editorsStorefrontMissingDefinition = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_save',
        'expected_revision' => 0,
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontMissingDefinition['status'] === 422
        && ($editorsStorefrontMissingDefinition['body']['errorCode'] ?? '') === 'VALIDATION_ERROR',
        'Storefront save must require its exact definition');

    $conflictingStorefront = $storefrontDefinition;
    $conflictingStorefront['name'] = 'Conflict';
    $editorsStorefrontConflict = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_save',
        'expected_revision' => 0,
        'storefront' => $conflictingStorefront,
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontConflict['status'] === 409
        && ($editorsStorefrontConflict['body']['errorCode'] ?? '') === 'REVISION_CONFLICT',
        'Storefront CAS conflicts must map to HTTP 409 REVISION_CONFLICT');

    $editorsStorefrontWrongPreset = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_get',
        'preset_id' => 999,
        'id' => $storefrontId,
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontWrongPreset['status'] === 422
        && ($editorsStorefrontWrongPreset['body']['errorCode'] ?? '') === 'VALIDATION_ERROR',
        'Storefront get must remain scoped to the requested preset');

    $editorsInvalid = $post('editors.php', 'application/x-www-form-urlencoded', $form([
        'sessid' => 'valid',
        'payload' => '[]',
    ]));
    $assert($editorsInvalid['status'] === 400 && ($editorsInvalid['body']['errorCode'] ?? '') === 'INVALID_JSON', 'Editors form payload must reject non-object JSON');

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
