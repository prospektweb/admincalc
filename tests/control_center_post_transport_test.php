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

    class ControlCenterEditorsService
    {
        public function getCatalog(): array
        {
            return [
                'contract' => 'prospektweb.control-center.editors/v1',
                'focusPresetId' => 12740,
                'calculations' => [],
                'storefront' => [
                    'available' => true,
                    'visualEditorAvailable' => true,
                    'visualEditorContract' => 'prospektweb.frontcalc.storefront-editor/v1',
                    'formFirstAuthoringAvailable' => true,
                    'formFirstAuthoringContract' => 'prospektweb.frontcalc.form-first-authoring/v1',
                    'formFirstPilotProductIds' => [4267],
                    'productIblockId' => 7,
                    'products' => [],
                ],
                'transport' => 'ok',
            ];
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

        public function validateStorefrontLaunch(int $productId): array
        {
            return [
                'contract' => 'prospektweb.control-center.editors/v1',
                'productIblockId' => 7,
                'productId' => $productId,
                'transport' => 'ok',
            ];
        }

        public function loadStorefrontWorkspace(int $productId, string $target = 'effective', string $templateId = ''): array
        {
            if ($productId === 99) {
                throw new \RuntimeException('Visual editor unavailable');
            }
            return $this->storefrontResult('load', [
                'productId' => $productId,
                'target' => $target,
                'templateId' => $templateId,
            ]);
        }

        public function validateStorefrontSchema(int $productId, string $target, array $schema): array
        {
            return $this->storefrontResult('validate', [
                'productId' => $productId,
                'target' => $target,
                'schema' => $schema,
            ]);
        }

        public function saveStorefrontTemplate(
            int $productId,
            string $templateId,
            int $expectedRevision,
            string $name,
            int $sectionId,
            array $schema
        ): array {
            return $this->storefrontResult('save_template', [
                'productId' => $productId,
                'templateId' => $templateId,
                'expectedRevision' => $expectedRevision,
                'name' => $name,
                'sectionId' => $sectionId,
                'schema' => $schema,
            ]);
        }

        public function saveStorefrontProduct(int $productId, string $expectedRevision, array $schema): array
        {
            if ($expectedRevision === str_repeat('b', 64)) {
                throw new \RuntimeException('Individual settings changed', 409);
            }
            return $this->storefrontResult('save_product', [
                'productId' => $productId,
                'expectedRevision' => $expectedRevision,
                'schema' => $schema,
            ]);
        }

        public function enableStorefrontInheritance(int $productId, string $expectedRevision): array
        {
            return $this->storefrontResult('enable_inheritance', [
                'productId' => $productId,
                'expectedRevision' => $expectedRevision,
            ]);
        }

        public function deleteStorefrontTemplate(int $productId, string $templateId, int $expectedRevision): array
        {
            return $this->storefrontResult('delete_template', [
                'productId' => $productId,
                'templateId' => $templateId,
                'expectedRevision' => $expectedRevision,
            ]);
        }

        public function loadFormFirstWorkspace(int $productId, int $presetId): array
        {
            return $this->formFirstResult('form_first_load', compact('productId', 'presetId'));
        }

        public function inspectFormFirstFieldDeletion(
            int $productId,
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
                'productId' => $productId,
            ];
        }

        public function saveFormFirstDraft(
            int $productId,
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
                'productId',
                'presetId',
                'expectedAggregateRevision',
                'formDefinition',
                'bindingDefinition',
                'nodeKinds'
            ));
        }

        public function previewFormFirst(
            int $productId,
            int $presetId,
            array $formDefinition,
            array $bindingDefinition
        ): array {
            return $this->formFirstResult('form_first_preview', compact(
                'productId',
                'presetId',
                'formDefinition',
                'bindingDefinition'
            ));
        }

        public function publishFormFirst(
            int $productId,
            int $presetId,
            string $expectedAggregateRevision,
            string $expectedCompileHash
        ): array {
            return $this->formFirstResult('form_first_publish', compact(
                'productId',
                'presetId',
                'expectedAggregateRevision',
                'expectedCompileHash'
            ));
        }

        public function rollbackFormFirst(
            int $productId,
            int $presetId,
            string $expectedAggregateRevision,
            int $targetPublishedRevision
        ): array {
            return $this->formFirstResult('form_first_rollback', compact(
                'productId',
                'presetId',
                'expectedAggregateRevision',
                'targetPublishedRevision'
            ));
        }

        private function storefrontResult(string $operation, array $extra): array
        {
            return array_merge([
                'contract' => 'prospektweb.frontcalc.storefront-editor/v1',
                'operation' => $operation,
                'transport' => 'ok',
            ], $extra);
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

    class Phase5aParityContractService
    {
        public function build(): array
        {
            return [
                'contract' => 'prospektweb.calc.form-first-parity/v1',
                'presetId' => 12740,
                'readOnly' => true,
                'transport' => 'ok',
            ];
        }

        public function compare(array $baseline, array $candidate): array
        {
            return [
                'contract' => 'prospektweb.calc.form-first-golden-comparison/v1',
                'presetId' => 12740,
                'readOnly' => true,
                'valid' => $baseline === $candidate,
                'transport' => 'ok',
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
        && ($editorsCatalog['body']['data']['focusPresetId'] ?? 0) === 12740
        && ($editorsCatalog['body']['data']['storefront']['visualEditorAvailable'] ?? false) === true
        && ($editorsCatalog['body']['data']['storefront']['visualEditorContract'] ?? '')
            === 'prospektweb.frontcalc.storefront-editor/v1'
        && ($editorsCatalog['body']['data']['storefront']['formFirstAuthoringAvailable'] ?? false) === true
        && ($editorsCatalog['body']['data']['storefront']['formFirstPilotProductIds'] ?? []) === [4267]
        && ($editorsCatalog['body']['data']['transport'] ?? '') === 'ok',
        'Editors catalog form payload must pass prolog and decode');

    $editorsCalculation = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'validate_calculation_launch',
        'presetId' => 12740,
        'offerIds' => [101],
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsCalculation['status'] === 200
        && ($editorsCalculation['body']['data']['offerIds'] ?? []) === [101],
        'Editors raw JSON must pass the selective list through server validation');

    $editorsStorefront = $post('editors.php', 'application/x-www-form-urlencoded', $form([
        'sessid' => 'valid',
        'payload' => json_encode([
            'action' => 'validate_storefront_launch',
            'productId' => 11,
        ], JSON_UNESCAPED_SLASHES),
    ]));
    $assert($editorsStorefront['status'] === 200
        && ($editorsStorefront['body']['data']['productIblockId'] ?? 0) === 7,
        'Editors storefront form payload must preserve the validated product ID');

    $editorSchema = [
        'version' => 2,
        'fields' => [[
            'property_code' => 'CALC_PROP_VOLUME',
            'title' => 'Quantity',
        ]],
    ];
    $templateId = 'abcdef0123456789';
    $individualRevision = str_repeat('a', 64);

    $editorsStorefrontLoad = $post('editors.php', 'application/x-www-form-urlencoded', $form([
        'sessid' => 'valid',
        'payload' => json_encode([
            'action' => 'storefront_load',
            'productId' => 11,
            'target' => 'template',
            'templateId' => $templateId,
        ], JSON_UNESCAPED_SLASHES),
    ]));
    $assert($editorsStorefrontLoad['status'] === 200
        && ($editorsStorefrontLoad['body']['data']['operation'] ?? '') === 'load'
        && ($editorsStorefrontLoad['body']['data']['target'] ?? '') === 'template'
        && ($editorsStorefrontLoad['body']['data']['templateId'] ?? '') === $templateId,
        'Storefront load must preserve a validated template target');

    $editorsStorefrontValidate = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_validate',
        'productId' => 11,
        'target' => 'product',
        'schema' => $editorSchema,
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontValidate['status'] === 200
        && ($editorsStorefrontValidate['body']['data']['operation'] ?? '') === 'validate'
        && ($editorsStorefrontValidate['body']['data']['schema'] ?? []) === $editorSchema,
        'Storefront validate must preserve the typed schema object');

    $editorsStorefrontTemplateCreate = $post('editors.php', 'application/x-www-form-urlencoded', $form([
        'sessid' => 'valid',
        'payload' => json_encode([
            'action' => 'storefront_save_template',
            'productId' => 11,
            'templateId' => null,
            'expectedRevision' => 0,
            'name' => 'New template',
            'sectionId' => 0,
            'schema' => $editorSchema,
        ], JSON_UNESCAPED_SLASHES),
    ]));
    $assert($editorsStorefrontTemplateCreate['status'] === 200
        && ($editorsStorefrontTemplateCreate['body']['data']['operation'] ?? '') === 'save_template'
        && ($editorsStorefrontTemplateCreate['body']['data']['templateId'] ?? null) === ''
        && ($editorsStorefrontTemplateCreate['body']['data']['expectedRevision'] ?? -1) === 0,
        'Storefront template create must map a null template ID to the provider empty ID');

    $editorsStorefrontProductSave = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_save_product',
        'productId' => 11,
        'expectedRevision' => $individualRevision,
        'schema' => $editorSchema,
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontProductSave['status'] === 200
        && ($editorsStorefrontProductSave['body']['data']['operation'] ?? '') === 'save_product'
        && ($editorsStorefrontProductSave['body']['data']['expectedRevision'] ?? '') === $individualRevision,
        'Storefront product save must preserve the individual SHA-256 revision');

    $editorsStorefrontInheritance = $post('editors.php', 'application/x-www-form-urlencoded', $form([
        'sessid' => 'valid',
        'payload' => json_encode([
            'action' => 'storefront_enable_inheritance',
            'productId' => 11,
            'expectedRevision' => $individualRevision,
        ], JSON_UNESCAPED_SLASHES),
    ]));
    $assert($editorsStorefrontInheritance['status'] === 200
        && ($editorsStorefrontInheritance['body']['data']['operation'] ?? '') === 'enable_inheritance',
        'Storefront inheritance must pass through the existing editors transport');

    $editorsStorefrontDelete = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_delete_template',
        'productId' => 11,
        'templateId' => $templateId,
        'expectedRevision' => 4,
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontDelete['status'] === 200
        && ($editorsStorefrontDelete['body']['data']['operation'] ?? '') === 'delete_template'
        && ($editorsStorefrontDelete['body']['data']['expectedRevision'] ?? 0) === 4,
        'Storefront template delete must preserve the positive template revision');

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
        'productId' => 4267,
        'presetId' => 12740,
    ], JSON_UNESCAPED_SLASHES));
    $assert($formFirstLoad['status'] === 200
        && ($formFirstLoad['body']['data']['operation'] ?? '') === 'form_first_load'
        && ($formFirstLoad['body']['data']['presetId'] ?? 0) === 12740,
        'Form-first load must preserve the exact product and preset pilot IDs');

    $formFirstDeleteImpact = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'form_first_field_delete_impact',
        'productId' => 4267,
        'presetId' => 12740,
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
        'productId' => 4267,
        'presetId' => 12740,
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
        . '"productId":4267,'
        . '"presetId":12740,'
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
        'productId' => 4267,
        'presetId' => 12740,
        'formDefinition' => $formDefinition,
        'bindingDefinition' => $bindingDefinition,
    ], JSON_UNESCAPED_SLASHES));
    $assert($formFirstPreview['status'] === 200
        && ($formFirstPreview['body']['data']['operation'] ?? '') === 'form_first_preview',
        'Form-first preview must preserve both typed documents without a CAS write token');

    $formFirstPublish = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'form_first_publish',
        'productId' => 4267,
        'presetId' => 12740,
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
        'productId' => 4267,
        'presetId' => 12740,
        'expectedAggregateRevision' => $aggregateRevision,
        'targetPublishedRevision' => 0,
    ], JSON_UNESCAPED_SLASHES));
    $assert($formFirstRollback['status'] === 200
        && ($formFirstRollback['body']['data']['targetPublishedRevision'] ?? -1) === 0,
        'Form-first rollback must transport the pre-form-first revision zero');

    $formFirstInvalidPreset = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'form_first_load',
        'productId' => 4267,
        'presetId' => '12740',
    ], JSON_UNESCAPED_SLASHES));
    $assert($formFirstInvalidPreset['status'] === 422,
        'Form-first requests must reject a string preset ID before provider delegation');

    $formFirstInvalidRevision = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'form_first_publish',
        'productId' => 4267,
        'presetId' => 12740,
        'expectedAggregateRevision' => 'invalid',
        'expectedCompileHash' => $compileHash,
    ], JSON_UNESCAPED_SLASHES));
    $assert($formFirstInvalidRevision['status'] === 422,
        'Form-first writes must reject malformed aggregate revisions');

    $formFirstOversizedBinding = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'form_first_preview',
        'productId' => 4267,
        'presetId' => 12740,
        'formDefinition' => $formDefinition,
        'bindingDefinition' => ['version' => 1, 'padding' => str_repeat('x', 60001)],
    ], JSON_UNESCAPED_SLASHES));
    $assert($formFirstOversizedBinding['status'] === 422,
        'Form-first bindings must be rejected above the 60 KB transport cap');

    $formFirstConflict = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'form_first_save_draft',
        'productId' => 4267,
        'presetId' => 12740,
        'expectedAggregateRevision' => str_repeat('f', 64),
        'formDefinition' => $formDefinition,
        'bindingDefinition' => $bindingDefinition,
    ], JSON_UNESCAPED_SLASHES));
    $assert($formFirstConflict['status'] === 409
        && ($formFirstConflict['body']['errorCode'] ?? '') === 'REVISION_CONFLICT',
        'Form-first CAS conflicts must retain the stable HTTP 409 mapping');

    $parityContract = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'phase5a_parity_contract',
    ], JSON_UNESCAPED_SLASHES));
    $assert($parityContract['status'] === 200
        && ($parityContract['body']['data']['contract'] ?? '')
            === 'prospektweb.calc.form-first-parity/v1'
        && ($parityContract['body']['data']['readOnly'] ?? false) === true,
        'The Phase 5A parity contract must be available through the read-only POST action');

    $observation = [
        'contract' => 'prospektweb.calc.form-first-golden-observation/v1',
        'presetId' => 12740,
        'products' => [],
    ];
    $parityCompare = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'phase5a_parity_compare',
        'baseline' => $observation,
        'candidate' => $observation,
    ], JSON_UNESCAPED_SLASHES));
    $assert($parityCompare['status'] === 200
        && ($parityCompare['body']['data']['valid'] ?? false) === true,
        'The Phase 5A comparator must accept bounded read-only observation objects');

    $parityCompareOversized = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'phase5a_parity_compare',
        'baseline' => ['padding' => str_repeat('x', 60001)],
        'candidate' => $observation,
    ], JSON_UNESCAPED_SLASHES));
    $assert($parityCompareOversized['status'] === 422,
        'The Phase 5A comparator must reject oversized observation objects');

    $editorsStorefrontExtraField = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_load',
        'productId' => 11,
        'target' => 'effective',
        'unexpected' => true,
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontExtraField['status'] === 422
        && ($editorsStorefrontExtraField['body']['errorCode'] ?? '') === 'VALIDATION_ERROR',
        'Storefront requests must reject keys outside the exact action allowlist');

    $editorsStorefrontListSchema = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_validate',
        'productId' => 11,
        'target' => 'product',
        'schema' => [['version' => 2]],
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontListSchema['status'] === 422
        && ($editorsStorefrontListSchema['body']['errorCode'] ?? '') === 'VALIDATION_ERROR',
        'Storefront schema must be a JSON object rather than a list');

    $editorsStorefrontOversizedSchema = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_validate',
        'productId' => 11,
        'target' => 'product',
        'schema' => ['version' => 2, 'padding' => str_repeat('x', 60001)],
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontOversizedSchema['status'] === 422
        && ($editorsStorefrontOversizedSchema['body']['errorCode'] ?? '') === 'VALIDATION_ERROR',
        'Storefront schema must be rejected above the 60 KB JSON cap');

    $editorsStorefrontInvalidRevision = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_save_product',
        'productId' => 11,
        'expectedRevision' => 'not-a-revision',
        'schema' => $editorSchema,
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontInvalidRevision['status'] === 422
        && ($editorsStorefrontInvalidRevision['body']['errorCode'] ?? '') === 'VALIDATION_ERROR',
        'Storefront product mutations must require an exact lowercase SHA-256 revision');

    $editorsStorefrontStringTemplateRevision = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_delete_template',
        'productId' => 11,
        'templateId' => $templateId,
        'expectedRevision' => '4',
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontStringTemplateRevision['status'] === 422
        && ($editorsStorefrontStringTemplateRevision['body']['errorCode'] ?? '') === 'VALIDATION_ERROR',
        'Storefront template revisions must retain their strict integer type');

    $editorsStorefrontMissingTemplateId = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_save_template',
        'productId' => 11,
        'expectedRevision' => 0,
        'name' => 'New template',
        'sectionId' => 0,
        'schema' => $editorSchema,
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontMissingTemplateId['status'] === 422
        && ($editorsStorefrontMissingTemplateId['body']['errorCode'] ?? '') === 'VALIDATION_ERROR',
        'Storefront template creation must distinguish an explicit null ID from an omitted field');

    $editorsStorefrontConflict = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_save_product',
        'productId' => 11,
        'expectedRevision' => str_repeat('b', 64),
        'schema' => $editorSchema,
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontConflict['status'] === 409
        && ($editorsStorefrontConflict['body']['errorCode'] ?? '') === 'REVISION_CONFLICT',
        'Provider revision conflicts must map to HTTP 409 REVISION_CONFLICT');

    $editorsStorefrontUnavailable = $post('editors.php', 'application/json', json_encode([
        'sessid' => 'valid',
        'action' => 'storefront_load',
        'productId' => 99,
        'target' => 'effective',
    ], JSON_UNESCAPED_SLASHES));
    $assert($editorsStorefrontUnavailable['status'] === 409
        && ($editorsStorefrontUnavailable['body']['errorCode'] ?? '') === 'EDITOR_UNAVAILABLE',
        'Unavailable storefront providers must fail closed with HTTP 409');

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
