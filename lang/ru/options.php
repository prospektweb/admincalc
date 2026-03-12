<?php

$MESS['PROSPEKTWEB_CALC_MODULE_NOT_INSTALLED'] = 'Модуль "Калькулятор себестоимости" не установлен';
$MESS['PROSPEKTWEB_CALC_SETTINGS_SAVED'] = 'Настройки успешно сохранены';

$MESS['PROSPEKTWEB_CALC_TAB_MAIN'] = 'Основные';
$MESS['PROSPEKTWEB_CALC_TAB_MAIN_TITLE'] = 'Основные настройки модуля';
$MESS['PROSPEKTWEB_CALC_TAB_IBLOCKS'] = 'Инфоблоки';
$MESS['PROSPEKTWEB_CALC_TAB_IBLOCKS_TITLE'] = 'Инфоблоки модуля';

$MESS['PROSPEKTWEB_CALC_DEFAULT_PRICE_TYPE'] = 'Тип цены по умолчанию';
$MESS['PROSPEKTWEB_CALC_DEFAULT_CURRENCY'] = 'Валюта по умолчанию';
$MESS['PROSPEKTWEB_CALC_LOGGING_ENABLED'] = 'Включить логирование';
$MESS['PROSPEKTWEB_CALC_SAVE_CALC_HISTORY'] = 'Сохранять историю расчетов';
$MESS['PROSPEKTWEB_CALC_PRICE_ROUNDING'] = 'Округление цен составляющих элементов';
$MESS['PROSPEKTWEB_CALC_HISTORY_LIMIT'] = 'Макс. количество записей истории расчётов на 1 ТП';
$MESS['PROSPEKTWEB_CALC_DEFAULT_EXTRA_VALUE'] = 'Значение наценки по умолчанию';
$MESS['PROSPEKTWEB_CALC_DEFAULT_EXTRA_CURRENCY'] = 'Валюта наценки по умолчанию';

$MESS['PROSPEKTWEB_CALC_IBLOCK_PRESETS'] = 'Пресеты калькуляции';
$MESS['PROSPEKTWEB_CALC_IBLOCK_CALC_STAGES'] = 'Этапы';
$MESS['PROSPEKTWEB_CALC_IBLOCK_CALC_STAGES_VARIANTS'] = 'Варианты этапов';
$MESS['PROSPEKTWEB_CALC_IBLOCK_CALC_SETTINGS'] = 'Калькуляторы';
$MESS['PROSPEKTWEB_CALC_IBLOCK_MATERIALS'] = 'Материалы';
$MESS['PROSPEKTWEB_CALC_IBLOCK_MATERIALS_VARIANTS'] = 'Варианты материалов';
$MESS['PROSPEKTWEB_CALC_IBLOCK_OPERATIONS'] = 'Операции';
$MESS['PROSPEKTWEB_CALC_IBLOCK_OPERATIONS_VARIANTS'] = 'Варианты операций';
$MESS['PROSPEKTWEB_CALC_IBLOCK_EQUIPMENT'] = 'Оборудование';
$MESS['PROSPEKTWEB_CALC_IBLOCK_DETAILS'] = 'Детали';
$MESS['PROSPEKTWEB_CALC_IBLOCK_DETAILS_VARIANTS'] = 'Варианты деталей';
$MESS['PROSPEKTWEB_CALC_IBLOCK_NOT_CREATED'] = 'Не создан';

// Настройки интеграции с React-калькулятором
$MESS['PROSPEKTWEB_CALC_TAB_INTEGRATION'] = 'Интеграция';
$MESS['PROSPEKTWEB_CALC_TAB_INTEGRATION_TITLE'] = 'Настройки интеграции с React-калькулятором';
$MESS['PROSPEKTWEB_CALC_IBLOCK_MATERIALS_INTEGRATION'] = 'ID инфоблока материалов (для интеграции)';
$MESS['PROSPEKTWEB_CALC_IBLOCK_OPERATIONS_INTEGRATION'] = 'ID инфоблока операций (для интеграции)';
$MESS['PROSPEKTWEB_CALC_IBLOCK_EQUIPMENT_INTEGRATION'] = 'ID инфоблока оборудования (для интеграции)';
$MESS['PROSPEKTWEB_CALC_IBLOCK_DETAILS_INTEGRATION'] = 'ID инфоблока деталей (для интеграции)';
$MESS['PROSPEKTWEB_CALC_IBLOCK_CALCULATORS_INTEGRATION'] = 'ID инфоблока калькуляторов (для интеграции)';
$MESS['PROSPEKTWEB_CALC_IBLOCK_CONFIGURATIONS_INTEGRATION'] = 'ID инфоблока конфигураций (для интеграции)';

// Настройки сервера расчётов
$MESS['PROSPEKTWEB_CALC_CALC_SERVER_HEADING'] = 'Сервер расчётов (calc-server)';
$MESS['PROSPEKTWEB_CALC_CALC_SERVER_URL'] = 'URL сервера расчётов';
$MESS['PROSPEKTWEB_CALC_CALC_SERVER_URL_HINT'] = 'URL для обращения к calc-server (Node.js движок для серверных расчётов). Например: http://localhost:3100';


// Настройки связей торговых предложений
$MESS['PROSPEKTWEB_CALC_TAB_OFFERS'] = 'Связи ТП';
$MESS['PROSPEKTWEB_CALC_TAB_OFFERS_TITLE'] = 'Настройки связей торговых предложений с калькуляцией';
$MESS['PROSPEKTWEB_CALC_OFFERS_PROPERTIES_HEADING'] = 'Свойства торговых предложений для калькуляции';
$MESS['PROSPEKTWEB_CALC_FORMAT_FIELD_CODE'] = 'Код свойства "Формат"';
$MESS['PROSPEKTWEB_CALC_FORMAT_FIELD_CODE_HINT'] = 'Свойство типа "список" в ТП. XML_ID значений должен содержать WIDTH и LENGTH (например: "210x297")';
$MESS['PROSPEKTWEB_CALC_VOLUME_FIELD_CODE'] = 'Код свойства "Объём/Тираж"';
$MESS['PROSPEKTWEB_CALC_VOLUME_FIELD_CODE_HINT'] = 'Свойство типа "список" в ТП для определения тиража/объёма';
$MESS['PROSPEKTWEB_CALC_SELECT_PROPERTY'] = '-- Выберите свойство --';
$MESS['PROSPEKTWEB_CALC_NO_LIST_PROPERTIES'] = 'Свойства типа "список" не найдены в инфоблоке ТП. Укажите код вручную.';
$MESS['PROSPEKTWEB_CALC_SAVE'] = 'Сохранить';
$MESS['PROSPEKTWEB_CALC_RESET'] = 'Сбросить';


$MESS['PROSPEKTWEB_CALC_HISTORY_SERVICE_TITLE'] = 'Сервис истории расчётов';
$MESS['PROSPEKTWEB_CALC_HISTORY_SERVICE_ORPHANS'] = 'Очистка записей удалённых ТП';
$MESS['PROSPEKTWEB_CALC_HISTORY_SERVICE_ORPHANS_BTN'] = 'Удалить записи удалённых ТП';
$MESS['PROSPEKTWEB_CALC_HISTORY_SERVICE_ORPHANS_HINT'] = 'Удаляет только записи истории, связанные с торговыми предложениями, которых больше нет в системе.';
$MESS['PROSPEKTWEB_CALC_HISTORY_SERVICE_ORPHANS_CONFIRM'] = 'Удалить все записи истории, связанные с удалёнными ТП?';
$MESS['PROSPEKTWEB_CALC_HISTORY_SERVICE_ALL'] = 'Полная очистка истории';
$MESS['PROSPEKTWEB_CALC_HISTORY_SERVICE_ALL_BTN'] = 'Удалить всю историю';
$MESS['PROSPEKTWEB_CALC_HISTORY_SERVICE_ALL_HINT'] = 'Полностью удаляет всю историю расчётов и очищает ссылки COMPLETED_CALCS у ТП.';
$MESS['PROSPEKTWEB_CALC_HISTORY_SERVICE_ALL_CONFIRM'] = 'Удалить всю историю расчётов без возможности восстановления?';
$MESS['PROSPEKTWEB_CALC_HISTORY_CLEANUP_DONE'] = 'Сервисная очистка истории выполнена';
$MESS['PROSPEKTWEB_CALC_HISTORY_CLEANUP_DETAILS'] = 'Режим: %s<br>Проверено записей: %d<br>Удалено записей: %d<br>%s';

$MESS['PROSPEKTWEB_CALC_UNUSED_ELEMENTS_SERVICE'] = 'Сервисная очистка от незадействованных деталей / этапов';
$MESS['PROSPEKTWEB_CALC_UNUSED_ELEMENTS_SERVICE_BTN'] = 'Удалить незадействованные детали и этапы';
$MESS['PROSPEKTWEB_CALC_UNUSED_ELEMENTS_SERVICE_HINT'] = 'Анализирует все пресеты, собирает используемые детали/этапы и удаляет элементы, которые не используются ни в одном пресете.';
$MESS['PROSPEKTWEB_CALC_UNUSED_ELEMENTS_SERVICE_CONFIRM'] = 'Удалить все незадействованные детали и этапы? Операция необратима.';
$MESS['PROSPEKTWEB_CALC_UNUSED_ELEMENTS_CLEANUP_DONE'] = 'Сервисная очистка незадействованных деталей и этапов выполнена';
$MESS['PROSPEKTWEB_CALC_UNUSED_ELEMENTS_CLEANUP_DETAILS'] = 'Проверено деталей: %d<br>Удалено деталей: %d<br>Проверено этапов: %d<br>Удалено этапов: %d<br>%s';

$MESS['PROSPEKTWEB_CALC_TAB_DIAGNOSTIC'] = 'Диагностика';
$MESS['PROSPEKTWEB_CALC_TAB_DIAGNOSTIC_TITLE'] = 'Проверка целостности модуля';


$MESS['PROSPEKTWEB_CALC_TAB_MARKUPS'] = 'Наценки';
$MESS['PROSPEKTWEB_CALC_TAB_MARKUPS_TITLE'] = 'Настройки наценок для типов цен';
$MESS['PROSPEKTWEB_CALC_MARKUPS_EMPTY_PRICE_TYPES'] = 'Типы цен не найдены. Проверьте модуль catalog.';
$MESS['PROSPEKTWEB_CALC_MARKUPS_COL_PRICE_TYPE'] = 'Тип цены';
$MESS['PROSPEKTWEB_CALC_MARKUPS_COL_BASE'] = 'Стартовая цена';
$MESS['PROSPEKTWEB_CALC_MARKUPS_COL_RATE'] = 'Наценка';
$MESS['PROSPEKTWEB_CALC_MARKUPS_BASE_LABEL'] = 'Базовый тип';
$MESS['PROSPEKTWEB_CALC_MARKUPS_HINT'] = 'Для каждого типа цены задайте процент наценки относительно выбранного стартового типа цены.';
