<?php
/**
 * Настройки модуля prospektweb.calc по умолчанию
 */

$prospektweb_calc_default_option = [
    'DEFAULT_PRICE_TYPE_ID' => 1,
    'DEFAULT_CURRENCY' => 'RUB',
    'LOGGING_ENABLED' => 'N',
    'PRICE_ROUNDING' => 1,
    // Инфоблоки для интеграции с React-калькулятором
    'IBLOCK_MATERIALS' => 0,
    'IBLOCK_OPERATIONS' => 0,
    'IBLOCK_EQUIPMENT' => 0,
    'IBLOCK_DETAILS' => 0,
    'IBLOCK_CALCULATORS' => 0,
    'IBLOCK_CONFIGURATIONS' => 0,
    // Связи ТП
    'FORMAT_FIELD_CODE' => 'FORMAT',
    'VOLUME_FIELD_CODE' => 'VOLUME',
    // История расчётов
    'SAVE_CALC_HISTORY' => 'N',
    'CALC_HISTORY_LIMIT' => 10,
    // Сервер расчётов
    'CALC_SERVER_URL' => 'https://pwrt.ru/calc-api',
    // Настройки наценок
    'MARKUP_SETTINGS' => '{"basePriceTypeId":0,"rates":{}}',
];
