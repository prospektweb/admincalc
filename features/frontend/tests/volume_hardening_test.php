<?php
require_once __DIR__ . '/../lib/Service/VolumeQuantityResolver.php';
require_once __DIR__ . '/../lib/Service/VirtualOfferBatchBuilder.php';

require_once __DIR__ . '/../lib/Service/CustomSelectionValidator.php';
require_once __DIR__ . '/../lib/Service/ProductAccessValidator.php';
use Prospektweb\Frontcalc\Service\CustomSelectionValidator;
use Prospektweb\Frontcalc\Service\ProductAccessValidator;

use Prospektweb\Frontcalc\Service\VolumeQuantityResolver;
use Prospektweb\Frontcalc\Service\VirtualOfferBatchBuilder;

function vh_assert($cond, $msg) { if (!$cond) { throw new RuntimeException($msg); } }
function vh_same($e,$a,$m){ if($e!==$a){ throw new RuntimeException($m.' expected='.var_export($e,true).' actual='.var_export($a,true)); }}


$validator = new ProductAccessValidator();
vh_assert(!$validator->validate(1, 1, 's1'), 'ProductAccessValidator fails closed without Bitrix classes');
$customValidator = new CustomSelectionValidator();
vh_assert(!$customValidator->parseTargetQuantity(str_repeat('9', 30))['ok'], 'calculate_custom huge target_quantity rejected');
vh_assert(!$customValidator->parseTargetQuantity('30000.5')['ok'], 'calculate_custom fractional target_quantity rejected');
vh_same(30000, $customValidator->parseTargetQuantity('30 000')['value'] ?? null, 'calculate_custom spaced quantity accepted');

$r = new VolumeQuantityResolver();
vh_same(30000, $r->resolvePropertyQuantity(['xml_id'=>'QTY_30K','value'=>'30 000']), 'QTY_30K VALUE resolves');
vh_same(null, $r->resolvePropertyQuantity(['xml_id'=>'QTY_30K']), 'opaque XML_ID without value rejected');
vh_same(null, $r->parseStrictPositiveInt('30000.5'), 'fraction rejected');
vh_same(null, $r->parseStrictPositiveInt(str_repeat('9', 30)), 'huge target rejected');

$builder = new VirtualOfferBatchBuilder();
$config = ['fields'=>[
 ['property_code'=>'CALC_PROP_COLOR','presets'=>[['xml_id'=>'RED','value'=>'Red']]],
 ['property_code'=>'CALC_PROP_VOLUME','reference_volumes'=>['base'=>[30000]],'inputs'=>[['min'=>100,'max'=>100000,'step'=>100]]],
]];
$existing = [[
 'offerKey'=>'bitrix:1','quantity'=>30000,
 'properties'=>['CALC_PROP_COLOR'=>['xml_id'=>'RED','value'=>'Red'],'CALC_PROP_VOLUME'=>['xml_id'=>'QTY_30K','value'=>'30 000']],
 'catalog'=>['prices_view'=>[['quantity_from'=>1,'quantity_to'=>null,'price'=>10]]],
]];
$virtual = $builder->build($config, [], [], $existing, 1, 2);
vh_assert(!in_array('30000', array_map(static fn($offer) => $offer['properties']['CALC_PROP_VOLUME']['VALUE_XML_ID'], $virtual), true), 'real opaque-volume offer suppresses duplicate virtual combination');

$constraints = $builder->buildVolumeConstraints(['fields'=>[
 ['property_code'=>'CALC_PROP_FORMAT','use_for_area_dependency'=>true],
 ['property_code'=>'CALC_PROP_VOLUME','inputs'=>[['min'=>100,'max'=>1000,'step'=>100]],'area_ranges'=>[['area_from_mm2'=>0,'area_to_mm2'=>10000,'min'=>200,'max'=>500,'step'=>100]]],
]]);
vh_assert(!$builder->validateQuantityConstraints(100, $constraints, ['CALC_PROP_FORMAT'=>['value'=>'50x50']])['ok'], 'below area min rejected');
vh_assert(!$builder->validateQuantityConstraints(600, $constraints, ['CALC_PROP_FORMAT'=>['value'=>'50x50']])['ok'], 'above area max rejected');
vh_assert(!$builder->validateQuantityConstraints(250, $constraints, ['CALC_PROP_FORMAT'=>['value'=>'50x50']])['ok'], 'bad step rejected');
vh_assert($builder->validateQuantityConstraints(300, $constraints, ['CALC_PROP_FORMAT'=>['value'=>'50x50']])['ok'], 'area range applied');

$ajax = file_get_contents(__DIR__ . '/../ajax/frontcalc.php');
vh_assert(strpos($ajax, "\$_POST['action']") !== false && strpos($ajax, '$_REQUEST') === false, 'backend does not read action/product from REQUEST');
vh_assert(strpos($ajax, 'FRONTCALC_ACTION_INVALID') < strpos($ajax, 'CALC_PROPERTY_CODE'), 'empty/unknown action rejected before config');
vh_assert(strpos($ajax, 'ProductAccessValidator') !== false, 'product access validator is used');

$productsPos = strpos($ajax, '$productsIblockId =');
$validatePos = strpos($ajax, 'ProductAccessValidator())->validate');
vh_assert($productsPos !== false && $validatePos !== false && $productsPos < $validatePos, 'PRODUCTS_IBLOCK_ID assigned before ProductAccessValidator validate');
vh_same(1, substr_count($ajax, '$productsIblockId ='), 'PRODUCTS_IBLOCK_ID assigned exactly once');
vh_assert(strpos($ajax, '$offersIblockId =') < strpos($ajax, '$offersIblockId,'), 'OFFERS_IBLOCK_ID assigned before first use');
vh_assert(strpos($ajax, '$propertyCode =') !== false && strpos($ajax, '$propertyCode =') < strpos($ajax, "'CODE' => $" . "propertyCode"), 'property code assigned before use');
vh_assert(strpos($ajax, 'round(') === false || strpos($ajax, 'frontcalc_extract_offer_quantity') > strrpos(substr($ajax, 0, strpos($ajax, 'round(')), 'function frontcalc_extract_offer_quantity'), 'frontcalc_extract_offer_quantity does not use round');
vh_assert(strpos($ajax, 'resolvePropertyQuantity($properties') !== false, 'frontcalc_extract_offer_quantity uses VolumeQuantityResolver');
vh_assert(strpos($ajax, 'FRONTCALC_PRODUCT_NOT_AVAILABLE') < strpos($ajax, 'prepareProductInitPayload'), 'product access stops before calc-server');
$js = file_get_contents(__DIR__ . '/../assets/js/frontcalc-jqm-popup.js');
vh_assert(strpos($js, 'action: "load"') !== false && strpos($js, 'BX.bitrix_sessid') !== false, 'load uses POST and sessid');
vh_assert(strpos($js, 'function getAreaVolumeRangeForSelection(selection)') !== false, 'area-specific volume constraints are resolved from the pending selection');
vh_assert(strpos($js, 'var adjustedTargetQuantity = normalizeVolumeForSelection(targetQuantity, selectedValuesResult.payload);') !== false, 'custom property confirmation snaps quantity into the new valid range before server request');
vh_assert(strpos($js, 'var areaStep = areaRange ? parseNumber(areaRange.step') !== false, 'volume increment is taken from the matched area range');
vh_assert(strpos($js, 'String(rawTo).trim() === "" ? Number.NaN') !== false, 'an empty upper area bound is treated as open-ended instead of zero');
$admin = file_get_contents(__DIR__ . '/../admin/editor.php');
vh_assert(strpos($admin, 'volume.value || volume.xml_id') !== false, 'admin JS prefers value over XML_ID');
vh_assert(strpos($admin, "(string)(\$enum['VALUE']") !== false, 'admin parses enum VALUE');

echo "Volume hardening tests passed\n";
