<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

/** Address adapter only. Conditions and constraints are evaluated by FrontCalc. */
final class DocumentBatchForm
{
    public static function canonical(mixed $value): string
    {
        $sort = static function($v) use (&$sort) {
            if (is_object($v)) { $v = get_object_vars($v); ksort($v); return (object)array_map($sort, $v); }
            if (is_array($v)) { if (!array_is_list($v)) ksort($v); return array_map($sort, $v); }
            return $v;
        };
        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function controllers(array $runtime, string $view): array
    {
        $views = array_column($runtime['storefronts'], null, 'id');
        if (!isset($views[$view])) throw new \InvalidArgumentException('Витрина недоступна.');
        $schema = $views[$view]['runtimeSchema']; $codes = []; $result = [];
        foreach ($runtime['bindingDefinition']['bindings'] as $binding) {
            if (isset($binding['target']['propertyCode'])) $codes[$binding['target']['propertyCode']] = $binding['fieldId'];
        }
        $walk = static function($node) use (&$walk, &$result, $codes) {
            if (is_object($node)) $node = (array)$node;
            if (!is_array($node)) return;
            if (isset($node['operator'], $node['property_code'], $codes[$node['property_code']])) $result[$codes[$node['property_code']]] = true;
            foreach ($node as $item) $walk($item);
        };
        $walk($schema);
        $hasAreaConstraints = count(array_filter($schema['fields'], fn($f) => !empty($f['area_ranges']))) > 0;
        foreach ($schema['fields'] as $field) {
            if ((!empty($field['dependent_property_codes']) || ($hasAreaConstraints && ($field['use_for_area_dependency'] ?? false))) && isset($codes[$field['property_code']])) $result[$codes[$field['property_code']]] = true;
        }
        return array_keys($result);
    }
    public static function validate(array $runtime, string $view, object $values, object $activation, object $execution): array
    {
        $form = $runtime['formDefinition']; $bindings = array_column($runtime['bindingDefinition']['bindings'], null, 'fieldId');
        $known = [...array_column($form['fields'], 'fieldId'), ...array_map(fn($s) => 'section:' . $s['id'], $form['sections'])];
        foreach ($values as $id => $_) if (!in_array($id, $known, true)) throw new \InvalidArgumentException('Неизвестный вход формы: ' . $id);
        foreach ($activation as $id => $active) if (!in_array($id, array_column($form['sections'], 'id'), true) || !is_bool($active)) throw new \InvalidArgumentException('Некорректное включение раздела.');
        $views = array_column($runtime['storefronts'], null, 'id');
        if (!isset($views[$view])) throw new \InvalidArgumentException('Витрина недоступна.');
        $schema = $views[$view]['runtimeSchema'];
        // Native editor projections carry no published storefront identity.
        // Drop only that envelope; all projected conditions/defaults stay intact.
        unset($schema['_storefront']);
        $selection = []; $codes = []; $labels = [];
        foreach ($form['fields'] as $field) {
            $id = $field['fieldId']; $binding = $bindings[$id];
            $code = $binding['target']['propertyCode'] ?? 'CALC_PROP_FORM_' . strtoupper(str_replace(['.', '-'], '_', $id));
            $codes[$id] = $code; $labels[$code] = $field['label']; $value = $values->{$id} ?? '';
            $configIndex = array_search($code, array_column($schema['fields'], 'property_code'), true);
            if ($configIndex === false && isset($field['systemKey'])) {
                $system = $views[$view]['systemFields'][$field['systemKey']] ?? $field;
                $schema['fields'][] = ['property_code' => $code, 'name' => $system['label'] ?? $field['label'], 'selection_mode' => 'single', 'required' => $system['required'] ?? $field['required'],
                    'options' => array_map(fn($o) => ['xml_id' => $o['id'], 'label' => $o['label']], $system['options'] ?? $field['options']),
                    'inputs' => $field['type'] === 'number' ? [['code' => 'value', 'min' => $system['min'] ?? null, 'max' => $system['max'] ?? null, 'step' => $system['step'] ?? null]] : []];
                $configIndex = count($schema['fields']) - 1;
            }
            if ($configIndex === false) continue;
            $config = $schema['fields'][$configIndex];
            if ($field['type'] === 'dimensions') {
                if (!is_object($value)) throw new \InvalidArgumentException($field['label'] . ': требуется цельный формат.');
                $parts = [];
                foreach ($config['inputs'] ?? [] as $index => $input) {
                    $key = array_search($input['code'], (array)($binding['inputMap'] ?? []), true);
                    $parts[] = $value->{$key ?: $field['dimensionInputs'][$index]['id']} ?? '';
                }
                $selection[$code] = count(array_filter($parts, fn($part) => $part !== '')) ? implode($config['group_delimiter'] ?? 'x', $parts) : '';
            } elseif ($field['type'] === 'checkbox') {
                if (!is_bool($value)) throw new \InvalidArgumentException($field['label'] . ': требуется boolean.');
                $selection[$code] = $value ? 'Y' : 'N';
            } elseif ($field['type'] === 'select') {
                $map = (array)($binding['optionMap'] ?? []);
                $convert = static fn($v) => $map[(string)$v] ?? (string)$v;
                $selection[$code] = is_array($value) ? array_map($convert, $value) : $convert($value);
            } else $selection[$code] = $value;
        }
        foreach ($form['sections'] as $section) if (($section['userActivatable'] ?? false) && ($activation->{$section['id']} ?? false) !== true) {
            foreach ($schema['fields'] as &$config) if (in_array($config['property_code'], array_map(fn($id) => $codes[$id], $section['fieldIds']), true)) $config['hidden'] = true;
            unset($config);
        }
        $resolved = (new \Prospektweb\Frontcalc\Service\CalculatorConditionResolver())->reconcile($schema, $selection);
        foreach ($resolved['changes'] as $change) if ($change['type'] === 'hidden_removed') {
            $fieldId = array_search($change['field'], $codes, true);
            $field = $form['fields'][array_search($fieldId, array_column($form['fields'], 'fieldId'), true)];
            if ($field['type'] === 'checkbox' && ($values->{$fieldId} ?? null) === false) continue;
            throw new \InvalidArgumentException(($labels[$change['field']] ?? $change['field']) . ': поле скрыто условиями формы.');
        }
        if (!$resolved['ready']) {
            $error = $resolved['errors'][0];
            throw new \InvalidArgumentException(($labels[$error['field'] ?? ''] ?? 'Форма') . ': ' . ($error['code'] ?? 'недопустимое значение'));
        }
        foreach ($resolved['visible_fields'] as $code) {
            $before = $selection[$code] ?? ''; $after = $resolved['selection'][$code] ?? '';
            $config = $schema['fields'][array_search($code, array_column($schema['fields'], 'property_code'), true)];
            foreach ([...($config['presets'] ?? []), ...($config['values'] ?? [])] as $preset) if (($preset['xml_id'] ?? null) === $after && isset($preset['value'])) $after = $preset['value'];
            $text = static fn($v) => is_array($v) ? array_map('strval', $v) : (string)$v;
            if ($text($before) !== $text($after)) throw new \InvalidArgumentException(($labels[$code] ?? $code) . ': выбранное значение недопустимо в текущей форме.');
        }
        foreach (['unitCount', 'layoutCount', 'runCount'] as $key) if (!is_int($execution->{$key} ?? null) || $execution->{$key} < 1 || $execution->{$key} > 999999999) throw new \InvalidArgumentException('Некорректное количество: ' . $key);
        if (!in_array($execution->deadlineType ?? '', ['strict', 'urgent', 'flexible'], true)) throw new \InvalidArgumentException('Выберите тип срока.');
        foreach ($form['fields'] as $field) {
            $key = ['volume' => 'unitCount', 'layoutCount' => 'layoutCount', 'deadlineType' => 'deadlineType'][$field['systemKey'] ?? ''] ?? null;
            if ($key !== null && ($values->{$field['fieldId']} ?? null) !== $execution->{$key}) throw new \InvalidArgumentException('Количество или срок не соответствует вводу формы.');
        }
        $sections = \Prospektweb\Frontcalc\Service\FormSectionState::values($form['sections'], (array)$activation, $resolved['selection']);
        foreach ($sections as $key => $active) {
            if (($values->{$key} ?? null) !== $active) throw new \InvalidArgumentException('Состояние раздела не соответствует форме: ' . $key);
        }
        $shape = [];
        foreach ($form['fields'] as $field) {
            $code = $codes[$field['fieldId']]; $options = $resolved['allowed_options'][$code] ?? null;
            if (is_array($options)) sort($options, SORT_STRING);
            $shape[$field['fieldId']] = ['type' => $field['type'], 'multiple' => $field['multiple'] ?? false, 'unit' => $field['unit'] ?? null,
                'visible' => in_array($code, $resolved['visible_fields'], true), 'required' => in_array($code, $resolved['required_fields'], true),
                'options' => $options, 'constraints' => $resolved['effective_constraints'][$code] ?? []];
        }
        $resolver = new \Prospektweb\Frontcalc\Service\CalculatorConditionResolver();
        foreach ($form['sections'] as $section) {
            $when = $section['visible_when'] ?? $section['visibleWhen'] ?? null;
            $visible = !is_array($when) || $resolver->evaluate($when, $resolved['selection'], static fn($code) => array_key_exists($code, $resolved['selection']));
            $shape['section:' . $section['id']] = ['visible' => $visible, 'active' => $sections['section:' . $section['id']]];
        }
        ksort($shape); return $shape;
    }
}
