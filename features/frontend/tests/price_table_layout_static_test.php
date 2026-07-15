<?php

declare(strict_types=1);

$css = (string)file_get_contents(__DIR__ . '/../assets/css/frontcalc-jqm-popup.css');
$js = (string)file_get_contents(__DIR__ . '/../assets/js/frontcalc-jqm-popup.js');

function price_layout_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

price_layout_assert(substr_count($css, 'grid-template-columns:repeat(2,minmax(0,1fr))') >= 2, 'table header and rows must use the same equal-width grid');
price_layout_assert(strpos($css, '.frontcalc-table-head>div{display:flex;align-items:center;gap:6px;padding:0 11px;}') !== false, 'header text must align with cell content');
price_layout_assert(strpos($css, '.frontcalc-cell[data-col="strict"]{flex-direction:row;align-items:center;justify-content:space-between;gap:8px;}') !== false, 'total and unit prices must share one row');
price_layout_assert(strpos($css, '.frontcalc-cell[data-col="strict"] .frontcalc-cell-sub{margin:0 0 0 auto;text-align:right;}') !== false, 'unit price must align to the right');
price_layout_assert(strpos($css, '.frontcalc-deadline-tabs .nav-tabs>li{float:none;flex:1 1 0;width:100%;') !== false, 'deadline tabs must have equal widths');
price_layout_assert(strpos($css, '.frontcalc-deadline-tabs .nav-tabs>li.active>a') !== false, 'active deadline tab must have a dedicated style');
price_layout_assert(strpos($js, '<div class="tabs frontcalc-deadline-tabs" style="margin:36px 0;">') !== false, 'deadline tabs must keep the requested vertical rhythm');
price_layout_assert(strpos($js, 'style="background:none;padding:12px 4px 0;"') !== false, 'deadline explanation must keep the requested padding and transparent background');
price_layout_assert(strpos($css, '.frontcalc-deadline-tabs{margin:36px 0;}') !== false, 'deadline tabs CSS must use the requested margin');
price_layout_assert(strpos($css, '.frontcalc-deadline-tab-content{padding:12px 4px 0;background:none!important;') !== false, 'deadline explanation CSS must explicitly use background none and the requested padding');
price_layout_assert(strpos($css, 'padding-right:6px') === false && strpos($css, 'margin-right:-16px') === false, 'price table scroll area must not be shifted horizontally');
price_layout_assert(strpos($css, '.frontcalc-internal-accordion .accordion-body{padding-top:16px;}') === false, 'internal accordion must use native Aspro body spacing');
price_layout_assert(strpos($css, 'width:min(1400px,calc(100vw - 32px))') !== false, 'desktop popup must use the configured reference width');
price_layout_assert(strpos($css, '.frontcalc-selectors{grid-area:selectors;display:flex;flex-direction:column;gap:20px;padding:24px 30px 0;}') !== false, 'selector panel must use the current horizontal inset and flush bottom edge');
price_layout_assert(strpos($css, '.frontcalc-price-panel__inner{position:sticky;top:12px;height:100vh;border:1px solid #d9dee7;background:#fafbff;padding:32px 30px;') !== false, 'price panel must use the reference inset and full viewport height');
price_layout_assert(strpos($css, '.frontcalc-offer-title{display:flex;align-items:flex-start;gap:24px;') !== false, 'calculator icon and title must keep the reference spacing');

echo "Price table layout tests passed\n";
