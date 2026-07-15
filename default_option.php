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

    // Публичный калькулятор
    'PRODUCTS_IBLOCK_ID' => 0,
    'OFFERS_IBLOCK_ID' => 0,
    'CALC_PROPERTY_CODE' => 'FRONTCALC_CONFIG',
    'CALC_AJAX_URL' => '/local/ajax/frontcalc.php',
    'AREA_DISPLAY_UNIT' => 'mm2',
    'CALC_SERVER_TIMEOUT' => 10,
    'CALC_SERVER_BATCH_LIMIT' => 200,
    'CALC_SERVER_DEBUG_CONSOLE' => 'N',
    'SERVICE_OFFER_ID' => 0,
    'VOLUME_GRID_VALUES' => '1,2,3,4,5,10,15,20,30,40,50,100,150,200,300,400,500,1000,1500,2000,3000,4000,5000,10000,15000,20000,30000,40000,50000,100000,150000,200000',
    'VOLUME_GRID_TAIL_STEP' => 50000,

    // Описания значений свойств
    'ENABLED' => 'Y',
    'PROPERTY_DESCRIPTIONS_HL_BLOCK_ID' => 0,
    'PROPERTY_DESCRIPTIONS_JSON_PATH' => '',
    'PROPERTY_DESCRIPTIONS_JSON_VERSION' => '',

    // Макеты, корзина и желаемая дата
    'base_folder' => '/',
    'max_size' => 104857600,
    'extensions' => 'pdf,ai,eps,cdr,psd,tif,tiff,jpg,jpeg,png,zip,rar,7z',
    'temp_lifetime_hours' => 24,
    'desired_receive_min_hours' => 4,
    'desired_receive_workdays' => '1,2,3,4,5',
    'desired_receive_time_from' => '09:00',
    'desired_receive_time_to' => '18:00',
    'desired_receive_step_minutes' => 30,
    'desired_receive_default_time' => '11:00',
    'desired_receive_holidays' => '01.01,02.01,05.01,06.01,07.01,08.01,09.01,23.02,09.03,01.05,11.05,12.06,04.11,31.12',
    'desired_receive_production_hours_property' => 'MIN_TIME_PRODUCTION_IN_WORK_HOURS',
    'hidden_basket_property_codes' => '',
];
