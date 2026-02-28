<?php

namespace Prospektweb\Calc\Diagnostic;

use Bitrix\Main\Application;

/**
 * Файловый кеш результатов GitHub-проверки.
 */
class DiagnosticCache
{
    /** @var string Путь к кеш-файлу относительно DOCUMENT_ROOT */
    private const CACHE_RELATIVE_PATH = '/upload/prospektweb.calc/diagnostic_cache.json';

    /** @var int TTL кеша в секундах */
    private const TTL = 600;

    /**
     * Возвращает путь к кеш-файлу.
     */
    private function getCachePath(): string
    {
        return Application::getDocumentRoot() . self::CACHE_RELATIVE_PATH;
    }

    /**
     * Возвращает кешированные данные или null, если кеш устарел/отсутствует.
     */
    public function get(): ?array
    {
        $path = $this->getCachePath();

        if (!file_exists($path)) {
            return null;
        }

        $mtime = filemtime($path);
        if ($mtime === false || (time() - $mtime) > self::TTL) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $data;
    }

    /**
     * Сохраняет данные в кеш.
     */
    public function set(array $result): void
    {
        $path = $this->getCachePath();
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($content === false) {
            return;
        }

        file_put_contents($path, $content, LOCK_EX);
    }

    /**
     * Очищает кеш.
     */
    public function clear(): void
    {
        $path = $this->getCachePath();
        if (file_exists($path)) {
            unlink($path);
        }
    }
}
