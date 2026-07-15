<?php
namespace Prospektweb\LayoutFiles;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Sale;

class DesiredReceiveDateManager
{
    public const PROPERTY_CODE = 'PROSPEKT_DESIRED_RECEIVE_DATE';
    public const PROPERTY_NAME = 'Желаемая дата получения';
    private const SESSION_KEY = 'PROSPEKT_DESIRED_RECEIVE_DATE';

    public static function getCurrent(): array
    {
        $value = self::resolveCurrentValue();

        return [
            'value' => $value,
            'isoValue' => self::toPickerValue($value),
            'placeholder' => 'Желаемая дата получения',
            'constraints' => self::getConstraints(),
        ];
    }

    public static function getInitialDisplayValue(): string
    {
        try {
            return self::resolveCurrentValue();
        } catch (\Throwable $e) {
            return '';
        }
    }

    public static function set(string $rawValue): array
    {
        $value = self::normalize($rawValue);
        self::storeValue($value);
        self::syncBasket($value);
        return self::getCurrent();
    }

    public static function clear(): array
    {
        self::storeValue('');
        self::syncBasket('');
        return self::getCurrent();
    }

    public static function sync(): array
    {
        $value = self::normalizeStoredValue(self::getStoredValue());
        self::storeValue($value);
        self::syncBasket($value);
        return self::getCurrent();
    }

    public static function applyToItem(Sale\BasketItem $item): void
    {
        $value = self::getStoredValue();
        if ($value !== '') {
            self::setBasketItemProperty($item, $value);
        }
    }

    private static function getFuserId(): int
    {
        Loader::includeModule('sale');
        return (int)Sale\Fuser::getId();
    }

    private static function resolveCurrentValue(): string
    {
        $value = self::getStoredValue();
        if ($value === '') {
            $value = self::getValueFromBasket();
        }
        $value = self::normalizeStoredValue($value);
        self::storeValue($value);
        self::syncBasket($value);
        return $value;
    }

    private static function getSessionKey(): string
    {
        return self::SESSION_KEY . '_' . self::getFuserId();
    }

    private static function getStoredValue(): string
    {
        return trim((string)Application::getInstance()->getSession()->get(self::getSessionKey()));
    }

    private static function storeValue(string $value): void
    {
        $session = Application::getInstance()->getSession();
        if ($value === '') {
            $session->remove(self::getSessionKey());
        } else {
            $session->set(self::getSessionKey(), $value);
        }
    }

    private static function syncBasket(string $value): void
    {
        $basket = self::loadBasket();
        $changed = false;
        foreach ($basket as $item) {
            if ($item->isDelay() || !$item->canBuy()) {
                continue;
            }
            $changed = ($value === '' ? self::clearBasketItemProperty($item) : self::setBasketItemProperty($item, $value)) || $changed;
        }
        if ($changed) {
            $basket->save();
        }
    }

    private static function loadBasket(): Sale\Basket
    {
        if (!Loader::includeModule('sale')) {
            throw new \RuntimeException('Модуль sale не установлен.');
        }
        return Sale\Basket::loadItemsForFUser(self::getFuserId(), SITE_ID);
    }

    private static function getValueFromBasket(): string
    {
        try {
            $basket = self::loadBasket();
        } catch (\Throwable $e) {
            return '';
        }
        foreach ($basket as $item) {
            foreach ($item->getPropertyCollection() as $property) {
                if ($property->getField('CODE') === self::PROPERTY_CODE) {
                    return trim((string)$property->getField('VALUE'));
                }
            }
        }
        return '';
    }

    private static function getMaxProductionHours(): int
    {
        try {
            $basket = self::loadBasket();
        } catch (\Throwable $e) {
            return 0;
        }
        $max = 0;
        $propertyCode = Config::getDesiredReceiveProductionHoursPropertyCode();
        foreach ($basket as $item) {
            $itemHours = 0;
            foreach ($item->getPropertyCollection() as $property) {
                if ($property->getField('CODE') === $propertyCode) {
                    $itemHours = max($itemHours, (int)$property->getField('VALUE'));
                }
            }
            if ($itemHours <= 0) {
                $itemHours = self::getProductProductionHours((int)$item->getProductId(), $propertyCode);
            }
            $max = max($max, $itemHours);
        }
        return $max;
    }

    private static function getProductProductionHours(int $productId, string $propertyCode): int
    {
        if ($productId <= 0 || $propertyCode === '' || !Loader::includeModule('iblock') || !class_exists('CIBlockElement')) {
            return 0;
        }
        $element = \CIBlockElement::GetByID($productId)->Fetch();
        if (!is_array($element) || empty($element['IBLOCK_ID'])) {
            return 0;
        }
        $propertyIterator = \CIBlockElement::GetProperty((int)$element['IBLOCK_ID'], $productId, [], ['CODE' => $propertyCode]);
        $property = $propertyIterator ? $propertyIterator->Fetch() : false;
        return is_array($property) ? (int)($property['VALUE'] ?? 0) : 0;
    }

    private static function setBasketItemProperty(Sale\BasketItem $item, string $value): bool
    {
        $collection = $item->getPropertyCollection();
        foreach ($collection as $property) {
            if ($property->getField('CODE') === self::PROPERTY_CODE) {
                if ((string)$property->getField('VALUE') === $value && (string)$property->getField('NAME') === self::PROPERTY_NAME) {
                    return false;
                }
                $property->setFields(['NAME' => self::PROPERTY_NAME, 'VALUE' => $value]);
                return true;
            }
        }
        $collection->createItem()->setFields(['NAME' => self::PROPERTY_NAME, 'CODE' => self::PROPERTY_CODE, 'VALUE' => $value, 'SORT' => 510]);
        return true;
    }

    private static function clearBasketItemProperty(Sale\BasketItem $item): bool
    {
        $changed = false;
        foreach ($item->getPropertyCollection() as $property) {
            if ($property->getField('CODE') === self::PROPERTY_CODE && (string)$property->getField('VALUE') !== '') {
                $property->setFields(['NAME' => self::PROPERTY_NAME, 'VALUE' => '']);
                $changed = true;
            }
        }
        return $changed;
    }

    private static function normalize(string $rawValue): string
    {
        $rawValue = trim($rawValue);
        if ($rawValue === '') {
            return '';
        }
        [$timestamp, $dateOnly] = self::parseTimestamp($rawValue);
        if ($timestamp <= 0) {
            throw new \RuntimeException('Укажите корректную дату получения.');
        }
        $minTimestamp = self::getMinimumTimestamp();
        if ($dateOnly) {
            $timestamp = self::applyDefaultTimeToDate($timestamp, $minTimestamp);
        }
        self::validateTimestamp($timestamp, $minTimestamp);
        return date('d.m.Y H:i', $timestamp);
    }


    private static function normalizeStoredValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        [$timestamp] = self::parseTimestamp($value);
        if ($timestamp <= 0) {
            return '';
        }

        $minTimestamp = self::getMinimumTimestamp();
        if ($timestamp < $minTimestamp) {
            return date('d.m.Y H:i', $minTimestamp);
        }

        try {
            self::validateTimestamp($timestamp, $minTimestamp);
            return date('d.m.Y H:i', $timestamp);
        } catch (\Throwable $e) {
            return date('d.m.Y H:i', $minTimestamp);
        }
    }

    private static function parseTimestamp(string $value): array
    {
        foreach ([['Y-m-d\\TH:i', false], ['Y-m-d H:i', false], ['d.m.Y H:i', false], ['d.m.Y H:i:s', false], ['Y-m-d', true], ['d.m.Y', true]] as $format) {
            $date = \DateTime::createFromFormat($format[0], $value);
            if ($date instanceof \DateTime) {
                return [$date->getTimestamp(), $format[1]];
            }
        }
        return [strtotime($value) ?: 0, false];
    }

    private static function applyDefaultTimeToDate(int $timestamp, int $minTimestamp): int
    {
        $candidate = mktime(Config::getDesiredReceiveDefaultTimeHour(), Config::getDesiredReceiveDefaultTimeMinute(), 0, (int)date('m', $timestamp), (int)date('d', $timestamp), (int)date('Y', $timestamp));
        if (date('Y-m-d', $candidate) === date('Y-m-d', $minTimestamp) && $candidate < $minTimestamp) {
            return $minTimestamp;
        }
        return self::roundUpToStep($candidate);
    }

    private static function validateTimestamp(int $timestamp, ?int $minTimestamp = null): void
    {
        $minTimestamp = $minTimestamp ?? self::getMinimumTimestamp();
        if ($timestamp < $minTimestamp) {
            throw new \RuntimeException('Выберите дату не раньше ' . date('d.m.Y H:i', $minTimestamp) . '.');
        }
        if (!self::isWorkingDay($timestamp)) {
            throw new \RuntimeException('Выберите рабочий день.');
        }
        if (!self::isWorkingTime($timestamp)) {
            throw new \RuntimeException('Выберите время в рамках рабочего графика.');
        }
        $step = Config::getDesiredReceiveStepMinutes();
        if ($step > 1 && ((int)date('i', $timestamp)) % $step !== 0) {
            throw new \RuntimeException('Выберите время с шагом ' . $step . ' мин.');
        }
    }

    private static function getMinimumTimestamp(): int
    {
        $hours = max(Config::getDesiredReceiveMinHours(), self::getMaxProductionHours());
        return self::roundUpToStep(self::addWorkingHours(time(), $hours));
    }

    private static function addWorkingHours(int $timestamp, int $hours): int
    {
        $remaining = max(0, $hours) * 3600;
        $cursor = self::moveToWorkingTime($timestamp);
        while ($remaining > 0) {
            $end = mktime(Config::getDesiredReceiveWorkTimeToHour(), Config::getDesiredReceiveWorkTimeToMinute(), 0, (int)date('m', $cursor), (int)date('d', $cursor), (int)date('Y', $cursor));
            $available = max(0, $end - $cursor);
            if ($remaining <= $available) {
                return $cursor + $remaining;
            }
            $remaining -= $available;
            $cursor = self::nextWorkingDayStart($cursor);
        }
        return $cursor;
    }

    private static function moveToWorkingTime(int $timestamp): int
    {
        $cursor = $timestamp;
        while (!self::isWorkingDay($cursor)) {
            $cursor = self::nextWorkingDayStart($cursor);
        }
        $start = mktime(Config::getDesiredReceiveWorkTimeFromHour(), Config::getDesiredReceiveWorkTimeFromMinute(), 0, (int)date('m', $cursor), (int)date('d', $cursor), (int)date('Y', $cursor));
        $end = mktime(Config::getDesiredReceiveWorkTimeToHour(), Config::getDesiredReceiveWorkTimeToMinute(), 0, (int)date('m', $cursor), (int)date('d', $cursor), (int)date('Y', $cursor));
        if ($cursor < $start) {
            return $start;
        }
        if ($cursor >= $end) {
            return self::nextWorkingDayStart($cursor);
        }
        return $cursor;
    }

    private static function nextWorkingDayStart(int $timestamp): int
    {
        $cursor = strtotime(date('Y-m-d 00:00:00', $timestamp) . ' +1 day');
        while (!self::isWorkingDay($cursor)) {
            $cursor = strtotime(date('Y-m-d 00:00:00', $cursor) . ' +1 day');
        }
        return mktime(Config::getDesiredReceiveWorkTimeFromHour(), Config::getDesiredReceiveWorkTimeFromMinute(), 0, (int)date('m', $cursor), (int)date('d', $cursor), (int)date('Y', $cursor));
    }

    private static function isWorkingDay(int $timestamp): bool
    {
        return in_array((int)date('N', $timestamp), Config::getDesiredReceiveWorkdays(), true) && !in_array(date('d.m', $timestamp), Config::getDesiredReceiveHolidays(), true);
    }

    private static function isWorkingTime(int $timestamp): bool
    {
        $minutes = ((int)date('H', $timestamp)) * 60 + (int)date('i', $timestamp);
        $from = Config::getDesiredReceiveWorkTimeFromHour() * 60 + Config::getDesiredReceiveWorkTimeFromMinute();
        $to = Config::getDesiredReceiveWorkTimeToHour() * 60 + Config::getDesiredReceiveWorkTimeToMinute();
        return $minutes >= $from && $minutes <= $to;
    }

    private static function roundUpToStep(int $timestamp): int
    {
        $step = Config::getDesiredReceiveStepMinutes() * 60;
        if ($step <= 60) {
            return $timestamp;
        }
        return (int)(ceil($timestamp / $step) * $step);
    }

    private static function toPickerValue(string $value): string
    {
        $date = $value !== '' ? \DateTime::createFromFormat('d.m.Y H:i', $value) : false;
        return $date instanceof \DateTime ? $date->format('Y-m-d H:i') : '';
    }

    private static function getConstraints(): array
    {
        $minTimestamp = self::getMinimumTimestamp();
        return [
            'minDate' => date('Y-m-d H:i', $minTimestamp),
            'defaultTime' => Config::getDesiredReceiveDefaultTime(),
            'workdays' => Config::getDesiredReceiveWorkdays(),
            'timeFrom' => Config::getDesiredReceiveWorkTimeFrom(),
            'timeTo' => Config::getDesiredReceiveWorkTimeTo(),
            'stepMinutes' => Config::getDesiredReceiveStepMinutes(),
            'holidays' => Config::getDesiredReceiveHolidays(),
            'productionHours' => self::getMaxProductionHours(),
        ];
    }
}
