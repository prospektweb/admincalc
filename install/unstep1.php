<?php
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loc::loadMessages(__FILE__);

global $APPLICATION;

$moduleId = 'prospektweb.calc';

// Получаем информацию о созданных данных
$createdData = [];
$hasData = false;

if (Loader::includeModule('iblock')) {
    $iblockCodes = [
        'CALC_PRESETS' => 'Пресеты',
        'CALC_STAGES' => 'Этапы',
        'CALC_SETTINGS' => 'Калькуляторы',
        'CALC_MATERIALS' => 'Материалы',
        'CALC_MATERIALS_VARIANTS' => 'Варианты материалов',
        'CALC_OPERATIONS' => 'Операции',
        'CALC_OPERATIONS_VARIANTS' => 'Варианты операций',
        'CALC_EQUIPMENT' => 'Оборудование',
        'CALC_DETAILS' => 'Детали',
    ];

    foreach ($iblockCodes as $code => $name) {
        $iblockId = (int)Option::get($moduleId, 'IBLOCK_' . $code, 0);
        if ($iblockId > 0) {
            $rsIBlock = \CIBlock::GetByID($iblockId);
            if ($arIBlock = $rsIBlock->Fetch()) {
                // Получаем количество элементов через GetList с использованием CNT
                $rsCount = \CIBlockElement::GetList(
                    [],
                    ['IBLOCK_ID' => $iblockId],
                    false,
                    false,
                    ['ID']
                );
                $elementsCount = $rsCount->SelectedRowsCount();
                $createdData[] = [
                    'type' => 'iblock',
                    'id' => $iblockId,
                    'name' => $arIBlock['NAME'],
                    'code' => $code,
                    'elements' => (int)$elementsCount,
                ];
                $hasData = true;
            }
        }
    }
}
?>

<form action="<?= $APPLICATION->GetCurPage() ?>" method="post">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="hidden" name="id" value="prospektweb.calc">
    <input type="hidden" name="uninstall" value="Y">
    <input type="hidden" name="step" value="2">

    <div class="adm-info-message">
        <?= Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_CONFIRM') ?>
    </div>

    <table class="adm-detail-content-table edit-table">
        <tr class="heading">
            <td colspan="2"><?= Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_TITLE') ?></td>
        </tr>
        <tr>
            <td colspan="2">
                <label style="display: flex; align-items: flex-start; gap: 10px;">
                    <input type="checkbox" name="DELETE_DATA" value="Y" style="margin-top: 3px;">
                    <span>
                        <strong><?= Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DELETE_DATA') ?></strong>
                        <br>
                        <small style="color:#666;"><?= Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DELETE_DATA_NOTE') ?></small>
                    </span>
                </label>
            </td>
        </tr>
    </table>

    <?php if ($hasData): ?>
    <div class="adm-info-message" style="margin-top: 15px;">
        <strong><?= Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_DATA_LIST') ?></strong>
        <ul style="margin: 10px 0 0 20px; padding: 0;">
            <?php foreach ($createdData as $item): ?>
            <li>
                <strong><?= htmlspecialcharsbx($item['name']) ?></strong> 
                [ID: <?= $item['id'] ?>]
                <?php if ($item['elements'] > 0): ?>
                    — <?= $item['elements'] ?> <?= Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_ELEMENTS') ?>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <div style="margin-top: 10px; padding: 10px; background: #fff3cd; border-radius: 4px;">
            <strong><?= Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_ALSO_DELETE') ?></strong>
            <ul style="margin: 5px 0 0 20px; padding: 0;">
                <li><?= Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_IBLOCK_TYPES') ?></li>
                <li><?= Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_FILES') ?></li>
                <li><?= Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_EVENTS') ?></li>
                <li><?= Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_OPTIONS') ?></li>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <div style="margin-top: 20px;">
        <input type="submit" name="uninstall_confirm" value="<?= Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_CONFIRM_BTN') ?>" class="adm-btn-save">
        <a href="/bitrix/admin/partner_modules.php?lang=<?= LANGUAGE_ID ?>" class="adm-btn"><?= Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_CANCEL') ?></a>
    </div>
</form>
