<?php

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loc::loadMessages(__FILE__);

class CalcCustomFieldEditComponent extends CBitrixComponent
{
    protected $iblockId;
    protected $elementId;
    protected $element;
    protected $properties;
    protected $enumValues = []; // Варианты списков

    /**
     * Подготовка параметров компонента
     */
    public function onPrepareComponentParams($arParams)
    {
        $arParams['IBLOCK_ID'] = (int)($arParams['IBLOCK_ID'] ?? 0);
        $arParams['ELEMENT_ID'] = (int)($arParams['ELEMENT_ID'] ?? 0);
        $arParams['BACK_URL'] = $arParams['BACK_URL'] ?? '';

        return $arParams;
    }

    /**
     * Загрузка вариантов списков (для свойств типа L)
     */
    protected function loadEnumValues()
    {
        $this->enumValues = [];
        
        // Получаем свойства типа "Список" для данного инфоблока
        $rsProps = \CIBlockProperty::GetList(
            ['SORT' => 'ASC'],
            ['IBLOCK_ID' => $this->iblockId, 'PROPERTY_TYPE' => 'L', 'ACTIVE' => 'Y']
        );
        
        while ($prop = $rsProps->Fetch()) {
            $propCode = $prop['CODE'];
            $this->enumValues[$propCode] = [];
            
            // Получаем варианты для этого свойства
            $rsEnum = \CIBlockPropertyEnum:: GetList(
                ['SORT' => 'ASC'],
                ['PROPERTY_ID' => $prop['ID']]
            );
            
            while ($enum = $rsEnum->Fetch()) {
                $this->enumValues[$propCode][$enum['XML_ID']] = [
                    'ID' => $enum['ID'],
                    'VALUE' => $enum['VALUE'],
                    'XML_ID' => $enum['XML_ID'],
                ];
            }
        }
    }

    /**
     * Получить ID варианта списка по XML_ID
     */
    protected function getEnumIdByXmlId($propCode, $xmlId)
    {
        if (isset($this->enumValues[$propCode][$xmlId])) {
            return $this->enumValues[$propCode][$xmlId]['ID'];
        }
        return null;
    }

    /**
     * Загрузка данных элемента
     */
    protected function loadElement()
    {
        if ($this->elementId <= 0) {
            return true;
        }

        // Загружаем основные поля элемента
        $rsElement = CIBlockElement::GetByID($this->elementId);
        if (!$rsElement) {
            $this->arResult['ERRORS'][] = 'Ошибка загрузки элемента';
            return false;
        }

        $this->element = $rsElement->Fetch();

        if (!$this->element) {
            $this->arResult['ERRORS'][] = 'Элемент не найден';
            return false;
        }

        // Загружаем свойства через GetProperty
        $rsProps = CIBlockElement::GetProperty(
            $this->iblockId,
            $this->elementId,
            ['SORT' => 'ASC'],
            []
        );

        $this->properties = [];
        while ($prop = $rsProps->Fetch()) {
            $code = $prop['CODE'];

            // Для множественных свойств собираем в массив
            if ($prop['MULTIPLE'] === 'Y') {
                if (! isset($this->properties[$code])) {
                    $this->properties[$code] = [
                        'CODE' => $code,
                        'PROPERTY_TYPE' => $prop['PROPERTY_TYPE'],
                        'MULTIPLE' => 'Y',
                        'VALUES' => [],
                    ];
                }
                if (!empty($prop['VALUE']) || !empty($prop['DESCRIPTION'])) {
                    $this->properties[$code]['VALUES'][] = [
                        'VALUE' => $prop['VALUE'],
                        'DESCRIPTION' => $prop['DESCRIPTION'],
                    ];
                }
            } else {
                // Для одиночных свойств
                $this->properties[$code] = [
                    'CODE' => $code,
                    'PROPERTY_TYPE' => $prop['PROPERTY_TYPE'],
                    'MULTIPLE' => 'N',
                    'VALUE' => $prop['VALUE'],
                    'VALUE_XML_ID' => $prop['VALUE_XML_ID'] ?? null,
                    'VALUE_ENUM_ID' => $prop['VALUE_ENUM_ID'] ?? null,
                    'DESCRIPTION' => $prop['DESCRIPTION'] ?? null,
                ];
            }
        }

        return true;
    }

    /**
     * Сохранение элемента
     */
    protected function saveElement()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return false;
        }

        if (!check_bitrix_sessid()) {
            $this->arResult['ERRORS'][] = 'Ошибка безопасности.  Обновите страницу и попробуйте снова.';
            return false;
        }

        // Проверяем, нажата ли кнопка сохранения
        if (empty($_POST['save']) && empty($_POST['apply'])) {
            return false;
        }

        $el = new CIBlockElement();

        // Основные поля
        $arFields = [
            'IBLOCK_ID' => $this->iblockId,
            'NAME' => trim($_POST['NAME'] ?? ''),
            'ACTIVE' => ! empty($_POST['ACTIVE']) ? 'Y' : 'N',
        ];

        // Валидация названия
        if (empty($arFields['NAME'])) {
            $this->arResult['ERRORS'][] = 'Не указано название поля';
            return false;
        }

        // Получаем значения свойств
        $propValues = $_POST['PROPERTY_VALUES'] ?? [];

        // Валидация символьного кода
        $fieldCode = trim($propValues['FIELD_CODE'] ??  '');
        if (empty($fieldCode)) {
            $this->arResult['ERRORS'][] = 'Не указан символьный код поля';
            return false;
        }
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $fieldCode)) {
            $this->arResult['ERRORS'][] = 'Символьный код должен начинаться с буквы и содержать только заглавные латинские буквы, цифры и подчёркивание';
            return false;
        }

        // Валидация типа поля
        $fieldTypeXmlId = $propValues['FIELD_TYPE'] ?? '';
        $allowedTypes = ['number', 'text', 'checkbox', 'select'];
        if (empty($fieldTypeXmlId) || !in_array($fieldTypeXmlId, $allowedTypes)) {
            $this->arResult['ERRORS'][] = 'Не выбран тип поля';
            return false;
        }

        // Получаем ID варианта списка для FIELD_TYPE
        $fieldTypeEnumId = $this->getEnumIdByXmlId('FIELD_TYPE', $fieldTypeXmlId);
        if (!$fieldTypeEnumId) {
            $this->arResult['ERRORS'][] = 'Ошибка:  не найден вариант типа поля "' . $fieldTypeXmlId .  '"';
            return false;
        }

        // Получаем ID варианта списка для IS_REQUIRED
        $isRequiredXmlId = ! empty($propValues['IS_REQUIRED']) ? 'Y' : 'N';
        $isRequiredEnumId = $this->getEnumIdByXmlId('IS_REQUIRED', $isRequiredXmlId);

        // Обработка значения по умолчанию
        $defaultValue = '';
        if ($fieldTypeXmlId === 'checkbox') {
            $defaultValue = ! empty($propValues['DEFAULT_VALUE']) ? 'Y' : 'N';
        } elseif ($fieldTypeXmlId === 'select') {
            // Для select берём из DEFAULT_OPTION (radio)
            $defaultValue = $_POST['DEFAULT_OPTION'] ??  '';
        } elseif ($fieldTypeXmlId === 'number') {
            // Для number берём из отдельного поля
            $defaultValue = trim($propValues['DEFAULT_VALUE_NUMBER'] ??  '');
        } elseif ($fieldTypeXmlId === 'text') {
            // Для text берём из отдельного поля
            $defaultValue = trim($propValues['DEFAULT_VALUE_TEXT'] ?? '');
        }

        // Обработка OPTIONS для select
        $optionsValues = [];
        if ($fieldTypeXmlId === 'select' && !empty($propValues['OPTIONS'])) {
            foreach ($propValues['OPTIONS'] as $opt) {
                $optValue = trim($opt['VALUE'] ??  '');
                $optDesc = trim($opt['DESCRIPTION'] ?? '');
                if (!empty($optValue) || !empty($optDesc)) {
                    $optionsValues[] = [
                        'VALUE' => $optValue,
                        'DESCRIPTION' => $optDesc,
                    ];
                }
            }
        }

        // Формируем массив свойств
        // ВАЖНО:  для свойств типа L передаём ID варианта (VALUE_ENUM_ID)
        $arFields['PROPERTY_VALUES'] = [
            'FIELD_CODE' => $fieldCode,
            'FIELD_TYPE' => $fieldTypeEnumId, // ID варианта списка! 
            'DEFAULT_VALUE' => $defaultValue,
            'IS_REQUIRED' => $isRequiredEnumId, // ID варианта списка! 
            'UNIT' => $fieldTypeXmlId === 'number' ? trim($propValues['UNIT'] ??  '') : '',
            'MIN_VALUE' => $fieldTypeXmlId === 'number' ?  $propValues['MIN_VALUE'] :  '',
            'MAX_VALUE' => $fieldTypeXmlId === 'number' ? $propValues['MAX_VALUE'] : '',
            'STEP_VALUE' => $fieldTypeXmlId === 'number' ? $propValues['STEP_VALUE'] : '',
            'MAX_LENGTH' => $fieldTypeXmlId === 'text' ? $propValues['MAX_LENGTH'] : '',
            'OPTIONS' => $optionsValues,
            'SORT_ORDER' => (int)($propValues['SORT_ORDER'] ?? 500),
        ];

        // Создание или обновление
        if ($this->elementId > 0) {
            $success = $el->Update($this->elementId, $arFields);
        } else {
            $this->elementId = $el->Add($arFields);
            $success = $this->elementId > 0;
        }

        if (! $success) {
            $this->arResult['ERRORS'][] = 'Ошибка сохранения:  ' . $el->LAST_ERROR;
            return false;
        }

        // Редирект после сохранения
        if (! empty($_POST['save'])) {
            // Кнопка "Сохранить" — возврат к списку
            $backUrl = $this->arParams['BACK_URL'] ?: '/bitrix/admin/iblock_list_admin.php? IBLOCK_ID=' . $this->iblockId .  '&type=calculator&lang=' .  LANGUAGE_ID;
            LocalRedirect($backUrl);
        } else {
            // Кнопка "Применить" — остаёмся на странице
            $this->arResult['SUCCESS_MESSAGE'] = 'Изменения сохранены';
            // Перезагружаем элемент
            $this->loadElement();
        }

        return true;
    }

    /**
     * Выполнение компонента
     */
    public function executeComponent()
    {
        if (! Loader::includeModule('iblock')) {
            $this->arResult['ERRORS'][] = 'Модуль iblock не установлен';
            $this->includeComponentTemplate();
            return;
        }

        $this->iblockId = $this->arParams['IBLOCK_ID'];
        $this->elementId = $this->arParams['ELEMENT_ID'];

        if ($this->iblockId <= 0) {
            $this->arResult['ERRORS'][] = 'Не указан ID инфоблока';
            $this->includeComponentTemplate();
            return;
        }

        $this->arResult['ERRORS'] = [];
        $this->arResult['IS_NEW'] = ($this->elementId <= 0);

        // Загружаем варианты списков (нужно для сохранения)
        $this->loadEnumValues();

        // Сначала пытаемся сохранить
        $this->saveElement();

        // Затем загружаем данные
        if (empty($this->arResult['SUCCESS_MESSAGE'])) {
            $this->loadElement();
        }

        // Передаём данные в шаблон
        $this->arResult['ELEMENT'] = $this->element ??  [];
        $this->arResult['PROPERTIES'] = $this->properties ?? [];
        $this->arResult['ENUM_VALUES'] = $this->enumValues;

        $this->includeComponentTemplate();
    }
}
