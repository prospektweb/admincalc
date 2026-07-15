<?php

namespace Prospektweb\Frontcalc\Service;

require_once __DIR__ . '/DeadlineAdjustmentNormalizer.php';
require_once __DIR__ . '/VirtualOfferBatchBuilder.php';

final class CalculationSessionStore
{
    private const VERSION = 1;
    private const DEFAULT_TTL = 7200;

    private string $directory;
    private int $ttl;
    /** @var callable */
    private $contextResolver;
    /** @var callable */
    private $timeResolver;

    public function __construct(?string $documentRoot = null, int $ttl = self::DEFAULT_TTL, ?callable $contextResolver = null, ?callable $timeResolver = null)
    {
        $root = $documentRoot ?? (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        $this->directory = rtrim($root, '/\\') . '/bitrix/cache/prospektweb.calc/calculation_sessions';
        if ($root === '') {
            $this->directory = sys_get_temp_dir() . '/prospektweb.calc/calculation_sessions';
        }
        $this->ttl = max(60, $ttl);
        $this->contextResolver = $contextResolver ?? [self::class, 'resolveDefaultContext'];
        $this->timeResolver = $timeResolver ?? static fn(): int => time();
    }

    public function create(int $productId, array $offers, array $config): string
    {
        $token = $this->generateToken();
        $now = $this->now();
        $context = $this->context();
        $payload = [
            'version' => self::VERSION,
            'productId' => $productId,
            'siteId' => $context['siteId'],
            'userId' => $context['userId'],
            'sessionBinding' => $context['sessionBinding'],
            'createdAt' => $now,
            'expiresAt' => $now + $this->ttl,
            'offers' => $this->sanitizeOffers($offers),
            'config' => $this->sanitizeConfig($config),
        ];
        $this->write($token, $payload);
        if (random_int(1, 100) <= 2) { $this->cleanupExpired(100); }
        return $token;
    }

    public function load(string $token, int $productId): ?array
    {
        $token = $this->normalizeToken($token);
        if ($token === '') { return null; }
        $path = $this->path($token);
        if (!is_file($path)) { return null; }
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') { $this->delete($token); return null; }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) { $this->delete($token); return null; }
        if ((int)($payload['version'] ?? 0) !== self::VERSION || !is_array($payload['offers'] ?? null) || !is_array($payload['config'] ?? null)) { $this->delete($token); return null; }
        if ((int)($payload['expiresAt'] ?? 0) <= $this->now()) { $this->delete($token); return null; }
        $context = $this->context();
        if ((int)($payload['productId'] ?? 0) !== $productId
            || (string)($payload['siteId'] ?? '') !== $context['siteId']
            || (int)($payload['userId'] ?? 0) !== $context['userId']
            || !hash_equals((string)($payload['sessionBinding'] ?? ''), $context['sessionBinding'])) {
            return null;
        }
        return $payload;
    }

    public function mergeOffers(string $token, int $productId, array $offers): bool
    {
        $token = $this->normalizeToken($token);
        if ($token === '') { return false; }
        $path = $this->path($token);
        if (!is_file($path)) { return false; }
        $lockPath = $path . '.lock';
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) { return false; }
        $lock = @fopen($lockPath, 'c');
        if (!is_resource($lock)) { return false; }
        try {
            if (!flock($lock, LOCK_EX)) { return false; }
            $payload = $this->load($token, $productId);
            if ($payload === null) { return false; }
            $byKey = [];
            foreach ($payload['offers'] as $offer) {
                $key = (string)($offer['offerKey'] ?? '');
                if ($key !== '') { $byKey[$key] = $offer; }
            }
            foreach ($this->sanitizeOffers($offers) as $offer) {
                $key = (string)($offer['offerKey'] ?? '');
                if ($key !== '') { $byKey[$key] = $offer; }
            }
            $payload['offers'] = array_values($byKey);
            $payload['expiresAt'] = $this->now() + $this->ttl;
            $this->write($token, $payload);
            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function delete(string $token): bool
    {
        $token = $this->normalizeToken($token);
        if ($token === '') { return false; }
        $ok = true;
        $path = $this->path($token);
        foreach ([$path, $path . '.lock'] as $file) {
            if (is_file($file) && !@unlink($file)) { $ok = false; }
        }
        foreach (glob($this->directory . '/.' . $token . '.*.tmp') ?: [] as $tmp) {
            if (is_file($tmp) && !@unlink($tmp)) { $ok = false; }
        }
        return $ok;
    }

    public function cleanupExpired(int $limit = 100): int
    {
        $limit = max(1, $limit);
        if (!is_dir($this->directory)) { return 0; }
        $now = $this->now();
        $processed = 0;
        $iterator = new \DirectoryIterator($this->directory);
        foreach ($iterator as $entry) {
            if ($entry->isDot() || !$entry->isFile()) { continue; }
            $name = $entry->getFilename();
            if (++$processed > $limit) { break; }
            $path = $entry->getPathname();
            if (substr($name, -5) === '.json') {
                $raw = @file_get_contents($path);
                $payload = is_string($raw) ? json_decode($raw, true) : null;
                $expiresAt = is_array($payload) ? (int)($payload['expiresAt'] ?? 0) : 0;
                $expired = $expiresAt > 0 ? $expiresAt <= $now : ($entry->getMTime() + $this->ttl <= $now);
                if ($expired) {
                    $token = substr($name, 0, -5);
                    if ($this->normalizeToken($token) !== '') { $this->delete($token); } else { @unlink($path); }
                }
                continue;
            }
            if (preg_match('/^\.[A-Za-z0-9_-]{32,128}\.[A-Fa-f0-9]+\.tmp$/', $name)) {
                if ($entry->getMTime() + $this->ttl <= $now) { @unlink($path); }
                continue;
            }
            if (substr($name, -10) === '.json.lock') {
                $jsonPath = substr($path, 0, -5);
                if (!is_file($jsonPath) || $entry->getMTime() + $this->ttl <= $now) { @unlink($path); }
            }
        }
        return min($processed, $limit);
    }

    public function sanitizeOffers(array $offers): array
    {
        $result = [];
        foreach ($offers as $offer) {
            if (!is_array($offer)) { continue; }
            $properties = [];
            foreach (is_array($offer['properties'] ?? null) ? $offer['properties'] : [] as $code => $row) {
                if (strpos((string)$code, 'CALC_PROP_') !== 0) { continue; }
                $row = is_array($row) ? $row : ['value' => $row];
                $properties[(string)$code] = [
                    'value' => (string)($row['value'] ?? $row['VALUE'] ?? ''),
                    'xml_id' => (string)($row['xml_id'] ?? $row['xmlId'] ?? $row['VALUE_XML_ID'] ?? ''),
                    'sort' => (int)($row['sort'] ?? $row['SORT'] ?? 500),
                ];
            }
            $ranges = [];
            foreach (is_array($offer['pricing']['ranges'] ?? null) ? $offer['pricing']['ranges'] : [] as $range) {
                if (!is_array($range)) { continue; }
                $ranges[] = [
                    'typeId' => (int)($range['typeId'] ?? $range['catalog_group_id'] ?? 0),
                    'quantityFrom' => array_key_exists('quantityFrom', $range) ? ($range['quantityFrom'] === null ? null : (int)$range['quantityFrom']) : (array_key_exists('quantity_from', $range) ? ($range['quantity_from'] === null ? null : (int)$range['quantity_from']) : null),
                    'quantityTo' => array_key_exists('quantityTo', $range) ? ($range['quantityTo'] === null ? null : (int)$range['quantityTo']) : (array_key_exists('quantity_to', $range) ? ($range['quantity_to'] === null ? null : (int)$range['quantity_to']) : null),
                    'price' => (float)($range['price'] ?? 0),
                    'currency' => strtoupper(trim((string)($range['currency'] ?? ''))),
                ];
            }
            $result[] = [
                'offerKey' => (string)($offer['offerKey'] ?? ''),
                'id' => (int)($offer['id'] ?? 0),
                'source' => (string)($offer['source'] ?? ''),
                'isVirtual' => (bool)($offer['isVirtual'] ?? $offer['is_virtual'] ?? false),
                'quantity' => (int)($offer['quantity'] ?? 0),
                'name' => (string)($offer['name'] ?? ''),
                'xmlId' => (string)($offer['xmlId'] ?? $offer['xml_id'] ?? ''),
                'properties' => $properties,
                'pricing' => ['ranges' => $ranges],
            ];
        }
        return $result;
    }

    public function sanitizeConfig(array $config): array
    {
        $required = [];
        foreach (is_array($config['fields'] ?? null) ? $config['fields'] : [] as $field) {
            if (!is_array($field)) { continue; }
            $code = (string)($field['property_code'] ?? $field['code'] ?? '');
            if (strpos($code, 'CALC_PROP_') === 0 && !in_array($code, $required, true)) { $required[] = $code; }
        }
        return [
            'volumeCode' => 'CALC_PROP_VOLUME',
            'requiredPropertyCodes' => $required,
            'deadline_adjustments' => DeadlineAdjustmentNormalizer::sanitizeNormalized(is_array($config['deadline_adjustments'] ?? null) ? $config['deadline_adjustments'] : []),
            'volumeConstraints' => (new VirtualOfferBatchBuilder())->buildVolumeConstraints($config),
        ];
    }

    public static function normalizeDeadlineAdjustments(array $config, array $volumeEnumValues = []): array
    {
        return DeadlineAdjustmentNormalizer::normalize($config, $volumeEnumValues);
    }

    public static function resolveDefaultContext(): array
    {
        global $USER;
        $userId = is_object($USER) && method_exists($USER, 'GetID') ? (int)$USER->GetID() : 0;
        $sessionId = session_id();
        if ($sessionId === '' && isset($_COOKIE['PHPSESSID'])) { $sessionId = (string)$_COOKIE['PHPSESSID']; }
        return ['siteId' => defined('SITE_ID') ? (string)SITE_ID : '', 'userId' => $userId, 'sessionBinding' => hash('sha256', $sessionId)];
    }

    private function generateToken(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
    private function normalizeToken(string $token): string { return preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token) ? $token : ''; }
    private function path(string $token): string { return $this->directory . '/' . $token . '.json'; }
    private function now(): int { return (int)call_user_func($this->timeResolver); }
    private function context(): array { $ctx = (array)call_user_func($this->contextResolver); return ['siteId'=>(string)($ctx['siteId']??''),'userId'=>(int)($ctx['userId']??0),'sessionBinding'=>(string)($ctx['sessionBinding']??'')]; }
    private function write(string $token, array $payload): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Unable to create calculation session directory');
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) { throw new \RuntimeException('Unable to encode calculation session'); }
        $tmp = $this->directory . '/.' . $token . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $bytes = @file_put_contents($tmp, $json, LOCK_EX);
        if ($bytes !== strlen($json)) { @unlink($tmp); throw new \RuntimeException('Unable to write calculation session'); }
        if (!@rename($tmp, $this->path($token))) { @unlink($tmp); throw new \RuntimeException('Unable to publish calculation session'); }
    }

}
