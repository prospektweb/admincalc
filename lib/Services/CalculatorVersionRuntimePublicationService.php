<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Config\Option;

/**
 * Atomic active-version pointer for the complete calculator bundle.
 *
 * The pointer never copies individual components. It pins one immutable
 * versionId/contentHash pair and every runtime reader revalidates the bundle
 * manifest before exposing it. This prevents a form from one revision being
 * combined with logic, storefronts or commercial policy from another.
 */
final class CalculatorVersionRuntimePublicationService
{
    public const CONTRACT = 'prospektweb.calc.active-calculator-bundle/v2';

    private const MODULE_ID = 'prospektweb.calc';
    private const OPTION_PREFIX = 'CALC_ACTIVE_BUNDLE_';
    private const MAX_BYTES = 65536;

    private CalculatorVersionBundleDocumentService $bundles;

    /** @var array<string,callable> */
    private array $adapters;

    public function __construct(
        ?CalculatorVersionBundleDocumentService $bundles = null,
        array $adapters = []
    ) {
        $this->bundles = $bundles ?? new CalculatorVersionBundleDocumentService();
        $this->adapters = $adapters;
    }

    /** @return array<string,mixed> */
    public function activate(int $presetId, string $versionId): array
    {
        if ($presetId <= 0
            || preg_match('/^v_[a-f0-9]{16,40}$/D', $versionId) !== 1) {
            throw new \InvalidArgumentException('Контекст активации полного bundle некорректен.');
        }
        $bundle = $this->bundles->load($presetId, $versionId);
        if ($bundle === null || ($bundle['readiness']['complete'] ?? false) !== true) {
            $missing = is_array($bundle['readiness']['missingComponents'] ?? null)
                ? implode(', ', $bundle['readiness']['missingComponents'])
                : 'неизвестно';
            throw new \RuntimeException(
                'Полный bundle версии не готов к публикации. Требуется пересборка компонентов: ' . $missing . '.',
                409
            );
        }
        $metadata = $bundle['documents']['publicationMetadata'] ?? null;
        if (!is_array($metadata)
            || (string)($metadata['contract'] ?? '') !== CalculatorVersionSnapshotSourceService::PUBLICATION_METADATA_CONTRACT
            || (int)($metadata['presetId'] ?? 0) !== $presetId) {
            throw new \RuntimeException('Публичные метаданные полного bundle отсутствуют или повреждены.', 409);
        }
        $metadataName = trim((string)($metadata['calculatorName'] ?? ''));
        if ($metadataName === '') {
            throw new \RuntimeException('Название калькулятора в bundle отсутствует.', 409);
        }
        $record = [
            'contract' => self::CONTRACT,
            'presetId' => $presetId,
            'versionId' => $versionId,
            'calculatorName' => $metadataName,
            'contentHash' => (string)$bundle['contentHash'],
            'componentHashes' => $bundle['componentHashes'],
            'activatedAt' => $this->now(),
        ];
        $raw = $this->encode($record);
        if (strlen($raw) > self::MAX_BYTES) {
            throw new \RuntimeException('Указатель активной версии превышает безопасный размер.', 409);
        }
        $this->rawSet($presetId, $raw);
        $readback = $this->resolve($presetId);
        if ($readback === null
            || !hash_equals((string)$record['contentHash'], (string)$readback['contentHash'])
            || (string)$readback['versionId'] !== $versionId) {
            throw new \RuntimeException('Не удалось подтвердить активацию полного bundle.', 409);
        }
        return $readback;
    }

    /** @return array<string,mixed>|null */
    public function resolve(int $presetId): ?array
    {
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('presetId must be positive.');
        }
        $raw = $this->rawGet($presetId);
        if ($raw === '') {
            return null;
        }
        if (strlen($raw) > self::MAX_BYTES) {
            throw new \RuntimeException('Указатель активной версии повреждён.', 409);
        }
        $record = json_decode($raw, true);
        $this->assertRecord(is_array($record) ? $record : [], $presetId);
        $bundle = $this->bundles->load($presetId, (string)$record['versionId']);
        $recordHashes = is_array($record['componentHashes'] ?? null) ? $record['componentHashes'] : [];
        $bundleHashes = is_array($bundle['componentHashes'] ?? null) ? $bundle['componentHashes'] : [];
        ksort($recordHashes, SORT_STRING);
        ksort($bundleHashes, SORT_STRING);
        if ($bundle === null
            || ($bundle['readiness']['complete'] ?? false) !== true
            || !hash_equals((string)$record['contentHash'], (string)$bundle['contentHash'])
            || $recordHashes !== $bundleHashes) {
            throw new \RuntimeException('Активный bundle калькулятора отсутствует, неполон или изменён.', 409);
        }
        return $record + [
            'readiness' => $bundle['readiness'],
            'updatedAt' => (string)$bundle['updatedAt'],
            'documents' => $bundle['documents'],
        ];
    }

    /** @return array<string,mixed> */
    public function readiness(int $presetId): array
    {
        try {
            $active = $this->resolve($presetId);
        } catch (\Throwable $error) {
            return [
                'contract' => self::CONTRACT,
                'presetId' => $presetId,
                'ready' => false,
                'problem' => 'bundle_invalid',
            ];
        }
        if ($active === null) {
            return [
                'contract' => self::CONTRACT,
                'presetId' => $presetId,
                'ready' => false,
                'problem' => 'rebuild_required',
            ];
        }
        return [
            'contract' => self::CONTRACT,
            'presetId' => $presetId,
            'ready' => true,
            'problem' => null,
            'versionId' => (string)$active['versionId'],
            'contentHash' => (string)$active['contentHash'],
            'componentHashes' => $active['componentHashes'],
        ];
    }

    /** @param array<string,mixed> $record */
    private function assertRecord(array $record, int $presetId): void
    {
        $keys = array_keys($record);
        sort($keys, SORT_STRING);
        $expected = [
            'activatedAt', 'calculatorName', 'componentHashes', 'contentHash',
            'contract', 'presetId', 'versionId',
        ];
        sort($expected, SORT_STRING);
        if ($keys !== $expected
            || ($record['contract'] ?? null) !== self::CONTRACT
            || (int)($record['presetId'] ?? 0) !== $presetId
            || preg_match('/^v_[a-f0-9]{16,40}$/D', (string)($record['versionId'] ?? '')) !== 1
            || trim((string)($record['calculatorName'] ?? '')) === ''
            || preg_match('/^[a-f0-9]{64}$/D', (string)($record['contentHash'] ?? '')) !== 1
            || !is_array($record['componentHashes'] ?? null)
            || !is_string($record['activatedAt'] ?? null)) {
            throw new \RuntimeException('Указатель активной версии повреждён.', 409);
        }
        $componentKeys = array_keys($record['componentHashes']);
        $expectedComponents = CalculatorVersionBundleDocumentService::COMPONENTS;
        sort($componentKeys, SORT_STRING);
        sort($expectedComponents, SORT_STRING);
        if ($componentKeys !== $expectedComponents) {
            throw new \RuntimeException('Указатель активной версии содержит неполный набор компонентов.', 409);
        }
        foreach ($record['componentHashes'] as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', (string)$hash) !== 1) {
                throw new \RuntimeException('Указатель активной версии содержит повреждённый hash.', 409);
            }
        }
    }

    /** @param array<string,mixed> $value */
    private function encode(array $value): string
    {
        ksort($value, SORT_STRING);
        if (is_array($value['componentHashes'] ?? null)) {
            ksort($value['componentHashes'], SORT_STRING);
        }
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Не удалось сериализовать указатель активной версии.');
        }
        return $encoded;
    }

    private function rawGet(int $presetId): string
    {
        return isset($this->adapters['get'])
            ? (string)call_user_func($this->adapters['get'], self::OPTION_PREFIX . $presetId)
            : (string)Option::get(self::MODULE_ID, self::OPTION_PREFIX . $presetId, '');
    }

    private function rawSet(int $presetId, string $raw): void
    {
        if (isset($this->adapters['set'])) {
            call_user_func($this->adapters['set'], self::OPTION_PREFIX . $presetId, $raw);
            return;
        }
        Option::set(self::MODULE_ID, self::OPTION_PREFIX . $presetId, $raw);
    }

    private function now(): string
    {
        return isset($this->adapters['now'])
            ? (string)call_user_func($this->adapters['now'])
            : date(DATE_ATOM);
    }
}
