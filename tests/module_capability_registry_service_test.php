<?php

namespace Bitrix\Main\Config {
    class Option
    {
        public static array $values = [];

        public static function get(string $moduleId, string $name, $default = '')
        {
            return self::$values[$moduleId][$name] ?? $default;
        }

        public static function set(string $moduleId, string $name, $value): void
        {
            self::$values[$moduleId][$name] = $value;
        }
    }
}

namespace Bitrix\Main {
    class ModuleManager
    {
        public static ?int $versionReadsUntilFailure = null;

        public static array $versions = [
            'prospektweb.calc' => '1.4.0',
            'prospektweb.frontcalc' => '2.0.0',
            'prospektweb.propvalmanager' => '1.0.0',
            'prospektweb.companyrequisites' => '1.0.0',
            'prospektweb.layoutfiles' => '1.1.5',
            'prospektweb.offerfilter' => '1.0.0',
        ];

        public static function isModuleInstalled(string $moduleId): bool
        {
            return array_key_exists($moduleId, self::$versions);
        }

        public static function getVersion(string $moduleId)
        {
            if (self::$versionReadsUntilFailure !== null) {
                if (self::$versionReadsUntilFailure === 0) {
                    self::$versionReadsUntilFailure = null;
                    throw new \RuntimeException('Injected post-write catalog failure');
                }
                self::$versionReadsUntilFailure--;
            }

            return self::$versions[$moduleId] ?? false;
        }
    }
}

namespace {
    use Bitrix\Main\Config\Option;
    use Prospektweb\Calc\Services\ModuleCapabilityRegistryService;

    class CEventLog
    {
        public static array $events = [];
        public static bool $fail = false;

        public static function Add(array $event)
        {
            if (self::$fail) {
                throw new RuntimeException('event log unavailable');
            }
            self::$events[] = $event;
            return count(self::$events);
        }
    }

    require_once __DIR__ . '/../lib/Services/ModuleCapabilityRegistryService.php';

    $assert = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    };

    $findCapability = static function (array $catalog, string $capabilityId): array {
        foreach ($catalog['modules'] as $module) {
            foreach ($module['capabilities'] as $capability) {
                if ($capability['id'] === $capabilityId) {
                    return $capability;
                }
            }
        }
        throw new RuntimeException('Capability not found in fixture: ' . $capabilityId);
    };

    $service = new ModuleCapabilityRegistryService();
    $initial = $service->getCatalog();
    $repeat = $service->getCatalog();

    $assert($initial['contract'] === 'prospektweb.control-plane/catalog/v1', 'Catalog contract must be versioned');
    $assert(strlen((string)$initial['revision']) === 64, 'Catalog revision must be SHA-256');
    $assert($repeat['revision'] === $initial['revision'], 'Unchanged catalogs must have a stable revision');
    $assert(count($initial['modules']) === 6, 'Catalog must expose exactly six canonical modules');
    $assert($initial['summary']['capabilities'] === 14, 'Catalog capability summary must match the allowlist');
    $assert($initial['summary']['mutableCapabilities'] === 8, 'Provider-owned feature guards must be mutable');

    $moduleIds = array_column($initial['modules'], 'id');
    $assert(in_array('prospektweb.layoutfiles', $moduleIds, true), 'Canonical layoutfiles module ID must be used');
    $assert(!in_array('prospekt.layoutfiles', $moduleIds, true), 'Deprecated layoutfiles module ID must not be exposed');

    $propertyDescriptions = $findCapability($initial, 'storefront.property_descriptions');
    $companySuggestions = $findCapability($initial, 'storefront.checkout.company_suggestions');
    $calculator = $findCapability($initial, 'storefront.calculator');
    $mobileDescription = $findCapability($initial, 'mobile.catalog.section_description_expand');
    $massProperties = $findCapability($initial, 'admin.offers.mass_property_editor');
    $contactsGallery = $findCapability($initial, 'storefront.contacts.gallery');
    $assert($propertyDescriptions['enabled'] === true && $propertyDescriptions['mutable'] === true, 'Property descriptions must default to enabled and be mutable');
    $assert($companySuggestions['enabled'] === true && $companySuggestions['mutable'] === true, 'Company suggestions must default to enabled and be mutable');
    $assert($calculator['mutable'] === false && $calculator['state'] === 'managed-later', 'Ungarded calculator capability must be honestly marked for later management');
    $assert($mobileDescription['group'] === 'Мобильная версия' && strpos($mobileDescription['tooltip'], 'SEO-описания') !== false, 'Mobile description switch must expose its dedicated group and exact help');
    $assert($massProperties['mutable'] === true && $massProperties['enabled'] === true, 'Mass offer property editor must be guarded and enabled by default');
    $assert($contactsGallery['mutable'] === true && $contactsGallery['enabled'] === false, 'Contacts gallery must be manageable and disabled by default');

    $updated = $service->setCapability(
        'storefront.property_descriptions',
        false,
        (string)$initial['revision'],
        42
    );
    $assert(Option::$values['prospektweb.propvalmanager']['ENABLED'] === 'N', 'Property descriptions must write the provider-owned uppercase option');
    $assert($findCapability($updated, 'storefront.property_descriptions')['enabled'] === false, 'Set must return read-back effective state');
    $assert($updated['revision'] !== $initial['revision'], 'A capability change must advance the revision');
    $assert(count(CEventLog::$events) === 1, 'A capability change must be audited');
    $assert(CEventLog::$events[0]['AUDIT_TYPE_ID'] === 'PROSPEKTWEB_CONTROL_CENTER_CAPABILITY_CHANGED', 'Audit event type must be stable');
    $audit = json_decode((string)CEventLog::$events[0]['DESCRIPTION'], true);
    $assert(($audit['userId'] ?? null) === 42 && ($audit['afterEnabled'] ?? null) === false, 'Audit payload must identify the actor and resulting state');

    Bitrix\Main\ModuleManager::$versionReadsUntilFailure = count(Bitrix\Main\ModuleManager::$versions);
    try {
        $service->setCapability(
            'storefront.checkout.company_suggestions',
            false,
            (string)$updated['revision'],
            42
        );
        throw new RuntimeException('Post-write catalog failure was accepted');
    } catch (RuntimeException $exception) {
        $assert(strpos($exception->getMessage(), 'rolled back') !== false, 'Post-write catalog failure must report successful rollback');
    }
    $assert(Option::get('prospektweb.companyrequisites', 'enabled', 'Y') === 'Y', 'Post-write catalog failure must restore the previous option');
    $assert(count(CEventLog::$events) === 1, 'A rolled-back catalog refresh failure must not create an audit event');

    try {
        $service->setCapability('storefront.checkout.company_suggestions', false, (string)$initial['revision'], 42);
        throw new RuntimeException('Stale catalog revision was accepted');
    } catch (RuntimeException $exception) {
        $assert($exception->getCode() === 409, 'Stale capability revision must return conflict semantics');
    }

    try {
        $service->setCapability('storefront.calculator', false, (string)$updated['revision'], 42);
        throw new RuntimeException('Read-only capability was changed');
    } catch (InvalidArgumentException $exception) {
        $assert(strpos($exception->getMessage(), 'read-only') !== false, 'Read-only capability must return a validation error');
    }

    $final = $service->setCapability(
        'storefront.checkout.company_suggestions',
        false,
        (string)$updated['revision'],
        42
    );
    $assert(Option::$values['prospektweb.companyrequisites']['enabled'] === 'N', 'Company suggestions must write the provider-owned lowercase option');
    $assert($findCapability($final, 'storefront.checkout.company_suggestions')['enabled'] === false, 'Company suggestions read-back must match the write');
    $assert(count(CEventLog::$events) === 2, 'Each effective capability change must be audited exactly once');

    CEventLog::$fail = true;
    try {
        $service->setCapability('storefront.property_descriptions', true, (string)$final['revision'], 42);
        throw new RuntimeException('Unaudited capability change was accepted');
    } catch (RuntimeException $exception) {
        $assert($exception->getMessage() === 'Capability audit failed and option change was rolled back', 'Audit failures must be explicit');
        $assert(Option::$values['prospektweb.propvalmanager']['ENABLED'] === 'N', 'Audit failure must roll the provider option back');
    }

    echo "Module capability registry service tests passed\n";
}
