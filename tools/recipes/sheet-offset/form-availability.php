<?php
declare(strict_types=1);

/** Preserve every other method; expose only mapped materials for this offset graph. */
function alignSheetOffsetAvailability(array $form, array $tree): array
{
    $paper = null;
    foreach ($tree['tree']['branches'] ?? [] as $branch) {
        if (($branch['option_id'] ?? '') === 'paper') $paper = $branch['child'];
    }
    if (($paper['source']['field_id'] ?? '') !== 'type.paper') throw new RuntimeException('Unexpected paper tree');
    $densities = [];
    foreach ($paper['branches'] as $branch) {
        if (($branch['child']['source']['field_id'] ?? '') !== 'density.paper') throw new RuntimeException('Unexpected density tree');
        foreach ($branch['child']['branches'] as $density) {
            if (($density['child']['kind'] ?? '') !== 'result' || ($density['child']['result']['entity_id'] ?? 0) <= 0) throw new RuntimeException('Unresolved material');
            $densities[(string)$density['option_id']][] = $branch['option_id'];
        }
    }
    $paperTypes = array_column($paper['branches'], 'option_id');
    $leaf = static fn(string $code, array $values, string $op = 'equals'): array => ['property_code'=>$code,'operator'=>$op,'values'=>$values];
    $offset = $leaf('CALC_PROP_METHOD', ['OFSET']);
    $other = $leaf('CALC_PROP_METHOD', ['OFSET'], 'not_equals');
    $count = 0;
    foreach ($form['fields'] as &$field) {
        $id = $field['fieldId'] ?? '';
        if (!in_array($id, ['type.material','type.paper','density.paper'], true)) continue;
        foreach ($field['_runtime']['presets'] as &$option) {
            $value = (string)$option['xml_id'];
            $previous = [$other];
            if (!empty($option['visible_when'])) $previous[] = $option['visible_when'];
            $branches = [['mode'=>'all','conditions'=>$previous]];
            $allowed = $id === 'type.material' ? $value === 'paper' : ($id === 'type.paper' ? in_array($value, $paperTypes, true) : isset($densities[$value]));
            if ($allowed) {
                $conditions = [$offset];
                if ($id === 'density.paper') $conditions[] = $leaf('CALC_PROP_TYPE_PAPER', $densities[$value], 'in');
                $branches[] = ['mode'=>'all','conditions'=>$conditions];
            }
            $option['visible_when'] = ['mode'=>'any','conditions'=>$branches];
        }
        unset($option);
        $count++;
    }
    unset($field);
    if ($count !== 3) throw new RuntimeException('Expected three material controls');
    return $form;
}
