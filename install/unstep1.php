<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) { die(); }
global $APPLICATION;
?>
<form method="post" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="id" value="prospektweb.calc">
    <input type="hidden" name="uninstall" value="Y">
    <input type="hidden" name="step" value="2">
    <input type="hidden" name="lang" value="<?= htmlspecialcharsbx(LANGUAGE_ID) ?>">
    <p>Будут отключены модуль и его обработчики, удалены опубликованные файлы интерфейса.
    Документы, история, привязки, справочники и настройки сохраняются для повторной установки.
    Очистка данных выполняется отдельно по проверенному плану и резервной копии.</p>
    <input type="submit" class="adm-btn-save" value="Удалить модуль, сохранив данные">
</form>
