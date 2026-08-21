<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\SettingsManager;

/**
 * Single read/write contract for module settings used by both Bitrix options
 * and the modern PROSPEKT control center.
 */
class ControlCenterSettingsService
{
    private const MODULE_ID = 'prospektweb.calc';

    private const DIRECTORY_LABELS = [
        'CALC_PRESETS' => 'Пресеты калькуляции',
        'CALC_STAGES' => 'Этапы',
        'CALC_SETTINGS' => 'Калькуляторы',
        'CALC_MATERIALS' => 'Материалы',
        'CALC_MATERIALS_VARIANTS' => 'Варианты материалов',
        'CALC_OPERATIONS' => 'Операции',
        'CALC_OPERATIONS_VARIANTS' => 'Варианты операций',
        'CALC_EQUIPMENT' => 'Оборудование',
        'CALC_DETAILS' => 'Детали',
        'CALC_CUSTOM_FIELDS' => 'Пользовательские поля',
    ];

    private SettingsManager $settingsManager;
    public function __construct()
    {
        $this->settingsManager = new SettingsManager();
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        $editable = $this->loadEditableSettings();
        $editable['pricing']['priceTypes'] = array_values($this->loadPriceTypes());
        $editable['integration']['patchStatus'] = $this->loadPatchStatus();
        $editable['directories'] = $this->loadDirectories();
        $editable['revision'] = $this->buildRevision($this->revisionPayload($editable));

        return $editable;
    }

    /**
     * Save a complete or partial modern settings payload using optimistic
     * concurrency. All validation is completed before the first Option write.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function saveSettings(array $settings, string $expectedRevision): array
    {
        return $this->withWriteLock(function () use ($settings, $expectedRevision): array {
            $current = $this->loadEditableSettings();
            $currentRevision = $this->buildRevision($this->revisionPayload($current));
            if ($expectedRevision === '' || !hash_equals($currentRevision, $expectedRevision)) {
                throw new \RuntimeException('SETTINGS_REVISION_CONFLICT', 409);
            }

            $normalized = $this->normalizeSettings($settings, $current);
            $this->persistSettings($normalized);

            return $this->getSettings();
        });
    }

    /**
     * Legacy Bitrix form adapter. It intentionally uses the same normalization
     * and persistence path as the JSON API, while the form keeps its PRG flow.
     *
     * @param array<string, mixed> $post
     */
    public function saveLegacyPost(array $post): void
    {
        $this->withWriteLock(function () use ($post): void {
            $current = $this->loadEditableSettings();
            $rates = is_array($post['MARKUP_RATE'] ?? null) ? $post['MARKUP_RATE'] : [];
            $settings = [
                'calculation' => [
                    'defaultExtraValue' => $post['DEFAULT_EXTRA_VALUE'] ?? $current['calculation']['defaultExtraValue'],
                    'defaultExtraCurrency' => $post['DEFAULT_EXTRA_CURRENCY_VALUE'] ?? $current['calculation']['defaultExtraCurrency'],
                ],
                'history' => [
                    'enabled' => (($post['SAVE_CALC_HISTORY'] ?? 'N') === 'Y'),
                    'limit' => $post['CALC_HISTORY_LIMIT'] ?? $current['history']['limit'],
                    'loggingEnabled' => (($post['LOGGING_ENABLED'] ?? 'N') === 'Y'),
                ],
                'pricing' => [
                    'basePriceTypeId' => $post['MARKUP_BASE_PRICE_TYPE_ID'] ?? $current['pricing']['basePriceTypeId'],
                    'rates' => $rates,
                ],
                'integration' => [
                    'calcServerUrl' => $post['CALC_SERVER_URL'] ?? $current['integration']['calcServerUrl'],
                    'asproAiEnabled' => (($post['ASPRO_AI_TIMEWEB_ENABLED'] ?? 'N') === 'Y'),
                    'asproAiBaseUrl' => $post['ASPRO_AI_TIMEWEB_BASE_URL'] ?? $current['integration']['asproAiBaseUrl'],
                ],
            ];

            $this->persistSettings($this->normalizeSettings($settings, $current));
        });
    }

    public function saveAsproIntegration(bool $enabled, string $baseUrl): void
    {
        $this->withWriteLock(function () use ($enabled, $baseUrl): void {
            $current = $this->loadEditableSettings();
            $normalized = $this->normalizeSettings([
                'integration' => [
                    'asproAiEnabled' => $enabled,
                    'asproAiBaseUrl' => $baseUrl,
                ],
            ], $current);

            Option::set(self::MODULE_ID, 'ASPRO_AI_TIMEWEB_ENABLED', $normalized['integration']['asproAiEnabled'] ? 'Y' : 'N');
            Option::set(self::MODULE_ID, 'ASPRO_AI_TIMEWEB_BASE_URL', $normalized['integration']['asproAiBaseUrl']);
        });
    }

    /** @return array<string, mixed> */
    public function getContactGallery(): array
    {
        return $this->contactGalleryManager()->getSnapshot();
    }

    /** @return array<string, mixed> */
    public function setContactGalleryEnabled(bool $enabled, string $expectedRevision, int $userId): array
    {
        return $this->contactGalleryManager()->setEnabled($enabled, $expectedRevision, $userId);
    }

    /** @param array<string, mixed> $uploadedFiles @return array<string, mixed> */
    public function uploadContactGallery(array $uploadedFiles, string $expectedRevision, int $userId): array
    {
        return $this->contactGalleryManager()->upload($uploadedFiles, $expectedRevision, $userId);
    }

    /** @return array<string, mixed> */
    public function removeContactGalleryFile(int $fileId, string $expectedRevision, int $userId): array
    {
        return $this->contactGalleryManager()->remove($fileId, $expectedRevision, $userId);
    }

    /** @param int[] $fileIds @return array<string, mixed> */
    public function reorderContactGallery(array $fileIds, string $expectedRevision, int $userId): array
    {
        return $this->contactGalleryManager()->reorder($fileIds, $expectedRevision, $userId);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadEditableSettings(): array
    {
        $priceTypes = $this->loadPriceTypes();
        $markupSettings = json_decode((string)Option::get(self::MODULE_ID, 'MARKUP_SETTINGS', ''), true);
        if (!is_array($markupSettings)) {
            $markupSettings = [];
        }

        $basePriceTypeId = (int)($markupSettings['basePriceTypeId'] ?? 0);
        if ($basePriceTypeId <= 0 && $priceTypes !== []) {
            $basePriceTypeId = (int)$priceTypes[0]['id'];
        }

        $rates = [];
        $storedRates = is_array($markupSettings['rates'] ?? null) ? $markupSettings['rates'] : [];
        foreach ($priceTypes as $priceType) {
            $id = (int)$priceType['id'];
            $rates[(string)$id] = (float)($storedRates[$id] ?? $storedRates[(string)$id] ?? 0);
        }
        ksort($rates, SORT_NUMERIC);

        return [
            'calculation' => [
                'defaultExtraValue' => $this->settingsManager->getDefaultExtraValue(),
                'defaultExtraCurrency' => $this->settingsManager->getDefaultExtraCurrency(),
            ],
            'history' => [
                'enabled' => Option::get(self::MODULE_ID, 'SAVE_CALC_HISTORY', 'N') === 'Y',
                'limit' => max(1, min(100, (int)Option::get(self::MODULE_ID, 'CALC_HISTORY_LIMIT', '10'))),
                'loggingEnabled' => $this->settingsManager->isLoggingEnabled(),
            ],
            'pricing' => [
                'basePriceTypeId' => $basePriceTypeId,
                'rates' => $rates,
            ],
            'integration' => [
                'calcServerUrl' => (string)Option::get(self::MODULE_ID, 'CALC_SERVER_URL', 'https://pwrt.ru/calc-api'),
                'asproAiEnabled' => Option::get(self::MODULE_ID, 'ASPRO_AI_TIMEWEB_ENABLED', 'N') === 'Y',
                'asproAiBaseUrl' => (string)Option::get(self::MODULE_ID, 'ASPRO_AI_TIMEWEB_BASE_URL', 'https://api.timeweb.ai/v1'),
            ],
        ];
    }

    private function contactGalleryManager(): \Prospektweb\LayoutFiles\ContactGalleryManager
    {
        if (
            !Loader::includeModule('prospektweb.layoutfiles')
            || !class_exists('\\Prospektweb\\LayoutFiles\\ContactGalleryManager')
        ) {
            throw new \RuntimeException('CONTACT_GALLERY_UNAVAILABLE', 503);
        }

        return new \Prospektweb\LayoutFiles\ContactGalleryManager();
    }

    /**
     * @return array<int, array{id:int,name:string}>
     */
    private function loadPriceTypes(): array
    {
        if (!Loader::includeModule('catalog')) {
            return [];
        }

        $result = [];
        foreach ((array)\CCatalogGroup::GetListArray() as $type) {
            $id = (int)($type['ID'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $result[] = [
                'id' => $id,
                'name' => (string)($type['NAME'] ?? ('ID ' . $id)),
            ];
        }

        usort($result, static function (array $left, array $right): int {
            return $left['id'] <=> $right['id'];
        });

        return $result;
    }

    /**
     * @return array<int, array{code:string,label:string,iblockId:int,name:string,exists:bool}>
     */
    private function loadDirectories(): array
    {
        $iblockAvailable = Loader::includeModule('iblock');
        $result = [];
        foreach (self::DIRECTORY_LABELS as $code => $label) {
            $iblockId = (int)Option::get(self::MODULE_ID, 'IBLOCK_' . $code, 0);
            $name = '';
            $exists = false;
            if ($iblockAvailable && $iblockId > 0) {
                $iblock = \CIBlock::GetByID($iblockId)->Fetch();
                $exists = is_array($iblock);
                $name = $exists ? (string)($iblock['NAME'] ?? '') : '';
            }

            $result[] = [
                'code' => $code,
                'label' => $label,
                'iblockId' => $iblockId,
                'name' => $name,
                'exists' => $exists,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPatchStatus(): array
    {
        $fallback = [
            'state' => 'access_error',
            'message' => 'Не удалось получить состояние патча.',
            'canApply' => false,
            'canRemove' => false,
            'asproVersion' => '',
            'patchVersion' => AsproAiPatchManager::PATCH_VERSION,
        ];

        try {
            $status = (new AsproAiPatchManager())->getStatus();
            return array_merge($fallback, is_array($status) ? $status : []);
        } catch (\Throwable $exception) {
            $fallback['message'] = $exception->getMessage();
            return $fallback;
        }
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    private function normalizeSettings(array $settings, array $current): array
    {
        $calculation = is_array($settings['calculation'] ?? null) ? $settings['calculation'] : [];
        $history = is_array($settings['history'] ?? null) ? $settings['history'] : [];
        $pricing = is_array($settings['pricing'] ?? null) ? $settings['pricing'] : [];
        $integration = is_array($settings['integration'] ?? null) ? $settings['integration'] : [];

        $extraValue = $this->normalizeInteger(
            $calculation['defaultExtraValue'] ?? $current['calculation']['defaultExtraValue'],
            0,
            1000000000,
            'calculation.defaultExtraValue'
        );
        $extraCurrency = (string)($calculation['defaultExtraCurrency'] ?? $current['calculation']['defaultExtraCurrency']);
        if (!in_array($extraCurrency, ['RUB', 'PRC'], true)) {
            throw new \InvalidArgumentException('calculation.defaultExtraCurrency must be RUB or PRC');
        }

        $historyLimit = $this->normalizeInteger(
            $history['limit'] ?? $current['history']['limit'],
            1,
            100,
            'history.limit'
        );

        $priceTypes = $this->loadPriceTypes();
        $allowedPriceTypeIds = array_map(static function (array $row): int {
            return (int)$row['id'];
        }, $priceTypes);
        $basePriceTypeId = $this->normalizeInteger(
            $pricing['basePriceTypeId'] ?? $current['pricing']['basePriceTypeId'],
            0,
            PHP_INT_MAX,
            'pricing.basePriceTypeId'
        );
        if ($allowedPriceTypeIds !== [] && !in_array($basePriceTypeId, $allowedPriceTypeIds, true)) {
            throw new \InvalidArgumentException('pricing.basePriceTypeId is not an existing catalog price type');
        }
        if ($allowedPriceTypeIds === []) {
            $basePriceTypeId = 0;
        }

        $rawRates = is_array($pricing['rates'] ?? null) ? $pricing['rates'] : $current['pricing']['rates'];
        $rates = [];
        foreach ($allowedPriceTypeIds as $priceTypeId) {
            $rawValue = $rawRates[$priceTypeId] ?? $rawRates[(string)$priceTypeId]
                ?? $current['pricing']['rates'][(string)$priceTypeId] ?? 0;
            $rates[(string)$priceTypeId] = $this->normalizeFloat(
                $rawValue,
                -100,
                100000,
                'pricing.rates.' . $priceTypeId
            );
        }
        ksort($rates, SORT_NUMERIC);

        $calcServerUrl = $this->normalizeUrl(
            (string)($integration['calcServerUrl'] ?? $current['integration']['calcServerUrl']),
            ['http', 'https'],
            'integration.calcServerUrl'
        );
        $calcServerUrl = BatchRecalculateService::normalizeCalcServerUrl($calcServerUrl);
        $asproAiBaseUrl = $this->normalizeUrl(
            (string)($integration['asproAiBaseUrl'] ?? $current['integration']['asproAiBaseUrl']),
            ['https'],
            'integration.asproAiBaseUrl'
        );

        return [
            'calculation' => [
                'defaultExtraValue' => $extraValue,
                'defaultExtraCurrency' => $extraCurrency,
            ],
            'history' => [
                'enabled' => $this->normalizeBoolean($history['enabled'] ?? $current['history']['enabled'], 'history.enabled'),
                'limit' => $historyLimit,
                'loggingEnabled' => $this->normalizeBoolean(
                    $history['loggingEnabled'] ?? $current['history']['loggingEnabled'],
                    'history.loggingEnabled'
                ),
            ],
            'pricing' => [
                'basePriceTypeId' => $basePriceTypeId,
                'rates' => $rates,
            ],
            'integration' => [
                'calcServerUrl' => $calcServerUrl,
                'asproAiEnabled' => $this->normalizeBoolean(
                    $integration['asproAiEnabled'] ?? $current['integration']['asproAiEnabled'],
                    'integration.asproAiEnabled'
                ),
                'asproAiBaseUrl' => $asproAiBaseUrl,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function persistSettings(array $settings): void
    {
        $this->settingsManager->setDefaultExtraValue((int)$settings['calculation']['defaultExtraValue']);
        $this->settingsManager->setDefaultExtraCurrency((string)$settings['calculation']['defaultExtraCurrency']);
        $this->settingsManager->setLoggingEnabled((bool)$settings['history']['loggingEnabled']);

        Option::set(self::MODULE_ID, 'SAVE_CALC_HISTORY', $settings['history']['enabled'] ? 'Y' : 'N');
        Option::set(self::MODULE_ID, 'CALC_HISTORY_LIMIT', (string)$settings['history']['limit']);
        Option::set(self::MODULE_ID, 'MARKUP_SETTINGS', json_encode([
            'basePriceTypeId' => (int)$settings['pricing']['basePriceTypeId'],
            'rates' => $settings['pricing']['rates'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Option::set(self::MODULE_ID, 'CALC_SERVER_URL', (string)$settings['integration']['calcServerUrl']);
        Option::set(self::MODULE_ID, 'ASPRO_AI_TIMEWEB_ENABLED', $settings['integration']['asproAiEnabled'] ? 'Y' : 'N');
        Option::set(self::MODULE_ID, 'ASPRO_AI_TIMEWEB_BASE_URL', (string)$settings['integration']['asproAiBaseUrl']);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function revisionPayload(array $settings): array
    {
        return [
            'calculation' => $settings['calculation'],
            'history' => $settings['history'],
            'pricing' => [
                'basePriceTypeId' => $settings['pricing']['basePriceTypeId'],
                'rates' => $settings['pricing']['rates'],
            ],
            'integration' => [
                'calcServerUrl' => $settings['integration']['calcServerUrl'],
                'asproAiEnabled' => $settings['integration']['asproAiEnabled'],
                'asproAiBaseUrl' => $settings['integration']['asproAiBaseUrl'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildRevision(array $payload): string
    {
        return hash('sha256', (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param mixed $value
     */
    private function normalizeBoolean($value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [1, '1', 'Y', 'y', 'true'], true)) {
            return true;
        }
        if (in_array($value, [0, '0', 'N', 'n', 'false'], true)) {
            return false;
        }

        throw new \InvalidArgumentException($field . ' must be boolean');
    }

    /**
     * @param mixed $value
     */
    private function normalizeInteger($value, int $minimum, int $maximum, string $field): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException($field . ' must be an integer');
        }
        $normalized = (int)$value;
        if ($normalized < $minimum || $normalized > $maximum) {
            throw new \InvalidArgumentException($field . ' is outside the allowed range');
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     */
    private function normalizeFloat($value, float $minimum, float $maximum, string $field): float
    {
        $normalized = filter_var(str_replace(',', '.', (string)$value), FILTER_VALIDATE_FLOAT);
        if ($normalized === false || !is_finite((float)$normalized)) {
            throw new \InvalidArgumentException($field . ' must be a finite number');
        }
        $normalized = (float)$normalized;
        if ($normalized < $minimum || $normalized > $maximum) {
            throw new \InvalidArgumentException($field . ' is outside the allowed range');
        }

        return $normalized;
    }

    /**
     * @param string[] $allowedSchemes
     */
    private function normalizeUrl(string $value, array $allowedSchemes, string $field): string
    {
        $value = trim($value);
        $parts = parse_url($value);
        if (
            $value === ''
            || filter_var($value, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || !in_array(strtolower((string)($parts['scheme'] ?? '')), $allowedSchemes, true)
            || (string)($parts['host'] ?? '') === ''
            || !empty($parts['user'])
            || !empty($parts['pass'])
            || !empty($parts['query'])
            || !empty($parts['fragment'])
        ) {
            throw new \InvalidArgumentException($field . ' must be a valid allowed URL without credentials, query or fragment');
        }

        return rtrim($value, '/');
    }

    /**
     * @return mixed
     */
    private function withWriteLock(callable $callback)
    {
        $documentRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        $lockName = 'prospektweb-calc-settings-'
            . substr(hash('sha256', $documentRoot . '|prospektweb.calc|settings'), 0, 24)
            . '.lock';
        $lockPath = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . $lockName;
        $handle = @fopen($lockPath, 'c+');
        if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock module settings');
        }
        @chmod($lockPath, 0600);

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
