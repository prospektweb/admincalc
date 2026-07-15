<?php

namespace Prospektweb\Frontcalc\Service;

use Bitrix\Main\Config\Option;

class AccessScenarioResolver
{
    public const SCENARIO_RESTRICTED = 'restricted';
    public const SCENARIO_VERIFIED = 'verified';
    public const SCENARIO_EXTENDED = 'extended';
    public const DENIED_CODE = 'FRONTCALC_ACCESS_DENIED';
    public const DENIED_MESSAGE = 'Расширенные функции калькуляции доступны верифицированным пользователям';
    public const DEFAULT_MOBILE_DENIED_MESSAGE = 'Используйте декстоп-версию сайта для возможности расширенной калькуляции.';

    private const MODULE_ID = 'prospektweb.calc';
    private const OPTION_RESTRICTED_GROUPS = 'access_scenarios.restricted.group_ids';
    private const OPTION_VERIFIED_GROUPS = 'access_scenarios.verified.group_ids';
    private const OPTION_EXTENDED_GROUPS = 'access_scenarios.extended.group_ids';
    private const OPTION_RESTRICTED_MORE_URL = 'access_scenarios.restricted.more_url';
    private const OPTION_RESTRICTED_MOBILE_MESSAGE = 'access_scenarios.restricted.mobile_message';

    /** @var int[]|null */
    private $userGroups;
    /** @var bool|null */
    private $isAdmin;

    public function __construct(?array $userGroups = null, ?bool $isAdmin = null)
    {
        $this->userGroups = $userGroups === null ? null : self::normalizeGroupIds($userGroups);
        $this->isAdmin = $isAdmin;
    }

    public function getScenario(): string
    {
        if ($this->isCurrentUserAdmin()) {
            return self::SCENARIO_EXTENDED;
        }

        $userGroups = $this->getCurrentUserGroups();
        if ($this->intersects($userGroups, self::getExtendedGroupIds())) {
            return self::SCENARIO_EXTENDED;
        }
        if ($this->intersects($userGroups, self::getVerifiedGroupIds())) {
            return self::SCENARIO_VERIFIED;
        }

        return self::SCENARIO_RESTRICTED;
    }

    public function canOpenCalculator(): bool { return $this->getScenario() !== self::SCENARIO_RESTRICTED; }
    public function canCalculate(): bool { return $this->getScenario() !== self::SCENARIO_RESTRICTED; }
    public function canAddToBasket(): bool { return $this->getScenario() !== self::SCENARIO_RESTRICTED; }
    public function canViewInternalCalculationData(): bool { return $this->getScenario() === self::SCENARIO_EXTENDED; }

    public function getPermissions(): array
    {
        return [
            'can_open_calculator' => $this->canOpenCalculator(),
            'can_calculate' => $this->canCalculate(),
            'can_add_to_basket' => $this->canAddToBasket(),
            'can_view_internal_calculation_data' => $this->canViewInternalCalculationData(),
        ];
    }

    public function getPublicPayload(): array
    {
        return [
            'scenario' => $this->getScenario(),
            'permissions' => $this->getPermissions(),
            'restricted_message' => self::DENIED_MESSAGE,
            'restricted_mobile_message' => self::getRestrictedMobileMessage(),
            'restricted_more_url' => self::getRestrictedMoreUrl(),
        ];
    }

    public static function getRestrictedGroupIds(): array { return self::getGroupIdsOption(self::OPTION_RESTRICTED_GROUPS); }
    public static function getVerifiedGroupIds(): array { return self::getGroupIdsOption(self::OPTION_VERIFIED_GROUPS); }
    public static function getExtendedGroupIds(): array { return self::getGroupIdsOption(self::OPTION_EXTENDED_GROUPS); }
    public static function getRestrictedMoreUrl(): string { return trim((string)Option::get(self::MODULE_ID, self::OPTION_RESTRICTED_MORE_URL, '')); }
    public static function getRestrictedMobileMessage(): string
    {
        $value = trim((string)Option::get(self::MODULE_ID, self::OPTION_RESTRICTED_MOBILE_MESSAGE, self::DEFAULT_MOBILE_DENIED_MESSAGE));
        return $value !== '' ? $value : self::DEFAULT_MOBILE_DENIED_MESSAGE;
    }

    public static function normalizeGroupIds(array $groupIds): array
    {
        $normalized = [];
        foreach ($groupIds as $groupId) {
            $groupId = (int)$groupId;
            if ($groupId > 0) { $normalized[$groupId] = $groupId; }
        }
        return array_values($normalized);
    }

    private static function getGroupIdsOption(string $name): array
    {
        $raw = (string)Option::get(self::MODULE_ID, $name, '');
        $decoded = $raw !== '' ? json_decode($raw, true) : [];
        $rawGroupIds = is_array($decoded) ? $decoded : preg_split('/[,;\s]+/', $raw);
        return self::normalizeGroupIds(is_array($rawGroupIds) ? $rawGroupIds : []);
    }

    private function getCurrentUserGroups(): array
    {
        if ($this->userGroups !== null) { return $this->userGroups; }
        global $USER;
        if (is_object($USER) && method_exists($USER, 'GetUserGroupArray')) {
            $groups = $USER->GetUserGroupArray();
            if (is_array($groups)) { return self::normalizeGroupIds($groups); }
        }
        return [2];
    }

    private function isCurrentUserAdmin(): bool
    {
        if ($this->isAdmin !== null) { return $this->isAdmin; }
        global $USER;
        return is_object($USER) && method_exists($USER, 'IsAdmin') && $USER->IsAdmin();
    }

    private function intersects(array $left, array $right): bool
    {
        return !empty(array_intersect($left, $right));
    }
}
