<?php
declare(strict_types=1);
namespace Prospektweb\Frontcalc\Service {
    final class DocumentSiteCompiler {
        public static $received;
        public function compile(object $document, object $connection, int $revision): array {
            self::$received = [$document, $connection, $revision];
            return ['formDefinition' => (array)$document->form, 'bindingDefinition' => (array)$connection->formBindings,
                'snapshot' => ['fields' => []], 'presentations' => [['id' => 'BASE', 'name' => 'Base']]];
        }
    }
    final class StorefrontPresentationProjector {
        public function apply(array $schema, array $authoring, array $view): array { return $schema + ['view' => $view['id']]; }
    }
    final class SystemFormFieldConfigResolver {
        public function resolve(array $authoring, array $view): array { return ['layoutCount' => ['defaultValue' => 3]]; }
    }
}
namespace {
    require dirname(__DIR__) . '/lib/Documents/DocumentFormRuntime.php';
    $field = static fn(array $values): array => $values + ['dimensionInputs' => [], 'options' => []];
    $document = json_decode(json_encode(['form' => ['fields' => [
        $field(['fieldId' => 'copies', 'type' => 'number', 'systemKey' => 'volume', 'control' => ['inputs' => [['code' => 'volume']]]]),
        $field(['fieldId' => 'layout', 'type' => 'number', 'systemKey' => 'layoutCount']),
        $field(['fieldId' => 'deadline', 'type' => 'select', 'systemKey' => 'deadlineType']),
        $field(['fieldId' => 'format', 'type' => 'dimensions', 'dimensionInputs' => [['id' => 'width'], ['id' => 'length']], 'control' => ['inputs' => [['code' => 'width'], ['code' => 'height']]]]),
        $field(['fieldId' => 'paper.type', 'type' => 'select', 'multiple' => true, 'options' => [['id' => 'mat']]]),
        $field(['fieldId' => 'paper-type', 'type' => 'checkbox']),
    ]]], JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
    $before = serialize($document); $runtime = (new \Prospektweb\Calc\Documents\DocumentFormRuntime())($document, 7);
    [$received, $connection, $revision] = \Prospektweb\Frontcalc\Service\DocumentSiteCompiler::$received;
    $bindings = $connection->formBindings->bindings;
    if ($before !== serialize($document) || $revision !== 7 || $received !== $document) throw new \RuntimeException('Projection changed its source');
    if ($bindings[0]['target']['propertyCode'] !== 'CALC_PROP_VOLUME' || $bindings[0]['inputMap']->value !== 'volume') throw new \RuntimeException('Volume contract');
    if ($bindings[1]['target'] !== ['kind' => 'display_only'] || $bindings[2]['target'] !== ['kind' => 'display_only']) throw new \RuntimeException('System fields must stay outside the catalog');
    if ($bindings[3]['inputMap']->length !== 'height') throw new \RuntimeException('Dimension order must retain authored runtime input addresses');
    if ($bindings[4]['valueMode'] !== 'multiple' || $bindings[4]['optionMap']->mat !== 'mat' || $bindings[5]['valueMode'] !== 'boolean_yn') throw new \RuntimeException('Value domains changed');
    if ($bindings[4]['target'] === $bindings[5]['target']) throw new \RuntimeException('Punctuation collision');
    if ($runtime['storefronts'][0]['runtimeSchema']['view'] !== 'BASE' || $runtime['storefronts'][0]['systemFields']['layoutCount']['defaultValue'] !== 3) throw new \RuntimeException('Shared projector not used');
    $document->form->fields[] = $document->form->fields[0];
    try { (new \Prospektweb\Calc\Documents\DocumentFormRuntime())($document, 7); throw new \RuntimeException('Duplicate accepted'); }
    catch (\InvalidArgumentException $expected) {}
    echo "PASS native form projection bindings and duplicate guards (compiler/projector doubles)\n";
}
