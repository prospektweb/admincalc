<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

/**
 * Exact, versioned authority for calculator/catalog runtime selectors.
 *
 * Calculator-owned sources live only in prospektweb.calc. Catalog product and
 * offer IDs live only in the complete FrontCalc settings aggregate. No cached
 * Bitrix Option read and no legacy mirror participates in this contract.
 */
final class CatalogRuntimeConfigAuthorityService
{
    public const CONTRACT = 'prospektweb.calc.catalog-runtime-config/v1';

    private const ADMIN_MODULE_ID = 'prospektweb.calc';
    private const FRONT_MODULE_ID = 'prospektweb.frontcalc';

    private const CONTRACT_KEY = 'contract';
    private const FRONT_CONTRACT_KEY = 'frontSettingsContract';
    private const FRONT_REVISION_KEY = 'frontSettingsRevision';
    private const FRONT_FINGERPRINT_KEY = 'frontSettingsFingerprint';

    /** @var list<string> */
    private const ADMIN_OPTION_NAMES = [
        'CALC_SERVER_URL',
        'IBLOCK_CALC_PRESETS',
        'IBLOCK_CALC_STAGES',
        'IBLOCK_CALC_SETTINGS',
        'IBLOCK_CALC_GLOBAL_VALUES',
        'IBLOCK_CALC_CUSTOM_FIELDS',
        'IBLOCK_CALC_MATERIALS',
        'IBLOCK_CALC_MATERIALS_VARIANTS',
        'IBLOCK_CALC_OPERATIONS',
        'IBLOCK_CALC_OPERATIONS_VARIANTS',
        'IBLOCK_CALC_EQUIPMENT',
        'IBLOCK_CALC_DETAILS',
    ];

    /** @var list<string> */
    private const LEGACY_ADMIN_CATALOG_OPTION_NAMES = [
        'PRODUCT_IBLOCK_ID',
        'SKU_IBLOCK_ID',
    ];

    /** @var list<string> */
    private const CALCULATOR_IBLOCK_CODES = [
        'CALC_PRESETS',
        'CALC_STAGES',
        'CALC_SETTINGS',
        'CALC_GLOBAL_VALUES',
        'CALC_CUSTOM_FIELDS',
        'CALC_MATERIALS',
        'CALC_MATERIALS_VARIANTS',
        'CALC_OPERATIONS',
        'CALC_OPERATIONS_VARIANTS',
        'CALC_EQUIPMENT',
        'CALC_DETAILS',
    ];

    /** @var array<string,callable> */
    private array $adapters;

    /** @param array<string,callable> $adapters */
    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    /** Source-derived deployment/cutover contract. @return list<string> */
    public static function canonicalAdminOptionNames(): array
    {
        return self::ADMIN_OPTION_NAMES;
    }

    /** Source-derived one-time deletion contract. @return list<string> */
    public static function legacyAdminCatalogOptionNames(): array
    {
        return self::LEGACY_ADMIN_CATALOG_OPTION_NAMES;
    }

    /** Front mirrors of calculator-owned sources are forbidden after cutover. @return list<string> */
    public static function legacyFrontCalculatorOptionNames(): array
    {
        return array_values(array_map(
            static fn(string $code): string => 'IBLOCK_' . $code,
            self::CALCULATOR_IBLOCK_CODES
        ));
    }

    /**
     * Bootstrap the exact Admin aggregate before the module row is registered.
     * Existing values are accepted only when the complete binary-exact state
     * already equals the requested install state.
     *
     * @param array<string,string|int> $options
     * @return array<string,string>
     */
    public function initializeAdminOptionsForInstall(array $options): array
    {
        if (isset($this->adapters['initialize_admin'])) {
            $state = call_user_func($this->adapters['initialize_admin'], $options);
            return self::normalizeAdminInstallOptions(is_array($state) ? $state : []);
        }
        if (!class_exists(Application::class)) {
            throw new \RuntimeException('Bitrix database is unavailable for runtime config installation.', 409);
        }
        $normalized = self::normalizeAdminInstallOptions($options);
        $connection = Application::getConnection();
        if ($this->transactionActive($connection)) {
            throw new \RuntimeException('Runtime config installation requires its own transaction.', 409);
        }
        $helper = $connection->getSqlHelper();
        $lockName = self::ADMIN_MODULE_ID . ':runtime-config-install';
        $lockRow = $connection->query(
            "SELECT GET_LOCK('" . $helper->forSql($lockName) . "',5) AS ACQUIRED"
        )->fetch();
        if (!is_array($lockRow) || (int)($lockRow['ACQUIRED'] ?? $lockRow['acquired'] ?? 0) !== 1) {
            throw new \RuntimeException('Runtime config installation lock is unavailable.', 409);
        }
        try {
            $connection->startTransaction();
            $rows = $this->readCandidateRows($connection, [], true);
            $existing = self::selectExactAdminOptions($rows, false);
            foreach (self::ADMIN_OPTION_NAMES as $name) {
                if (array_key_exists($name, $existing)) {
                    if (!hash_equals($existing[$name], $normalized[$name])) {
                        throw new \RuntimeException(
                            'Existing Admin runtime option differs from install state: ' . $name . '.',
                            409
                        );
                    }
                    continue;
                }
                $connection->queryExecute(
                    "INSERT INTO b_option (MODULE_ID, NAME, VALUE, SITE_ID) VALUES ('"
                    . $helper->forSql(self::ADMIN_MODULE_ID) . "','"
                    . $helper->forSql($name) . "','"
                    . $helper->forSql($normalized[$name]) . "',NULL)"
                );
            }
            $after = self::selectExactAdminOptions(
                $this->readCandidateRows($connection, [], true),
                true
            );
            if ($after !== $normalized) {
                throw new \RuntimeException('Admin runtime option install readback mismatch.', 409);
            }
            if ($existing !== $after) {
                $this->writeInstallAudit($existing, $after);
            }
            $connection->commitTransaction();
            return $after;
        } catch (\Throwable $error) {
            try {
                $connection->rollbackTransaction();
            } catch (\Throwable $ignored) {
                // Preserve the exact install failure.
            }
            throw $error;
        } finally {
            try {
                $connection->query(
                    "SELECT RELEASE_LOCK('" . $helper->forSql($lockName) . "') AS RELEASED"
                );
            } catch (\Throwable $ignored) {
                // Connection teardown releases a retained named lock.
            }
        }
    }

    /** @return array<string,string> */
    public function captureCalculatorSnapshot($connection = null, bool $forUpdate = false): array
    {
        if (isset($this->adapters['capture_calculator'])) {
            $snapshot = call_user_func($this->adapters['capture_calculator'], $forUpdate);
            return self::normalizeCalculatorSnapshot(is_array($snapshot) ? $snapshot : []);
        }
        return $this->captureProduction(false, $connection, $forUpdate);
    }

    /** @return array<string,string> */
    public function captureCatalogSnapshot($connection = null, bool $forUpdate = false): array
    {
        if (isset($this->adapters['capture_catalog'])) {
            $snapshot = call_user_func($this->adapters['capture_catalog'], $forUpdate);
            return self::normalizeCatalogSnapshot(is_array($snapshot) ? $snapshot : []);
        }
        return $this->captureProduction(true, $connection, $forUpdate);
    }

    /** @param array<string,mixed> $snapshot @return array<string,string> */
    public static function normalizeCalculatorSnapshot(array $snapshot): array
    {
        return self::normalizeSnapshot($snapshot, false);
    }

    /** @param array<string,mixed> $snapshot @return array<string,string> */
    public static function normalizeCatalogSnapshot(array $snapshot): array
    {
        return self::normalizeSnapshot($snapshot, true);
    }

    /** @param array<string,mixed> $snapshot @return array<string,int> */
    public static function runtimeIblockMap(array $snapshot): array
    {
        $catalog = array_key_exists(self::frontOptionKey('PRODUCTS_IBLOCK_ID'), $snapshot)
            || array_key_exists(self::frontOptionKey('OFFERS_IBLOCK_ID'), $snapshot);
        $snapshot = $catalog
            ? self::normalizeCatalogSnapshot($snapshot)
            : self::normalizeCalculatorSnapshot($snapshot);

        $result = [];
        if ($catalog) {
            $result['PRODUCTS'] = self::canonicalPositiveId(
                $snapshot[self::frontOptionKey('PRODUCTS_IBLOCK_ID')],
                'PRODUCTS_IBLOCK_ID'
            );
            $result['OFFERS'] = self::canonicalPositiveId(
                $snapshot[self::frontOptionKey('OFFERS_IBLOCK_ID')],
                'OFFERS_IBLOCK_ID'
            );
        }
        foreach (self::CALCULATOR_IBLOCK_CODES as $code) {
            $result[$code] = self::canonicalPositiveId(
                $snapshot[self::adminOptionKey('IBLOCK_' . $code)],
                'IBLOCK_' . $code
            );
        }
        return $result;
    }

    /** @param array<string,mixed> $snapshot */
    public static function runtimeIblockId(array $snapshot, string $code): int
    {
        $map = self::runtimeIblockMap($snapshot);
        if (!array_key_exists($code, $map)) {
            throw new \RuntimeException('Runtime source is not part of this authority snapshot: ' . $code . '.', 409);
        }
        return $map[$code];
    }

    /** @param array<string,mixed> $snapshot */
    public static function adminOptionValue(array $snapshot, string $name): string
    {
        if (!in_array($name, self::ADMIN_OPTION_NAMES, true)) {
            throw new \InvalidArgumentException('Unsupported Admin runtime option: ' . $name . '.');
        }
        $catalog = array_key_exists(self::FRONT_CONTRACT_KEY, $snapshot);
        $normalized = $catalog
            ? self::normalizeCatalogSnapshot($snapshot)
            : self::normalizeCalculatorSnapshot($snapshot);
        return $normalized[self::adminOptionKey($name)];
    }

    public function resolveCalculatorIblockId(string $code, string $expectedType): int
    {
        $resolved = $this->resolveCalculatorIblockIds([$code => $expectedType]);
        return $resolved[$code];
    }

    /** @param array<string,string> $targets @return array<string,int> */
    public function resolveCalculatorIblockIds(array $targets): array
    {
        if ($targets === []) {
            throw new \InvalidArgumentException('Calculator iblock authority targets are empty.');
        }
        foreach ($targets as $code => $expectedType) {
            if (!is_string($code)
                || !is_string($expectedType)
                || !in_array($code, self::CALCULATOR_IBLOCK_CODES, true)
                || preg_match('/^[a-z0-9_]+$/D', $expectedType) !== 1) {
                throw new \InvalidArgumentException('Unsupported calculator iblock authority target.');
            }
        }
        if (isset($this->adapters['resolve_calculator_iblock'])) {
            $resolved = [];
            foreach ($targets as $code => $expectedType) {
                $id = call_user_func($this->adapters['resolve_calculator_iblock'], $code, $expectedType);
                if (!is_int($id) || $id <= 0) {
                    throw new \RuntimeException('Calculator iblock adapter returned an invalid target.', 409);
                }
                $resolved[$code] = $id;
            }
            return $resolved;
        }
        if (!class_exists(Application::class)) {
            throw new \RuntimeException('Bitrix database is unavailable for calculator iblock authority.', 409);
        }
        $connection = Application::getConnection();
        $ownsTransaction = !$this->transactionActive($connection);
        if ($ownsTransaction) {
            $connection->startTransaction();
        }
        try {
            $snapshot = $this->captureCalculatorSnapshot($connection, false);
            $resolved = [];
            foreach ($targets as $code => $expectedType) {
                $configuredId = self::runtimeIblockId($snapshot, $code);
                $rows = $this->readIblockCandidates($connection, $configuredId, $code);
                if (count($rows) !== 1) {
                    throw new \RuntimeException('Calculator iblock authority is missing or ambiguous: ' . $code . '.', 409);
                }
                $row = $rows[0];
                if ((int)($row['ID'] ?? $row['id'] ?? 0) !== $configuredId
                    || (string)($row['CODE'] ?? $row['code'] ?? '') !== $code
                    || (string)($row['IBLOCK_TYPE_ID'] ?? $row['iblock_type_id'] ?? '') !== $expectedType) {
                    throw new \RuntimeException(
                        'Calculator iblock authority does not match the exact target: ' . $code . '.',
                        409
                    );
                }
                $resolved[$code] = $configuredId;
            }
            if ($ownsTransaction) {
                $connection->commitTransaction();
            }
            return $resolved;
        } catch (\Throwable $error) {
            if ($ownsTransaction) {
                try {
                    $connection->rollbackTransaction();
                } catch (\Throwable $ignored) {
                    // Preserve the exact authority failure.
                }
            }
            throw $error;
        }
    }

    /** @return array<string,string> */
    private function captureProduction(bool $includeCatalog, $connection, bool $forUpdate): array
    {
        if ($connection === null) {
            if (!class_exists(Application::class)) {
                throw new \RuntimeException('Bitrix database is unavailable for runtime config authority.', 409);
            }
            $connection = Application::getConnection();
        }
        if (!is_object($connection)) {
            throw new \RuntimeException('Runtime config database connection is invalid.', 409);
        }

        $ownsTransaction = !$this->transactionActive($connection);
        if ($forUpdate && $ownsTransaction) {
            throw new \RuntimeException('Locked runtime config read requires an outer transaction.', 409);
        }
        if ($ownsTransaction) {
            $connection->startTransaction();
        }
        try {
            $frontNames = [];
            if ($includeCatalog) {
                $this->ensureFrontSettingsAuthority();
                $frontNames = array_merge(
                    \Prospektweb\Frontcalc\Service\FrontcalcSettingsAuthority::canonicalSettingOptionNames(),
                    [\Prospektweb\Frontcalc\Service\FrontcalcSettingsAuthority::revisionOptionName()]
                );
            }
            if ($forUpdate) {
                $this->lockModuleAuthorities($connection, $includeCatalog);
            }
            $rows = $this->readCandidateRows($connection, $frontNames, $forUpdate);
            $adminRows = [];
            $frontRows = [];
            foreach ($rows as $row) {
                $moduleId = strtolower((string)($row['MODULE_ID'] ?? $row['module_id'] ?? ''));
                if ($moduleId === self::ADMIN_MODULE_ID) {
                    $adminRows[] = $row;
                } elseif ($includeCatalog && $moduleId === self::FRONT_MODULE_ID) {
                    $frontRows[] = $row;
                } else {
                    throw new \RuntimeException('Unexpected runtime config option module candidate.', 409);
                }
            }

            $admin = self::selectExactAdminOptions($adminRows);
            $snapshot = [self::CONTRACT_KEY => self::CONTRACT];
            foreach (self::ADMIN_OPTION_NAMES as $name) {
                $snapshot[self::adminOptionKey($name)] = $admin[$name];
            }

            if ($includeCatalog) {
                $frontState = $this->frontSettingsStateFromRows($frontRows);
                $snapshot[self::FRONT_CONTRACT_KEY] = (string)$frontState['contract'];
                $snapshot[self::FRONT_REVISION_KEY] = (string)$frontState['revision'];
                $snapshot[self::FRONT_FINGERPRINT_KEY] = (string)$frontState['fingerprint'];
                $snapshot[self::frontOptionKey('PRODUCTS_IBLOCK_ID')] =
                    (string)$frontState['settings']['PRODUCTS_IBLOCK_ID'];
                $snapshot[self::frontOptionKey('OFFERS_IBLOCK_ID')] =
                    (string)$frontState['settings']['OFFERS_IBLOCK_ID'];
            }

            $snapshot = $includeCatalog
                ? self::normalizeCatalogSnapshot($snapshot)
                : self::normalizeCalculatorSnapshot($snapshot);
            if ($ownsTransaction) {
                $connection->commitTransaction();
            }
            return $snapshot;
        } catch (\Throwable $error) {
            if ($ownsTransaction) {
                try {
                    $connection->rollbackTransaction();
                } catch (\Throwable $ignored) {
                    // Preserve the exact authority failure.
                }
            }
            throw $error;
        }
    }

    /** @param list<string> $frontNames @return array<int,array<string,mixed>> */
    private function readCandidateRows($connection, array $frontNames, bool $forUpdate): array
    {
        $helper = $connection->getSqlHelper();
        $conditions = [
            "(LOWER(MODULE_ID)='" . $helper->forSql(self::ADMIN_MODULE_ID)
                . "' AND LOWER(NAME) IN (" . self::quotedFoldedNames($helper, self::ADMIN_OPTION_NAMES) . '))',
        ];
        if ($frontNames !== []) {
            $conditions[] = "(LOWER(MODULE_ID)='" . $helper->forSql(self::FRONT_MODULE_ID)
                . "' AND LOWER(NAME) IN (" . self::quotedFoldedNames($helper, $frontNames) . '))';
        }
        $cursor = $connection->query(
            'SELECT MODULE_ID, NAME, VALUE, SITE_ID FROM b_option WHERE '
            . implode(' OR ', $conditions)
            . ' ORDER BY BINARY MODULE_ID, BINARY NAME, SITE_ID'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        if (!is_object($cursor) || !method_exists($cursor, 'fetch')) {
            throw new \RuntimeException('Runtime config candidate read is unavailable.', 409);
        }
        $rows = [];
        while (($row = $cursor->fetch()) !== false) {
            if (!is_array($row)) {
                throw new \RuntimeException('Runtime config candidate row is invalid.', 409);
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<string,string> */
    public static function selectExactAdminOptions(array $rows, bool $requireComplete = true): array
    {
        $canonicalByFold = [];
        foreach (self::ADMIN_OPTION_NAMES as $name) {
            $canonicalByFold[strtolower($name)] = $name;
        }
        $selected = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('Admin runtime option candidate row is invalid.', 409);
            }
            $moduleId = $row['MODULE_ID'] ?? $row['module_id'] ?? null;
            $name = $row['NAME'] ?? $row['name'] ?? null;
            if (array_key_exists('SITE_ID', $row)) {
                $siteId = $row['SITE_ID'];
            } elseif (array_key_exists('site_id', $row)) {
                $siteId = $row['site_id'];
            } else {
                throw new \RuntimeException('Admin runtime option SITE_ID is missing.', 409);
            }
            $value = $row['VALUE'] ?? $row['value'] ?? null;
            if (!is_string($moduleId) || !is_string($name)) {
                throw new \RuntimeException('Admin runtime option candidate identity is invalid.', 409);
            }
            $fold = strtolower($name);
            $canonical = $canonicalByFold[$fold] ?? null;
            if ($canonical === null) {
                continue;
            }
            if (!hash_equals(self::ADMIN_MODULE_ID, $moduleId)
                || !hash_equals($canonical, $name)
                || $siteId !== null
                || !is_string($value)
                || array_key_exists($canonical, $selected)) {
                throw new \RuntimeException('Admin runtime option authority is ambiguous.', 409);
            }
            $selected[$canonical] = $value;
        }
        if ($requireComplete && array_keys($selected) !== self::ADMIN_OPTION_NAMES) {
            $ordered = [];
            foreach (self::ADMIN_OPTION_NAMES as $name) {
                if (!array_key_exists($name, $selected)) {
                    throw new \RuntimeException('Admin runtime option authority is incomplete: ' . $name . '.', 409);
                }
                $ordered[$name] = $selected[$name];
            }
            return $ordered;
        }
        $ordered = [];
        foreach (self::ADMIN_OPTION_NAMES as $name) {
            if (array_key_exists($name, $selected)) {
                $ordered[$name] = $selected[$name];
            }
        }
        return $ordered;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<string,mixed> */
    private function frontSettingsStateFromRows(array $rows): array
    {
        $canonicalNames = \Prospektweb\Frontcalc\Service\FrontcalcSettingsAuthority::canonicalSettingOptionNames();
        $revisionName = \Prospektweb\Frontcalc\Service\FrontcalcSettingsAuthority::revisionOptionName();
        $selected = \Prospektweb\Frontcalc\Service\FrontcalcSettingsAuthority::selectExactOptionRows(
            $rows,
            array_merge($canonicalNames, [$revisionName])
        );
        if (count($selected) !== count($canonicalNames) + 1) {
            throw new \RuntimeException('FrontCalc settings aggregate is incomplete.', 409);
        }
        $revision = self::decodeFrontRevision((string)($selected[$revisionName] ?? ''));
        if ($revision <= 0) {
            throw new \RuntimeException('FrontCalc settings aggregate is not activated.', 409);
        }
        unset($selected[$revisionName]);
        $settings = [];
        foreach ($canonicalNames as $name) {
            if (!array_key_exists($name, $selected)) {
                throw new \RuntimeException('FrontCalc settings aggregate is incomplete: ' . $name . '.', 409);
            }
            $settings[$name] = $selected[$name];
        }
        $authority = new \Prospektweb\Frontcalc\Service\FrontcalcSettingsAuthority([
            'read_state' => static fn(bool $forUpdate): array => [
                'revision' => $revision,
                'settings' => $settings,
            ],
        ]);
        $state = $authority->read();
        if (!is_array($state)
            || ($state['contract'] ?? null) !== \Prospektweb\Frontcalc\Service\FrontcalcSettingsAuthority::CONTRACT
            || (int)($state['revision'] ?? 0) !== $revision
            || preg_match('/^[a-f0-9]{64}$/D', (string)($state['fingerprint'] ?? '')) !== 1
            || !is_array($state['settings'] ?? null)) {
            throw new \RuntimeException('FrontCalc settings aggregate readback is invalid.', 409);
        }
        return $state;
    }

    private function ensureFrontSettingsAuthority(): void
    {
        if (!class_exists(Loader::class)
            || !Loader::includeModule(self::FRONT_MODULE_ID)
            || !class_exists(\Prospektweb\Frontcalc\Service\FrontcalcSettingsAuthority::class)) {
            throw new \RuntimeException('FrontCalc settings aggregate authority is unavailable.', 409);
        }
    }

    /** @param array<string,mixed> $options @return array<string,string> */
    private static function normalizeAdminInstallOptions(array $options): array
    {
        $actualNames = array_map('strval', array_keys($options));
        if ($actualNames !== self::ADMIN_OPTION_NAMES) {
            throw new \InvalidArgumentException('Admin runtime install options do not match the exact contract.');
        }
        $snapshot = [self::CONTRACT_KEY => self::CONTRACT];
        $normalized = [];
        foreach (self::ADMIN_OPTION_NAMES as $name) {
            $value = $options[$name];
            if (!is_string($value) && !is_int($value)) {
                throw new \InvalidArgumentException('Admin runtime install option is not scalar: ' . $name . '.');
            }
            $normalized[$name] = (string)$value;
            $snapshot[self::adminOptionKey($name)] = (string)$value;
        }
        self::normalizeCalculatorSnapshot($snapshot);
        return $normalized;
    }

    /** @param array<string,string> $before @param array<string,string> $after */
    private function writeInstallAudit(array $before, array $after): void
    {
        if (!class_exists('CEventLog')) {
            throw new \RuntimeException('Bitrix audit is unavailable for runtime config installation.', 409);
        }
        $payload = [
            'contract' => self::CONTRACT,
            'action' => 'initialize_admin_runtime_config',
            'beforeFingerprint' => self::fingerprint($before),
            'afterFingerprint' => self::fingerprint($after),
            'result' => 'success',
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || \CEventLog::Add([
            'SEVERITY' => 'SECURITY',
            'AUDIT_TYPE_ID' => 'PROSPEKTWEB_CALC_RUNTIME_CONFIG_INSTALL',
            'MODULE_ID' => self::ADMIN_MODULE_ID,
            'ITEM_ID' => 'runtime-config',
            'DESCRIPTION' => $encoded,
        ]) === false) {
            throw new \RuntimeException('Runtime config install audit failed.', 409);
        }
    }

    /** @param array<string,string> $value */
    private static function fingerprint(array $value): string
    {
        ksort($value, SORT_STRING);
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Runtime config fingerprint failed.', 409);
        }
        return hash('sha256', $encoded);
    }

    /** @param array<string,mixed> $snapshot @return array<string,string> */
    private static function normalizeSnapshot(array $snapshot, bool $catalog): array
    {
        $expected = [self::CONTRACT_KEY];
        foreach (self::ADMIN_OPTION_NAMES as $name) {
            $expected[] = self::adminOptionKey($name);
        }
        if ($catalog) {
            array_push(
                $expected,
                self::FRONT_CONTRACT_KEY,
                self::FRONT_REVISION_KEY,
                self::FRONT_FINGERPRINT_KEY,
                self::frontOptionKey('PRODUCTS_IBLOCK_ID'),
                self::frontOptionKey('OFFERS_IBLOCK_ID')
            );
        }
        $actual = array_map('strval', array_keys($snapshot));
        $expectedSorted = $expected;
        sort($expectedSorted, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($actual !== $expectedSorted) {
            throw new \RuntimeException('Runtime config snapshot does not match the exact contract.', 409);
        }
        $normalized = [];
        foreach ($expected as $key) {
            if (!is_string($snapshot[$key] ?? null)) {
                throw new \RuntimeException('Runtime config snapshot contains a non-string value.', 409);
            }
            $normalized[$key] = $snapshot[$key];
        }
        if (!hash_equals(self::CONTRACT, $normalized[self::CONTRACT_KEY])) {
            throw new \RuntimeException('Runtime config snapshot contract is incompatible.', 409);
        }
        foreach (self::CALCULATOR_IBLOCK_CODES as $code) {
            self::canonicalPositiveId(
                $normalized[self::adminOptionKey('IBLOCK_' . $code)],
                'IBLOCK_' . $code
            );
        }
        if ($catalog) {
            if (!hash_equals(
                \Prospektweb\Frontcalc\Service\FrontcalcSettingsAuthority::CONTRACT,
                $normalized[self::FRONT_CONTRACT_KEY]
            ) || preg_match('/^[1-9][0-9]*$/D', $normalized[self::FRONT_REVISION_KEY]) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $normalized[self::FRONT_FINGERPRINT_KEY]) !== 1) {
                throw new \RuntimeException('FrontCalc settings provenance is invalid.', 409);
            }
            self::canonicalPositiveId(
                $normalized[self::frontOptionKey('PRODUCTS_IBLOCK_ID')],
                'PRODUCTS_IBLOCK_ID'
            );
            self::canonicalPositiveId(
                $normalized[self::frontOptionKey('OFFERS_IBLOCK_ID')],
                'OFFERS_IBLOCK_ID'
            );
        }
        return $normalized;
    }

    private static function decodeFrontRevision(string $raw): int
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)
            || array_keys($decoded) !== ['contract', 'revision']
            || ($decoded['contract'] ?? null) !== \Prospektweb\Frontcalc\Service\FrontcalcSettingsAuthority::CONTRACT
            || !is_int($decoded['revision'] ?? null)
            || (int)$decoded['revision'] <= 0
            || (int)$decoded['revision'] > 9007199254740991) {
            throw new \RuntimeException('FrontCalc settings revision is invalid.', 409);
        }
        return (int)$decoded['revision'];
    }

    private static function canonicalPositiveId(string $raw, string $label): int
    {
        if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1 || (string)(int)$raw !== $raw) {
            throw new \RuntimeException($label . ' authority is invalid.', 409);
        }
        return (int)$raw;
    }

    private static function adminOptionKey(string $name): string
    {
        return self::ADMIN_MODULE_ID . ':' . $name;
    }

    private static function frontOptionKey(string $name): string
    {
        return self::FRONT_MODULE_ID . ':' . $name;
    }

    /** @param object $helper @param list<string> $names */
    private static function quotedFoldedNames($helper, array $names): string
    {
        $quoted = array_map(
            static fn(string $name): string => "'" . $helper->forSql(strtolower($name)) . "'",
            array_values(array_unique($names))
        );
        return implode(',', $quoted);
    }

    private function transactionActive($connection): bool
    {
        $row = $connection->query('SELECT @@session.in_transaction AS ACTIVE')->fetch();
        return is_array($row) && (int)($row['ACTIVE'] ?? $row['active'] ?? 0) === 1;
    }

    private function lockModuleAuthorities($connection, bool $includeCatalog): void
    {
        $helper = $connection->getSqlHelper();
        $moduleIds = [self::ADMIN_MODULE_ID];
        if ($includeCatalog) {
            $moduleIds[] = self::FRONT_MODULE_ID;
        }
        sort($moduleIds, SORT_STRING);
        $quoted = array_map(
            static fn(string $moduleId): string => "'" . $helper->forSql($moduleId) . "'",
            $moduleIds
        );
        $cursor = $connection->query(
            'SELECT ID FROM b_module WHERE ID IN (' . implode(',', $quoted) . ') ORDER BY BINARY ID FOR UPDATE'
        );
        $resolved = [];
        while (is_object($cursor) && method_exists($cursor, 'fetch') && ($row = $cursor->fetch())) {
            if (is_array($row)) {
                $resolved[] = (string)($row['ID'] ?? $row['id'] ?? '');
            }
        }
        if ($resolved !== $moduleIds) {
            throw new \RuntimeException('Runtime config module authority is incomplete.', 409);
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function readIblockCandidates($connection, int $configuredId, string $code): array
    {
        $helper = $connection->getSqlHelper();
        $cursor = $connection->query(
            'SELECT ID, CODE, IBLOCK_TYPE_ID FROM b_iblock WHERE ID=' . $configuredId
            . " OR LOWER(CODE)='" . $helper->forSql(strtolower($code)) . "'"
            . ' ORDER BY ID LIMIT 3'
        );
        if (!is_object($cursor) || !method_exists($cursor, 'fetch')) {
            throw new \RuntimeException('Calculator iblock readback is unavailable.', 409);
        }
        $rows = [];
        while (($row = $cursor->fetch()) !== false) {
            if (!is_array($row)) {
                throw new \RuntimeException('Calculator iblock readback is invalid.', 409);
            }
            $rows[] = $row;
        }
        return $rows;
    }
}
