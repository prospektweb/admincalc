<?php

namespace Prospektweb\Calc\Services;

require_once dirname(__DIR__) . '/Config/ModuleOptions.php';

use Bitrix\Main\Loader;

/**
 * Single read/write contract for module settings used by both Bitrix options
 * and the modern PROSPEKT control center.
 */
class ControlCenterSettingsService
{
    private const MODULE_ID = 'prospektweb.calc';

    private const DIRECTORY_LABELS = [
        'CALC_MATERIALS' => 'Материалы',
        'CALC_MATERIALS_VARIANTS' => 'Варианты материалов',
        'CALC_OPERATIONS' => 'Операции',
        'CALC_OPERATIONS_VARIANTS' => 'Варианты операций',
        'CALC_EQUIPMENT' => 'Оборудование',
        'CALC_SUPPLIERS' => 'Поставщики материалов',
    ];

    /** @var array<string,callable> */
    private array $adapters;

    /** @param array<string,callable> $adapters */
    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
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
        $mutation = function () use ($settings, $expectedRevision): array {
            $current = $this->loadEditableSettings();
            $currentRevision = $this->buildRevision($this->revisionPayload($current));
            if (preg_match('/^[a-f0-9]{64}$/D', $expectedRevision) !== 1
                || !hash_equals($currentRevision, $expectedRevision)) {
                throw new \RuntimeException('SETTINGS_REVISION_CONFLICT', 409);
            }

            $normalized = $this->normalizeSettings($settings, $current);
            $this->persistSettings($normalized);
            $after = $this->loadEditableSettings();
            $afterRevision = $this->buildRevision($this->revisionPayload($after));
            $expectedAfterRevision = $this->buildRevision($this->revisionPayload($normalized));
            if (!hash_equals($expectedAfterRevision, $afterRevision)) {
                throw new \RuntimeException('SETTINGS_READBACK_MISMATCH', 409);
            }
            return [
                'before' => $this->revisionPayload($current),
                'after' => $this->revisionPayload($after),
                'result' => ['status' => 'ok'],
            ];
        };

        if (isset($this->adapters['with_authority'])) {
            $outcome = call_user_func($this->adapters['with_authority'], $mutation);
            if (!is_array($outcome) || ($outcome['result']['status'] ?? null) !== 'ok') {
                throw new \RuntimeException('SETTINGS_MUTATION_AUTHORITY_FAILED', 409);
            }
        } else {
            (new \Prospektweb\Calc\Config\ModuleOptions())->mutate($mutation);
        }

        return $this->getSettings();
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
        $markupSettings = json_decode((string)$this->readOption('MARKUP_SETTINGS', ''), true);
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

        $extraValue = (int)$this->readOption('DEFAULT_EXTRA_VALUE', '10');
        $extraCurrency = (string)$this->readOption('DEFAULT_EXTRA_CURRENCY_VALUE', 'PRC');
        return [
            'calculation' => [
                'defaultExtraValue' => $extraValue >= 0 ? $extraValue : 10,
                'defaultExtraCurrency' => in_array($extraCurrency, ['RUB', 'PRC'], true) ? $extraCurrency : 'PRC',
            ],
            'history' => [
                'enabled' => $this->readOption('SAVE_CALC_HISTORY', 'N') === 'Y',
                'limit' => max(1, min(100, (int)$this->readOption('CALC_HISTORY_LIMIT', '10'))),
                'loggingEnabled' => $this->readOption('LOGGING_ENABLED', 'N') === 'Y',
            ],
            'pricing' => [
                'basePriceTypeId' => $basePriceTypeId,
                'rates' => $rates,
            ],
            'integration' => [
                'calcServerUrl' => (string)$this->readOption('CALC_SERVER_URL', ''),
                'asproAiEnabled' => $this->readOption('ASPRO_AI_TIMEWEB_ENABLED', 'N') === 'Y',
                'asproAiBaseUrl' => (string)$this->readOption('ASPRO_AI_TIMEWEB_BASE_URL', 'https://api.timeweb.ai/v1'),
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
            $iblockId = (int)$this->readOption('IBLOCK_' . $code, 0);
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

        $calcServerUrl = trim((string)($integration['calcServerUrl'] ?? $current['integration']['calcServerUrl']));
        // An empty installation is valid before the operator connects a server.
        // Never substitute another installation's endpoint for an unset value.
        if ($calcServerUrl !== '') {
            $calcServerUrl = BatchRecalculateService::normalizeCalcServerUrl(
                $this->normalizeUrl($calcServerUrl, ['http', 'https'], 'integration.calcServerUrl')
            );
        }
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
    private function readOption(string $name, $default = '')
    {
        if (isset($this->adapters['read_option'])) { return ($this->adapters['read_option'])($name, $default); }
        return (new \Prospektweb\Calc\Config\ModuleOptions())->get($name, $default);
    }

    private function persistSettings(array $settings): void
    {
        $values = [
            'DEFAULT_EXTRA_VALUE' => (string)$settings['calculation']['defaultExtraValue'],
            'DEFAULT_EXTRA_CURRENCY_VALUE' => (string)$settings['calculation']['defaultExtraCurrency'],
            'LOGGING_ENABLED' => $settings['history']['loggingEnabled'] ? 'Y' : 'N',
            'SAVE_CALC_HISTORY' => $settings['history']['enabled'] ? 'Y' : 'N',
            'CALC_HISTORY_LIMIT' => (string)$settings['history']['limit'],
            'MARKUP_SETTINGS' => json_encode(['basePriceTypeId' => (int)$settings['pricing']['basePriceTypeId'], 'rates' => $settings['pricing']['rates']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'CALC_SERVER_URL' => (string)$settings['integration']['calcServerUrl'],
            'ASPRO_AI_TIMEWEB_ENABLED' => $settings['integration']['asproAiEnabled'] ? 'Y' : 'N',
            'ASPRO_AI_TIMEWEB_BASE_URL' => (string)$settings['integration']['asproAiBaseUrl'],
        ];
        if (isset($this->adapters['write_options'])) { ($this->adapters['write_options'])($values); return; }
        $options = new \Prospektweb\Calc\Config\ModuleOptions();
        foreach ($values as $name => $value) { $options->set($name, $value); }
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

}
