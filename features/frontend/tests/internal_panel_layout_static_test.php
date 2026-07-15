<?php
$js=(string)file_get_contents(__DIR__.'/../assets/js/frontcalc-jqm-popup.js');
$css=(string)file_get_contents(__DIR__.'/../assets/css/frontcalc-jqm-popup.css');
function layout_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
layout_assert(strpos($js,'canViewInternal ? $(\'<div class="frontcalc-internal-panel-host"></div>\') : null')!==false,'extended host');
layout_assert(strpos($js,'$internalBlock.html(renderInternalPanelAspro(internalViewModel, internalPanelOpen, internalStatus))')!==false,'dedicated host render');
layout_assert(strpos($js,'accordion-type-2 frontcalc-internal-accordion')!==false,'Aspro accordion');
layout_assert(strpos($js,'tables-responsive')!==false&&strpos($js,'colored_table')!==false,'Aspro table');
layout_assert(strpos($js,'Идёт получение данных...')!==false,'loading text');
layout_assert(strpos($css,'grid-template-areas:"selectors price" "internal price"')!==false,'desktop grid');
layout_assert(strpos($css,'grid-template-areas:"price" "selectors" "internal"')!==false,'responsive grid');
layout_assert(strpos($css,'.frontcalc-internal-panel-host{grid-area:internal;min-width:0;')!==false,'no align-self end');
echo "Internal panel layout tests passed\n";
