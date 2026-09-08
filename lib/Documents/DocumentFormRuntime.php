<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

/** Pure form projection. Generated property tokens are UI addresses only;
 * there is no preset, catalog, publication or product identity in this DTO. */
final class DocumentFormRuntime
{
    public function __invoke(object $document, int $revision): array
    {
        $bindings = []; $tokens = [];
        foreach ($document->form->fields as $field) {
            $token = ($field->systemKey ?? '') === 'volume' ? 'CALC_PROP_VOLUME' : 'CALC_PROP_F_' . strtoupper(substr(hash('sha256', $field->fieldId), 0, 16));
            if (isset($tokens[$token])) throw new \InvalidArgumentException('Ambiguous form address.');
            $tokens[$token] = true;
            $binding = ['fieldId' => $field->fieldId,
                'target' => ['kind' => 'property', 'propertyCode' => $token],
                'valueMode' => $field->type === 'checkbox' ? 'boolean_yn' : ($field->type === 'dimensions' ? 'dimensions'
                    : ($field->type === 'select' && ($field->multiple ?? false) ? 'multiple' : 'scalar'))];
            if ($field->type === 'select') {
                $binding['optionMap'] = (object)array_column(array_map(static fn($option) => ['id' => $option->id, 'value' => $option->id], $field->options), 'value', 'id');
            }
            if (in_array($field->type, ['number', 'dimensions'], true)) {
                $inputs = $field->dimensionInputs ?: [(object)['id' => 'value']];
                $binding['inputMap'] = (object)[];
                foreach ($inputs as $index => $input) $binding['inputMap']->{$input->id} = $field->control->inputs[$index]->code ?? $input->id;
            }
            if (($field->systemKey ?? '') !== '' && $field->systemKey !== 'volume') {
                $binding = ['fieldId' => $field->fieldId, 'target' => ['kind' => 'display_only'], 'valueMode' => 'scalar'];
            }
            $bindings[] = $binding;
        }
        $projection = (new \Prospektweb\Frontcalc\Service\DocumentSiteCompiler())->compile($document,
            (object)['formBindings' => (object)['bindings' => $bindings]], $revision);
        $authoring = ['formDefinition' => $projection['formDefinition'], 'bindingDefinition' => $projection['bindingDefinition']];
        $storefronts = [];
        foreach ($projection['presentations'] as $view) {
            $storefronts[] = ['id' => $view['id'], 'name' => $view['name'],
                'runtimeSchema' => (new \Prospektweb\Frontcalc\Service\StorefrontPresentationProjector())->apply($projection['snapshot'], $authoring, $view),
                'systemFields' => (new \Prospektweb\Frontcalc\Service\SystemFormFieldConfigResolver())->resolve($authoring, $view)];
        }
        return $authoring + ['runtimeSchema' => $projection['snapshot'], 'storefronts' => $storefronts];
    }
}
