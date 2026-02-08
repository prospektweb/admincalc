<?php
/**
 * Шаг 2 установки: Подтверждение создания
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loc::loadMessages(__FILE__);

// Получаем данные из POST/REQUEST, если пусто — пробуем из сессии
$productIblockId = (int)($_REQUEST['PRODUCT_IBLOCK_ID'] ?? 0);

// Если данные из REQUEST пусты, пробуем восстановить из сессии
if ($productIblockId <= 0 && !empty($_SESSION['PROSPEKTWEB_CALC_STEP1_DATA'])) {
    $productIblockId = (int)($_SESSION['PROSPEKTWEB_CALC_STEP1_DATA']['PRODUCT_IBLOCK_ID'] ?? 0);
}

// Сохраняем данные в сессию для надёжности (на случай перезагрузки)
if ($productIblockId > 0) {
    $_SESSION['PROSPEKTWEB_CALC_STEP1_DATA'] = [
        'PRODUCT_IBLOCK_ID' => $productIblockId,
    ];
}


$importSnapshotPath = '';
if (!empty($_FILES['IMPORT_SNAPSHOT_FILE']) && (int)($_FILES['IMPORT_SNAPSHOT_FILE']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $uploadError = (int)($_FILES['IMPORT_SNAPSHOT_FILE']['error'] ?? UPLOAD_ERR_OK);
    if ($uploadError === UPLOAD_ERR_OK) {
        $tmpName = (string)($_FILES['IMPORT_SNAPSHOT_FILE']['tmp_name'] ?? '');
        $originalName = (string)($_FILES['IMPORT_SNAPSHOT_FILE']['name'] ?? 'snapshot.json');
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['json', 'txt'], true)) {
            echo '<div class="adm-info-message adm-info-message-red">Допустимы только файлы .json или .txt для snapshot.</div>';
            return;
        }

        $uploadDir = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/upload/prospektweb.calc';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $importSnapshotPath = $uploadDir . '/install_snapshot_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
        if (!move_uploaded_file($tmpName, $importSnapshotPath)) {
            if (!copy($tmpName, $importSnapshotPath)) {
                echo '<div class="adm-info-message adm-info-message-red">Не удалось сохранить загруженный snapshot файл.</div>';
                return;
            }
        }

        $_SESSION['PROSPEKTWEB_CALC_STEP1_DATA']['IMPORT_SNAPSHOT_PATH'] = $importSnapshotPath;
        $_SESSION['PROSPEKTWEB_CALC_STEP1_DATA']['IMPORT_SNAPSHOT_NAME'] = $originalName;
    } else {
        echo '<div class="adm-info-message adm-info-message-red">Ошибка загрузки snapshot файла (код: ' . $uploadError . ').</div>';
        return;
    }
}

if ($importSnapshotPath === '') {
    $importSnapshotPath = (string)($_SESSION['PROSPEKTWEB_CALC_STEP1_DATA']['IMPORT_SNAPSHOT_PATH'] ?? '');
}
$importSnapshotName = (string)($_SESSION['PROSPEKTWEB_CALC_STEP1_DATA']['IMPORT_SNAPSHOT_NAME'] ?? '');

if ($productIblockId <= 0) {
    echo '<div class="adm-info-message adm-info-message-red">' .
        Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_SELECT_IBLOCK_ERROR') .
        '</div>';
    echo '<a href="' . $APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID . '&id=prospektweb.calc&install=Y&step=1">' .
        Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_BACK') .
        '</a>';
    return;
}

// Получаем информацию о выбранном инфоблоке
Loader::includeModule('iblock');
Loader::includeModule('catalog');

$arIBlock = \CIBlock::GetByID($productIblockId)->Fetch();
$catalogInfo = \CCatalogSKU::GetInfoByProductIBlock($productIblockId);
$skuIblockId = $catalogInfo['IBLOCK_ID'] ?? null;

?>
<form action="<?= $APPLICATION->GetCurPage() ?>" method="post">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="hidden" name="id" value="prospektweb.calc">
    <input type="hidden" name="install" value="Y">
    <input type="hidden" name="step" value="3">
    <input type="hidden" name="PRODUCT_IBLOCK_ID" value="<?= $productIblockId ?>">
    <input type="hidden" name="SKU_IBLOCK_ID" value="<?= (int)$skuIblockId ?>">
    <input type="hidden" name="IMPORT_SNAPSHOT_PATH" value="<?= htmlspecialcharsbx($importSnapshotPath) ?>">

    <table class="adm-detail-content-table edit-table">
        <tr class="heading">
            <td colspan="2"><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_STEP2_TITLE') ?></td>
        </tr>

        <tr>
            <td width="40%"><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_SELECTED_IBLOCK') ?></td>
            <td width="60%">
                <strong><?= htmlspecialcharsbx($arIBlock['NAME'] ?? '') ?></strong> [<?= $productIblockId ?>]
            </td>
        </tr>

        <?php if ($skuIblockId): ?>
        <tr>
            <td><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_SKU_IBLOCK') ?></td>
            <td>ID: <?= $skuIblockId ?></td>
        </tr>
        <?php endif; ?>

        <tr>
            <td>Импорт данных</td>
            <td>
                <?php if ($importSnapshotPath !== ''): ?>
                    Файл: <strong><?= htmlspecialcharsbx($importSnapshotName !== '' ? $importSnapshotName : basename($importSnapshotPath)) ?></strong>
                <?php else: ?>
                    Не выбран (установка начисто)
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <div class="adm-info-message">
        <h3><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_WILL_CREATE') ?></h3>
        <ul>
            <li><strong><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_TYPE_CALCULATOR') ?></strong> (calculator)
                <ul>
                    <li><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_CALC_STAGES') ?></li>
                    <li><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_CALC_SETTINGS') ?></li>
                </ul>
            </li>
            <li><strong><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_TYPE_CATALOG') ?></strong> (calculator_catalog)
                <ul>
                    <li><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_MATERIALS') ?></li>
                    <li><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_MATERIALS_VARIANTS') ?></li>
                    <li><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_OPERATIONS') ?></li>
                    <li><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_OPERATIONS_VARIANTS') ?></li>
                    <li><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_EQUIPMENT') ?></li>
                    <li><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_DETAILS') ?></li>
                    <li><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_IBLOCK_DETAILS_VARIANTS') ?></li>
                </ul>
            </li>
            <li><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_PROPERTIES_NOTE') ?></li>
        </ul>
    </div>

    <div style="margin-top: 20px;">
        <a href="<?= $APPLICATION->GetCurPage() ?>?lang=<?= LANGUAGE_ID ?>&id=prospektweb.calc&install=Y&step=1"
           class="adm-btn"><?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_BACK') ?></a>
        <input type="submit" name="install_confirm" value="<?= Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_CONFIRM') ?>" class="adm-btn-save">
    </div>
</form>
