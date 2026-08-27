<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

require_once __DIR__ . '/BitrixTransactionStateAuthority.php';

use Bitrix\Main\Application;

require_once __DIR__ . '/BitrixTransactionStateAuthority.php';

/** Isolated editable form documents owned by calculator version ids. */
final class CalculatorVersionFormDocumentService
{
    public const CONTRACT = 'prospektweb.calc.calculator-version-form/v1';

    private const MODULE_ID = 'prospektweb.calc';
    private const STORAGE_VERSION = 1;
    private const MAX_BYTES = 2097152;

    /** @var array<string,callable> */
    private array $adapters;

    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    public function has(int $presetId, string $versionId): bool
    {
        $this->assertIdentity($presetId, $versionId);
        return $this->load($presetId, $versionId) !== null;
    }

    /** @return array<string,mixed> */
    public function ensure(
        int $presetId,
        string $versionId,
        ?string $sourceVersionId,
        array $legacyWorkspace,
        bool $seedEmptyDraft = false
    ): array {
        return $this->withLock($presetId, function () use (
            $presetId,
            $versionId,
            $sourceVersionId,
            $legacyWorkspace,
            $seedEmptyDraft
        ): array {
            return $this->ensureLocked(
                $presetId,
                $versionId,
                $sourceVersionId,
                $legacyWorkspace,
                $seedEmptyDraft
            );
        });
    }

    /** @return array<string,mixed> */
    private function ensureLocked(
        int $presetId,
        string $versionId,
        ?string $sourceVersionId,
        array $legacyWorkspace,
        bool $seedEmptyDraft
    ): array {
        $this->assertIdentity($presetId, $versionId);
        $stored = $this->load($presetId, $versionId);
        if ($stored !== null) {
            if ($seedEmptyDraft
                && ($stored['formDefinition']['fields'] ?? null) === []
                && is_array($legacyWorkspace['formDefinition']['fields'] ?? null)
                && $legacyWorkspace['formDefinition']['fields'] !== []) {
                $stored['formDefinition'] = $legacyWorkspace['formDefinition'];
                $stored['bindingDefinition'] = $legacyWorkspace['bindingDefinition'];
                $stored['updatedAt'] = $this->now();
                $this->assertDocument($stored);
                $this->save($stored);
            }
            return $this->publicDocument($stored);
        }
        $source = null;
        if ($sourceVersionId !== null) {
            $this->assertIdentity($presetId, $sourceVersionId);
            $source = $this->load($presetId, $sourceVersionId);
        }
        $now = $this->now();
        $document = [
            'storageVersion' => self::STORAGE_VERSION,
            'presetId' => $presetId,
            'versionId' => $versionId,
            'formDefinition' => $source['formDefinition'] ?? $legacyWorkspace['formDefinition'] ?? null,
            'bindingDefinition' => $source['bindingDefinition'] ?? $legacyWorkspace['bindingDefinition'] ?? null,
            'createdAt' => $now,
            'updatedAt' => $now,
        ];
        $this->assertDocument($document);
        $this->save($document);
        return $this->publicDocument($document);
    }

    /** @return array<string,mixed> */
    public function saveDraft(
        int $presetId,
        string $versionId,
        string $expectedRevision,
        array $formDefinition,
        array $bindingDefinition
    ): array {
        return $this->withLock($presetId, function () use (
            $presetId,
            $versionId,
            $expectedRevision,
            $formDefinition,
            $bindingDefinition
        ): array {
            return $this->saveDraftLocked(
                $presetId,
                $versionId,
                $expectedRevision,
                $formDefinition,
                $bindingDefinition
            );
        });
    }

    /** @return array<string,mixed> */
    private function saveDraftLocked(
        int $presetId,
        string $versionId,
        string $expectedRevision,
        array $formDefinition,
        array $bindingDefinition
    ): array {
        $this->assertIdentity($presetId, $versionId);
        $this->assertRevision($expectedRevision);
        $current = $this->load($presetId, $versionId);
        if ($current === null) {
            throw new \RuntimeException('Документ версии не найден. Перезагрузите список версий.', 409);
        }
        if (!hash_equals($this->revision($current), $expectedRevision)) {
            throw new \RuntimeException('Форма версии изменена в другой вкладке. Перезагрузите редактор.', 409);
        }
        $current['formDefinition'] = $formDefinition;
        $current['bindingDefinition'] = $bindingDefinition;
        $current['updatedAt'] = $this->now();
        $this->assertDocument($current);
        $this->save($current);
        return $this->publicDocument($current);
    }

    public function delete(int $presetId, string $versionId): void
    {
        $this->assertIdentity($presetId, $versionId);
        $this->withLock($presetId, function () use ($presetId, $versionId): void {
            $this->rawDelete($this->optionName($presetId, $versionId));
        });
    }

    /** @return array<string,mixed>|null */
    private function load(int $presetId, string $versionId): ?array
    {
        $raw = $this->rawGet($this->optionName($presetId, $versionId));
        if ($raw === '') {
            return null;
        }
        if (strlen($raw) > self::MAX_BYTES) {
            throw new \RuntimeException('Документ формы версии превышает безопасный размер.');
        }
        $document = json_decode($raw, true);
        if (!is_array($document)) {
            throw new \RuntimeException('Документ формы версии повреждён.');
        }
        $this->assertDocument($document);
        if ((int)$document['presetId'] !== $presetId || (string)$document['versionId'] !== $versionId) {
            throw new \RuntimeException('Документ формы не принадлежит запрошенной версии.');
        }
        return $document;
    }

    private function save(array $document): void
    {
        $encoded = $this->encodeCanonical($document);
        if (strlen($encoded) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Документ формы версии превышает безопасный лимит 2 МБ.');
        }
        $name = $this->optionName((int)$document['presetId'], (string)$document['versionId']);
        $this->rawSet($name, $encoded);
        $readBack = $this->load((int)$document['presetId'], (string)$document['versionId']);
        if ($readBack === null || !hash_equals($this->revision($document), $this->revision($readBack))) {
            throw new \RuntimeException('Не удалось подтвердить сохранение документа формы версии.');
        }
    }

    /** @return array<string,mixed> */
    private function publicDocument(array $document): array
    {
        return [
            'contract' => self::CONTRACT,
            'presetId' => (int)$document['presetId'],
            'versionId' => (string)$document['versionId'],
            'revision' => $this->revision($document),
            'formDefinition' => $document['formDefinition'],
            'bindingDefinition' => $document['bindingDefinition'],
            'updatedAt' => (string)$document['updatedAt'],
        ];
    }

    private function assertDocument(array $document): void
    {
        $this->assertIdentity((int)($document['presetId'] ?? 0), (string)($document['versionId'] ?? ''));
        if ((int)($document['storageVersion'] ?? 0) !== self::STORAGE_VERSION
            || !is_array($document['formDefinition'] ?? null)
            || !is_array($document['bindingDefinition'] ?? null)
            || !is_string($document['createdAt'] ?? null)
            || !is_string($document['updatedAt'] ?? null)) {
            throw new \RuntimeException('Документ формы версии имеет несовместимый контракт.');
        }
    }

    private function assertIdentity(int $presetId, string $versionId): void
    {
        if ($presetId <= 0 || preg_match('/^v_[a-f0-9]{16,40}$/D', $versionId) !== 1) {
            throw new \InvalidArgumentException('Некорректный идентификатор версии калькулятора.');
        }
    }

    private function assertRevision(string $revision): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
            throw new \InvalidArgumentException('expectedVersionRevision must be a lowercase SHA-256.');
        }
    }

    private function optionName(int $presetId, string $versionId): string
    {
        return 'CALC_VERSION_FORM_' . $presetId . '_' . $versionId;
    }

    private function revision(array $document): string
    {
        return hash('sha256', $this->encodeCanonical($document));
    }

    private function encodeCanonical($value): string
    {
        $canonicalize = function ($node) use (&$canonicalize) {
            if (!is_array($node)) return $node;
            if (array_values($node) === $node) return array_map($canonicalize, $node);
            ksort($node, SORT_STRING);
            foreach ($node as $key => $child) $node[$key] = $canonicalize($child);
            return $node;
        };
        $encoded = json_encode($canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) throw new \RuntimeException('Не удалось сериализовать документ формы версии.');
        return $encoded;
    }

    private function rawGet(string $name): string
    {
        if (isset($this->adapters['get'])) {
            return (string)call_user_func($this->adapters['get'], $name);
        }
        $name = mb_strtolower($name);
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $sql = "SELECT VALUE FROM b_option WHERE BINARY MODULE_ID='"
            . $helper->forSql(self::MODULE_ID)
            . "' AND BINARY NAME='" . $helper->forSql($name)
            . "' AND (SITE_ID IS NULL OR SITE_ID='')";
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
            throw new \RuntimeException('Хранилище формы версии содержит дублирующий параметр.', 409);
        }
        return count($rows) === 1 ? (string)($rows[0]['VALUE'] ?? '') : '';
    }

    private function rawSet(string $name, string $value): void
    {
        if (isset($this->adapters['set'])) {
            call_user_func($this->adapters['set'], $name, $value);
            return;
        }
        $name = mb_strtolower($name);
        $connection = Application::getConnection();
        if (!BitrixTransactionStateAuthority::isActive($connection)) {
            throw new \RuntimeException('Документ формы версии можно менять только в транзакции.', 409);
        }
        $helper = $connection->getSqlHelper();
        $moduleSql = $helper->forSql(self::MODULE_ID);
        $nameSql = $helper->forSql($name);
        $valueSql = $helper->forSql($value);
        if ($this->rawGet($name) === '') {
            $connection->queryExecute(
                "INSERT INTO b_option (MODULE_ID, NAME, VALUE, DESCRIPTION) VALUES ('"
                . $moduleSql . "','" . $nameSql . "','" . $valueSql . "','')"
            );
        } else {
            $connection->queryExecute(
                "UPDATE b_option SET VALUE='" . $valueSql
                . "' WHERE BINARY MODULE_ID='" . $moduleSql
                . "' AND BINARY NAME='" . $nameSql . "' AND (SITE_ID IS NULL OR SITE_ID='')"
            );
        }
        if (!hash_equals($value, $this->rawGet($name))) {
            throw new \RuntimeException('Не удалось подтвердить сохранение документа формы версии.', 409);
        }
    }

    private function rawDelete(string $name): void
    {
        if (isset($this->adapters['delete'])) {
            call_user_func($this->adapters['delete'], $name);
            return;
        }
        $name = mb_strtolower($name);
        $connection = Application::getConnection();
        if (!BitrixTransactionStateAuthority::isActive($connection)) {
            throw new \RuntimeException('Документ формы версии можно удалять только в транзакции.', 409);
        }
        $helper = $connection->getSqlHelper();
        $connection->queryExecute(
            "DELETE FROM b_option WHERE BINARY MODULE_ID='" . $helper->forSql(self::MODULE_ID)
            . "' AND BINARY NAME='" . $helper->forSql($name) . "' AND (SITE_ID IS NULL OR SITE_ID='')"
        );
        if ($this->rawGet($name) !== '') {
            throw new \RuntimeException('Не удалось подтвердить удаление документа формы версии.', 409);
        }
    }

    /** @template T @param callable():T $callback @return T */
    private function withLock(int $presetId, callable $callback)
    {
        if (isset($this->adapters['lock'])) {
            return call_user_func($this->adapters['lock'], $presetId, $callback);
        }
        // Adapter-backed unit stores do not participate in the Bitrix DB
        // transaction; their callbacks provide their own deterministic CAS.
        if (isset($this->adapters['get']) || isset($this->adapters['set']) || isset($this->adapters['delete'])) {
            return $callback();
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
        return isset($this->adapters['now']) ? (string)call_user_func($this->adapters['now']) : date(DATE_ATOM);
    }
}
