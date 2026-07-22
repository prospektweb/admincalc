<?php

namespace Prospektweb\Calc\Services;

/**
 * Управляемый и обратимый патч Aspro AI для Timeweb Cloud AI Gateway.
 *
 * Патч ограничен одним поддержанным файлом aspro.ai 1.1.1. Он меняет только
 * выбор Base URL перед созданием штатного HTTP-клиента; endpoints, Bearer
 * авторизация, proxy, фоновые задания и обработка ответов остаются штатными.
 */
final class AsproAiPatchManager
{
    public const PATCH_ID = 'prospektweb.calc.aspro-ai-timeweb';
    public const PATCH_VERSION = '1.0.0';
    public const ASPRO_MODULE_ID = 'aspro.ai';
    public const SUPPORTED_ASPRO_VERSION = '1.1.1';
    public const TARGET_RELATIVE_PATH = 'lib/services/chatgpt.php';

    private const BEGIN_MARKER = '/* PROSPEKTWEB.CALC ASPRO AI PATCH BEGIN v1.0.0 */';
    private const END_MARKER = '/* PROSPEKTWEB.CALC ASPRO AI PATCH END v1.0.0 */';
    private const SOURCE_NEEDLE = "    public function __construct()\n    {\n        \$this->arConfig = static::getConfig();";

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
        $target = $this->getTargetPath();
        $base = [
            'patchId' => self::PATCH_ID,
            'patchVersion' => self::PATCH_VERSION,
            'asproModuleId' => self::ASPRO_MODULE_ID,
            'asproVersion' => $moduleVersion,
            'targetFile' => $target,
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
        if (!is_file($target)) {
            return $this->status($base, 'target_missing', 'Целевой файл «Аспро: AI» отсутствует.');
        }
        if (!$this->isSafeTarget($target)) {
            return $this->status($base, 'access_error', 'Целевой путь не прошёл проверку безопасности.');
        }
        if (!is_readable($target)) {
            return $this->status($base, 'access_error', 'Целевой файл недоступен для чтения.');
        }

        $contents = (string)file_get_contents($target);
        $hash = hash('sha256', $contents);
        $state = $this->readState();
        $hasBegin = substr_count($contents, self::BEGIN_MARKER);
        $hasEnd = substr_count($contents, self::END_MARKER);

        $base['currentSha256'] = $hash;
        $base['recordedState'] = $state !== null;

        if ($state !== null) {
            $base['installedAt'] = (string)($state['installedAt'] ?? '');
            $base['backupFile'] = (string)($state['backupFile'] ?? '');
            if (hash_equals((string)($state['patchedSha256'] ?? ''), $hash) && $hasBegin === 1 && $hasEnd === 1) {
                $base['canRemove'] = true;
                return $this->status($base, 'installed', 'Патч установлен и актуален.');
            }
            if ($hasBegin === 0 && $hasEnd === 0) {
                $base['canApply'] = $this->hasUniqueSourceNeedle($contents) && is_writable($target);
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

        if ($hasBegin !== 0 || $hasEnd !== 0) {
            $base['hasExternalChanges'] = true;
            return $this->status($base, 'unmanaged_patch', 'Обнаружены маркеры патча без подтверждённого состояния.');
        }
        if (!$this->hasUniqueSourceNeedle($contents)) {
            $base['hasExternalChanges'] = true;
            return $this->status($base, 'source_modified', 'Структура целевого файла отличается от поддержанной версии.');
        }

        $base['canApply'] = is_writable($target);
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

        $target = $this->getTargetPath();
        $lock = $this->openLock();
        try {
            $original = (string)file_get_contents($target);
            if (!$this->hasUniqueSourceNeedle($original)) {
                throw new \RuntimeException('Ожидаемый фрагмент исходного кода отсутствует или неоднозначен.');
            }
            $this->ensureStorage();
            $timestamp = gmdate('Ymd_His') . '_' . substr(hash('sha256', $original), 0, 12);
            $backup = $this->storageRoot . '/backups/chatgpt.php.' . $timestamp . '.bak';
            if (!@copy($target, $backup)) {
                throw new \RuntimeException('Не удалось создать резервную копию целевого файла.');
            }
            @chmod($backup, 0600);

            $patched = str_replace(self::SOURCE_NEEDLE, $this->patchedNeedle(), $original, $count);
            if ($count !== 1) {
                throw new \RuntimeException('Патч должен изменить ровно один фрагмент, изменено: ' . $count . '.');
            }

            $mode = fileperms($target) & 0777;
            $this->atomicWrite($target, $patched, $mode);
            $lint = $this->lintPhp($target);
            if (!$lint['success'] || hash('sha256', (string)file_get_contents($target)) !== hash('sha256', $patched)) {
                $this->atomicWrite($target, $original, $mode);
                throw new \RuntimeException('Проверка пропатченного файла не пройдена; исходный файл восстановлен. ' . $lint['message']);
            }

            $state = [
                'patchId' => self::PATCH_ID,
                'patchVersion' => self::PATCH_VERSION,
                'asproModuleId' => self::ASPRO_MODULE_ID,
                'asproVersion' => $this->getAsproVersion(),
                'targetFile' => $target,
                'originalSha256' => hash('sha256', $original),
                'patchedSha256' => hash('sha256', $patched),
                'backupFile' => $backup,
                'installedAt' => gmdate('c'),
                'lint' => $lint['message'],
            ];
            $this->writeState($state);

            $result = $this->getStatus();
            $result['changed'] = true;
            $result['lint'] = $lint;
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
            $this->deleteState();
            $status = $this->getStatus();
            $status['changed'] = false;
            $status['message'] = 'Патч уже отсутствует; целевой файл не изменён.';
            return $status;
        }
        if (($status['state'] ?? '') !== 'installed') {
            $status['changed'] = false;
            $status['message'] = 'Обнаружены внешние изменения; резервная копия не восстановлена.';
            return $status;
        }

        $target = $this->getTargetPath();
        $backup = $this->normalizePath((string)($state['backupFile'] ?? ''));
        if (!$this->isSafeStorageFile($backup) || !is_file($backup)) {
            throw new \RuntimeException('Подтверждённая резервная копия не найдена.');
        }
        $backupContents = (string)file_get_contents($backup);
        if (!hash_equals((string)$state['originalSha256'], hash('sha256', $backupContents))) {
            throw new \RuntimeException('Контрольная сумма резервной копии не совпадает с сохранённой.');
        }

        $lock = $this->openLock();
        try {
            $current = (string)file_get_contents($target);
            if (!hash_equals((string)$state['patchedSha256'], hash('sha256', $current))) {
                throw new \RuntimeException('Целевой файл изменён после патча; откат отменён.');
            }
            $mode = fileperms($target) & 0777;
            $this->atomicWrite($target, $backupContents, $mode);
            $lint = $this->lintPhp($target);
            if (!$lint['success'] || !hash_equals((string)$state['originalSha256'], hash_file('sha256', $target))) {
                $this->atomicWrite($target, $current, $mode);
                throw new \RuntimeException('Проверка восстановленного файла не пройдена; пропатченная версия возвращена.');
            }
            $this->deleteState();
            $result = $this->getStatus();
            $result['changed'] = true;
            $result['message'] = 'Патч безопасно снят, исходный файл восстановлен.';
            $result['lint'] = $lint;
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

    private function hasUniqueSourceNeedle(string $contents): bool
    {
        return substr_count($contents, self::SOURCE_NEEDLE) === 1;
    }

    private function isSafeTarget(string $target): bool
    {
        $moduleReal = realpath($this->asproModuleRoot);
        $targetReal = realpath($target);
        if ($moduleReal === false || $targetReal === false || is_link($target)) {
            return false;
        }
        $moduleReal = rtrim($this->normalizePath($moduleReal), '/') . '/';
        $targetReal = $this->normalizePath($targetReal);
        return strncmp($targetReal, $moduleReal, strlen($moduleReal)) === 0
            && $targetReal === $this->getTargetPath();
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
