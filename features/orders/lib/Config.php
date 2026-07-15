<?php
namespace Prospektweb\LayoutFiles;

use Bitrix\Main\Config\Option;

class Config
{
    public const MODULE_ID = 'prospektweb.calc';
    public const DEFAULT_EXTENSIONS = 'pdf,ai,eps,cdr,psd,tif,tiff,jpg,jpeg,png,zip,rar,7z';
    public const DEFAULT_MAX_SIZE = 104857600;
    public const DEFAULT_TOOLTIP_TEXT = 'Прикрепите 1 графический файл (.pdf, .cdr, .tiff) или архив до 100 МБ. Если файл крупнее, укажите ссылку в комментарии к заказу или отправьте на mail@prospekt-print.ru (укажите номер заказа).';
    public const OAUTH_VERIFICATION_URI = 'https://oauth.yandex.ru/verification_code';
    public const DEFAULT_DESIRED_RECEIVE_TOOLTIP_TEXT = 'Дата ориентировочная. Точный график производства утвердим после проверки состава заказа. Любые изменения проводим только по согласованию с Вами.';
    public const DEFAULT_DESIRED_RECEIVE_HOLIDAYS = '01.01,02.01,05.01,06.01,07.01,08.01,09.01,23.02,09.03,01.05,11.05,12.06,04.11,31.12';
    public const DEFAULT_PRODUCTION_HOURS_PROPERTY_CODE = 'MIN_TIME_PRODUCTION_IN_WORK_HOURS';


    public static function getToken(): string { return trim((string)Option::get(self::MODULE_ID, 'yadisk_token', '')); }
    public static function setToken(string $token): void { Option::set(self::MODULE_ID, 'yadisk_token', trim($token)); }
    public static function getRefreshToken(): string { return trim((string)Option::get(self::MODULE_ID, 'yadisk_refresh_token', '')); }
    public static function setRefreshToken(string $token): void { Option::set(self::MODULE_ID, 'yadisk_refresh_token', trim($token)); }
    public static function clearTokens(): void { Option::delete(self::MODULE_ID, ['name' => 'yadisk_token']); Option::delete(self::MODULE_ID, ['name' => 'yadisk_refresh_token']); Option::delete(self::MODULE_ID, ['name' => 'yadisk_account']); Option::delete(self::MODULE_ID, ['name' => 'yadisk_connected_at']); }
    public static function getClientId(): string { return trim((string)Option::get(self::MODULE_ID, 'yadisk_client_id', '')); }
    public static function getClientSecret(): string { return trim((string)Option::get(self::MODULE_ID, 'yadisk_client_secret', '')); }
    public static function setConnectedAccount(string $account): void { Option::set(self::MODULE_ID, 'yadisk_account', $account); }
    public static function getConnectedAccount(): string { return trim((string)Option::get(self::MODULE_ID, 'yadisk_account', '')); }
    public static function setConnectedAt(string $date): void { Option::set(self::MODULE_ID, 'yadisk_connected_at', $date); }
    public static function getConnectedAt(): string { return trim((string)Option::get(self::MODULE_ID, 'yadisk_connected_at', '')); }
    public static function getBaseFolder(): string { return '/' . trim((string)Option::get(self::MODULE_ID, 'base_folder', '/'), '/'); }
    public static function getMaxSize(): int { return (int)Option::get(self::MODULE_ID, 'max_size', self::DEFAULT_MAX_SIZE); }
    public static function getTooltipText(): string { return trim((string)Option::get(self::MODULE_ID, 'tooltip_text', self::DEFAULT_TOOLTIP_TEXT)); }
    public static function getTempLifetimeHours(): int { return max(1, (int)Option::get(self::MODULE_ID, 'temp_lifetime_hours', 24)); }
    public static function getExtensions(): array { return array_values(array_filter(array_map('trim', explode(',', strtolower((string)Option::get(self::MODULE_ID, 'extensions', self::DEFAULT_EXTENSIONS)))))); }
    public static function isAllowedExtension(string $name): bool { return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::getExtensions(), true); }
    public static function getExtension(string $name): string { return strtolower(pathinfo($name, PATHINFO_EXTENSION)); }

    public static function getDesiredReceiveMinHours(): int { return max(0, (int)Option::get(self::MODULE_ID, 'desired_receive_min_hours', 4)); }
    public static function getDesiredReceiveWorkdays(): array { return array_values(array_filter(array_map('intval', explode(',', (string)Option::get(self::MODULE_ID, 'desired_receive_workdays', '1,2,3,4,5'))), static function ($day) { return $day >= 1 && $day <= 7; })); }
    public static function getDesiredReceiveWorkTimeFrom(): string { return self::normalizeTime((string)Option::get(self::MODULE_ID, 'desired_receive_time_from', '09:00'), '09:00'); }
    public static function getDesiredReceiveWorkTimeTo(): string { return self::normalizeTime((string)Option::get(self::MODULE_ID, 'desired_receive_time_to', '18:00'), '18:00'); }
    public static function getDesiredReceiveWorkTimeFromHour(): int { return (int)substr(self::getDesiredReceiveWorkTimeFrom(), 0, 2); }
    public static function getDesiredReceiveWorkTimeFromMinute(): int { return (int)substr(self::getDesiredReceiveWorkTimeFrom(), 3, 2); }
    public static function getDesiredReceiveWorkTimeToHour(): int { return (int)substr(self::getDesiredReceiveWorkTimeTo(), 0, 2); }
    public static function getDesiredReceiveWorkTimeToMinute(): int { return (int)substr(self::getDesiredReceiveWorkTimeTo(), 3, 2); }
    public static function getDesiredReceiveStepMinutes(): int { return max(1, (int)Option::get(self::MODULE_ID, 'desired_receive_step_minutes', 30)); }
    public static function getDesiredReceiveDefaultTime(): string { return self::normalizeTime((string)Option::get(self::MODULE_ID, 'desired_receive_default_time', '11:00'), '11:00'); }
    public static function getDesiredReceiveDefaultTimeHour(): int { return (int)substr(self::getDesiredReceiveDefaultTime(), 0, 2); }
    public static function getDesiredReceiveDefaultTimeMinute(): int { return (int)substr(self::getDesiredReceiveDefaultTime(), 3, 2); }
    public static function getDesiredReceiveTooltipText(): string { return trim((string)Option::get(self::MODULE_ID, 'desired_receive_tooltip_text', self::DEFAULT_DESIRED_RECEIVE_TOOLTIP_TEXT)); }
    public static function getDesiredReceiveProductionHoursPropertyCode(): string { $code = trim((string)Option::get(self::MODULE_ID, 'desired_receive_production_hours_property', self::DEFAULT_PRODUCTION_HOURS_PROPERTY_CODE)); return $code !== '' ? $code : self::DEFAULT_PRODUCTION_HOURS_PROPERTY_CODE; }
    public static function getHiddenBasketPropertyCodes(): array { return self::normalizeCodes((string)Option::get(self::MODULE_ID, 'hidden_basket_property_codes', '')); }
    public static function getDesiredReceiveHolidays(): array
    {
        $holidays = [];
        foreach (array_map('trim', explode(',', (string)Option::get(self::MODULE_ID, 'desired_receive_holidays', self::DEFAULT_DESIRED_RECEIVE_HOLIDAYS))) as $date) {
            if (preg_match('/^\d{2}\.\d{2}$/', $date)) {
                $holidays[] = $date;
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $holidays[] = substr($date, 8, 2) . '.' . substr($date, 5, 2);
            }
        }
        return array_values(array_unique($holidays));
    }
    private static function normalizeCodes(string $codes): array
    {
        return array_values(array_unique(array_filter(array_map(static function ($code) {
            return trim((string)$code);
        }, explode(',', $codes)))));
    }

    private static function normalizeTime(string $time, string $default): string { return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : $default; }
}
