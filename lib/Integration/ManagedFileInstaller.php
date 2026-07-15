<?php

namespace Prospektweb\Calc\Integration;

use Bitrix\Main\Config\Option;
use RuntimeException;

final class ManagedFileInstaller
{
    private const MODULE_ID = 'prospektweb.calc';
    private const MANIFEST_OPTION = 'CONSOLIDATION_MANAGED_FILES';
    private const BACKUP_DIR = '/upload/prospektweb.calc/consolidation-backups';

    /**
     * @param array<string, string> $files absolute document-root relative path => content
     * @return array<string, string>
     */
    public function install(array $files): array
    {
        $manifest = $this->getManifest();
        $report = [];

        foreach ($files as $relativePath => $content) {
            $relativePath = '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
            $target = $this->documentRoot() . $relativePath;
            $targetHash = is_file($target) ? (string)hash_file('sha256', $target) : '';
            $contentHash = hash('sha256', $content);
            $previous = $manifest[$relativePath] ?? null;

            if ($targetHash === $contentHash) {
                $report[$relativePath] = 'current';
                $manifest[$relativePath] = $this->manifestEntry($relativePath, $contentHash, $previous['backup'] ?? '');
                continue;
            }

            if (is_array($previous) && $targetHash !== '' && $targetHash !== (string)($previous['installed_hash'] ?? '')) {
                throw new RuntimeException('Управляемый файл изменён после установки: ' . $relativePath);
            }

            if (!is_array($previous) && $targetHash !== '' && !$this->isRecognizedLegacyWrapper((string)file_get_contents($target))) {
                throw new RuntimeException('Существующий файл не распознан как legacy-wrapper: ' . $relativePath);
            }

            $backup = is_array($previous) ? (string)($previous['backup'] ?? '') : '';
            if ($targetHash !== '' && $backup === '') {
                $backup = $this->backup($relativePath, $target);
            }

            $this->atomicWrite($target, $content);
            $manifest[$relativePath] = $this->manifestEntry($relativePath, $contentHash, $backup);
            $report[$relativePath] = $targetHash === '' ? 'created' : 'updated';
        }

        Option::set(self::MODULE_ID, self::MANIFEST_OPTION, json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $report;
    }

    /** @return array<string, mixed> */
    public function getManifest(): array
    {
        $manifest = json_decode((string)Option::get(self::MODULE_ID, self::MANIFEST_OPTION, '{}'), true);
        return is_array($manifest) ? $manifest : [];
    }

    private function isRecognizedLegacyWrapper(string $content): bool
    {
        foreach (['prospektweb.frontcalc', 'prospektweb.layoutfiles', 'prospekt_layout', 'frontcalc ajax endpoint'] as $marker) {
            if (strpos($content, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    private function backup(string $relativePath, string $target): string
    {
        $backupRelative = self::BACKUP_DIR . '/' . hash('sha256', $relativePath) . '.bak';
        $backup = $this->documentRoot() . $backupRelative;
        $this->ensureDirectory(dirname($backup));

        if (!copy($target, $backup)) {
            throw new RuntimeException('Не удалось создать резервную копию: ' . $relativePath);
        }

        return $backupRelative;
    }

    private function atomicWrite(string $target, string $content): void
    {
        $this->ensureDirectory(dirname($target));
        $temporary = $target . '.prospektweb-calc-' . getmypid() . '.tmp';

        if (file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) {
            @unlink($temporary);
            throw new RuntimeException('Не удалось полностью записать временный файл: ' . $target);
        }

        if (!@rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Не удалось атомарно заменить файл: ' . $target);
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось создать каталог: ' . $directory);
        }
    }

    /** @return array{path:string, installed_hash:string, backup:string, installed_at:string} */
    private function manifestEntry(string $path, string $hash, string $backup): array
    {
        return [
            'path' => $path,
            'installed_hash' => $hash,
            'backup' => $backup,
            'installed_at' => date('c'),
        ];
    }

    private function documentRoot(): string
    {
        return rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/\\');
    }
}
