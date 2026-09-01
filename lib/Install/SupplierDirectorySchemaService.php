<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Install;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

/**
 * Idempotent owner of the private material-supplier directory schema.
 *
 * The service creates schema only. Supplier, material and relation elements
 * are deliberately outside this migration.
 */
final class SupplierDirectorySchemaService
{
    public const CONTRACT = 'prospektweb.calc.supplier-directory-schema/v1';
    public const SCHEMA_VERSION = 1;
    public const MODULE_ID = 'prospektweb.calc';
    public const IBLOCK_TYPE = 'calculator_catalog';
    public const IBLOCK_CODE = 'CALC_SUPPLIERS';
    public const IBLOCK_XML_ID = 'prospektweb.calc.suppliers';
    public const OPTION_NAME = 'IBLOCK_CALC_SUPPLIERS';
    public const RECEIPT_OPTION_NAME = 'SUPPLIER_DIRECTORY_SCHEMA_RECEIPT';

    /** @var array<string,mixed> */
    private array $adapters;

    /** @param array<string,mixed> $adapters */
    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    /** @return array<string,array<string,mixed>> */
    public static function supplierPropertySchema(): array
    {
        return [
            'ENTITY_KEY' => [
                'NAME' => 'Стабильный ключ поставщика',
                'PROPERTY_TYPE' => 'S',
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'Y',
                'SORT' => 100,
                'HINT' => 'Переносимый ключ формата supplier:<stable-token>',
            ],
            'LEGAL_NAME' => [
                'NAME' => 'Юридическое наименование',
                'PROPERTY_TYPE' => 'S',
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
                'SORT' => 200,
            ],
            'INN' => [
                'NAME' => 'ИНН',
                'PROPERTY_TYPE' => 'S',
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
                'SORT' => 210,
                'HINT' => 'Строка без числового преобразования',
            ],
            'KPP' => [
                'NAME' => 'КПП',
                'PROPERTY_TYPE' => 'S',
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
                'SORT' => 220,
            ],
            'STATUS' => [
                'NAME' => 'Статус',
                'PROPERTY_TYPE' => 'L',
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'Y',
                'SORT' => 300,
                'VALUES' => [
                    ['XML_ID' => 'ACTIVE', 'VALUE' => 'Активен', 'SORT' => 100, 'DEF' => 'Y'],
                    ['XML_ID' => 'SUSPENDED', 'VALUE' => 'Приостановлен', 'SORT' => 200, 'DEF' => 'N'],
                    ['XML_ID' => 'ARCHIVED', 'VALUE' => 'В архиве', 'SORT' => 300, 'DEF' => 'N'],
                ],
            ],
            'WEBSITE_URL' => [
                'NAME' => 'Сайт поставщика',
                'PROPERTY_TYPE' => 'S',
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
                'SORT' => 400,
            ],
            'SOURCE_LINKS' => [
                'NAME' => 'Ссылки на источники данных',
                'PROPERTY_TYPE' => 'S',
                'MULTIPLE' => 'Y',
                'MULTIPLE_CNT' => 1,
                'WITH_DESCRIPTION' => 'Y',
                'IS_REQUIRED' => 'N',
                'SORT' => 500,
            ],
            'NOTES' => [
                'NAME' => 'Внутренняя закупочная заметка',
                'PROPERTY_TYPE' => 'S',
                'USER_TYPE' => 'HTML',
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
                'SORT' => 600,
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function materialPropertySchema(): array
    {
        return [
            'ENTITY_KEY' => [
                'NAME' => 'Стабильный ключ материала',
                'PROPERTY_TYPE' => 'S',
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
                'SORT' => 515,
                'HINT' => 'Переносимый ключ; заполняется отдельной управляемой миграцией',
            ],
            'SUPPLIERS' => [
                'NAME' => 'Поставщики',
                'PROPERTY_TYPE' => 'E',
                'MULTIPLE' => 'Y',
                'MULTIPLE_CNT' => 1,
                'IS_REQUIRED' => 'N',
                'SORT' => 520,
                'SEARCHABLE' => 'N',
                'FILTRABLE' => 'N',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function analyze(): array
    {
        $state = $this->readState();
        $operations = [];
        $blockers = [];

        foreach (['CALC_MATERIALS', 'CALC_MATERIALS_VARIANTS'] as $code) {
            $target = $state['targets'][$code] ?? null;
            if (!is_array($target)
                || (int)($target['ID'] ?? 0) <= 0
                || (string)($target['CODE'] ?? '') !== $code) {
                $blockers[] = 'Не найден точный владеющий инфоблок ' . $code . '.';
            }
        }

        $supplierRows = is_array($state['supplierCandidates'] ?? null)
            ? array_values($state['supplierCandidates'])
            : [];
        $configuredId = (int)($state['configuredSupplierId'] ?? 0);
        $supplierId = 0;
        $exactRows = array_values(array_filter(
            $supplierRows,
            static fn(array $row): bool => self::isExactSupplierIblock($row)
        ));

        if ($configuredId > 0) {
            $configured = array_values(array_filter(
                $supplierRows,
                static fn(array $row): bool => (int)($row['ID'] ?? 0) === $configuredId
            ));
            if (count($configured) !== 1 || !self::isExactSupplierIblock($configured[0])) {
                $blockers[] = 'Option ' . self::OPTION_NAME . ' указывает не на точный CALC_SUPPLIERS.';
            } elseif (count($supplierRows) !== 1) {
                $blockers[] = 'Обнаружены дубли или частичные совпадения CALC_SUPPLIERS.';
            } else {
                $supplierId = $configuredId;
            }
        } elseif ($supplierRows === []) {
            $operations[] = ['action' => 'create_iblock', 'code' => self::IBLOCK_CODE];
        } elseif (count($supplierRows) === 1 && count($exactRows) === 1) {
            $supplierId = (int)$exactRows[0]['ID'];
            $operations[] = ['action' => 'adopt_iblock', 'id' => $supplierId, 'code' => self::IBLOCK_CODE];
        } else {
            $blockers[] = 'Нельзя однозначно принять существующий CALC_SUPPLIERS по полной stable identity.';
        }

        if ($supplierId > 0) {
            $this->planProperties(
                'CALC_SUPPLIERS',
                $supplierId,
                self::supplierPropertySchema(),
                $state['properties']['CALC_SUPPLIERS'] ?? [],
                $operations,
                $blockers
            );
        } elseif ($supplierRows === []) {
            foreach (array_keys(self::supplierPropertySchema()) as $propertyCode) {
                $operations[] = [
                    'action' => 'create_property',
                    'iblock' => 'CALC_SUPPLIERS',
                    'property' => $propertyCode,
                ];
            }
        }

        foreach (['CALC_MATERIALS', 'CALC_MATERIALS_VARIANTS'] as $code) {
            $targetId = (int)($state['targets'][$code]['ID'] ?? 0);
            if ($targetId <= 0) {
                continue;
            }
            $schema = self::materialPropertySchema();
            if ($supplierId > 0) {
                $schema['SUPPLIERS']['LINK_IBLOCK_ID'] = $supplierId;
            }
            $this->planProperties(
                $code,
                $targetId,
                $schema,
                $state['properties'][$code] ?? [],
                $operations,
                $blockers,
                $supplierId === 0
            );
        }

        if ($supplierId > 0 && $configuredId !== $supplierId) {
            $operations[] = ['action' => 'set_option', 'name' => self::OPTION_NAME, 'value' => $supplierId];
        }

        $basis = [
            'contract' => self::CONTRACT,
            'schemaVersion' => self::SCHEMA_VERSION,
            'state' => $state,
            'operations' => $operations,
            'blockers' => $blockers,
        ];
        $currentHash = self::hashValue($state);
        $planHash = self::hashValue($basis);

        return [
            'contract' => self::CONTRACT,
            'schemaVersion' => self::SCHEMA_VERSION,
            'currentHash' => $currentHash,
            'planHash' => $planHash,
            'supplierIblockId' => $supplierId,
            'operations' => $operations,
            'blockers' => $blockers,
            'counts' => $state['counts'] ?? [],
            'definitions' => [
                'iblock' => self::iblockDefinition(),
                'supplierProperties' => self::supplierPropertySchema(),
                'materialProperties' => self::materialPropertySchema(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function apply(string $expectedPlanHash = '', int $actorId = 0): array
    {
        $initial = $this->analyze();
        if ($expectedPlanHash !== '' && !hash_equals($initial['planHash'], $expectedPlanHash)) {
            throw new \RuntimeException('Supplier schema plan hash is stale.', 409);
        }
        if ($initial['blockers'] !== []) {
            throw new \RuntimeException(implode(' ', $initial['blockers']), 409);
        }

        if (isset($this->adapters['apply'])) {
            $result = call_user_func($this->adapters['apply'], $initial);
            return is_array($result) ? $result : $initial;
        }

        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $lockName = self::MODULE_ID . ':supplier-directory-schema';
        $lockRow = $connection->query(
            "SELECT GET_LOCK('" . $helper->forSql($lockName) . "',10) AS ACQUIRED"
        )->fetch();
        if (!is_array($lockRow) || (int)($lockRow['ACQUIRED'] ?? $lockRow['acquired'] ?? 0) !== 1) {
            throw new \RuntimeException('Supplier schema lock is unavailable.', 409);
        }

        try {
            $lockedPlan = $this->analyze();
            if (!hash_equals($initial['planHash'], $lockedPlan['planHash'])) {
                throw new \RuntimeException('Supplier schema changed before apply.', 409);
            }
            if ($lockedPlan['blockers'] !== []) {
                throw new \RuntimeException(implode(' ', $lockedPlan['blockers']), 409);
            }

            $targetIds = $this->targetIds();
            $supplierId = (int)$lockedPlan['supplierIblockId'];
            if ($supplierId <= 0) {
                $supplierId = $this->createSupplierIblock();
            }

            foreach (self::supplierPropertySchema() as $code => $definition) {
                $this->ensureProperty($supplierId, $code, $definition);
            }
            foreach (['CALC_MATERIALS', 'CALC_MATERIALS_VARIANTS'] as $iblockCode) {
                $definitionSet = self::materialPropertySchema();
                $definitionSet['SUPPLIERS']['LINK_IBLOCK_ID'] = $supplierId;
                foreach ($definitionSet as $code => $definition) {
                    $this->ensureProperty((int)$targetIds[$iblockCode], $code, $definition);
                }
            }

            $this->writeCanonicalRuntimeOption($connection, self::OPTION_NAME, (string)$supplierId);

            $after = $this->analyze();
            if ($after['blockers'] !== [] || $after['operations'] !== []) {
                throw new \RuntimeException('Supplier schema readback has a residual diff.', 409);
            }
            $receipt = [
                'contract' => self::CONTRACT,
                'schemaVersion' => self::SCHEMA_VERSION,
                'actorId' => $actorId,
                'appliedAt' => gmdate('c'),
                'supplierIblockId' => $supplierId,
                'beforePlanHash' => $lockedPlan['planHash'],
                'afterCurrentHash' => $after['currentHash'],
                'operations' => $lockedPlan['operations'],
            ];
            Option::set(
                self::MODULE_ID,
                self::RECEIPT_OPTION_NAME,
                (string)json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $after['receipt'] = $receipt;
            return $after;
        } finally {
            try {
                $connection->query(
                    "SELECT RELEASE_LOCK('" . $helper->forSql($lockName) . "') AS RELEASED"
                );
            } catch (\Throwable $ignored) {
                // The database releases named locks when the connection closes.
            }
        }
    }

    /**
     * Runtime authority is deliberately binary-exact. Bitrix Option::set()
     * lower-cases option names on some installations, so authority-owned
     * options must be persisted with their canonical identity directly.
     * Existing folded rows are upgraded in place only when unambiguous.
     *
     * @param object $connection
     */
    private function writeCanonicalRuntimeOption($connection, string $name, string $value): void
    {
        $helper = $connection->getSqlHelper();
        $moduleSql = $helper->forSql(self::MODULE_ID);
        $nameSql = $helper->forSql($name);
        $foldedModuleSql = $helper->forSql(strtolower(self::MODULE_ID));
        $foldedNameSql = $helper->forSql(strtolower($name));
        $cursor = $connection->query(
            "SELECT MODULE_ID, NAME, VALUE, SITE_ID FROM b_option WHERE "
            . "LOWER(MODULE_ID)='" . $foldedModuleSql . "' AND LOWER(NAME)='" . $foldedNameSql . "' "
            . "AND (SITE_ID IS NULL OR SITE_ID='') FOR UPDATE"
        );
        $rows = [];
        while (($row = $cursor->fetch()) !== false) {
            if (!is_array($row)) {
                throw new \RuntimeException('Supplier runtime option candidate is invalid.', 409);
            }
            $rows[] = $row;
        }
        if (count($rows) > 1) {
            throw new \RuntimeException('Supplier runtime option authority is ambiguous.', 409);
        }

        $valueSql = $helper->forSql($value);
        if ($rows === []) {
            $connection->queryExecute(
                "INSERT INTO b_option (MODULE_ID, NAME, VALUE, SITE_ID) VALUES ('"
                . $moduleSql . "','" . $nameSql . "','" . $valueSql . "',NULL)"
            );
        } else {
            $row = $rows[0];
            $existingModule = (string)($row['MODULE_ID'] ?? $row['module_id'] ?? '');
            $existingName = (string)($row['NAME'] ?? $row['name'] ?? '');
            $connection->queryExecute(
                "UPDATE b_option SET MODULE_ID='" . $moduleSql . "',NAME='" . $nameSql
                . "',VALUE='" . $valueSql . "',SITE_ID=NULL WHERE BINARY MODULE_ID='"
                . $helper->forSql($existingModule) . "' AND BINARY NAME='"
                . $helper->forSql($existingName) . "' AND (SITE_ID IS NULL OR SITE_ID='')"
            );
        }

        $readback = $connection->query(
            "SELECT MODULE_ID, NAME, VALUE, SITE_ID FROM b_option WHERE BINARY MODULE_ID='"
            . $moduleSql . "' AND BINARY NAME='" . $nameSql . "' AND SITE_ID IS NULL"
        );
        $selected = [];
        while (($row = $readback->fetch()) !== false) {
            if (is_array($row)) {
                $selected[] = $row;
            }
        }
        if (count($selected) !== 1 || !hash_equals($value, (string)($selected[0]['VALUE'] ?? ''))) {
            throw new \RuntimeException('Supplier runtime option canonical readback mismatch.', 409);
        }
    }

    /** @return array<string,mixed> */
    private static function iblockDefinition(): array
    {
        return [
            'IBLOCK_TYPE_ID' => self::IBLOCK_TYPE,
            'CODE' => self::IBLOCK_CODE,
            'XML_ID' => self::IBLOCK_XML_ID,
            'NAME' => 'Поставщики материалов',
            'VERSION' => 2,
            'ACTIVE' => 'Y',
        ];
    }

    /** @return array<string,mixed> */
    private function readState(): array
    {
        if (isset($this->adapters['state'])) {
            $state = call_user_func($this->adapters['state']);
            if (!is_array($state)) {
                throw new \RuntimeException('Supplier schema state adapter is invalid.');
            }
            return $state;
        }
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Bitrix iblock module is unavailable.');
        }

        $targets = [];
        foreach ($this->targetIds() as $code => $id) {
            $row = \CIBlock::GetList([], ['ID' => $id, 'CHECK_PERMISSIONS' => 'N'])->Fetch();
            $targets[$code] = is_array($row) ? self::normalizeIblock($row) : null;
        }

        $supplierCandidates = [];
        foreach ([
            ['CODE' => self::IBLOCK_CODE],
            ['XML_ID' => self::IBLOCK_XML_ID],
        ] as $filter) {
            $rs = \CIBlock::GetList(['ID' => 'ASC'], $filter + ['CHECK_PERMISSIONS' => 'N']);
            while ($row = $rs->Fetch()) {
                $normalized = self::normalizeIblock($row);
                $supplierCandidates[(int)$normalized['ID']] = $normalized;
            }
        }
        ksort($supplierCandidates, SORT_NUMERIC);

        $configuredSupplierId = self::positiveId((string)Option::get(
            self::MODULE_ID,
            self::OPTION_NAME,
            ''
        ));
        if ($configuredSupplierId > 0 && !isset($supplierCandidates[$configuredSupplierId])) {
            $row = \CIBlock::GetList([], [
                'ID' => $configuredSupplierId,
                'CHECK_PERMISSIONS' => 'N',
            ])->Fetch();
            if (is_array($row)) {
                $supplierCandidates[$configuredSupplierId] = self::normalizeIblock($row);
                ksort($supplierCandidates, SORT_NUMERIC);
            }
        }

        $properties = [];
        foreach ($targets as $code => $row) {
            $properties[$code] = is_array($row)
                ? $this->readProperties((int)$row['ID'])
                : [];
        }
        $supplierRow = $supplierCandidates[$configuredSupplierId] ?? null;
        if (!is_array($supplierRow)) {
            $exact = array_values(array_filter(
                $supplierCandidates,
                static fn(array $row): bool => self::isExactSupplierIblock($row)
            ));
            $supplierRow = count($exact) === 1 ? $exact[0] : null;
        }
        $properties['CALC_SUPPLIERS'] = is_array($supplierRow)
            ? $this->readProperties((int)$supplierRow['ID'])
            : [];

        $counts = ['suppliers' => 0, 'materialLinks' => 0, 'variantLinks' => 0];
        if (is_array($supplierRow)) {
            $counts['suppliers'] = (int)\CIBlockElement::GetList(
                [],
                ['IBLOCK_ID' => (int)$supplierRow['ID']],
                []
            );
        }
        foreach ([
            'CALC_MATERIALS' => 'materialLinks',
            'CALC_MATERIALS_VARIANTS' => 'variantLinks',
        ] as $code => $countKey) {
            $targetId = (int)($targets[$code]['ID'] ?? 0);
            if ($targetId > 0 && isset($properties[$code]['SUPPLIERS'])) {
                $counts[$countKey] = (int)\CIBlockElement::GetList(
                    [],
                    ['IBLOCK_ID' => $targetId, '!PROPERTY_SUPPLIERS' => false],
                    []
                );
            }
        }

        return [
            'configuredSupplierId' => $configuredSupplierId,
            'targets' => $targets,
            'supplierCandidates' => array_values($supplierCandidates),
            'properties' => $properties,
            'counts' => $counts,
        ];
    }

    /** @return array<string,int> */
    private function targetIds(): array
    {
        $provided = $this->adapters['target_ids'] ?? null;
        $ids = [];
        foreach (['CALC_MATERIALS', 'CALC_MATERIALS_VARIANTS'] as $code) {
            $value = is_array($provided)
                ? ($provided[$code] ?? 0)
                : Option::get(self::MODULE_ID, 'IBLOCK_' . $code, '');
            $ids[$code] = is_int($value) ? $value : self::positiveId((string)$value);
        }
        return $ids;
    }

    /** @return array<string,array<string,mixed>> */
    private function readProperties(int $iblockId): array
    {
        $result = [];
        $rs = \CIBlockProperty::GetList(['ID' => 'ASC'], ['IBLOCK_ID' => $iblockId]);
        while ($row = $rs->Fetch()) {
            $code = (string)($row['CODE'] ?? '');
            if ($code === '') {
                continue;
            }
            if (isset($result[$code])) {
                $result[$code]['_DUPLICATE'] = true;
                continue;
            }
            $normalized = [
                'ID' => (int)$row['ID'],
                'CODE' => $code,
                'NAME' => (string)$row['NAME'],
                'PROPERTY_TYPE' => (string)$row['PROPERTY_TYPE'],
                'USER_TYPE' => (string)($row['USER_TYPE'] ?? ''),
                'MULTIPLE' => (string)$row['MULTIPLE'],
                'IS_REQUIRED' => (string)$row['IS_REQUIRED'],
                'SORT' => (int)$row['SORT'],
                'LINK_IBLOCK_ID' => (int)($row['LINK_IBLOCK_ID'] ?? 0),
                'WITH_DESCRIPTION' => (string)($row['WITH_DESCRIPTION'] ?? 'N'),
                'SEARCHABLE' => (string)($row['SEARCHABLE'] ?? 'N'),
                'FILTRABLE' => (string)($row['FILTRABLE'] ?? 'N'),
            ];
            if ($normalized['PROPERTY_TYPE'] === 'L') {
                $values = [];
                $enum = \CIBlockPropertyEnum::GetList(
                    ['SORT' => 'ASC', 'ID' => 'ASC'],
                    ['PROPERTY_ID' => $normalized['ID']]
                );
                while ($item = $enum->Fetch()) {
                    $values[(string)$item['XML_ID']] = [
                        'VALUE' => (string)$item['VALUE'],
                        'SORT' => (int)$item['SORT'],
                        'DEF' => (string)$item['DEF'],
                    ];
                }
                $normalized['VALUES'] = $values;
            }
            $result[$code] = $normalized;
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /**
     * @param array<string,array<string,mixed>> $schema
     * @param array<string,array<string,mixed>> $current
     * @param array<int,array<string,mixed>> $operations
     * @param array<int,string> $blockers
     */
    private function planProperties(
        string $iblockCode,
        int $iblockId,
        array $schema,
        array $current,
        array &$operations,
        array &$blockers,
        bool $deferSupplierLink = false
    ): void {
        foreach ($schema as $code => $definition) {
            if (!isset($current[$code])) {
                $operations[] = [
                    'action' => 'create_property',
                    'iblock' => $iblockCode,
                    'iblockId' => $iblockId,
                    'property' => $code,
                ];
                continue;
            }
            if ($deferSupplierLink && $code === 'SUPPLIERS') {
                continue;
            }
            $mismatch = self::propertyMismatch($current[$code], $definition);
            if ($mismatch !== []) {
                $blockers[] = $iblockCode . '.' . $code . ' несовместимо: ' . implode(', ', $mismatch) . '.';
            }
        }
    }

    /** @return list<string> */
    private static function propertyMismatch(array $current, array $definition): array
    {
        if (!empty($current['_DUPLICATE'])) {
            return ['duplicate CODE'];
        }
        $fields = [
            'PROPERTY_TYPE' => 'PROPERTY_TYPE',
            'MULTIPLE' => 'MULTIPLE',
            'IS_REQUIRED' => 'IS_REQUIRED',
        ];
        if (array_key_exists('USER_TYPE', $definition)) {
            $fields['USER_TYPE'] = 'USER_TYPE';
        }
        if (array_key_exists('LINK_IBLOCK_ID', $definition)) {
            $fields['LINK_IBLOCK_ID'] = 'LINK_IBLOCK_ID';
        }
        if (array_key_exists('WITH_DESCRIPTION', $definition)) {
            $fields['WITH_DESCRIPTION'] = 'WITH_DESCRIPTION';
        }
        $mismatch = [];
        foreach ($fields as $actualKey => $expectedKey) {
            if ((string)($current[$actualKey] ?? '') !== (string)($definition[$expectedKey] ?? '')) {
                $mismatch[] = $actualKey;
            }
        }
        if (isset($definition['VALUES'])) {
            $currentValues = is_array($current['VALUES'] ?? null) ? $current['VALUES'] : [];
            foreach ($definition['VALUES'] as $value) {
                $xmlId = (string)$value['XML_ID'];
                if (!isset($currentValues[$xmlId])
                    || (string)$currentValues[$xmlId]['VALUE'] !== (string)$value['VALUE']) {
                    $mismatch[] = 'VALUES:' . $xmlId;
                }
            }
        }
        return $mismatch;
    }

    private function createSupplierIblock(): int
    {
        $siteId = (string)\CSite::GetDefSite();
        $iblock = new \CIBlock();
        $id = (int)$iblock->Add([
            'ACTIVE' => 'Y',
            'NAME' => 'Поставщики материалов',
            'CODE' => self::IBLOCK_CODE,
            'XML_ID' => self::IBLOCK_XML_ID,
            'IBLOCK_TYPE_ID' => self::IBLOCK_TYPE,
            'SITE_ID' => [$siteId],
            'SORT' => 550,
            'VERSION' => 2,
            'WORKFLOW' => 'N',
            'BIZPROC' => 'N',
            'INDEX_ELEMENT' => 'N',
            'LIST_PAGE_URL' => '',
            'DETAIL_PAGE_URL' => '',
            'SECTION_PAGE_URL' => '',
            'GROUP_ID' => ['1' => 'X', '2' => 'D'],
            'FIELDS' => [
                'NAME' => ['IS_REQUIRED' => 'Y'],
                'CODE' => [
                    'IS_REQUIRED' => 'Y',
                    'DEFAULT_VALUE' => ['UNIQUE' => 'Y'],
                ],
                'XML_ID' => ['IS_REQUIRED' => 'Y'],
            ],
        ]);
        if ($id <= 0) {
            throw new \RuntimeException('Не удалось создать CALC_SUPPLIERS: ' . self::bitrixError());
        }
        return $id;
    }

    private function ensureProperty(int $iblockId, string $code, array $definition): int
    {
        $existing = [];
        $rs = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $code]);
        while ($row = $rs->Fetch()) {
            $existing[] = $row;
        }
        if (count($existing) > 1) {
            throw new \RuntimeException('Дублирующее свойство ' . $code . '.', 409);
        }
        if (count($existing) === 1) {
            $current = $this->readProperties($iblockId)[$code] ?? [];
            $mismatch = self::propertyMismatch($current, $definition);
            if ($mismatch !== []) {
                throw new \RuntimeException(
                    'Свойство ' . $code . ' несовместимо: ' . implode(', ', $mismatch) . '.',
                    409
                );
            }
            return (int)$existing[0]['ID'];
        }

        $fields = [
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
            'CODE' => $code,
            'NAME' => (string)$definition['NAME'],
            'PROPERTY_TYPE' => (string)$definition['PROPERTY_TYPE'],
            'MULTIPLE' => (string)$definition['MULTIPLE'],
            'IS_REQUIRED' => (string)$definition['IS_REQUIRED'],
            'SORT' => (int)$definition['SORT'],
            'SEARCHABLE' => (string)($definition['SEARCHABLE'] ?? 'N'),
            'FILTRABLE' => (string)($definition['FILTRABLE'] ?? 'N'),
        ];
        foreach ([
            'USER_TYPE', 'MULTIPLE_CNT', 'WITH_DESCRIPTION', 'LINK_IBLOCK_ID',
            'HINT', 'VALUES',
        ] as $field) {
            if (array_key_exists($field, $definition)) {
                $fields[$field] = $definition[$field];
            }
        }
        $property = new \CIBlockProperty();
        $id = (int)$property->Add($fields);
        if ($id <= 0) {
            throw new \RuntimeException('Не удалось создать свойство ' . $code . ': ' . self::bitrixError());
        }
        return $id;
    }

    /** @return array<string,mixed> */
    private static function normalizeIblock(array $row): array
    {
        return [
            'ID' => (int)($row['ID'] ?? 0),
            'IBLOCK_TYPE_ID' => (string)($row['IBLOCK_TYPE_ID'] ?? ''),
            'CODE' => (string)($row['CODE'] ?? ''),
            'XML_ID' => (string)($row['XML_ID'] ?? ''),
            'NAME' => (string)($row['NAME'] ?? ''),
            'VERSION' => (int)($row['VERSION'] ?? 0),
            'ACTIVE' => (string)($row['ACTIVE'] ?? ''),
        ];
    }

    private static function isExactSupplierIblock(array $row): bool
    {
        return (string)($row['IBLOCK_TYPE_ID'] ?? '') === self::IBLOCK_TYPE
            && (string)($row['CODE'] ?? '') === self::IBLOCK_CODE
            && (string)($row['XML_ID'] ?? '') === self::IBLOCK_XML_ID;
    }

    private static function positiveId(string $value): int
    {
        return preg_match('/^[1-9][0-9]*$/D', $value) === 1 ? (int)$value : 0;
    }

    private static function bitrixError(): string
    {
        global $APPLICATION;
        $exception = is_object($APPLICATION) ? $APPLICATION->GetException() : null;
        return is_object($exception) ? (string)$exception->GetString() : 'неизвестная ошибка Bitrix';
    }

    /** @param mixed $value */
    private static function hashValue($value): string
    {
        $normalized = self::sortRecursive($value);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('Supplier schema hash serialization failed.');
        }
        return hash('sha256', $json);
    }

    /** @param mixed $value @return mixed */
    private static function sortRecursive($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'sortRecursive'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::sortRecursive($item);
        }
        return $value;
    }
}
