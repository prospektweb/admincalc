<?php
/**
 * Шаг 4 установки: Завершение
 */

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loc::loadMessages(__FILE__);

$installData = $_SESSION['PROSPEKTWEB_CALC_INSTALL'] ?? [];
$createdResources = $installData['created_resources'] ?? [];
$errors = $installData['errors'] ?? [];
$productIblockId = $installData['products_iblock_id'] ?? 0;

// Очищаем сессию
unset($_SESSION['PROSPEKTWEB_CALC_INSTALL']);

?>

<?php if (empty($errors)): ?>
<div class="adm-info-message adm-info-message-green">
    <?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_SUCCESS_MESSAGE') ?>
</div>
<?php else: ?>
<div class="adm-info-message adm-info-message-yellow">
    <?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_PARTIAL_SUCCESS') ?>
</div>
<?php endif; ?>

<?php if (!empty($createdResources)): ?>
<table class="adm-detail-content-table edit-table">
    <tr class="heading">
        <td colspan="3"><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_CREATED_RESOURCES') ?></td>
    </tr>
    <tr>
        <th><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_RESOURCE_TYPE') ?></th>
        <th><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_RESOURCE_ID') ?></th>
        <th><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_RESOURCE_NAME') ?></th>
    </tr>
    <?php foreach ($createdResources as $resource): ?>
    <tr>
        <td><?= htmlspecialcharsbx($resource['type']) ?></td>
        <td><?= htmlspecialcharsbx($resource['id']) ?></td>
        <td><?= htmlspecialcharsbx($resource['name']) ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

<div style="margin-top: 20px;">
    <a href="/bitrix/admin/iblock_element_edit.php?IBLOCK_ID=<?= $productIblockId ?>&type=catalog&lang=<?= LANGUAGE_ID ?>"
       class="adm-btn"><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_GO_TO_PRODUCTS') ?></a>

    <a href="/bitrix/admin/settings.php?lang=<?= LANGUAGE_ID ?>&mid=prospektweb.calc"
       class="adm-btn"><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_GO_TO_SETTINGS') ?></a>
</div>
