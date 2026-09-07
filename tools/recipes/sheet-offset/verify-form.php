<?php
declare(strict_types=1);
require __DIR__.'/form-availability.php';
require $argv[2].'/lib/Service/CalculatorSchemaNormalizer.php';
require $argv[2].'/lib/Service/CalculatorConditionResolver.php';
$snapshot=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$form=$snapshot['documents']['form']['formDefinition'];
foreach ($snapshot['documents']['logic']['runtimePayload']['elementsStore']['CALC_STAGES'] as $stage) if ($stage['id']===16827) $tree=json_decode($stage['properties']['OPTIONS_MATERIAL']['VALUE'],true,512,JSON_THROW_ON_ERROR);
$after=alignSheetOffsetAvailability($form,$tree);
$resolver=new \Prospektweb\Frontcalc\Service\CalculatorConditionResolver();
$expected=['mel-mat-paper'=>['105','115','140','150','200','300'],'mel-glossy-paper'=>['105','115','200'],'vhi-paper'=>['80','160']];
$checks=0;
foreach ($after['fields'] as $i=>$field) {
    $id=$field['fieldId'];
    if (!in_array($id,['type.material','type.paper','density.paper'],true)) {
        if ($field!==$form['fields'][$i]) throw new RuntimeException('Unrelated field changed');
        continue;
    }
    foreach (['DIGITAL','OFSET','UF'] as $method) foreach (['mel-mat-paper','mel-glossy-paper','vhi-paper','design-paper'] as $paper) {
        $selection=['CALC_PROP_METHOD'=>$method,'CALC_PROP_TYPE_PAPER'=>$paper];
        foreach ($field['_runtime']['presets'] as $n=>$option) {
            $actual=$resolver->evaluate($option['visible_when'],$selection);
            $v=(string)$option['xml_id'];
            $old=$form['fields'][$i]['_runtime']['presets'][$n]['visible_when'] ?? null;
            $want=$method!=='OFSET' ? (!$old || $resolver->evaluate($old,$selection)) : ($id==='type.material' ? $v==='paper' : ($id==='type.paper' ? isset($expected[$v]) : in_array($v,$expected[$paper] ?? [],true)));
            if ($actual!==$want) throw new RuntimeException("Availability mismatch $method $paper $id $v");
            $checks++;
        }
    }
}
echo json_encode(['passed'=>$checks,'otherMethodsPreserved'=>true])."\n";
