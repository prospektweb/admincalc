<?php
/** Native schema installation, no catalog profile or business content. */
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) { die(); }
global $APPLICATION;
?>
<form method="post" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= htmlspecialcharsbx(LANGUAGE_ID) ?>">
    <input type="hidden" name="id" value="prospektweb.calc">
    <input type="hidden" name="install" value="Y">
    <input type="hidden" name="step" value="2">
    <p>Будут созданы собственные таблицы документов, ревизий, публикаций и привязок,
    а также пустые справочники материалов, вариантов материалов, операций,
    вариантов операций, оборудования и поставщиков.</p>
    <p>Товары, калькуляторы и демо-данные не создаются. Привязки к каталогу
    настраиваются отдельно в документном редакторе после установки FrontCalc.
    Существующие служебные инфоблоки автоматически не удаляются.</p>
    <input type="submit" class="adm-btn-save" value="Далее">
</form>
