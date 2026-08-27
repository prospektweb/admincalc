<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Application;

/**
 * Preset-owned allowlist for projecting calculator results into catalog fields.
 *
 * Input prefill and product eligibility deliberately do not belong here. The
 * document only controls writeback sinks and carries its own integer CAS.
 */
final class CatalogOutputMappingService
{
    public const CONTRACT = 'prospektweb.calc.catalog-output-mapping/v1';

    private const MODULE_ID = 'prospektweb.calc';
    private const OPTION_PREFIX = 'CATALOG_OUTPUT_MAPPING_';
    private const MAX_DOCUMENT_BYTES = 32768;
    private const MAX_SAFE_INTEGER = 9007199254740991;
    private const LOCK_TIMEOUT_SECONDS = 5.0;

    /** @var array<string,string> */
    private const PAIRS = [
        'result.purchasePrice' => 'catalog.offer.purchasingPrice',
        'result.priceTypes' => 'catalog.offer.priceTypes',
        'result.dimensions.weight' => 'catalog.offer.weight',
        'result.dimensions.length' => 'catalog.offer.length',
        'result.dimensions.width' => 'catalog.offer.width',
        'result.dimensions.height' => 'catalog.offer.height',
        'runtime.provenance' => 'catalog.offer.provenance',
    ];

    /** @var array<string,callable> */
    private array $adapters;

    public function __construct(array $adapters = [])
    {
        $hasGetOption = isset($adapters['get_option']);
        $hasSetOption = isset($adapters['set_option']);
        if ($hasGetOption !== $hasSetOption) {
            throw new \InvalidArgumentException('Output mapping option adapters must be provided as a get/set pair.');
        }
        $this->adapters = $adapters;
    }

    /** @return array<string,mixed> */
    public function load(int $presetId): array
    {
        $this->assertPresetId($presetId);
        return $this->loadFromRaw($presetId, $this->getRaw($presetId));
    }

    /** @return array<string,mixed> */
    public function loadFromRaw(int $presetId, string $raw): array
    {
        $this->assertPresetId($presetId);
        if ($raw === '') {
            return $this->defaultDocument($presetId);
        }
        if (strlen($raw) > self::MAX_DOCUMENT_BYTES) {
            throw new \RuntimeException('Хранилище сопоставлений записи превышает допустимый размер.');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $this->isList($decoded)) {
            throw new \RuntimeException('Хранилище сопоставлений записи повреждено.');
        }
        try {
            return $this->normalizeDefinition($presetId, $decoded);
        } catch (\InvalidArgumentException $error) {
            throw new \RuntimeException('Хранилище сопоставлений записи повреждено: ' . $error->getMessage(), 0, $error);
        }
    }

    /** @param array<string,mixed> $definition @return array<string,mixed> */
    public function validate(int $presetId, array $definition): array
    {
        $mapping = $this->normalizeDefinition($presetId, $definition);
        return [
            'contract' => self::CONTRACT,
            'preset_id' => $presetId,
            'valid' => true,
            'mapping' => $mapping,
            'issues' => [],
        ];
    }

    /** @param array<string,mixed> $definition @return array<string,mixed> */
    public function save(int $presetId, int $expectedRevision, array $definition): array
    {
        $this->assertPresetId($presetId);
        $this->assertRevision($expectedRevision);

        if (!isset($this->adapters['get_option'])) {
            return (new PresetMutationCoordinatorService())->mutate(
                $presetId,
                [
                    'action' => 'catalog_output_mapping_save',
                    'entity_type' => 'catalog_output_mapping',
                    'entity_id' => (string)$presetId,
                    'expected_revision' => $expectedRevision,
                    'product_ids' => [],
                ],
                function () use ($presetId, $expectedRevision, $definition): array {
                    return $this->withMutationLock(
                        $presetId,
                        function () use ($presetId, $expectedRevision, $definition): array {
                            return $this->saveDirectUnderCoordinatorTransaction(
                                $presetId,
                                $expectedRevision,
                                $definition
                            );
                        }
                    );
                },
                function () use ($presetId): array {
                    return $this->load($presetId);
                }
            );
        }

        return $this->withMutationLock($presetId, function () use ($presetId, $expectedRevision, $definition): array {
            $current = $this->load($presetId);
            $stored = $this->prepareNextDocument($presetId, $expectedRevision, $definition, $current);
            $raw = self::encodeCanonical($stored);
            $this->assertStoredSize($raw);
            $this->setRaw($presetId, $raw);
            $readBack = $this->load($presetId);
            if ($readBack !== $stored) {
                throw new \RuntimeException('Контрольное чтение сопоставлений записи не совпало с записью.');
            }
            return $readBack;
        });
    }

    /** @return mixed */
    public function withMutationLock(int $presetId, callable $callback)
    {
        $this->assertPresetId($presetId);
        if (isset($this->adapters['mutation_lock'])) {
            return call_user_func($this->adapters['mutation_lock'], $presetId, $callback);
        }
        $directory = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\') . '/bitrix/managed_cache';
        if ($directory === '/bitrix/managed_cache' || $directory === '\\bitrix\\managed_cache') {
            $directory = sys_get_temp_dir();
        }
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Не удалось создать каталог блокировки сопоставлений записи.');
        }
        $path = rtrim($directory, '/\\') . '/prospektweb.calc.catalog-output-mapping.' . $presetId . '.lock';
        $handle = @fopen($path, 'c');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Не удалось открыть блокировку сопоставлений записи.');
        }
        $deadline = microtime(true) + self::LOCK_TIMEOUT_SECONDS;
        try {
            do {
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    try {
                        return $callback();
                    } finally {
                        flock($handle, LOCK_UN);
                    }
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
        } finally {
            fclose($handle);
        }
        throw new \RuntimeException('Хранилище сопоставлений записи занято. Повторите операцию.');
    }

    /**
     * @param array<int,array<string,mixed>> $offerResults
     * @param array<int,array<string,mixed>> $priceTypes
     * @param array<string,mixed>|null $definition
     * @param array<string,mixed>|null $publication
     * @return array<int,array<string,mixed>>
     */
    public function projectResultsForWrite(
        int $presetId,
        array $offerResults,
        array $priceTypes,
        ?array $definition = null,
        ?array $publication = null,
        bool $definitionIsPinned = false
    ): array {
        $definition = $definition === null
            ? $this->load($presetId)
            : $this->normalizeDefinition($presetId, $definition);
        if (!$definitionIsPinned) {
            $current = $this->load($presetId);
            if ((int)$current['revision'] !== (int)$definition['revision']) {
                throw new \RuntimeException(
                    'Сопоставления записи изменились во время расчёта; результат не будет записан.',
                    409
                );
            }
        }
        if ((int)$definition['revision'] <= 0) {
            throw new \RuntimeException('Автоматическая запись результатов не настроена для пресета.', 409);
        }
        if ($publication !== null
            && ((int)($publication['revision'] ?? 0) <= 0
                || preg_match('/^[a-f0-9]{64}$/D', (string)($publication['compileHash'] ?? '')) !== 1)) {
            throw new \RuntimeException('Результат не содержит подтверждённую публикацию формы пресета.');
        }

        $enabledTargets = [];
        foreach ($definition['mappings'] as $mapping) {
            $enabledTargets[(string)$mapping['target_path']] = true;
        }
        $allowedPriceTypes = [];
        foreach ($priceTypes as $priceType) {
            $typeId = is_array($priceType) ? (int)($priceType['id'] ?? $priceType['ID'] ?? 0) : 0;
            if ($typeId > 0) {
                $allowedPriceTypes[$typeId] = true;
            }
        }

        $projectedResults = [];
        foreach ($offerResults as $result) {
            if (!is_array($result)) {
                throw new \RuntimeException('calc-server вернул некорректный результат записи каталога.');
            }
            $currency = strtoupper(trim((string)($result['currency'] ?? 'RUB')));
            if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                throw new \RuntimeException('calc-server вернул некорректную валюту результата записи каталога.');
            }
            $projected = [
                'offerId' => (int)($result['offerId'] ?? 0),
                'currency' => $currency,
                'catalogOutputMappingProvenance' => [
                    'contract' => self::CONTRACT,
                    'preset_id' => $presetId,
                    'revision' => (int)$definition['revision'],
                ],
            ];
            if ($publication !== null) {
                $projected['catalogOutputMappingProvenance']['publicationRevision'] = (int)$publication['revision'];
                $projected['catalogOutputMappingProvenance']['publicationCompileHash'] = (string)$publication['compileHash'];
            }
            if (isset($enabledTargets['catalog.offer.purchasingPrice'])) {
                $projected['purchasePrice'] = $result['purchasePrice'] ?? $result['purchase_price'] ?? null;
            }
            if (isset($enabledTargets['catalog.offer.priceTypes'])) {
                $ranges = [];
                foreach (is_array($result['priceRangesWithMarkup'] ?? null) ? $result['priceRangesWithMarkup'] : [] as $range) {
                    if (!is_array($range)) {
                        continue;
                    }
                    $prices = [];
                    foreach (is_array($range['prices'] ?? null) ? $range['prices'] : [] as $price) {
                        $typeId = is_array($price) ? (int)($price['typeId'] ?? 0) : 0;
                        if ($typeId > 0 && isset($allowedPriceTypes[$typeId])) {
                            $priceCurrency = strtoupper(trim((string)($price['currency'] ?? $currency)));
                            if (preg_match('/^[A-Z]{3}$/D', $priceCurrency) !== 1) {
                                throw new \RuntimeException('calc-server вернул некорректную валюту цены записи каталога.');
                            }
                            $prices[] = [
                                'typeId' => $typeId,
                                'basePrice' => $price['basePrice'] ?? null,
                                'currency' => $priceCurrency,
                            ];
                        }
                    }
                    if ($prices !== []) {
                        $ranges[] = [
                            'quantityFrom' => $range['quantityFrom'] ?? null,
                            'quantityTo' => $range['quantityTo'] ?? null,
                            'prices' => $prices,
                        ];
                    }
                }
                $projected['priceRangesWithMarkup'] = $ranges;
            }

            $detail = is_array($result['details'][0] ?? null) ? $result['details'][0] : [];
            $outputs = is_array($detail['outputs'] ?? null) ? $detail['outputs'] : [];
            $dimensionOutputs = [];
            foreach (['weight', 'length', 'width', 'height'] as $dimension) {
                if (isset($enabledTargets['catalog.offer.' . $dimension])) {
                    $dimensionOutputs[$dimension] = $outputs[$dimension] ?? $detail[$dimension] ?? null;
                }
            }
            $projected['details'] = [['outputs' => $dimensionOutputs]];
            $projectedResults[] = $projected;
        }
        return $projectedResults;
    }

    /** @return array<int,array<string,mixed>> */
    public function projectPinnedResultsForWrite(
        int $presetId,
        array $offerResults,
        array $priceTypes,
        array $definition,
        ?array $publication = null
    ): array {
        return $this->projectResultsForWrite(
            $presetId,
            $offerResults,
            $priceTypes,
            $definition,
            $publication,
            true
        );
    }

    /** @return array<string,mixed> */
    private function defaultDocument(int $presetId): array
    {
        return self::initialDocument($presetId);
    }

    /** @return array<string,mixed> */
    public static function initialDocument(int $presetId): array
    {
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('Catalog output mapping presetId must be positive.');
        }
        $mappings = [];
        foreach (self::PAIRS as $sourcePath => $targetPath) {
            $mappings[] = ['source_path' => $sourcePath, 'target_path' => $targetPath];
        }
        usort($mappings, static fn(array $left, array $right): int => strcmp($left['target_path'], $right['target_path']));
        return [
            'contract' => self::CONTRACT,
            'preset_id' => $presetId,
            'revision' => 0,
            'mappings' => $mappings,
        ];
    }

    /** @param array<string,mixed> $definition @return array<string,mixed> */
    private function normalizeDefinition(int $presetId, array $definition): array
    {
        if ($this->isList($definition)) {
            throw new \InvalidArgumentException('Документ сопоставлений записи должен быть JSON-объектом.');
        }
        $this->assertExactKeys($definition, ['contract', 'preset_id', 'revision', 'mappings'], 'catalog_output_mapping');
        if (($definition['contract'] ?? null) !== self::CONTRACT) {
            throw new \InvalidArgumentException('catalog_output_mapping.contract имеет неизвестную версию.');
        }
        $documentPresetId = $this->integer($definition['preset_id'] ?? null, 1, 'catalog_output_mapping.preset_id');
        if ($documentPresetId !== $presetId) {
            throw new \InvalidArgumentException('catalog_output_mapping.preset_id не совпадает с целевым пресетом.');
        }
        $revision = $this->integer($definition['revision'] ?? null, 0, 'catalog_output_mapping.revision');
        $mappings = $definition['mappings'] ?? null;
        if (!is_array($mappings) || !$this->isList($mappings) || count($mappings) !== count(self::PAIRS)) {
            throw new \InvalidArgumentException('catalog_output_mapping.mappings должен содержать все разрешённые пары.');
        }
        $normalized = [];
        $seenSources = [];
        $seenTargets = [];
        foreach ($mappings as $index => $mapping) {
            if (!is_array($mapping) || $this->isList($mapping)) {
                throw new \InvalidArgumentException('catalog_output_mapping.mappings[' . $index . '] должен быть JSON-объектом.');
            }
            $this->assertExactKeys($mapping, ['source_path', 'target_path'], 'catalog_output_mapping.mappings[' . $index . ']');
            $sourcePath = trim((string)($mapping['source_path'] ?? ''));
            $targetPath = trim((string)($mapping['target_path'] ?? ''));
            if (!isset(self::PAIRS[$sourcePath]) || self::PAIRS[$sourcePath] !== $targetPath
                || isset($seenSources[$sourcePath]) || isset($seenTargets[$targetPath])) {
                throw new \InvalidArgumentException('catalog_output_mapping содержит запрещённую или повторную пару.');
            }
            $seenSources[$sourcePath] = true;
            $seenTargets[$targetPath] = true;
            $normalized[] = ['source_path' => $sourcePath, 'target_path' => $targetPath];
        }
        usort($normalized, static fn(array $left, array $right): int => strcmp($left['target_path'], $right['target_path']));
        return [
            'contract' => self::CONTRACT,
            'preset_id' => $presetId,
            'revision' => $revision,
            'mappings' => $normalized,
        ];
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $current @return array<string,mixed> */
    private function prepareNextDocument(int $presetId, int $expectedRevision, array $definition, array $current): array
    {
        if ((int)$current['revision'] !== $expectedRevision) {
            throw new \RuntimeException('Сопоставления записи уже изменены в другой сессии. Перезагрузите данные.', 409);
        }
        $candidate = $this->normalizeDefinition($presetId, $definition);
        if ((int)$candidate['revision'] !== $expectedRevision) {
            throw new \RuntimeException('revision документа не совпадает с expected_revision.', 409);
        }
        if ($expectedRevision >= self::MAX_SAFE_INTEGER) {
            throw new \RuntimeException('Ревизия сопоставлений записи исчерпана.', 409);
        }
        $candidate['revision'] = $expectedRevision + 1;
        return $candidate;
    }

    /** @param mixed $value */
    public static function encodeCanonical($value): string
    {
        $encoded = json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if (!is_string($encoded)) {
            throw new \RuntimeException('Не удалось сериализовать сопоставления записи.');
        }
        return $encoded;
    }

    /** @param mixed $value @return mixed */
    private static function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $keys = array_keys($value);
        if ($keys !== [] && $keys !== range(0, count($keys) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }

    private function optionName(int $presetId): string
    {
        return self::OPTION_PREFIX . $presetId;
    }

    private function getRaw(int $presetId): string
    {
        $name = $this->optionName($presetId);
        if (isset($this->adapters['get_option'])) {
            return (string)call_user_func($this->adapters['get_option'], $name, '');
        }
        if (!class_exists(Application::class)) {
            return '';
        }
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $escapedName = $helper->forSql($name);
        $escapedModuleId = $helper->forSql(self::MODULE_ID);
        $rows = $connection->query(
            "SELECT MODULE_ID, NAME, VALUE FROM b_option WHERE MODULE_ID='" . $escapedModuleId
            . "' AND NAME='" . $escapedName . "' AND (SITE_ID IS NULL OR SITE_ID='') ORDER BY MODULE_ID, NAME"
        );
        $row = is_object($rows) && method_exists($rows, 'fetch') ? $rows->fetch() : null;
        $duplicate = is_object($rows) && method_exists($rows, 'fetch') ? $rows->fetch() : null;
        if (is_array($duplicate)) {
            throw new \RuntimeException('Обнаружены дублирующиеся строки сопоставлений записи.', 409);
        }
        if (!is_array($row)) {
            return '';
        }
        return (string)($row['VALUE'] ?? $row['value'] ?? '');
    }

    private function setRaw(int $presetId, string $raw): void
    {
        if (!isset($this->adapters['set_option'])) {
            throw new \LogicException('Direct output mapping writes must use the transactional CAS path.');
        }
        call_user_func($this->adapters['set_option'], $this->optionName($presetId), $raw);
    }

    /** @param array<string,mixed> $definition @return array<string,mixed> */
    private function saveDirectUnderCoordinatorTransaction(
        int $presetId,
        int $expectedRevision,
        array $definition
    ): array
    {
        if (!class_exists(Application::class)) {
            throw new \RuntimeException('Bitrix database connection is unavailable for output mapping CAS.');
        }
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $name = $this->optionName($presetId);
        $escapedName = $helper->forSql($name);
        $escapedModuleId = $helper->forSql(self::MODULE_ID);
        $selectSql = "SELECT VALUE FROM b_option WHERE MODULE_ID='" . $escapedModuleId
            . "' AND NAME='" . $escapedName . "' AND (SITE_ID IS NULL OR SITE_ID='') FOR UPDATE";
        $rows = $connection->query($selectSql);
        $row = is_object($rows) && method_exists($rows, 'fetch') ? $rows->fetch() : null;
        $duplicate = is_object($rows) && method_exists($rows, 'fetch') ? $rows->fetch() : null;
        if (is_array($duplicate)) {
            throw new \RuntimeException('Обнаружены дублирующиеся строки сопоставлений записи.', 409);
        }
        $raw = is_array($row) ? (string)($row['VALUE'] ?? $row['value'] ?? '') : '';
        $current = $this->loadFromRaw($presetId, $raw);
        $stored = $this->prepareNextDocument($presetId, $expectedRevision, $definition, $current);
        $encoded = self::encodeCanonical($stored);
        $this->assertStoredSize($encoded);
        $escapedEncoded = $helper->forSql($encoded);
        if (is_array($row)) {
            $connection->queryExecute(
                "UPDATE b_option SET VALUE='" . $escapedEncoded . "' WHERE MODULE_ID='" . $escapedModuleId
                . "' AND NAME='" . $escapedName . "' AND (SITE_ID IS NULL OR SITE_ID='')"
            );
        } else {
            $connection->queryExecute(
                "INSERT INTO b_option (MODULE_ID, NAME, VALUE, SITE_ID) VALUES ('"
                . $escapedModuleId . "','" . $escapedName . "','" . $escapedEncoded . "',NULL)"
            );
        }
        $readBackRows = $connection->query($selectSql);
        $readBackRow = is_object($readBackRows) && method_exists($readBackRows, 'fetch') ? $readBackRows->fetch() : null;
        $readBackDuplicate = is_object($readBackRows) && method_exists($readBackRows, 'fetch') ? $readBackRows->fetch() : null;
        if (!is_array($readBackRow) || is_array($readBackDuplicate)) {
            throw new \RuntimeException('Контрольное чтение сопоставлений записи отсутствует или неоднозначно.');
        }
        $readBack = $this->loadFromRaw($presetId, (string)($readBackRow['VALUE'] ?? $readBackRow['value'] ?? ''));
        if ($readBack !== $stored) {
            throw new \RuntimeException('Контрольное чтение сопоставлений записи не совпало с записью.');
        }
        return $readBack;
    }

    private function assertStoredSize(string $raw): void
    {
        if (strlen($raw) > self::MAX_DOCUMENT_BYTES) {
            throw new \InvalidArgumentException('Сопоставления записи превышают допустимый размер.');
        }
    }

    /** @param array<string,mixed> $value @param string[] $expected */
    private function assertExactKeys(array $value, array $expected, string $path): void
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new \InvalidArgumentException($path . ' содержит неизвестные или отсутствующие поля.');
        }
    }

    /** @param mixed $value */
    private function integer($value, int $minimum, string $path): int
    {
        if (!is_int($value) || $value < $minimum || $value > self::MAX_SAFE_INTEGER) {
            throw new \InvalidArgumentException($path . ' должен быть безопасным целым числом не меньше ' . $minimum . '.');
        }
        return $value;
    }

    private function assertPresetId(int $presetId): void
    {
        if ($presetId <= 0 || $presetId > self::MAX_SAFE_INTEGER) {
            throw new \InvalidArgumentException('preset_id должен быть безопасным положительным целым числом.');
        }
    }

    private function assertRevision(int $revision): void
    {
        if ($revision < 0 || $revision > self::MAX_SAFE_INTEGER) {
            throw new \InvalidArgumentException('expected_revision должен быть безопасным неотрицательным целым числом.');
        }
    }

    private function isList(array $value): bool
    {
        $keys = array_keys($value);
        return $keys === [] || $keys === range(0, count($keys) - 1);
    }
}
