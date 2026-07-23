<?php

namespace Prospektweb\Calc\Services;

/**
 * Управляемый и обратимый патч Aspro AI для Timeweb Cloud AI Gateway.
 *
 * Патч ограничен поддержанными файлами aspro.ai 1.1.1. Он меняет выбор Base
 * URL перед созданием штатного HTTP-клиента и выбирает ChatGPT по умолчанию
 * во всех формах генерации. Endpoints, Bearer-авторизация, proxy, фоновые
 * задания и обработка ответов остаются штатными.
 */
final class AsproAiPatchManager
{
    public const PATCH_ID = 'prospektweb.calc.aspro-ai-timeweb';
    public const PATCH_VERSION = '1.1.0';
    public const ASPRO_MODULE_ID = 'aspro.ai';
    public const SUPPORTED_ASPRO_VERSION = '1.1.1';
    public const TARGET_RELATIVE_PATH = 'lib/services/chatgpt.php';

    private const PROVIDER_TARGET_RELATIVE_PATHS = [
        'html/popup.php',
        'tools/popup_ajax.php',
        'tools/popup_group_ajax.php',
    ];

    private const BEGIN_MARKER = '/* PROSPEKTWEB.CALC ASPRO AI PATCH BEGIN v1.0.0 */';
    private const END_MARKER = '/* PROSPEKTWEB.CALC ASPRO AI PATCH END v1.0.0 */';
    private const SOURCE_NEEDLE = "    public function __construct()\n    {\n        \$this->arConfig = static::getConfig();";
    private const PROVIDER_SOURCE_NEEDLE = '<option value="ChatGPT">';
    private const PROVIDER_PATCHED_NEEDLE = '<option value="ChatGPT" selected data-prospektweb-calc-default="true">';

    private string $documentRoot;
    private string $asproModuleRoot;
    private string $storageRoot;
    private string $phpBinary;

    public function __construct(
        ?string $documentRoot = null,
        ?string $asproModuleRoot = null,
        ?string $storageRoot = null,
        ?string $phpBinary = null
    ) {
        $root = $documentRoot ?? (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        $this->documentRoot = $this->normalizePath($root);
        $this->asproModuleRoot = $this->normalizePath(
            $asproModuleRoot ?? ($this->documentRoot . '/bitrix/modules/' . self::ASPRO_MODULE_ID)
        );
        $this->storageRoot = $this->normalizePath(
            $storageRoot ?? ($this->documentRoot . '/bitrix/modules/prospektweb.calc/var/aspro-ai-patch')
        );
        $cliCandidate = defined('PHP_BINDIR')
            ? rtrim((string)PHP_BINDIR, '/\\') . DIRECTORY_SEPARATOR . 'php' . (DIRECTORY_SEPARATOR === '\\' ? '.exe' : '')
            : '';
        $this->phpBinary = $phpBinary
            ?? ($cliCandidate !== '' && is_file($cliCandidate) ? $cliCandidate : (defined('PHP_BINARY') ? (string)PHP_BINARY : 'php'));
    }

    public function getStatus(): array
    {
        $moduleVersion = $this->getAsproVersion();
        $targets = $this->getTargetPaths();
        $base = [
            'patchId' => self::PATCH_ID,
            'patchVersion' => self::PATCH_VERSION,
            'asproModuleId' => self::ASPRO_MODULE_ID,
            'asproVersion' => $moduleVersion,
            'targetFile' => $targets[self::TARGET_RELATIVE_PATH],
            'targetFiles' => array_values($targets),
            'state' => 'unknown',
            'message' => '',
            'canApply' => false,
            'canRemove' => false,
            'hasExternalChanges' => false,
        ];

        if (!is_dir($this->asproModuleRoot)) {
            return $this->status($base, 'module_missing', 'Модуль «Аспро: AI» не найден.');
        }
        if ($moduleVersion === '') {
            return $this->status($base, 'version_unknown', 'Не удалось определить версию «Аспро: AI».');
        }
        if ($moduleVersion !== self::SUPPORTED_ASPRO_VERSION) {
            return $this->status(
                $base,
                'unsupported_version',
                'Версия «Аспро: AI» ' . $moduleVersion . ' не поддерживается этим патчем.'
            );
        }
        foreach ($targets as $relativePath => $target) {
            if (!is_file($target)) {
                return $this->status($base, 'target_missing', 'Целевой файл «Аспро: AI» отсутствует: ' . $relativePath . '.');
            }
            if (!$this->isSafeTarget($target, $relativePath)) {
                return $this->status($base, 'access_error', 'Целевой путь не прошёл проверку безопасности: ' . $relativePath . '.');
            }
            if (!is_readable($target)) {
                return $this->status($base, 'access_error', 'Целевой файл недоступен для чтения: ' . $relativePath . '.');
            }
        }

        $contents = $this->readTargets($targets);
        $state = $this->readState();
        $base['currentSha256'] = hash('sha256', $contents[self::TARGET_RELATIVE_PATH]);
        $base['recordedState'] = $state !== null;

        if ($state !== null) {
            $base['installedAt'] = (string)($state['installedAt'] ?? '');
            $base['backupFile'] = (string)($state['backupFile'] ?? '');

            if (!isset($state['files']) || !is_array($state['files'])) {
                if ($this->isLegacyPatchIntact($state, $contents[self::TARGET_RELATIVE_PATH])) {
                    $base['canApply'] = $this->providerTargetsArePatchable($contents)
                        && $this->targetsAreWritable($targets);
                    $base['canRemove'] = true;
                    return $this->status(
                        $base,
                        'upgrade_available',
                        'Установлена версия 1.0.0. Доступно безопасное обновление: ChatGPT будет выбран по умолчанию.'
                    );
                }
                $base['hasExternalChanges'] = true;
                return $this->status($base, 'external_changes', 'Файл старой версии патча был изменён. Автоматическая запись запрещена.');
            }

            if ($this->allManagedFilesMatchState($state, $contents, 'patchedSha256') && $this->hasExpectedPatches($contents)) {
                $base['canRemove'] = true;
                return $this->status($base, 'installed', 'Патч установлен и актуален.');
            }
            if ($this->isRepairableManagedState($state, $contents)) {
                $base['canApply'] = $this->targetsAreWritable($targets);
                if ($base['canApply']) {
                    return $this->status(
                        $base,
                        'overwritten',
                        'Патч был удалён или затёрт обновлением «Аспро: AI»; доступна безопасная повторная установка.'
                    );
                }

                $base['hasExternalChanges'] = true;
                return $this->status(
                    $base,
                    'source_modified',
                    'После установки патча исходный файл «Аспро: AI» был заменён несовместимой версией.'
                );
            }
            $base['hasExternalChanges'] = true;
            return $this->status(
                $base,
                'external_changes',
                'После установки патча файл был изменён. Автоматическая запись и откат запрещены.'
            );
        }

        if ($this->hasAnyPatchMarkers($contents)) {
            $base['hasExternalChanges'] = true;
            return $this->status($base, 'unmanaged_patch', 'Обнаружены маркеры патча без подтверждённого состояния.');
        }
        if (!$this->allTargetsAreOriginal($contents)) {
            $base['hasExternalChanges'] = true;
            return $this->status($base, 'source_modified', 'Структура целевых файлов отличается от поддержанной версии.');
        }

        $base['canApply'] = $this->targetsAreWritable($targets);
        return $this->status(
            $base,
            $base['canApply'] ? 'not_installed' : 'access_error',
            $base['canApply'] ? 'Патч не установлен.' : 'Целевой файл недоступен для записи.'
        );
    }

    public function apply(): array
    {
        $status = $this->getStatus();
        if (($status['state'] ?? '') === 'installed') {
            $status['changed'] = false;
            $status['message'] = 'Патч уже установлен и актуален; изменения не требуются.';
            return $status;
        }
        if (empty($status['canApply'])) {
            throw new \RuntimeException((string)($status['message'] ?? 'Патч нельзя применить.'));
        }

        $targets = $this->getTargetPaths();
        $lock = $this->openLock();
        try {
            $current = $this->readTargets($targets);
            $legacyState = $this->readState();
            $isLegacyUpgrade = ($status['state'] ?? '') === 'upgrade_available';
            $isManagedRepair = ($status['state'] ?? '') === 'overwritten'
                && isset($legacyState['files'])
                && is_array($legacyState['files']);
            $this->ensureStorage();
            $timestamp = gmdate('Ymd_His');
            $patched = [];
            $fileState = [];

            foreach ($targets as $relativePath => $target) {
                $source = $current[$relativePath];
                if ($relativePath === self::TARGET_RELATIVE_PATH && $isLegacyUpgrade) {
                    $patched[$relativePath] = $source;
                    $backup = $this->normalizePath((string)($legacyState['backupFile'] ?? ''));
                    if (!$this->isSafeStorageFile($backup) || !is_file($backup)) {
                        throw new \RuntimeException('Резервная копия версии 1.0.0 не найдена.');
                    }
                    $originalSha256 = (string)($legacyState['originalSha256'] ?? '');
                    if ($originalSha256 === '' || !hash_equals($originalSha256, hash_file('sha256', $backup))) {
                        throw new \RuntimeException('Контрольная сумма резервной копии версии 1.0.0 не совпадает.');
                    }
                } elseif (
                    $isManagedRepair
                    && isset($legacyState['files'][$relativePath])
                    && hash_equals(
                        (string)($legacyState['files'][$relativePath]['patchedSha256'] ?? ''),
                        hash('sha256', $source)
                    )
                ) {
                    $patched[$relativePath] = $source;
                    $backup = $this->normalizePath((string)$legacyState['files'][$relativePath]['backupFile']);
                    $originalSha256 = (string)$legacyState['files'][$relativePath]['originalSha256'];
                    if (
                        !$this->isSafeStorageFile($backup)
                        || !is_file($backup)
                        || !hash_equals($originalSha256, hash_file('sha256', $backup))
                    ) {
                        throw new \RuntimeException('Подтверждённая резервная копия недоступна: ' . $relativePath . '.');
                    }
                } else {
                    $patched[$relativePath] = $this->patchTarget($relativePath, $source);
                    $backup = $this->storageRoot . '/backups/' . str_replace('/', '__', $relativePath)
                        . '.' . $timestamp . '_' . substr(hash('sha256', $source), 0, 12) . '.bak';
                    if (!@copy($target, $backup)) {
                        throw new \RuntimeException('Не удалось создать резервную копию: ' . $relativePath . '.');
                    }
                    @chmod($backup, 0600);
                    $originalSha256 = hash('sha256', $source);
                }
                $fileState[$relativePath] = [
                    'targetFile' => $target,
                    'originalSha256' => $originalSha256,
                    'patchedSha256' => hash('sha256', $patched[$relativePath]),
                    'backupFile' => $backup,
                ];
            }

            $written = [];
            $lintMessages = [];
            try {
                foreach ($targets as $relativePath => $target) {
                    if ($patched[$relativePath] === $current[$relativePath]) {
                        continue;
                    }
                    $mode = fileperms($target) & 0777;
                    $this->atomicWrite($target, $patched[$relativePath], $mode);
                    $written[] = $relativePath;
                    $lint = $this->lintPhp($target);
                    $lintMessages[$relativePath] = $lint['message'];
                    if (!$lint['success'] || !hash_equals(hash('sha256', $patched[$relativePath]), hash_file('sha256', $target))) {
                        throw new \RuntimeException('Проверка файла не пройдена: ' . $relativePath . '. ' . $lint['message']);
                    }
                }
            } catch (\Throwable $exception) {
                foreach (array_reverse($written) as $relativePath) {
                    $target = $targets[$relativePath];
                    $this->atomicWrite($target, $current[$relativePath], fileperms($target) & 0777);
                }
                throw $exception;
            }

            $state = [
                'patchId' => self::PATCH_ID,
                'patchVersion' => self::PATCH_VERSION,
                'asproModuleId' => self::ASPRO_MODULE_ID,
                'asproVersion' => $this->getAsproVersion(),
                'targetFile' => $targets[self::TARGET_RELATIVE_PATH],
                'files' => $fileState,
                'installedAt' => gmdate('c'),
                'lint' => $lintMessages,
            ];
            $this->writeState($state);

            $result = $this->getStatus();
            $result['changed'] = true;
            $result['lint'] = $lintMessages;
            return $result;
        } finally {
            $this->closeLock($lock);
        }
    }

    public function remove(): array
    {
        $status = $this->getStatus();
        $state = $this->readState();
        if ($state === null) {
            $status['changed'] = false;
            $status['message'] = 'Подтверждённое состояние патча отсутствует; файлы не изменены.';
            return $status;
        }
        if (($status['state'] ?? '') === 'overwritten') {
            $contents = $this->readTargets($this->getTargetPaths());
            if ($this->allTargetsAreOriginal($contents)) {
                $this->deleteState();
                $status = $this->getStatus();
                $status['changed'] = false;
                $status['message'] = 'Патч уже отсутствует; целевые файлы не изменены.';
                return $status;
            }
            $status['changed'] = false;
            $status['message'] = 'Патч затёрт частично. Сначала восстановите его кнопкой установки, затем выполните снятие.';
            return $status;
        }
        if (($status['state'] ?? '') === 'upgrade_available') {
            return $this->removeLegacyPatch($status, $state);
        }
        if (($status['state'] ?? '') !== 'installed') {
            $status['changed'] = false;
            $status['message'] = 'Обнаружены внешние изменения; резервная копия не восстановлена.';
            return $status;
        }

        $targets = $this->getTargetPaths();
        $restores = $this->prepareRestores($state, $targets);

        $lock = $this->openLock();
        try {
            $current = $this->readTargets($targets);
            $restored = [];
            try {
                foreach ($targets as $relativePath => $target) {
                    $mode = fileperms($target) & 0777;
                    $this->atomicWrite($target, $restores[$relativePath], $mode);
                    $restored[] = $relativePath;
                    $lint = $this->lintPhp($target);
                    if (!$lint['success'] || !hash_equals((string)$state['files'][$relativePath]['originalSha256'], hash_file('sha256', $target))) {
                        throw new \RuntimeException('Проверка восстановленного файла не пройдена: ' . $relativePath . '.');
                    }
                }
            } catch (\Throwable $exception) {
                foreach (array_reverse($restored) as $relativePath) {
                    $target = $targets[$relativePath];
                    $this->atomicWrite($target, $current[$relativePath], fileperms($target) & 0777);
                }
                throw $exception;
            }
            $this->deleteState();
            $result = $this->getStatus();
            $result['changed'] = true;
            $result['message'] = 'Патч безопасно снят, исходный файл восстановлен.';
            return $result;
        } finally {
            $this->closeLock($lock);
        }
    }

    public function getStorageRoot(): string
    {
        return $this->storageRoot;
    }

    private function patchedNeedle(): string
    {
        return "    public function __construct()\n    {\n"
            . "        " . self::BEGIN_MARKER . "\n"
            . "        if (\\Bitrix\\Main\\Config\\Option::get('prospektweb.calc', 'ASPRO_AI_TIMEWEB_ENABLED', 'N') === 'Y') {\n"
            . "            \$timewebBaseUrl = rtrim((string)\\Bitrix\\Main\\Config\\Option::get(\n"
            . "                'prospektweb.calc',\n"
            . "                'ASPRO_AI_TIMEWEB_BASE_URL',\n"
            . "                'https://api.timeweb.ai/v1'\n"
            . "            ), '/');\n"
            . "            if (substr(\$timewebBaseUrl, -3) === '/v1') {\n"
            . "                \$timewebBaseUrl = substr(\$timewebBaseUrl, 0, -3);\n"
            . "            }\n"
            . "            \$timewebUrlParts = parse_url(\$timewebBaseUrl);\n"
            . "            if (\n"
            . "                is_array(\$timewebUrlParts)\n"
            . "                && strtolower((string)(\$timewebUrlParts['scheme'] ?? '')) === 'https'\n"
            . "                && (string)(\$timewebUrlParts['host'] ?? '') !== ''\n"
            . "                && empty(\$timewebUrlParts['user'])\n"
            . "                && empty(\$timewebUrlParts['pass'])\n"
            . "                && empty(\$timewebUrlParts['query'])\n"
            . "                && empty(\$timewebUrlParts['fragment'])\n"
            . "            ) {\n"
            . "                \$this->baseApiUrl = \$timewebBaseUrl;\n"
            . "            }\n"
            . "        }\n"
            . "        " . self::END_MARKER . "\n"
            . "        \$this->arConfig = static::getConfig();";
    }

    private function getAsproVersion(): string
    {
        $versionFile = $this->asproModuleRoot . '/install/version.php';
        if (!is_file($versionFile)) {
            return '';
        }
        $contents = (string)file_get_contents($versionFile);
        if (preg_match('/[\"\']VERSION[\"\']\s*=>\s*[\"\']([^\"\']+)[\"\']/', $contents, $matches)) {
            return (string)$matches[1];
        }
        return '';
    }

    private function getTargetPath(): string
    {
        return $this->normalizePath($this->asproModuleRoot . '/' . self::TARGET_RELATIVE_PATH);
    }

    private function getTargetPaths(): array
    {
        $paths = [self::TARGET_RELATIVE_PATH];
        foreach (self::PROVIDER_TARGET_RELATIVE_PATHS as $relativePath) {
            $paths[] = $relativePath;
        }

        $targets = [];
        foreach ($paths as $relativePath) {
            $targets[$relativePath] = $this->normalizePath($this->asproModuleRoot . '/' . $relativePath);
        }
        return $targets;
    }

    private function readTargets(array $targets): array
    {
        $contents = [];
        foreach ($targets as $relativePath => $target) {
            $contents[$relativePath] = (string)file_get_contents($target);
        }
        return $contents;
    }

    private function patchTarget(string $relativePath, string $contents): string
    {
        if ($relativePath === self::TARGET_RELATIVE_PATH) {
            if (!$this->hasUniqueSourceNeedle($contents)) {
                throw new \RuntimeException('Ожидаемый фрагмент chatgpt.php отсутствует или неоднозначен.');
            }
            return str_replace(self::SOURCE_NEEDLE, $this->patchedNeedle(), $contents);
        }

        if (substr_count($contents, self::PROVIDER_SOURCE_NEEDLE) !== 1) {
            throw new \RuntimeException('Опция ChatGPT отсутствует или неоднозначна: ' . $relativePath . '.');
        }
        return str_replace(self::PROVIDER_SOURCE_NEEDLE, self::PROVIDER_PATCHED_NEEDLE, $contents);
    }

    private function allTargetsAreOriginal(array $contents): bool
    {
        if (!$this->hasUniqueSourceNeedle($contents[self::TARGET_RELATIVE_PATH] ?? '')) {
            return false;
        }
        return $this->providerTargetsArePatchable($contents);
    }

    private function providerTargetsArePatchable(array $contents): bool
    {
        foreach (self::PROVIDER_TARGET_RELATIVE_PATHS as $relativePath) {
            if (substr_count($contents[$relativePath] ?? '', self::PROVIDER_SOURCE_NEEDLE) !== 1) {
                return false;
            }
        }
        return true;
    }

    private function hasExpectedPatches(array $contents): bool
    {
        $chat = $contents[self::TARGET_RELATIVE_PATH] ?? '';
        if (substr_count($chat, self::BEGIN_MARKER) !== 1 || substr_count($chat, self::END_MARKER) !== 1) {
            return false;
        }
        foreach (self::PROVIDER_TARGET_RELATIVE_PATHS as $relativePath) {
            if (substr_count($contents[$relativePath] ?? '', self::PROVIDER_PATCHED_NEEDLE) !== 1) {
                return false;
            }
        }
        return true;
    }

    private function hasAnyPatchMarkers(array $contents): bool
    {
        $chat = $contents[self::TARGET_RELATIVE_PATH] ?? '';
        if (strpos($chat, 'PROSPEKTWEB.CALC ASPRO AI PATCH') !== false) {
            return true;
        }
        foreach (self::PROVIDER_TARGET_RELATIVE_PATHS as $relativePath) {
            if (strpos($contents[$relativePath] ?? '', 'data-prospektweb-calc-default') !== false) {
                return true;
            }
        }
        return false;
    }

    private function targetsAreWritable(array $targets): bool
    {
        foreach ($targets as $target) {
            if (!is_writable($target)) {
                return false;
            }
        }
        return true;
    }

    private function isLegacyPatchIntact(array $state, string $chatContents): bool
    {
        return hash_equals((string)($state['patchedSha256'] ?? ''), hash('sha256', $chatContents))
            && substr_count($chatContents, self::BEGIN_MARKER) === 1
            && substr_count($chatContents, self::END_MARKER) === 1;
    }

    private function allManagedFilesMatchState(array $state, array $contents, string $hashKey): bool
    {
        foreach ($this->getTargetPaths() as $relativePath => $_target) {
            $expected = (string)($state['files'][$relativePath][$hashKey] ?? '');
            if ($expected === '' || !hash_equals($expected, hash('sha256', $contents[$relativePath] ?? ''))) {
                return false;
            }
        }
        return true;
    }

    private function isRepairableManagedState(array $state, array $contents): bool
    {
        foreach ($this->getTargetPaths() as $relativePath => $_target) {
            $current = $contents[$relativePath] ?? '';
            $patchedHash = (string)($state['files'][$relativePath]['patchedSha256'] ?? '');
            if ($patchedHash !== '' && hash_equals($patchedHash, hash('sha256', $current))) {
                continue;
            }
            if ($relativePath === self::TARGET_RELATIVE_PATH) {
                if (!$this->hasUniqueSourceNeedle($current)) {
                    return false;
                }
            } elseif (substr_count($current, self::PROVIDER_SOURCE_NEEDLE) !== 1) {
                return false;
            }
        }
        return true;
    }

    private function prepareRestores(array $state, array $targets): array
    {
        $restores = [];
        foreach ($targets as $relativePath => $_target) {
            $fileState = $state['files'][$relativePath] ?? null;
            if (!is_array($fileState)) {
                throw new \RuntimeException('Состояние файла отсутствует: ' . $relativePath . '.');
            }
            $backup = $this->normalizePath((string)($fileState['backupFile'] ?? ''));
            if (!$this->isSafeStorageFile($backup) || !is_file($backup)) {
                throw new \RuntimeException('Подтверждённая резервная копия не найдена: ' . $relativePath . '.');
            }
            $contents = (string)file_get_contents($backup);
            if (!hash_equals((string)($fileState['originalSha256'] ?? ''), hash('sha256', $contents))) {
                throw new \RuntimeException('Контрольная сумма резервной копии не совпадает: ' . $relativePath . '.');
            }
            $restores[$relativePath] = $contents;
        }
        return $restores;
    }

    private function removeLegacyPatch(array $status, array $state): array
    {
        $target = $this->getTargetPath();
        $backup = $this->normalizePath((string)($state['backupFile'] ?? ''));
        if (!$this->isSafeStorageFile($backup) || !is_file($backup)) {
            throw new \RuntimeException('Подтверждённая резервная копия версии 1.0.0 не найдена.');
        }
        $original = (string)file_get_contents($backup);
        if (!hash_equals((string)($state['originalSha256'] ?? ''), hash('sha256', $original))) {
            throw new \RuntimeException('Контрольная сумма резервной копии версии 1.0.0 не совпадает.');
        }
        $current = (string)file_get_contents($target);
        $this->atomicWrite($target, $original, fileperms($target) & 0777);
        $lint = $this->lintPhp($target);
        if (!$lint['success']) {
            $this->atomicWrite($target, $current, fileperms($target) & 0777);
            throw new \RuntimeException('Проверка восстановленного файла версии 1.0.0 не пройдена.');
        }
        $this->deleteState();
        $result = $this->getStatus();
        $result['changed'] = true;
        $result['message'] = 'Патч версии 1.0.0 безопасно снят.';
        return $result;
    }

    private function hasUniqueSourceNeedle(string $contents): bool
    {
        return substr_count($contents, self::SOURCE_NEEDLE) === 1;
    }

    private function isSafeTarget(string $target, string $relativePath): bool
    {
        $moduleReal = realpath($this->asproModuleRoot);
        $targetReal = realpath($target);
        if ($moduleReal === false || $targetReal === false || is_link($target)) {
            return false;
        }
        $moduleReal = rtrim($this->normalizePath($moduleReal), '/') . '/';
        $targetReal = $this->normalizePath($targetReal);
        return strncmp($targetReal, $moduleReal, strlen($moduleReal)) === 0
            && $targetReal === $this->normalizePath($this->asproModuleRoot . '/' . $relativePath);
    }

    private function isSafeStorageFile(string $path): bool
    {
        $root = rtrim($this->storageRoot, '/') . '/';
        return $path !== '' && strncmp($path, $root, strlen($root)) === 0 && !is_link($path);
    }

    private function ensureStorage(): void
    {
        foreach ([$this->storageRoot, $this->storageRoot . '/backups'] as $directory) {
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new \RuntimeException('Не удалось создать защищённый каталог состояния патча.');
            }
            @chmod($directory, 0700);
        }
        $deny = "Deny from all\n";
        if (!is_file($this->storageRoot . '/.htaccess')) {
            @file_put_contents($this->storageRoot . '/.htaccess', $deny, LOCK_EX);
        }
        if (!is_file($this->storageRoot . '/index.php')) {
            @file_put_contents($this->storageRoot . '/index.php', "<?php http_response_code(404); die();\n", LOCK_EX);
        }
    }

    private function readState(): ?array
    {
        $file = $this->storageRoot . '/state.json';
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $decoded = json_decode((string)file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeState(array $state): void
    {
        $this->ensureStorage();
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('Не удалось сериализовать состояние патча.');
        }
        $this->atomicWrite($this->storageRoot . '/state.json', $json . "\n", 0600);
    }

    private function deleteState(): void
    {
        $file = $this->storageRoot . '/state.json';
        if (is_file($file) && !@unlink($file)) {
            throw new \RuntimeException('Не удалось удалить состояние снятого патча.');
        }
    }

    private function atomicWrite(string $target, string $contents, int $mode): void
    {
        $directory = dirname($target);
        $temporary = tempnam($directory, '.pwcalc-patch-');
        if ($temporary === false) {
            throw new \RuntimeException('Не удалось создать временный файл для атомарной записи.');
        }
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
                throw new \RuntimeException('Не удалось записать временный файл.');
            }
            @chmod($temporary, $mode);
            if (!@rename($temporary, $target)) {
                if (DIRECTORY_SEPARATOR !== '\\' || !is_file($target)) {
                    throw new \RuntimeException('Не удалось атомарно заменить целевой файл.');
                }

                // Windows не разрешает rename поверх существующего файла. Этот fallback
                // нужен только для локальной проверки: прежний файл сначала сохраняется
                // рядом и немедленно возвращается при любой ошибке второго rename.
                $previous = $target . '.pwcalc-previous-' . bin2hex(random_bytes(6));
                if (!@rename($target, $previous)) {
                    throw new \RuntimeException('Не удалось подготовить безопасную замену целевого файла.');
                }
                if (!@rename($temporary, $target)) {
                    @rename($previous, $target);
                    throw new \RuntimeException('Не удалось заменить целевой файл; исходная версия восстановлена.');
                }
                @unlink($previous);
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function lintPhp(string $file): array
    {
        if (!function_exists('exec')) {
            return ['success' => false, 'message' => 'Функция exec недоступна; php -l не выполнен.'];
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (in_array('exec', $disabled, true)) {
            return ['success' => false, 'message' => 'Функция exec отключена; php -l не выполнен.'];
        }
        $output = [];
        $code = 1;
        @exec(escapeshellarg($this->phpBinary) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
        return [
            'success' => $code === 0,
            'message' => trim(implode("\n", $output)),
        ];
    }

    /** @return resource */
    private function openLock()
    {
        $this->ensureStorage();
        $file = $this->storageRoot . '/patch.lock';
        $handle = @fopen($file, 'c+');
        if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException('Не удалось получить блокировку менеджера патча.');
        }
        @chmod($file, 0600);
        return $handle;
    }

    /** @param resource $handle */
    private function closeLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function status(array $base, string $state, string $message): array
    {
        $base['state'] = $state;
        $base['message'] = $message;
        return $base;
    }
}
