<?php

namespace Bitrix\Main\Config {
    class Option
    {
        public static array $values = [];

        public static function get(string $moduleId, string $name, $default = '')
        {
            return self::$values[$name] ?? $default;
        }

        public static function set(string $moduleId, string $name, $value): void
        {
            self::$values[$name] = $value;
        }
    }
}

namespace Bitrix\Main {
    class Loader
    {
        public static function includeModule(string $moduleId): bool
        {
            return in_array($moduleId, ['catalog', 'iblock'], true);
        }
    }
}

namespace Prospektweb\Calc\Config {
    use Bitrix\Main\Config\Option;

    class SettingsManager
    {
        public function getDefaultExtraValue(): int
        {
            return (int)Option::get('prospektweb.calc', 'DEFAULT_EXTRA_VALUE', '10');
        }

        public function setDefaultExtraValue(int $value): void
        {
            Option::set('prospektweb.calc', 'DEFAULT_EXTRA_VALUE', (string)$value);
        }

        public function getDefaultExtraCurrency(): string
        {
            return (string)Option::get('prospektweb.calc', 'DEFAULT_EXTRA_CURRENCY_VALUE', 'PRC');
        }

        public function setDefaultExtraCurrency(string $currency): void
        {
            Option::set('prospektweb.calc', 'DEFAULT_EXTRA_CURRENCY_VALUE', $currency);
        }

        public function isLoggingEnabled(): bool
        {
            return Option::get('prospektweb.calc', 'LOGGING_ENABLED', 'N') === 'Y';
        }

        public function setLoggingEnabled(bool $enabled): void
        {
            Option::set('prospektweb.calc', 'LOGGING_ENABLED', $enabled ? 'Y' : 'N');
        }
    }

    class ConfigManager
    {
        public function getIblockId(string $code): int
        {
            return 0;
        }
    }
}

namespace Prospektweb\Calc\Services {
    class AsproAiPatchManager
    {
        public const PATCH_VERSION = 'test';

        public function getStatus(): array
        {
            return [
                'state' => 'not_installed',
                'message' => 'test',
                'canApply' => true,
                'canRemove' => false,
                'asproVersion' => 'test',
                'patchVersion' => self::PATCH_VERSION,
            ];
        }
    }
}

namespace {
    use Bitrix\Main\Config\Option;
    use Prospektweb\Calc\Services\ControlCenterSettingsService;

    class CCatalogGroup
    {
        public static function GetListArray(): array
        {
            return [
                ['ID' => 1, 'NAME' => 'Базовая'],
                ['ID' => 2, 'NAME' => 'Розничная'],
            ];
        }
    }

    class CIBlock
    {
        public static function GetByID(int $id)
        {
            return new class {
                public function Fetch(): ?array
                {
                    return null;
                }
            };
        }
    }

    require_once __DIR__ . '/../lib/Services/BatchRecalculateService.php';
    require_once __DIR__ . '/../lib/Services/ControlCenterSettingsService.php';

    Option::$values = [
        'DEFAULT_EXTRA_VALUE' => '10',
        'DEFAULT_EXTRA_CURRENCY_VALUE' => 'PRC',
        'LOGGING_ENABLED' => 'N',
        'SAVE_CALC_HISTORY' => 'N',
        'CALC_HISTORY_LIMIT' => '10',
        'MARKUP_SETTINGS' => '{"basePriceTypeId":1,"rates":{"1":0,"2":20}}',
        'CALC_SERVER_URL' => 'https://pwrt.ru/calc-api',
        'ASPRO_AI_TIMEWEB_ENABLED' => 'N',
        'ASPRO_AI_TIMEWEB_BASE_URL' => 'https://api.timeweb.ai/v1',
    ];

    $assert = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    };

    $authorityAudits = 0;
    $service = new ControlCenterSettingsService([
        'with_authority' => static function (callable $mutation) use (&$authorityAudits): array {
            $before = Option::$values;
            try {
                $outcome = $mutation();
                $authorityAudits++;
                return $outcome;
            } catch (\Throwable $error) {
                Option::$values = $before;
                throw $error;
            }
        },
    ]);
    $initial = $service->getSettings();
    $assert(strlen((string)$initial['revision']) === 64, 'Settings revision must be SHA-256');
    $assert(count($initial['pricing']['priceTypes']) === 2, 'Catalog price types must be exposed');

    $saved = $service->saveSettings([
        'calculation' => ['defaultExtraValue' => 15, 'defaultExtraCurrency' => 'RUB'],
        'history' => ['enabled' => true, 'limit' => 25, 'loggingEnabled' => true],
        'pricing' => ['basePriceTypeId' => 1, 'rates' => ['1' => 0, '2' => 12.5]],
        'integration' => [
            'calcServerUrl' => 'https://pwrt.ru/calc-api/',
            'asproAiEnabled' => true,
            'asproAiBaseUrl' => 'https://api.timeweb.ai/v1/',
        ],
    ], $initial['revision']);

    $assert($saved['calculation']['defaultExtraValue'] === 15, 'Validated calculation settings must persist');
    $assert($saved['history']['limit'] === 25, 'Validated history settings must persist');
    $assert($saved['pricing']['rates']['2'] === 12.5, 'Validated price rates must persist');
    $assert($saved['integration']['calcServerUrl'] === 'https://pwrt.ru/calc-api', 'Service URLs must be normalized');
    $assert($saved['revision'] !== $initial['revision'], 'Successful writes must advance the settings revision');
    $assert($authorityAudits === 1, 'Successful settings mutation must cross one transaction/audit authority');

    try {
        $service->saveSettings([], $initial['revision']);
        throw new RuntimeException('Stale settings revision was accepted');
    } catch (RuntimeException $exception) {
        $assert($exception->getCode() === 409, 'Stale settings revision must return conflict semantics');
        $assert($authorityAudits === 1, 'Stale settings mutation must not write a success audit');
    }

    $beforeInvalid = Option::$values;
    try {
        $service->saveSettings([
            'integration' => ['calcServerUrl' => 'file:///etc/passwd'],
        ], $saved['revision']);
        throw new RuntimeException('Unsafe URL was accepted');
    } catch (InvalidArgumentException $exception) {
        $assert(Option::$values === $beforeInvalid, 'Validation must complete before any setting is written');
    }

    echo "Control center settings service tests passed\n";
}
