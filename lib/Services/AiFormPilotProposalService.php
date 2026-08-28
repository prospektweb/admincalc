<?php

namespace Prospektweb\Calc\Services;

final class AiFormPilotProposalService
{
    public const PROPOSAL_SCHEMA = 'prospektweb.calc.ai-form-pilot-proposal/v1';
    private const LEVELS = ['simple', 'standard', 'professional'];
    private const FIELD_TYPES = ['number', 'select', 'checkbox', 'dimensions', 'datetime'];
    private const OPERATORS = ['equals', 'not_equals', 'greater', 'greater_or_equal', 'less', 'less_or_equal', 'empty', 'not_empty', 'in', 'not_in', 'contains', 'contains_any', 'contains_all'];

    public function sanitizeRequest(array $request): array
    {
        $this->assertExactKeys($request, ['level', 'wishes', 'calculatorName'], 'request');
        $level = trim((string)($request['level'] ?? ''));
        if (!in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException('Неизвестный уровень AI-пилота');
        }
        $wishes = trim((string)($request['wishes'] ?? ''));
        if ($wishes === '' || mb_strlen($wishes) > 12000) {
            throw new \InvalidArgumentException('Опишите пожелания к форме (не более 12000 символов)');
        }
        $calculatorName = trim((string)($request['calculatorName'] ?? ''));
        if ($calculatorName === '' || mb_strlen($calculatorName) > 250) {
            throw new \InvalidArgumentException('Название калькулятора недоступно');
        }
        return ['level' => $level, 'wishes' => $wishes, 'calculatorName' => $calculatorName];
    }

    public function buildSystemPrompt(array $cleanRequest): string
    {
        $level = (string)$cleanRequest['level'];
        $levelRules = [
            'simple' => 'Сделай компактную форму: 3-6 разделов и 6-12 пользовательских полей. Оставь только ключевые параметры заказа. Не добавляй процент заливки и узкие производственные параметры.',
            'standard' => 'Сделай рабочую форму: 5-9 разделов и 12-24 пользовательских поля. Для характера макета используй понятные варианты вроде «Текст», «Текст и графика», «Сплошная заливка». Добавь типовые материалы и послепечатные операции.',
            'professional' => 'Сделай подробную профессиональную форму: 7-12 разделов и 20-40 пользовательских полей. Продумай технические параметры до мелочей, включая числовой процент заливки 0-100 с шагом 1, если он влияет на выбранный вид продукции, а также материалы, печать и релевантную послепечатную обработку.',
        ][$level];
        $shape = [
            'schema' => self::PROPOSAL_SCHEMA,
            'level' => $level,
            'summary' => 'Краткое описание предложенной формы',
            'assumptions' => ['Явное допущение'],
            'volumePresets' => [10, 50, 100, 500, 1000],
            'volumeDefault' => 100,
            'sections' => [[
                'id' => 'product-parameters',
                'title' => 'Параметры продукции',
                'description' => 'Описание раздела для клиента',
                'displayMode' => 'block | accordion',
                'initiallyOpen' => true,
                'showTitle' => true,
                'visibleWhen' => null,
                'fields' => [[
                    'fieldId' => 'format',
                    'type' => 'number | select | checkbox | dimensions | datetime',
                    'multiple' => false,
                    'label' => 'Формат',
                    'help' => 'Понятная подсказка',
                    'publicVisible' => true,
                    'unit' => null,
                    'min' => null,
                    'max' => null,
                    'step' => null,
                    'required' => true,
                    'defaultValue' => 'a4',
                    'visibleWhen' => null,
                    'requiredWhen' => null,
                    'dependentFieldIds' => [],
                    'options' => [['id' => 'a4', 'label' => 'A4', 'help' => '210 x 297 мм']],
                    'dimensionInputs' => [],
                    'presetValues' => [],
                ]],
            ]],
        ];

        return "Ты проектируешь публичную форму полиграфического калькулятора. Верни ровно один JSON-объект без Markdown.\n"
            . "Создавай только разделы и пользовательские поля формы. Не создавай системные поля тиража, количества макетов, типа срока и даты готовности: они уже существуют.\n"
            . "Не добавляй Bitrix ID, названия или коды свойств Bitrix, товарные ID, presetId, versionId, sourcePath, формулы, цены, публикационные действия и команды сохранения. Все новые поля будут созданы без связи с Bitrix.\n"
            . "Предложи реалистичный отсортированный список востребованных положительных целых тиражей и один default из этого списка.\n"
            . "Поля должны иметь уникальные семантические ASCII-коды. Для select дай варианты и осмысленный default. Для number можно дать presetValues — числовые чипсы. Для dimensions дай минимум два dimensionInputs.\n"
            . "В каждом dimensionInputs используй только ключи id, label, unit, min, max, step: не используй fieldId и не дублируй там defaultValue. Начальные размеры задавай объектом defaultValue у самого поля dimensions.\n"
            . "Условия ссылаются на fieldId других предложенных полей и используют только операторы: " . implode(', ', self::OPERATORS) . ".\n"
            . "dependentFieldIds означает каскадную деактивацию: когда исходное поле не показывается или не используется, перечисленные зависимые поля тоже деактивируются. Не создавай циклы.\n"
            . "required=true используй только для действительно обязательного ввода. Для условной обязательности используй requiredWhen. Подсказки пиши по-русски и без внутренних терминов.\n"
            . $levelRules . "\n"
            . "Обязательная форма ответа; все указанные ключи должны присутствовать, а нерелевантные значения передавай как null, false или пустой массив:\n"
            . json_encode($shape, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public function parseProposal(string $content, string $expectedLevel): array
    {
        $json = trim($content);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/si', $json, $matches)) {
            $json = trim((string)$matches[1]);
        }
        $proposal = json_decode($json, true);
        if (!is_array($proposal) || array_values($proposal) === $proposal) {
            throw new \RuntimeException('AI вернул невалидный JSON формы');
        }
        return $this->validateProposal($proposal, $expectedLevel);
    }

    public function validateProposal(array $proposal, string $expectedLevel): array
    {
        $this->assertExactKeys($proposal, ['schema', 'level', 'summary', 'assumptions', 'volumePresets', 'volumeDefault', 'sections'], 'proposal');
        if (($proposal['schema'] ?? null) !== self::PROPOSAL_SCHEMA || ($proposal['level'] ?? null) !== $expectedLevel || !in_array($expectedLevel, self::LEVELS, true)) {
            throw new \RuntimeException('AI вернул неподдерживаемую схему или уровень формы');
        }
        $summary = $this->text($proposal['summary'] ?? '', 'summary', 2000, true);
        $assumptions = $this->textList($proposal['assumptions'] ?? null, 'assumptions', 12, 500);
        $volumePresets = $this->positiveIntegers($proposal['volumePresets'] ?? null, 'volumePresets', 3, 50);
        $volumePresets = array_values(array_unique($volumePresets));
        sort($volumePresets, SORT_NUMERIC);
        $volumeDefault = $proposal['volumeDefault'] ?? null;
        if (!is_int($volumeDefault) || $volumeDefault <= 0 || !in_array($volumeDefault, $volumePresets, true)) {
            throw new \RuntimeException('AI вернул недопустимый тираж по умолчанию');
        }
        $rawSections = $proposal['sections'] ?? null;
        if (!is_array($rawSections) || count($rawSections) < 1 || count($rawSections) > 20) {
            throw new \RuntimeException('AI вернул недопустимое количество разделов');
        }
        $sections = [];
        $sectionIds = [];
        $fieldIds = [];
        foreach ($rawSections as $sectionIndex => $section) {
            if (!is_array($section)) throw new \RuntimeException('AI вернул некорректный раздел');
            $section = $this->normalizeKeys(
                $section,
                ['id', 'title', 'fields'],
                ['description' => '', 'displayMode' => 'block', 'initiallyOpen' => true, 'showTitle' => true, 'visibleWhen' => null],
                'sections[' . $sectionIndex . ']'
            );
            $sectionId = $this->semanticId($section['id'] ?? null, 'sections[' . $sectionIndex . '].id');
            if ($sectionId === 'system' || isset($sectionIds[$sectionId])) throw new \RuntimeException('AI вернул повторный или системный код раздела');
            $sectionIds[$sectionId] = true;
            $displayMode = (string)($section['displayMode'] ?? '');
            if (!in_array($displayMode, ['block', 'accordion'], true) || !is_bool($section['initiallyOpen'] ?? null) || !is_bool($section['showTitle'] ?? null)) throw new \RuntimeException('AI вернул неверные настройки раздела');
            $rawFields = $section['fields'] ?? null;
            if (!is_array($rawFields) || count($rawFields) < 1 || count($rawFields) > 50) throw new \RuntimeException('AI вернул неверное количество полей раздела');
            $fields = [];
            foreach ($rawFields as $fieldIndex => $field) {
                $parsed = $this->validateField($field, 'sections[' . $sectionIndex . '].fields[' . $fieldIndex . ']');
                if (isset($fieldIds[$parsed['fieldId']])) throw new \RuntimeException('AI вернул повторный код поля ' . $parsed['fieldId']);
                $fieldIds[$parsed['fieldId']] = true;
                $fields[] = $parsed;
            }
            $sections[] = [
                'id' => $sectionId,
                'title' => $this->text($section['title'] ?? '', 'section.title', 200, true),
                'description' => $this->text($section['description'] ?? '', 'section.description', 2000),
                'displayMode' => $displayMode,
                'initiallyOpen' => (bool)$section['initiallyOpen'],
                'showTitle' => (bool)$section['showTitle'],
                'visibleWhen' => $this->validateCondition($section['visibleWhen'] ?? null, 'section.visibleWhen'),
                'fields' => $fields,
            ];
        }
        foreach ($sections as $section) {
            $this->assertConditionReferences($section['visibleWhen'], $fieldIds, 'section ' . $section['id']);
            foreach ($section['fields'] as $field) {
                $this->assertConditionReferences($field['visibleWhen'], $fieldIds, 'field ' . $field['fieldId']);
                $this->assertConditionReferences($field['requiredWhen'], $fieldIds, 'field ' . $field['fieldId']);
                foreach ($field['dependentFieldIds'] as $dependentId) {
                    if (!isset($fieldIds[$dependentId]) || $dependentId === $field['fieldId']) throw new \RuntimeException('AI вернул неверную каскадную связь поля ' . $field['fieldId']);
                }
            }
        }
        $this->assertAcyclicDependencies($sections);
        return ['schema' => self::PROPOSAL_SCHEMA, 'level' => $expectedLevel, 'summary' => $summary, 'assumptions' => $assumptions, 'volumePresets' => $volumePresets, 'volumeDefault' => $volumeDefault, 'sections' => $sections];
    }

    private function validateField($field, string $label): array
    {
        if (!is_array($field)) throw new \RuntimeException($label . ' не является объектом');
        $field = $this->normalizeKeys(
            $field,
            ['fieldId', 'type', 'label'],
            [
                'multiple' => false,
                'help' => '',
                'publicVisible' => true,
                'unit' => null,
                'min' => null,
                'max' => null,
                'step' => null,
                'required' => false,
                'defaultValue' => null,
                'visibleWhen' => null,
                'requiredWhen' => null,
                'dependentFieldIds' => [],
                'options' => [],
                'dimensionInputs' => [],
                'presetValues' => [],
            ],
            $label
        );
        $fieldId = $this->semanticId($field['fieldId'] ?? null, $label . '.fieldId');
        $type = (string)($field['type'] ?? '');
        if (!in_array($type, self::FIELD_TYPES, true) || !is_bool($field['multiple'] ?? null) || ($type !== 'select' && $field['multiple'])) throw new \RuntimeException($label . ': неверный тип или multiple');
        if (!is_bool($field['publicVisible'] ?? null) || !is_bool($field['required'] ?? null)) throw new \RuntimeException($label . ': неверные флаги');
        $unit = $field['unit'] ?? null;
        if ($unit !== null) $unit = $this->text($unit, $label . '.unit', 30);
        $min = $this->numberOrNull($field['min'] ?? null, $label . '.min');
        $max = $this->numberOrNull($field['max'] ?? null, $label . '.max');
        $step = $this->numberOrNull($field['step'] ?? null, $label . '.step');
        if ($min !== null && $max !== null && $min > $max) throw new \RuntimeException($label . ': минимум больше максимума');
        if ($step !== null && $step <= 0) throw new \RuntimeException($label . ': шаг должен быть положительным');
        $options = $this->validateOptions($field['options'] ?? null, $label . '.options');
        $dimensions = $this->validateDimensions($field['dimensionInputs'] ?? null, $label . '.dimensionInputs');
        $presets = $this->validatePresetValues($field['presetValues'] ?? null, $label . '.presetValues');
        if (($type === 'select') !== ($options !== [])) throw new \RuntimeException($label . ': варианты должны быть только у select и не могут быть пустыми');
        if (($type === 'dimensions' && count($dimensions) < 2) || ($type !== 'dimensions' && $dimensions !== [])) throw new \RuntimeException($label . ': неверные dimensionInputs');
        if ($type !== 'number' && $presets !== []) throw new \RuntimeException($label . ': числовые чипсы допустимы только для number');
        foreach ($presets as $preset) if (($min !== null && $preset['value'] < $min) || ($max !== null && $preset['value'] > $max)) throw new \RuntimeException($label . ': числовая чипса вне диапазона');
        $defaultValue = $this->validateDefaultValue($field['defaultValue'] ?? null, $type, (bool)$field['multiple'], $options, $dimensions, $min, $max, $label . '.defaultValue');
        $dependents = $field['dependentFieldIds'] ?? null;
        if (!is_array($dependents) || count($dependents) > 30) throw new \RuntimeException($label . ': неверные dependentFieldIds');
        $cleanDependents = [];
        foreach ($dependents as $index => $dependent) $cleanDependents[] = $this->semanticId($dependent, $label . '.dependentFieldIds[' . $index . ']');
        return [
            'fieldId' => $fieldId,
            'type' => $type,
            'multiple' => (bool)$field['multiple'],
            'label' => $this->text($field['label'] ?? '', $label . '.label', 200, true),
            'help' => $this->text($field['help'] ?? '', $label . '.help', 2000),
            'publicVisible' => (bool)$field['publicVisible'],
            'unit' => $unit,
            'min' => $min,
            'max' => $max,
            'step' => $step,
            'required' => (bool)$field['required'],
            'defaultValue' => $defaultValue,
            'visibleWhen' => $this->validateCondition($field['visibleWhen'] ?? null, $label . '.visibleWhen'),
            'requiredWhen' => $this->validateCondition($field['requiredWhen'] ?? null, $label . '.requiredWhen'),
            'dependentFieldIds' => array_values(array_unique($cleanDependents)),
            'options' => $options,
            'dimensionInputs' => $dimensions,
            'presetValues' => $presets,
        ];
    }

    private function validateCondition($value, string $label): ?array
    {
        if ($value === null) return null;
        if (!is_array($value)) throw new \RuntimeException($label . ' должен быть объектом или null');
        $value = $this->normalizeKeys($value, ['conditions'], ['mode' => 'all'], $label);
        if (!in_array($value['mode'] ?? null, ['all', 'any'], true) || !is_array($value['conditions'] ?? null) || count($value['conditions']) > 12) throw new \RuntimeException($label . ' содержит неверную группу');
        $conditions = [];
        foreach ($value['conditions'] as $index => $condition) {
            if (!is_array($condition)) throw new \RuntimeException($label . '.conditions содержит не объект');
            $condition = $this->normalizeKeys($condition, ['fieldId', 'operator'], ['values' => []], $label . '.conditions[' . $index . ']');
            $operator = (string)($condition['operator'] ?? '');
            if (!in_array($operator, self::OPERATORS, true) || !is_array($condition['values'] ?? null) || count($condition['values']) > 50) throw new \RuntimeException($label . ' содержит неверный оператор или значения');
            $values = [];
            foreach ($condition['values'] as $item) $values[] = $this->text($item, $label . '.value', 200);
            $conditions[] = ['fieldId' => $this->semanticId($condition['fieldId'] ?? null, $label . '.fieldId'), 'operator' => $operator, 'values' => $values];
        }
        return ['mode' => (string)$value['mode'], 'conditions' => $conditions];
    }

    private function validateOptions($value, string $label): array
    {
        if (!is_array($value) || count($value) > 100) throw new \RuntimeException($label . ' должен быть массивом');
        $result = []; $ids = [];
        foreach ($value as $index => $row) {
            if (!is_array($row)) throw new \RuntimeException($label . ' содержит не объект');
            $row = $this->normalizeKeys($row, ['id', 'label'], ['help' => ''], $label . '[' . $index . ']');
            $id = $this->semanticId($row['id'] ?? null, $label . '[' . $index . '].id');
            if (isset($ids[$id])) throw new \RuntimeException($label . ' содержит повторный id');
            $ids[$id] = true;
            $result[] = ['id' => $id, 'label' => $this->text($row['label'] ?? '', $label . '.label', 200, true), 'help' => $this->text($row['help'] ?? '', $label . '.help', 1000)];
        }
        return $result;
    }

    private function validateDimensions($value, string $label): array
    {
        if (!is_array($value) || count($value) > 12) throw new \RuntimeException($label . ' должен быть массивом');
        $result = []; $ids = [];
        foreach ($value as $index => $row) {
            if (!is_array($row)) throw new \RuntimeException($label . ' содержит не объект');
            // Models often reuse the outer field shape for a dimension row.
            // Accept only the two harmless, unambiguous aliases and discard the
            // redundant per-axis default; all other unknown keys still fail.
            if (!array_key_exists('id', $row) && array_key_exists('fieldId', $row)) {
                $row['id'] = $row['fieldId'];
                unset($row['fieldId']);
            }
            if (!array_key_exists('label', $row) && array_key_exists('name', $row)) {
                $row['label'] = $row['name'];
                unset($row['name']);
            }
            unset($row['defaultValue']);
            $row = $this->normalizeKeys($row, ['id', 'label'], ['unit' => '', 'min' => null, 'max' => null, 'step' => null], $label . '[' . $index . ']');
            $id = $this->semanticId($row['id'] ?? null, $label . '.id');
            if (isset($ids[$id])) throw new \RuntimeException($label . ' содержит повторный id');
            $ids[$id] = true;
            $result[] = ['id' => $id, 'label' => $this->text($row['label'] ?? '', $label . '.label', 100, true), 'unit' => $this->text($row['unit'] ?? '', $label . '.unit', 30), 'min' => $this->numberOrNull($row['min'] ?? null, $label . '.min'), 'max' => $this->numberOrNull($row['max'] ?? null, $label . '.max'), 'step' => $this->numberOrNull($row['step'] ?? null, $label . '.step')];
        }
        return $result;
    }

    private function validatePresetValues($value, string $label): array
    {
        if (!is_array($value) || count($value) > 100) throw new \RuntimeException($label . ' должен быть массивом');
        $result = []; $ids = [];
        foreach ($value as $index => $row) {
            if (!is_array($row)) throw new \RuntimeException($label . ' содержит не объект');
            $defaultLabel = is_int($row['value'] ?? null) || is_float($row['value'] ?? null) ? (string)$row['value'] : '';
            $row = $this->normalizeKeys($row, ['id', 'value'], ['label' => $defaultLabel], $label . '[' . $index . ']');
            $id = $this->semanticId($row['id'] ?? null, $label . '.id');
            if (isset($ids[$id]) || !is_int($row['value'] ?? null) && !is_float($row['value'] ?? null)) throw new \RuntimeException($label . ' содержит неверную чипсу');
            $ids[$id] = true;
            $result[] = ['id' => $id, 'label' => $this->text($row['label'] ?? '', $label . '.label', 100, true), 'value' => (float)$row['value']];
        }
        return $result;
    }

    private function validateDefaultValue($value, string $type, bool $multiple, array $options, array $dimensions, ?float $min, ?float $max, string $label)
    {
        if ($value === null) return null;
        if ($type === 'number') {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || ($min !== null && $value < $min) || ($max !== null && $value > $max)) throw new \RuntimeException($label . ' содержит неверное число');
            return $value;
        }
        if ($type === 'checkbox') {
            if (!is_bool($value)) throw new \RuntimeException($label . ' должен быть boolean');
            return $value;
        }
        if ($type === 'datetime') {
            if (!is_string($value)) throw new \RuntimeException($label . ' должен быть строкой');
            return $value;
        }
        if ($type === 'select') {
            $allowed = array_fill_keys(array_column($options, 'id'), true);
            $values = $multiple ? $value : [$value];
            if (!is_array($values)) throw new \RuntimeException($label . ' имеет неверный тип');
            foreach ($values as $item) if (!is_string($item) || !isset($allowed[$item])) throw new \RuntimeException($label . ' отсутствует среди вариантов');
            return $value;
        }
        if ($type === 'dimensions') {
            if (!is_array($value)) throw new \RuntimeException($label . ' должен быть объектом размеров');
            $allowed = array_fill_keys(array_column($dimensions, 'id'), true);
            foreach ($value as $key => $item) if (!isset($allowed[$key]) || (!is_int($item) && !is_float($item)) || !is_finite((float)$item)) throw new \RuntimeException($label . ' содержит неверный размер');
            return $value;
        }
        throw new \RuntimeException($label . ' имеет неизвестный тип');
    }

    private function assertConditionReferences(?array $group, array $fieldIds, string $label): void
    {
        if ($group === null) return;
        foreach ($group['conditions'] as $condition) if (!isset($fieldIds[$condition['fieldId']])) throw new \RuntimeException($label . ' ссылается на неизвестное поле ' . $condition['fieldId']);
    }

    private function assertAcyclicDependencies(array $sections): void
    {
        $graph = [];
        foreach ($sections as $section) foreach ($section['fields'] as $field) $graph[$field['fieldId']] = $field['dependentFieldIds'];
        $visiting = []; $visited = [];
        $walk = function (string $id) use (&$walk, &$graph, &$visiting, &$visited): void {
            if (isset($visited[$id])) return;
            if (isset($visiting[$id])) throw new \RuntimeException('AI вернул цикл каскадной деактивации');
            $visiting[$id] = true;
            foreach ($graph[$id] ?? [] as $next) $walk($next);
            unset($visiting[$id]); $visited[$id] = true;
        };
        foreach (array_keys($graph) as $id) $walk($id);
    }

    private function assertExactKeys(array $value, array $allowed, string $label): void
    {
        $keys = array_keys($value);
        sort($keys); $expected = $allowed; sort($expected);
        if ($keys !== $expected) throw new \RuntimeException($label . ' содержит неизвестные или отсутствующие поля');
    }

    private function normalizeKeys(array $value, array $required, array $defaults, string $label): array
    {
        $allowed = array_values(array_unique(array_merge($required, array_keys($defaults))));
        $unknown = array_diff(array_keys($value), $allowed);
        $missing = array_diff($required, array_keys($value));
        if ($unknown !== [] || $missing !== []) {
            throw new \RuntimeException($label . ' содержит неизвестные или отсутствующие обязательные поля');
        }
        return array_replace($defaults, $value);
    }

    private function semanticId($value, string $label): string
    {
        $id = trim((string)$value);
        if (mb_strlen($id) > 100 || !preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/', $id) || preg_match('/(?:^|[._-])(?:__proto__|prototype|constructor)(?:$|[._-])/', $id)) throw new \RuntimeException($label . ' содержит недопустимый код');
        return $id;
    }

    private function text($value, string $label, int $maxLength, bool $required = false): string
    {
        if (!is_string($value)) throw new \RuntimeException($label . ' должен быть строкой');
        $text = trim($value);
        if (($required && $text === '') || mb_strlen($text) > $maxLength) throw new \RuntimeException($label . ' не заполнен или слишком длинный');
        return $text;
    }

    private function textList($value, string $label, int $limit, int $maxLength): array
    {
        if (!is_array($value) || count($value) > $limit) throw new \RuntimeException($label . ' должен быть ограниченным массивом');
        $result = [];
        foreach ($value as $item) $result[] = $this->text($item, $label, $maxLength, true);
        return $result;
    }

    private function positiveIntegers($value, string $label, int $minCount, int $maxCount): array
    {
        if (!is_array($value) || count($value) < $minCount || count($value) > $maxCount) throw new \RuntimeException($label . ' содержит неверное количество значений');
        foreach ($value as $item) if (!is_int($item) || $item <= 0) throw new \RuntimeException($label . ' должен содержать положительные целые числа');
        return array_values($value);
    }

    private function numberOrNull($value, string $label): ?float
    {
        if ($value === null) return null;
        if (!is_int($value) && !is_float($value) || !is_finite((float)$value)) throw new \RuntimeException($label . ' должен быть числом или null');
        return (float)$value;
    }
}
