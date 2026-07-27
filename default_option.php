<?php
/**
 * Настройки модуля prospektweb.calc по умолчанию
 */

$prospektweb_calc_default_option = [
    'LOGGING_ENABLED' => 'N',
    'PRICE_ROUNDING' => 1,
    // История расчётов
    'SAVE_CALC_HISTORY' => 'N',
    'CALC_HISTORY_LIMIT' => 10,
    // Сервер расчётов
    'CALC_SERVER_URL' => 'https://pwrt.ru/calc-api',
    // Настройки наценок
    'MARKUP_SETTINGS' => '{"basePriceTypeId":0,"rates":{}}',
];
