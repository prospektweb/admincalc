<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

require_once __DIR__ . '/BitrixTransactionStateAuthority.php';

use Bitrix\Main\Application;

/**
 * Atomic active-version pointer for the complete calculator bundle.
 *
 * The working bundle remains editable under its stable versionId. Activation
 * first materializes an immutable content-addressed snapshot and only then
 * switches this pointer to that snapshot. Runtime readers never reopen the
 * mutable working bundle for v3 pointers.
 */
final class CalculatorVersionRuntimePublicationService
{
    public const CONTRACT = 'prospektweb.calc.active-calculator-bundle/v3';
    public const LEGACY_CONTRACT = 'prospektweb.calc.active-calculator-bundle/v2';
    public const FORM_RUNTIME_CONTRACT = 'prospektweb.calc.version-form-runtime/v1';

    private const MODULE_ID = 'prospektweb.calc';
    private const OPTION_PREFIX = 'CALC_ACTIVE_BUNDLE_';
    private const MAX_BYTES = 65536;

    private CalculatorVersionBundleDocumentService $bundles;
    private CalculatorInputMappingService $inputMappings;

    /** @var array<string,callable> */
    private array $adapters;

    public function __construct(
        ?CalculatorVersionBundleDocumentService $bundles = null,
        array $adapters = [],
        ?CalculatorInputMappingService $inputMappings = null
    ) {
        $this->bundles = $bundles ?? new CalculatorVersionBundleDocumentService();
        $this->adapters = $adapters;
        $this->inputMappings = $inputMappings ?? new CalculatorInputMappingService();
    }

    /** @return array<string,mixed> */
    public function activate(int $presetId, string $versionId): array
    {
        return $this->withLock(
            $presetId,
            fn(): array => $this->activateUnlocked($presetId, $versionId)
        );
    }

    /** @return array<string,mixed> */
    private function activateUnlocked(int $presetId, string $versionId): array
    {
        if ($presetId <= 0
            || preg_match('/^v_[a-f0-9]{16,40}$/D', $versionId) !== 1) {
            throw new \InvalidArgumentException('Контекст активации полного bundle некорректен.');
        }
        $bundle = $this->bundles->load($presetId, $versionId);
        if ($bundle === null || ($bundle['readiness']['complete'] ?? false) !== true) {
            $missingComponents = is_array($bundle['readiness']['missingComponents'] ?? null)
                ? $bundle['readiness']['missingComponents']
                : [];
            if (in_array('logic.runtimePayload', $missingComponents, true)) {
                throw new \RuntimeException(
                    'Логика полного bundle не готова к публикации: отсутствует самодостаточный runtime payload. '
                    . 'Откройте вкладку «Логика» версии и сохраните её повторно.',
                    409
                );
            }
            $missing = $missingComponents !== []
                ? implode(', ', $missingComponents)
                : 'неизвестно';
            throw new \RuntimeException(
                'Полный bundle версии не готов к публикации. Требуется пересборка компонентов: ' . $missing . '.',
                409
            );
        }
        try {
            CalculatorVersionComponentDocumentService::validateLogicDocument(
                is_array($bundle['documents']['logic'] ?? null) ? $bundle['documents']['logic'] : [],
                $presetId
            );
        } catch (\InvalidArgumentException $error) {
            throw new \RuntimeException(
                'Логика полного bundle не готова к публикации: ' . $error->getMessage()
                . ' Откройте вкладку «Логика» версии и сохраните её повторно.',
                409,
                $error
            );
        }
        try {
            $mappingValidation = $this->inputMappings->validateAgainstFormDocument(
                $presetId,
                is_array($bundle['documents']['inputMappings'] ?? null) ? $bundle['documents']['inputMappings'] : [],
                is_array($bundle['documents']['form'] ?? null) ? $bundle['documents']['form'] : []
            );
            if (($mappingValidation['valid'] ?? false) !== true) {
                $issues = is_array($mappingValidation['issues'] ?? null) ? $mappingValidation['issues'] : [];
                throw new \InvalidArgumentException((string)($issues[0]['message'] ?? 'неизвестная ошибка связи'));
            }
        } catch (\InvalidArgumentException | \RuntimeException $error) {
            throw new \RuntimeException(
                'Связи Bitrix полного bundle не готовы к публикации: ' . $error->getMessage()
                . ' Исправьте поле формы или вкладку «Сопоставления».',
                409,
                $error
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
        $commercialPolicy = $bundle['documents']['commercialPolicy'] ?? null;
        try {
            if (!is_array($commercialPolicy)
                || (string)($commercialPolicy['contract'] ?? '') !== CalculatorVersionSnapshotSourceService::COMMERCIAL_POLICY_CONTRACT
                || (int)($commercialPolicy['presetId'] ?? 0) !== $presetId) {
                throw new \InvalidArgumentException('Документ коммерческой политики не принадлежит версии калькулятора.');
            }
            CalculatorVersionComponentDocumentService::validateCommercialPolicyDocument($commercialPolicy);
        } catch (\InvalidArgumentException $error) {
            throw new \RuntimeException(
                'Политика сроков не готова к публикации: ' . $error->getMessage() . ' Исправьте вкладку «Сроки».',
                409,
                $error
            );
        }
        $formRuntimePublication = $this->normalizeFormRuntimePublication(
            $this->legacyFormPublication($presetId),
            is_array($bundle['documents']['form'] ?? null) ? $bundle['documents']['form'] : []
        );
        $snapshot = $this->materializeSnapshot($presetId, $versionId, $bundle, $formRuntimePublication);
        $record = [
            'contract' => self::CONTRACT,
            'presetId' => $presetId,
            'versionId' => $versionId,
            'activationId' => (string)$snapshot['activationId'],
            'snapshotVersionId' => (string)$snapshot['snapshotVersionId'],
            'calculatorName' => $metadataName,
            'contentHash' => (string)$snapshot['contentHash'],
            'componentHashes' => $snapshot['componentHashes'],
            'sourceContentHash' => (string)$snapshot['sourceContentHash'],
            'sourceComponentHashes' => $snapshot['sourceComponentHashes'],
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
            || (string)$readback['versionId'] !== $versionId
            || (string)($readback['activationId'] ?? '') !== (string)$record['activationId']) {
            throw new \RuntimeException('Не удалось подтвердить активацию полного bundle.', 409);
        }
        return $readback;
    }

    /**
     * Upgrade a legacy v2 pointer before its active working version is edited.
     * The exact currently active bundle is frozen first; a failed freeze leaves
     * both the pointer and the working bundle untouched.
     */
    public function freezeLegacyActiveForEditing(int $presetId, string $versionId): void
    {
        if ($presetId <= 0 || preg_match('/^v_[a-f0-9]{16,40}$/D', $versionId) !== 1) {
            throw new \InvalidArgumentException('Контекст редактирования версии некорректен.');
        }
        $this->withLock($presetId, function () use ($presetId, $versionId): void {
            $raw = $this->rawGet($presetId);
            if ($raw === '') {
                return;
            }
            $record = json_decode($raw, true);
            if (!is_array($record)) {
                throw new \RuntimeException('Указатель активной версии повреждён.', 409);
            }
            if (($record['contract'] ?? null) !== self::LEGACY_CONTRACT
                || ($record['versionId'] ?? null) !== $versionId) {
                return;
            }
            $this->assertRecord($record, $presetId);
            $bundle = $this->bundles->load($presetId, $versionId);
            $recordHashes = is_array($record['componentHashes'] ?? null) ? $record['componentHashes'] : [];
            $bundleHashes = is_array($bundle) && is_array($bundle['componentHashes'] ?? null)
                ? $bundle['componentHashes']
                : [];
            ksort($recordHashes, SORT_STRING);
            ksort($bundleHashes, SORT_STRING);
            if ($bundle === null
                || ($bundle['readiness']['complete'] ?? false) !== true
                || !hash_equals((string)$record['contentHash'], (string)$bundle['contentHash'])
                || $recordHashes !== $bundleHashes) {
                throw new \RuntimeException('Legacy-публикация уже отличается от рабочей версии. Редактирование остановлено.', 409);
            }
            $formRuntimePublication = $this->normalizeFormRuntimePublication(
                $this->legacyFormPublication($presetId),
                is_array($bundle['documents']['form'] ?? null) ? $bundle['documents']['form'] : []
            );
            $snapshot = $this->materializeSnapshot(
                $presetId,
                $versionId,
                $bundle,
                $formRuntimePublication
            );
            if (!hash_equals($raw, $this->rawGet($presetId))) {
                throw new \RuntimeException('Публичная версия изменилась во время миграции. Повторите действие.', 409);
            }
            $upgraded = [
                'contract' => self::CONTRACT,
                'presetId' => $presetId,
                'versionId' => $versionId,
                'activationId' => (string)$snapshot['activationId'],
                'snapshotVersionId' => (string)$snapshot['snapshotVersionId'],
                'calculatorName' => (string)$record['calculatorName'],
                'contentHash' => (string)$snapshot['contentHash'],
                'componentHashes' => $snapshot['componentHashes'],
                'sourceContentHash' => (string)$snapshot['sourceContentHash'],
                'sourceComponentHashes' => $snapshot['sourceComponentHashes'],
                'activatedAt' => (string)$record['activatedAt'],
            ];
            $this->rawSet($presetId, $this->encode($upgraded));
            $readback = $this->resolve($presetId);
            if ($readback === null
                || !hash_equals((string)$upgraded['contentHash'], (string)$readback['contentHash'])
                || !hash_equals((string)$upgraded['sourceContentHash'], (string)$readback['sourceContentHash'])) {
                throw new \RuntimeException('Не удалось подтвердить миграцию активной публикации.', 409);
            }
        });
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
        $bundleVersionId = ($record['contract'] ?? null) === self::CONTRACT
            ? (string)$record['snapshotVersionId']
            : (string)$record['versionId'];
        $bundle = $this->bundles->load($presetId, $bundleVersionId);
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
        CalculatorVersionComponentDocumentService::validateLogicDocument(
            is_array($bundle['documents']['logic'] ?? null) ? $bundle['documents']['logic'] : [],
            $presetId
        );
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
            'activationId' => $active['activationId'] ?? null,
            'contentHash' => (string)$active['contentHash'],
            'componentHashes' => $active['componentHashes'],
        ];
    }

    /** @param array<string,mixed> $record */
    private function assertRecord(array $record, int $presetId): void
    {
        $contract = (string)($record['contract'] ?? '');
        $keys = array_keys($record);
        sort($keys, SORT_STRING);
        $expected = $contract === self::CONTRACT
            ? [
                'activatedAt', 'activationId', 'calculatorName', 'componentHashes', 'contentHash',
                'contract', 'presetId', 'snapshotVersionId', 'sourceComponentHashes',
                'sourceContentHash', 'versionId',
            ]
            : [
                'activatedAt', 'calculatorName', 'componentHashes', 'contentHash',
                'contract', 'presetId', 'versionId',
            ];
        sort($expected, SORT_STRING);
        if ($keys !== $expected
            || !in_array($contract, [self::CONTRACT, self::LEGACY_CONTRACT], true)
            || (int)($record['presetId'] ?? 0) !== $presetId
            || preg_match('/^v_[a-f0-9]{16,40}$/D', (string)($record['versionId'] ?? '')) !== 1
            || ($contract === self::CONTRACT
                && (preg_match('/^a_[a-f0-9]{32}$/D', (string)($record['activationId'] ?? '')) !== 1
                    || preg_match('/^v_[a-f0-9]{40}$/D', (string)($record['snapshotVersionId'] ?? '')) !== 1))
            || trim((string)($record['calculatorName'] ?? '')) === ''
            || preg_match('/^[a-f0-9]{64}$/D', (string)($record['contentHash'] ?? '')) !== 1
            || ($contract === self::CONTRACT
                && (preg_match('/^[a-f0-9]{64}$/D', (string)($record['sourceContentHash'] ?? '')) !== 1
                    || !is_array($record['sourceComponentHashes'] ?? null)
                    || !hash_equals(
                        'a_' . substr((string)$record['contentHash'], 0, 32),
                        (string)$record['activationId']
                    )
                    || !hash_equals(
                        'v_' . substr((string)$record['contentHash'], 0, 40),
                        (string)$record['snapshotVersionId']
                    )))
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
        if ($contract === self::CONTRACT) {
            $sourceKeys = array_keys($record['sourceComponentHashes']);
            sort($sourceKeys, SORT_STRING);
            if ($sourceKeys !== $expectedComponents) {
                throw new \RuntimeException('Указатель активной версии содержит неполный исходный bundle.', 409);
            }
            foreach ($record['sourceComponentHashes'] as $hash) {
                if (preg_match('/^[a-f0-9]{64}$/D', (string)$hash) !== 1) {
                    throw new \RuntimeException('Указатель активной версии содержит повреждённый исходный hash.', 409);
                }
            }
        }
    }

    /** @param array<string,mixed> $bundle @param array<string,mixed> $formRuntimePublication @return array<string,mixed> */
    private function materializeSnapshot(
        int $presetId,
        string $versionId,
        array $bundle,
        array $formRuntimePublication
    ): array
    {
        $sourceContentHash = (string)($bundle['contentHash'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/D', $sourceContentHash) !== 1) {
            throw new \RuntimeException('Hash полного bundle версии повреждён.', 409);
        }
        $documents = is_array($bundle['documents'] ?? null) ? $bundle['documents'] : [];
        if (!is_array($documents['form'] ?? null)) {
            throw new \RuntimeException('Документ формы полного bundle отсутствует.', 409);
        }
        $documents['form']['runtimePublication'] = $formRuntimePublication;
        $deployment = $this->bundles->inspect($documents);
        $contentHash = (string)$deployment['contentHash'];
        $activationId = 'a_' . substr($contentHash, 0, 32);
        $snapshotVersionId = 'v_' . substr($contentHash, 0, 40);
        $existing = $this->bundles->load($presetId, $snapshotVersionId);
        if ($existing === null) {
            $existing = $this->bundles->save($presetId, $snapshotVersionId, $documents);
        }
        $sourceHashes = is_array($bundle['componentHashes'] ?? null) ? $bundle['componentHashes'] : [];
        $deploymentHashes = is_array($deployment['componentHashes'] ?? null) ? $deployment['componentHashes'] : [];
        $snapshotHashes = is_array($existing['componentHashes'] ?? null) ? $existing['componentHashes'] : [];
        ksort($deploymentHashes, SORT_STRING);
        ksort($snapshotHashes, SORT_STRING);
        if (($existing['readiness']['complete'] ?? false) !== true
            || !hash_equals($contentHash, (string)($existing['contentHash'] ?? ''))
            || $deploymentHashes !== $snapshotHashes) {
            throw new \RuntimeException('Не удалось подтвердить неизменяемый снимок активации.', 409);
        }
        return [
            'activationId' => $activationId,
            'snapshotVersionId' => $snapshotVersionId,
            'sourceVersionId' => $versionId,
            'contentHash' => $contentHash,
            'componentHashes' => $snapshotHashes,
            'sourceContentHash' => $sourceContentHash,
            'sourceComponentHashes' => $sourceHashes,
        ];
    }

    /** @param array<string,mixed> $value @param array<string,mixed> $formDocument @return array<string,mixed> */
    private function normalizeFormRuntimePublication(array $value, array $formDocument): array
    {
        if (($value['contract'] ?? null) === self::FORM_RUNTIME_CONTRACT) {
            $publication = is_array($value['publication'] ?? null) ? $value['publication'] : [];
            $snapshot = is_array($value['snapshot'] ?? null) ? $value['snapshot'] : [];
            $authoringForm = $formDocument['formDefinition'] ?? null;
            $authoringBinding = $formDocument['bindingDefinition'] ?? null;
        } elseif (is_array($value['authoring'] ?? null) && is_array($value['snapshot'] ?? null)) {
            $publication = is_array($value['authoring']['publication'] ?? null)
                ? $value['authoring']['publication']
                : [];
            $snapshot = $value['snapshot'];
            $authoringForm = $value['authoring']['formDefinition'] ?? null;
            $authoringBinding = $value['authoring']['bindingDefinition'] ?? null;
        } else {
            $published = is_array($value['published'] ?? null) ? $value['published'] : [];
            $publication = [
                'revision' => (int)($published['revision'] ?? 0),
                'compileHash' => (string)($published['compileHash'] ?? ''),
            ];
            $snapshot = is_array($published['snapshot'] ?? null) ? $published['snapshot'] : [];
            $authoringForm = $value['formDefinition'] ?? null;
            $authoringBinding = $value['bindingDefinition'] ?? null;
        }
        $revision = (int)($publication['revision'] ?? 0);
        $compileHash = (string)($publication['compileHash'] ?? '');
        $meta = is_array($snapshot['_form_first'] ?? null) ? $snapshot['_form_first'] : [];
        if ($revision <= 0
            || preg_match('/^[a-f0-9]{64}$/D', $compileHash) !== 1
            || (int)($meta['publishedRevision'] ?? 0) !== $revision
            || !hash_equals($compileHash, (string)($meta['compileHash'] ?? ''))
            || !is_array($authoringForm)
            || !is_array($authoringBinding)
            || !hash_equals($this->canonicalHash($authoringForm), $this->canonicalHash((array)($formDocument['formDefinition'] ?? [])))
            || !hash_equals($this->canonicalHash($authoringBinding), $this->canonicalHash((array)($formDocument['bindingDefinition'] ?? [])))) {
            throw new \RuntimeException('Материализованный runtime формы не соответствует активируемой версии.', 409);
        }
        return [
            'contract' => self::FORM_RUNTIME_CONTRACT,
            'publication' => ['revision' => $revision, 'compileHash' => $compileHash],
            'snapshot' => $snapshot,
        ];
    }

    /** @return array<string,mixed> */
    private function legacyFormPublication(int $presetId): array
    {
        if (isset($this->adapters['legacy_form_publication'])) {
            $value = call_user_func($this->adapters['legacy_form_publication'], $presetId);
            return is_array($value) ? $value : [];
        }
        $class = '\\Prospektweb\\Frontcalc\\Service\\FormFirstAuthoringStore';
        if (!class_exists($class) || !is_callable([$class, 'publishedBundleForPreset'])) {
            throw new \RuntimeException('Материализованный runtime формы недоступен.', 409);
        }
        $value = $class::publishedBundleForPreset($presetId);
        return is_array($value) ? $value : [];
    }

    /** @param array<string,mixed> $value */
    private function canonicalHash(array $value): string
    {
        $canonicalize = function ($node) use (&$canonicalize) {
            if (!is_array($node)) return $node;
            if (array_values($node) === $node) return array_map($canonicalize, $node);
            ksort($node, SORT_STRING);
            foreach ($node as $key => $child) $node[$key] = $canonicalize($child);
            return $node;
        };
        $raw = json_encode($canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($raw)) throw new \RuntimeException('Не удалось проверить runtime формы.');
        return hash('sha256', $raw);
    }

    /** @param array<string,mixed> $value */
    private function encode(array $value): string
    {
        ksort($value, SORT_STRING);
        if (is_array($value['componentHashes'] ?? null)) {
            ksort($value['componentHashes'], SORT_STRING);
        }
        if (is_array($value['sourceComponentHashes'] ?? null)) {
            ksort($value['sourceComponentHashes'], SORT_STRING);
        }
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Не удалось сериализовать указатель активной версии.');
        }
        return $encoded;
    }

    private function rawGet(int $presetId): string
    {
        $name = self::OPTION_PREFIX . $presetId;
        if (isset($this->adapters['get'])) {
            return (string)call_user_func($this->adapters['get'], $name);
        }
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $sql = "SELECT VALUE FROM b_option WHERE BINARY MODULE_ID='"
            . $helper->forSql(self::MODULE_ID)
            . "' AND BINARY NAME='" . $helper->forSql($name)
            . "' AND SITE_ID IS NULL";
        if (BitrixTransactionStateAuthority::isActive($connection)) {
            $sql .= ' FOR UPDATE';
        }
        $rows = [];
        $result = $connection->query($sql);
        while (is_array($row = $result->fetch())) {
            $rows[] = $row;
            if (count($rows) > 1) break;
        }
        if (count($rows) > 1) {
            throw new \RuntimeException('Хранилище указателя активной версии содержит дубликат.', 409);
        }
        return count($rows) === 1 ? (string)($rows[0]['VALUE'] ?? '') : '';
    }

    private function rawSet(int $presetId, string $raw): void
    {
        $name = self::OPTION_PREFIX . $presetId;
        if (isset($this->adapters['set'])) {
            call_user_func($this->adapters['set'], $name, $raw);
            return;
        }
        $connection = Application::getConnection();
        if (!BitrixTransactionStateAuthority::isActive($connection)) {
            throw new \RuntimeException('Указатель активной версии можно менять только в транзакции.', 409);
        }
        $helper = $connection->getSqlHelper();
        $moduleSql = $helper->forSql(self::MODULE_ID);
        $nameSql = $helper->forSql($name);
        $rawSql = $helper->forSql($raw);
        $current = $this->rawGet($presetId);
        if ($current === '') {
            $connection->queryExecute(
                "INSERT INTO b_option (MODULE_ID, NAME, VALUE, DESCRIPTION, SITE_ID) VALUES ('"
                . $moduleSql . "','" . $nameSql . "','" . $rawSql . "','',NULL)"
            );
        } else {
            $connection->queryExecute(
                "UPDATE b_option SET VALUE='" . $rawSql
                . "' WHERE BINARY MODULE_ID='" . $moduleSql
                . "' AND BINARY NAME='" . $nameSql . "' AND SITE_ID IS NULL"
            );
        }
        if (!hash_equals($raw, $this->rawGet($presetId))) {
            throw new \RuntimeException('Не удалось подтвердить указатель активной версии.', 409);
        }
    }

    private function withLock(int $presetId, callable $callback)
    {
        if (isset($this->adapters['lock'])) {
            return call_user_func($this->adapters['lock'], $presetId, $callback);
        }
        $connection = Application::getConnection();
        $ownsTransaction = !BitrixTransactionStateAuthority::isActive($connection);
        if ($ownsTransaction) {
            $connection->startTransaction();
        }
        try {
            $helper = $connection->getSqlHelper();
            $expectedModules = [self::MODULE_ID, 'prospektweb.frontcalc'];
            $result = $connection->query(
                "SELECT ID FROM b_module WHERE BINARY ID IN ('"
                . implode("','", array_map([$helper, 'forSql'], $expectedModules))
                . "') ORDER BY BINARY ID FOR UPDATE"
            );
            $lockedModules = [];
            while (is_array($row = $result->fetch())) {
                $lockedModules[] = (string)($row['ID'] ?? '');
            }
            sort($expectedModules, SORT_STRING);
            if ($lockedModules !== $expectedModules) {
                throw new \RuntimeException('Строки авторитета модулей калькулятора не найдены точно.', 409);
            }
            $result = $callback();
            if ($ownsTransaction) {
                $connection->commitTransaction();
            }
            return $result;
        } catch (\Throwable $error) {
            if ($ownsTransaction) {
                try {
                    $connection->rollbackTransaction();
                } catch (\Throwable $ignored) {
                }
            }
            throw $error;
        }
    }

    private function now(): string
    {
        return isset($this->adapters['now'])
            ? (string)call_user_func($this->adapters['now'])
            : date(DATE_ATOM);
    }
}
