<?php
$js=(string)file_get_contents(__DIR__.'/../assets/js/frontcalc-jqm-popup.js');
$css=(string)file_get_contents(__DIR__.'/../assets/css/frontcalc-jqm-popup.css');
$ajax=(string)file_get_contents(__DIR__.'/../ajax/frontcalc.php');
$include=(string)file_get_contents(__DIR__.'/../../../include.php');
$options=(string)file_get_contents(__DIR__.'/../../../options.php');
function rt_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
rt_assert(strpos($js,'payloadCache')===false&&strpos($js,'FrontcalcPopupCache')===false,'frontend payload cache removed');
rt_assert(strpos($js,'var popupInstanceCache = {};')!==false,'page-lifetime popup instance cache exists');
rt_assert(strpos($js,'if (popupInstanceCache[info.cacheKey])') < strpos($js,'if (inflightRequests[info.cacheKey]) return;'),'cached popup opens before in-flight/loading path');
rt_assert(substr_count($js,'popupInstanceCache[info.cacheKey] = responsePayload;')>=2,'initial and enriched popup payloads refresh the page-lifetime instance');
rt_assert(strpos($ajax,'CalculationCache')===false&&strpos($include,'CalculationCache')===false,'backend calculation cache removed');
rt_assert(strpos($options,'CALC_CACHE_TTL')===false&&strpos($options,'CLEAR_CALC_CACHE')===false,'cache settings removed');
rt_assert(strpos($js,'Загружаем доступные варианты')===false&&strpos($js,'}, 2000);')===false,'popup must not open on a forced two second placeholder');
rt_assert(strpos($js,'window.setTimeout(function ()')!==false&&strpos($js,'}, 10000);')!==false,'10 second UI fallback');
rt_assert(strpos($js,'Сервер калькуляций недоступен.')!==false,'console availability warning');
rt_assert(strpos($js,'$(".frontcalc-restricted-tooltip")')!==false,'singleton tooltip lookup');
rt_assert(strpos($js,'}, 5000));')!==false,'tooltip auto hide');
rt_assert(strpos($css,'var(--card_bg_hover_black)')!==false&&strpos($css,'var(--basic_text_black)')!==false,'theme aware tooltip');
rt_assert(strpos($css,'.frontcalc-open-popup-chip.is-frontcalc-loading .lineclamp-2{visibility:hidden;}')!==false,'loading chip label keeps its intrinsic width');
rt_assert(strpos($css,'.frontcalc-open-popup-chip.is-frontcalc-loading>.frontcalc-button-spinner{position:absolute;top:50%;left:50%;margin:-.575em 0 0 -.575em;transform-origin:center;}')!==false,'loading chip spinner stays centered while its rotation transform changes');
rt_assert(strpos($js,'restoreCatalogListProductPropertyOrder')!==false,'catalog property order restoration');
echo "Realtime and tooltip static tests passed\n";
