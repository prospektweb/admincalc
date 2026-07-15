<?php

namespace Prospektweb\Calc\Integration;

use Bitrix\Main\Config\Option;
use RuntimeException;

final class TemplatePatchCoordinator
{
    private const MODULE_ID = 'prospektweb.calc';
    private const MANIFEST_OPTION = 'CONSOLIDATION_TEMPLATE_MANIFEST';
    private const ASPRO_PRICES = '/bitrix/modules/aspro.premier/lib/product/prices.php';
    private const BACKUP_DIR = '/upload/prospektweb.calc/consolidation-backups/templates';

    /** @return array<string, mixed> */
    public function inspect(): array
    {
        $target = $this->documentRoot() . self::ASPRO_PRICES;
        $source = $this->moduleRoot() . '/features/frontend/prices_with_edit.php';

        return [
            'target' => self::ASPRO_PRICES,
            'target_exists' => is_file($target),
            'target_hash' => is_file($target) ? (string)hash_file('sha256', $target) : '',
            'source_hash' => is_file($source) ? (string)hash_file('sha256', $source) : '',
            'manifest' => $this->getManifest(),
        ];
    }

    /** @return array<string, mixed> */
    public function apply(string $approvedCurrentHash): array
    {
        $inspection = $this->inspect();
        if (!$inspection['target_exists']) {
            throw new RuntimeException('Не найден файл Aspro: ' . self::ASPRO_PRICES);
        }
        if ($inspection['source_hash'] === '') {
            throw new RuntimeException('Не найден подготовленный FrontCalc prices.php.');
        }

        $manifest = $inspection['manifest'];
        $previous = $manifest['frontcalc_prices'] ?? null;
        $currentHash = (string)$inspection['target_hash'];

        $frontcalcUpdated = false;
        if ($currentHash !== (string)$inspection['source_hash']) {
            if (is_array($previous)) {
                if ($currentHash !== (string)($previous['installed_hash'] ?? '')) {
                    throw new RuntimeException('prices.php изменён после предыдущего применения. Автоматическая замена остановлена.');
                }
            } elseif ($approvedCurrentHash === '' || !hash_equals($currentHash, $approvedCurrentHash)) {
                throw new RuntimeException('Текущий hash prices.php не подтверждён. Обновите страницу и повторите проверку.');
            }

            $manifest['frontcalc_prices'] = $this->replacePrices($currentHash, (string)$inspection['source_hash']);
            $this->saveManifest($manifest);
            $frontcalcUpdated = true;
        }

        Option::set(self::MODULE_ID, 'CONSOLIDATION_TEMPLATES_APPLIED_AT', date('c'));

        return [
            'frontcalc_prices' => $currentHash === (string)$inspection['source_hash'] ? 'current' : 'updated',
            'basket_templates' => 'unchanged; runtime integration is used',
            'property_values' => 'runtime injection; template files unchanged',
        ];
    }

    /** @return array<string, string> */
    private function replacePrices(string $originalHash, string $sourceHash): array
    {
        $target = $this->documentRoot() . self::ASPRO_PRICES;
        $source = $this->moduleRoot() . '/features/frontend/prices_with_edit.php';
        $backupRelative = self::BACKUP_DIR . '/aspro-prices-' . date('YmdHis') . '-' . substr($originalHash, 0, 12) . '.bak';
        $backup = $this->documentRoot() . $backupRelative;

        $this->ensureDirectory(dirname($backup));
        if (!copy($target, $backup)) {
            throw new RuntimeException('Не удалось создать backup Aspro prices.php.');
        }

        $content = (string)file_get_contents($source);
        $temporary = $target . '.prospektweb-calc-' . getmypid() . '.tmp';
        if (file_put_contents($temporary, $content, LOCK_EX) !== strlen($content) || !@rename($temporary, $target)) {
            @unlink($temporary);
            @copy($backup, $target);
            throw new RuntimeException('Не удалось атомарно обновить Aspro prices.php. Исходный файл восстановлен.');
        }

        return [
            'path' => self::ASPRO_PRICES,
            'backup' => $backupRelative,
            'original_hash' => $originalHash,
            'installed_hash' => $sourceHash,
            'installed_at' => date('c'),
        ];
    }

    /** @param array<string, string> $entry */
    private function restorePrices(array $entry): void
    {
        $target = $this->documentRoot() . self::ASPRO_PRICES;
        $backup = $this->documentRoot() . (string)($entry['backup'] ?? '');
        $installedHash = (string)($entry['installed_hash'] ?? '');

        if (!is_file($backup) || !is_file($target) || $installedHash === '') {
            throw new RuntimeException('Не удалось найти данные для отката Aspro prices.php.');
        }
        if (!hash_equals($installedHash, (string)hash_file('sha256', $target))) {
            throw new RuntimeException('Aspro prices.php изменён после установки; автоматический откат остановлен.');
        }
        if (!copy($backup, $target)) {
            throw new RuntimeException('Не удалось восстановить Aspro prices.php из резервной копии.');
        }
    }

    /** @return array<string, mixed> */
    private function getManifest(): array
    {
        $manifest = json_decode((string)Option::get(self::MODULE_ID, self::MANIFEST_OPTION, '{}'), true);
        return is_array($manifest) ? $manifest : [];
    }

    /** @param array<string, mixed> $manifest */
    private function saveManifest(array $manifest): void
    {
        Option::set(self::MODULE_ID, self::MANIFEST_OPTION, json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function moduleRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function documentRoot(): string
    {
        return rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/\\');
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось создать каталог: ' . $directory);
        }
    }
}
