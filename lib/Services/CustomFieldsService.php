<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;

/**
 * Сервис для работы с кастомными полями калькуляторов
 * Преобразует данные из инфоблока CALC_CUSTOM_FIELDS в формат для фронтенда
 */
class CustomFieldsService
{
    /**
     * Получить конфигурацию полей для фронтенда
     *
     * @param array $fieldIds Массив ID элементов инфоблока CALC_CUSTOM_FIELDS
     * @return array Массив конфигураций полей для фронтенда
     */
    public function getFieldsConfig(array $fieldIds): array
    {
        if (empty($fieldIds)) {
            return [];
        }

        if (! Loader::includeModule('iblock')) {
            throw new \RuntimeException('Требуется модуль Bitrix iblock');
        }

        $result = [];
        
        // Загружаем элементы
        $rsElements = \CIBlockElement::GetList(
            ['SORT' => 'ASC'],
            [
                'ID' => $fieldIds,
                'ACTIVE' => 'Y',
            ],
            false,
            false,
            ['ID', 'NAME', 'IBLOCK_ID']
        );

        while ($element = $rsElements->Fetch()) {
            // Загружаем свойства элемента (все, без фильтрации)
            $rsProps = \CIBlockElement::GetProperty(
                $element['IBLOCK_ID'],
                $element['ID'],
                ['sort' => 'asc'],
                []
            );

            $props = [];
            while ($prop = $rsProps->Fetch()) {
                $code = $prop['CODE'];
                
                // Для списочных свойств (тип "L") используем VALUE_XML_ID
                // VALUE_XML_ID содержит символьный код варианта (number, text, checkbox, select)
                if ($prop['PROPERTY_TYPE'] === 'L') {
                    $props[$code] = $prop['VALUE_XML_ID'] ?? $prop['VALUE_ENUM'] ?? $prop['VALUE'];
                } else {
                    $props[$code] = $prop['VALUE'];
                }
            }

            // Получаем тип поля (теперь это будет "number", "text", и т.д., а не ID)
            $fieldType = $props['FIELD_TYPE'] ?? 'text';

            // Формируем конфигурацию поля
            $fieldConfig = [
                'code' => $props['FIELD_CODE'] ?? '',
                'name' => $element['NAME'],
                'type' => $fieldType,
                'required' => ($props['IS_REQUIRED'] ??  'N') === 'Y',
            ];

            // Добавляем default value с приведением типа
            if (! empty($props['DEFAULT_VALUE'])) {
                $fieldConfig['default'] = $this->castDefaultValue(
                    $props['DEFAULT_VALUE'],
                    $fieldConfig['type']
                );
            }

            // Добавляем параметры в зависимости от типа поля
            switch ($fieldConfig['type']) {
                case 'number':
                    if (!empty($props['UNIT'])) {
                        $fieldConfig['unit'] = $props['UNIT'];
                    }
                    if (isset($props['MIN_VALUE']) && $props['MIN_VALUE'] !== '') {
                        $fieldConfig['min'] = (float)$props['MIN_VALUE'];
                    }
                    if (isset($props['MAX_VALUE']) && $props['MAX_VALUE'] !== '') {
                        $fieldConfig['max'] = (float)$props['MAX_VALUE'];
                    }
                    if (isset($props['STEP_VALUE']) && $props['STEP_VALUE'] !== '') {
                        $fieldConfig['step'] = (float)$props['STEP_VALUE'];
                    }
                    break;

                case 'text':
                    if (isset($props['MAX_LENGTH']) && $props['MAX_LENGTH'] > 0) {
                        $fieldConfig['maxLength'] = (int)$props['MAX_LENGTH'];
                    }
                    break;

                case 'select':
                    // OPTIONS — множественное свойство с описанием
                    $options = [];
                    
                    $rsOptions = \CIBlockElement::GetProperty(
                        $element['IBLOCK_ID'],
                        $element['ID'],
                        ['sort' => 'asc'],
                        ['CODE' => 'OPTIONS']
                    );
                    
                    while ($option = $rsOptions->Fetch()) {
                        if (! empty($option['VALUE'])) {
                            $options[] = [
                                'value' => $option['VALUE'],
                                'label' => $option['DESCRIPTION'] ?: $option['VALUE'],
                            ];
                        }
                    }
                    
                    if (!empty($options)) {
                        $fieldConfig['options'] = $options;
                    }
                    break;

                case 'checkbox': 
                    // Для checkbox default должен быть boolean
                    if (isset($fieldConfig['default'])) {
                        $fieldConfig['default'] = (bool)$fieldConfig['default'];
                    }
                    break;
            }

            $result[] = $fieldConfig;
        }

        return $result;
    }

    /**
     * Приведение значения по умолчанию к нужному типу
     *
     * @param string|null $value Значение
     * @param string $type Тип поля (number, text, checkbox, select)
     * @return mixed Приведенное значение
     */
    public function castDefaultValue(? string $value, string $type)
    {
        if ($value === null || $value === '') {
            return null;
        }
        
        switch ($type) {
            case 'number': 
                return (float)$value;

            case 'checkbox':
                return in_array(strtolower($value), ['1', 'true', 'y', 'yes', 'да'], true);

            case 'text':
            case 'select': 
            default:
                return $value;
        }
    }
}
