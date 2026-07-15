<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

define('ADMIN_MODULE_NAME', 'prospektweb.calc');

define('PROSPEKTWEB_PROPVALMANAGER_SELF', $APPLICATION->GetCurPage() . '?mid=' . urlencode(ADMIN_MODULE_NAME) . '&lang=' . LANGUAGE_ID);

use Bitrix\Catalog\CatalogIblockTable;
use Bitrix\Main\Loader;
use Prospektweb\PropValManager\Service\ModuleConfig;
use Prospektweb\PropValManager\Service\PropertyDescriptionJsonExporter;
use Prospektweb\PropValManager\Service\PropertyValueDescriptionInstaller;
use Prospektweb\PropValManager\Service\PropertyValueDescriptionRepository;

if (!$USER->IsAdmin()) {
    return;
}

if (!Loader::includeModule(ADMIN_MODULE_NAME)) {
    echo CAdminMessage::ShowMessage('Не удалось подключить модуль.');
    return;
}

Loader::includeModule('iblock');
Loader::includeModule('catalog');
Loader::includeModule('highloadblock');

$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
$errors = [];
$warnings = [];
$notes = [];
$repository = new PropertyValueDescriptionRepository();
$installer = new PropertyValueDescriptionInstaller();

try {
    $ensureResult = $installer->ensure();
    if (!empty($ensureResult['created'])) {
        $notes[] = 'HL-блок описаний значений свойств создан автоматически.';
    }
} catch (\Throwable $e) {
    $errors[] = $e->getMessage();
}

if ($request->isPost() && check_bitrix_sessid()) {
    $action = (string)$request->getPost('description_action');

    try {
        if ($request->getPost('Update')) {
            $enabled = $request->getPost('ENABLED') === 'Y';
            $productsIblockId = (int)$request->getPost('PRODUCTS_IBLOCK_ID');
            $offersIblockId = (int)$request->getPost('OFFERS_IBLOCK_ID');

            if ($productsIblockId < 0 || $offersIblockId < 0) {
                $errors[] = 'ID инфоблоков не могут быть отрицательными.';
            }

            if ($productsIblockId > 0 && $offersIblockId <= 0) {
                $offersIblockId = prospektweb_propvalmanager_resolve_offers_iblock($productsIblockId);
            }

            if (empty($errors)) {
                ModuleConfig::setEnabled($enabled);
                ModuleConfig::setProductsIblockId($productsIblockId);
                ModuleConfig::setOffersIblockId($offersIblockId);
                $json = (new PropertyDescriptionJsonExporter())->export();
                $notes[] = 'Сохранено. JSON описаний обновлён: ' . $json['path'];
            }
        } elseif ($action === 'create') {
            $repository->create(
                prospektweb_propvalmanager_binding_from_request($request),
                prospektweb_propvalmanager_content_from_request($request)
            );
            $notes[] = 'Описание создано и привязано к значению списка.';
        } elseif ($action === 'update') {
            $repository->update((int)$request->getPost('DESCRIPTION_ID'), prospektweb_propvalmanager_content_from_request($request));
            $notes[] = 'Описание обновлено.';
        } elseif ($action === 'unlink') {
            $repository->unlink((int)$request->getPost('DESCRIPTION_ID'));
            $notes[] = 'Описание отвязано от значения списка.';
        } elseif ($action === 'link') {
            $repository->link((int)$request->getPost('EXISTING_DESCRIPTION_ID'), prospektweb_propvalmanager_binding_from_request($request));
            $notes[] = 'Существующее описание привязано к значению списка.';
        }
    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

foreach ($errors as $error) {
    echo CAdminMessage::ShowMessage($error);
}
foreach ($warnings as $warning) {
    echo CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => $warning]);
}
foreach ($notes as $note) {
    echo CAdminMessage::ShowNote($note);
}

if ((string)$request->getQuery('pvm_quick') === 'Y' || (string)$request->getPost('pvm_quick') === 'Y') {
    prospektweb_propvalmanager_render_quick_description_page($request, $repository);
    return;
}

$productsIblockId = ModuleConfig::getProductsIblockId();
$offersIblockId = ModuleConfig::getOffersIblockId();
if ($productsIblockId > 0 && $offersIblockId <= 0) {
    $offersIblockId = prospektweb_propvalmanager_resolve_offers_iblock($productsIblockId);
}

$catalogIblocks = prospektweb_propvalmanager_get_iblocks($productsIblockId);
$offersIblocks = prospektweb_propvalmanager_get_iblocks($offersIblockId);
$allValues = [];
$productProperties = prospektweb_propvalmanager_get_list_properties($productsIblockId, 'product', $allValues);
$offerProperties = prospektweb_propvalmanager_get_list_properties($offersIblockId, 'offer', $allValues);
$linkedMap = $repository->getLinkedMap($allValues);
$availableDescriptions = $repository->getAvailableDescriptions();
$editId = (int)$request->getQuery('edit_description_id');
$viewId = (int)$request->getQuery('view_description_id');
$createKey = (string)$request->getQuery('create_description_key');

$aTabs = [[
    'DIV' => 'edit1',
    'TAB' => 'Настройки и описания',
    'ICON' => 'main_settings',
    'TITLE' => 'Расширенные описания значений списочных свойств',
]];
$tabControl = new CAdminTabControl('tabControl', $aTabs);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>
<style>
    .pwu-property-block { margin: 22px 18px 28px; }
    .pwu-property-block .adm-list-table-cell { padding: 10px 18px; vertical-align: top; }
    .pwu-description-form { margin: 14px 0; padding: 16px 20px; background: #eef5f7; border: 1px solid #c9d7dc; }
    .pwu-description-form .adm-detail-content-table { width: 100%; }
    .pwu-description-form .adm-detail-content-table td { padding: 7px 10px; vertical-align: top; }
    .pwu-description-form-actions { margin-top: 12px; padding-left: 30%; }
</style>
<form method="post" action="<?php echo PROSPEKTWEB_PROPVALMANAGER_SELF; ?>">
    <?php
    $tabControl->Begin();
    $tabControl->BeginNextTab();
    ?>
    <tr class="heading"><td colspan="2">Основные настройки</td></tr>
    <tr>
        <td width="40%">Включено:</td>
        <td><input type="checkbox" name="ENABLED" value="Y" <?php echo ModuleConfig::isEnabled() ? 'checked' : ''; ?>></td>
    </tr>
    <tr>
        <td>Инфоблок каталога:</td>
        <td>
            <select name="PRODUCTS_IBLOCK_ID">
                <option value="0">-- выберите каталог --</option>
                <?php foreach ($catalogIblocks as $iblock): ?>
                    <option value="<?php echo (int)$iblock['id']; ?>" <?php echo $iblock['selected'] ? 'selected' : ''; ?>>
                        [<?php echo (int)$iblock['id']; ?>] <?php echo htmlspecialcharsbx($iblock['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>
    <tr>
        <td>Связанный инфоблок торговых предложений:</td>
        <td>
            <select name="OFFERS_IBLOCK_ID">
                <option value="0">-- определить автоматически --</option>
                <?php foreach ($offersIblocks as $iblock): ?>
                    <option value="<?php echo (int)$iblock['id']; ?>" <?php echo $iblock['selected'] ? 'selected' : ''; ?>>
                        [<?php echo (int)$iblock['id']; ?>] <?php echo htmlspecialcharsbx($iblock['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="adm-info-message-wrap"><div class="adm-info-message">При выборе каталога модуль автоматически подставляет связанный SKU-инфоблок, но его можно изменить вручную аналогично инфоблоку товаров.</div></div>
        </td>
    </tr>
    <tr>
        <td>HL-блок описаний:</td>
        <td><?php echo (int)ModuleConfig::getPropertyDescriptionsHlBlockId(); ?> / <?php echo htmlspecialcharsbx(PropertyValueDescriptionInstaller::HL_BLOCK_NAME); ?></td>
    </tr>
    <tr>
        <td>Публичный JSON описаний:</td>
        <td>
            <?php $jsonPath = ModuleConfig::getPropertyDescriptionsJsonPath(); ?>
            <?php if ($jsonPath !== ''): ?>
                <a href="<?php echo htmlspecialcharsbx($jsonPath); ?>" target="_blank"><?php echo htmlspecialcharsbx($jsonPath); ?></a>
                <br>Версия: <?php echo htmlspecialcharsbx(ModuleConfig::getPropertyDescriptionsJsonVersion()); ?>
            <?php else: ?>
                <span style="color:#999">Файл ещё не сформирован</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php
    $tabControl->Buttons();
    echo bitrix_sessid_post();
    ?>
    <input type="submit" name="Update" value="Сохранить" class="adm-btn-save">
    <?php
    $tabControl->End();
    ?>
</form>

<?php if ($editId > 0 || $viewId > 0): ?>
    <?php prospektweb_propvalmanager_render_description_card($editId ?: $viewId, $repository, $viewId > 0); ?>
<?php endif; ?>

<?php prospektweb_propvalmanager_render_properties_block('Свойства товара типа «Список» с CALC_', $productProperties, $linkedMap, $availableDescriptions, $repository, $createKey); ?>
<?php prospektweb_propvalmanager_render_properties_block('Свойства торговых предложений типа «Список» с CALC_', $offerProperties, $linkedMap, $availableDescriptions, $repository, $createKey); ?>

<?php
/** @return array<int, array{id:int,name:string,selected:bool}> */
function prospektweb_propvalmanager_get_iblocks(int $selectedId): array
{
    $items = [];
    if (!Loader::includeModule('iblock') || !class_exists('CIBlock')) {
        return $items;
    }

    $rows = CIBlock::GetList(['ID' => 'ASC'], [], false);
    while ($row = $rows->Fetch()) {
        $iblockId = (int)$row['ID'];
        $items[] = [
            'id' => $iblockId,
            'name' => (string)$row['NAME'],
            'selected' => $iblockId === $selectedId,
        ];
    }

    return $items;
}

function prospektweb_propvalmanager_resolve_offers_iblock(int $productsIblockId): int
{
    if ($productsIblockId <= 0 || !Loader::includeModule('catalog')) {
        return 0;
    }

    if (class_exists('CCatalogSKU')) {
        $sku = CCatalogSKU::GetInfoByProductIBlock($productsIblockId);
        if (is_array($sku) && !empty($sku['IBLOCK_ID'])) {
            return (int)$sku['IBLOCK_ID'];
        }
    }

    $row = CatalogIblockTable::getList([
        'select' => ['IBLOCK_ID'],
        'filter' => ['=PRODUCT_IBLOCK_ID' => $productsIblockId],
        'limit' => 1,
    ])->fetch();

    return $row ? (int)$row['IBLOCK_ID'] : 0;
}

/** @param array<int, array<string, mixed>> $allValues @return array<int, array<string, mixed>> */
function prospektweb_propvalmanager_get_list_properties(int $iblockId, string $entityType, array &$allValues): array
{
    $properties = [];
    if ($iblockId <= 0 || !Loader::includeModule('iblock')) {
        return $properties;
    }

    $propertyRows = CIBlockProperty::GetList(['SORT' => 'ASC', 'NAME' => 'ASC'], [
        'IBLOCK_ID' => $iblockId,
        'ACTIVE' => 'Y',
        'PROPERTY_TYPE' => 'L',
    ]);

    while ($property = $propertyRows->Fetch()) {
        $propertyName = (string)$property['NAME'];
        $propertyCode = (string)$property['CODE'];
        if (stripos($propertyName, 'CALC_') === false && stripos($propertyCode, 'CALC_') === false) {
            continue;
        }

        $propertyId = (int)$property['ID'];
        $values = [];
        $enumRows = CIBlockPropertyEnum::GetList(['SORT' => 'ASC', 'VALUE' => 'ASC'], ['PROPERTY_ID' => $propertyId]);
        while ($enum = $enumRows->Fetch()) {
            $value = [
                'IBLOCK_ID' => $iblockId,
                'PROPERTY_ID' => $propertyId,
                'PROPERTY_CODE' => $propertyCode,
                'ID' => (int)$enum['ID'],
                'XML_ID' => (string)$enum['XML_ID'],
                'VALUE' => (string)$enum['VALUE'],
            ];
            $values[] = $value;
            $allValues[] = $value;
        }

        $properties[] = [
            'IBLOCK_ID' => $iblockId,
            'ID' => $propertyId,
            'CODE' => $propertyCode,
            'NAME' => $propertyName,
            'ENTITY_TYPE' => $entityType,
            'VALUES' => $values,
        ];
    }

    return $properties;
}

/** @return array<string, mixed> */
function prospektweb_propvalmanager_binding_from_request($request): array
{
    return [
        'UF_IBLOCK_ID' => (int)$request->getPost('UF_IBLOCK_ID'),
        'UF_PROPERTY_ID' => (int)$request->getPost('UF_PROPERTY_ID'),
        'UF_PROPERTY_CODE' => (string)$request->getPost('UF_PROPERTY_CODE'),
        'UF_VALUE_ID' => (int)$request->getPost('UF_VALUE_ID'),
        'UF_VALUE_XML_ID' => (string)$request->getPost('UF_VALUE_XML_ID'),
        'UF_VALUE_NAME' => (string)$request->getPost('UF_VALUE_NAME'),
    ];
}

/** @return array<string, mixed> */
function prospektweb_propvalmanager_content_from_request($request): array
{
    $links = prospektweb_propvalmanager_links_from_request($request);
    $content = [
        'UF_ACTIVE' => $request->getPost('UF_ACTIVE') === 'Y' ? 1 : 0,
        'UF_TITLE' => (string)$request->getPost('UF_TITLE'),
        'UF_DESCRIPTION' => (string)$request->getPost('UF_DESCRIPTION'),
        'UF_IMAGE' => (int)$request->getPost('UF_IMAGE'),
        'UF_LINK' => trim((string)prospektweb_propvalmanager_first_post_value($request->getPost('UF_LINK'))),
        'UF_LINK_TEXT' => trim((string)prospektweb_propvalmanager_first_post_value($request->getPost('UF_LINK_TEXT'))),
        'UF_LINK_TARGET' => prospektweb_propvalmanager_normalize_link_target((string)prospektweb_propvalmanager_first_post_value($request->getPost('UF_LINK_TARGET'))),
        'UF_SORT' => (int)$request->getPost('UF_SORT'),
    ];

    $file = prospektweb_propvalmanager_uploaded_file('UF_IMAGE_FILE');
    if ($file !== null) {
        $content['UF_IMAGE'] = $file;
    }

    return $content;
}

function prospektweb_propvalmanager_normalize_link_target(string $target): string
{
    return $target === '_blank' ? '_blank' : '_self';
}


/**
 * Kept for backward compatibility with deployed admin pages from the previous multiple-link form.
 * New code stores a single link directly in prospektweb_propvalmanager_content_from_request().
 *
 * @return array<int, array{URL:string,TEXT:string,TITLE:string}>
 */
function prospektweb_propvalmanager_links_from_request($request): array
{
    $url = trim((string)prospektweb_propvalmanager_first_post_value($request->getPost('UF_LINK')));
    $text = trim((string)prospektweb_propvalmanager_first_post_value($request->getPost('UF_LINK_TEXT')));

    if ($url === '' && $text === '') {
        return [];
    }

    return [[
        'URL' => $url,
        'TEXT' => $text,
        'TITLE' => '',
    ]];
}

/** @param mixed $value @return mixed */
function prospektweb_propvalmanager_first_post_value($value)
{
    if (is_array($value)) {
        foreach ($value as $item) {
            if ((string)$item !== '') {
                return $item;
            }
        }

        return '';
    }

    return $value;
}

/** @return array<string, mixed>|null */
function prospektweb_propvalmanager_uploaded_file(string $inputName): ?array
{
    if (empty($_FILES[$inputName]) || !is_array($_FILES[$inputName])) {
        return null;
    }

    $file = $_FILES[$inputName];
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (string)($file['tmp_name'] ?? '') === '') {
        return null;
    }

    return $file;
}

/** @param array<int, array<string, mixed>> $properties @param array<string, array<string, mixed>> $linkedMap @param array<int, array<string, mixed>> $availableDescriptions */
function prospektweb_propvalmanager_render_properties_block(string $title, array $properties, array $linkedMap, array $availableDescriptions, PropertyValueDescriptionRepository $repository, string $createKey = ''): void
{
    echo '<div class="pwu-property-block">';
    echo '<h2>' . htmlspecialcharsbx($title) . '</h2>';
    if (empty($properties)) {
        echo '<p>Списочные свойства с CALC_ в названии или коде не найдены.</p>';
        echo '</div>';
        return;
    }

    foreach ($properties as $property) {
        echo '<h3>' . htmlspecialcharsbx($property['NAME']) . ' [' . htmlspecialcharsbx($property['CODE']) . '] ID ' . (int)$property['ID'] . ' — ' . ($property['ENTITY_TYPE'] === 'offer' ? 'ТП' : 'товар') . '</h3>';
        echo '<table class="adm-list-table" style="width:100%">';
        echo '<tr class="adm-list-table-header"><td class="adm-list-table-cell">Значение</td><td class="adm-list-table-cell">ID</td><td class="adm-list-table-cell">XML_ID</td><td class="adm-list-table-cell">Статус</td><td class="adm-list-table-cell">Действия</td></tr>';
        foreach ($property['VALUES'] as $value) {
            $key = $repository->makeKey((int)$value['IBLOCK_ID'], (int)$value['PROPERTY_ID'], (string)$value['XML_ID']);
            $description = $linkedMap[$key] ?? null;
            echo '<tr class="adm-list-table-row">';
            echo '<td class="adm-list-table-cell">' . htmlspecialcharsbx($value['VALUE']) . '</td>';
            echo '<td class="adm-list-table-cell">' . (int)$value['ID'] . '</td>';
            echo '<td class="adm-list-table-cell">' . htmlspecialcharsbx($value['XML_ID']) . '</td>';
            echo '<td class="adm-list-table-cell">';
            if ($description) {
                echo '<b style="color:green">Есть описание</b><br>' . htmlspecialcharsbx((string)($description['UF_TITLE'] ?: $description['UF_VALUE_NAME']));
                if (!empty($description['UF_MODIFIED'])) {
                    echo '<br>Изменено: ' . htmlspecialcharsbx((string)$description['UF_MODIFIED']);
                }
            } else {
                echo '<span style="color:#999">Нет описания</span>';
            }
            echo '</td><td class="adm-list-table-cell">';
            prospektweb_propvalmanager_render_actions($value, $description, $availableDescriptions, $repository);
            echo '</td></tr>';
            if (!$description && $createKey === $key) {
                echo '<tr class="adm-list-table-row"><td class="adm-list-table-cell" colspan="5">';
                prospektweb_propvalmanager_render_description_form('create', 0, [], $value);
                echo '</td></tr>';
            }
        }
        echo '</table>';
    }
    echo '</div>';
}

/** @param array<string, mixed> $value @param array<string, mixed>|null $description @param array<int, array<string, mixed>> $availableDescriptions */
function prospektweb_propvalmanager_render_actions(array $value, ?array $description, array $availableDescriptions, PropertyValueDescriptionRepository $repository): void
{
    $hidden = prospektweb_propvalmanager_hidden_binding($value);
    if ($description) {
        $id = (int)$description['ID'];
        echo '<a class="adm-btn" href="' . PROSPEKTWEB_PROPVALMANAGER_SELF . '&view_description_id=' . $id . '">Просмотреть описание</a> ';
        echo '<a class="adm-btn adm-btn-save" href="' . PROSPEKTWEB_PROPVALMANAGER_SELF . '&edit_description_id=' . $id . '">Редактировать описание</a> ';
        echo '<form method="post" action="' . PROSPEKTWEB_PROPVALMANAGER_SELF . '" style="display:inline">' . bitrix_sessid_post() . '<input type="hidden" name="description_action" value="unlink"><input type="hidden" name="DESCRIPTION_ID" value="' . $id . '"><input type="submit" class="adm-btn" value="Отвязать описание"></form>';
        return;
    }

    $createUrl = prospektweb_propvalmanager_admin_url([
        'create_description_key' => $repository->makeKey((int)$value['IBLOCK_ID'], (int)$value['PROPERTY_ID'], (string)$value['XML_ID']),
    ]);
    echo '<a class="adm-btn adm-btn-save" href="' . htmlspecialcharsbx($createUrl) . '">Создать описание</a>';

    if (!empty($availableDescriptions)) {
        echo '<form method="post" action="' . PROSPEKTWEB_PROPVALMANAGER_SELF . '" style="margin-top:6px">' . bitrix_sessid_post() . $hidden . '<input type="hidden" name="description_action" value="link"><select name="EXISTING_DESCRIPTION_ID">';
        foreach ($availableDescriptions as $item) {
            echo '<option value="' . (int)$item['ID'] . '">#' . (int)$item['ID'] . ' ' . htmlspecialcharsbx((string)($item['UF_TITLE'] ?: $item['UF_VALUE_NAME'] ?: $item['UF_PROPERTY_CODE'])) . '</option>';
        }
        echo '</select> <input type="submit" class="adm-btn" value="Выбрать существующее описание"></form>';
    }
}



function prospektweb_propvalmanager_render_quick_description_page($request, PropertyValueDescriptionRepository $repository): void
{
    $binding = prospektweb_propvalmanager_quick_binding_from_request($request);
    $postedDescriptionId = (int)$request->getPost('DESCRIPTION_ID');
    $postedDescription = $postedDescriptionId > 0 ? $repository->getById($postedDescriptionId) : null;
    if ($postedDescription && ((int)$binding['IBLOCK_ID'] <= 0 || (int)$binding['PROPERTY_ID'] <= 0 || (string)$binding['XML_ID'] === '')) {
        $binding = prospektweb_propvalmanager_binding_from_description_row($postedDescription);
    }
    echo '<style>.pwu-property-block{margin:18px}.pwu-description-form{margin:14px 0;padding:16px 20px;background:#eef5f7;border:1px solid #c9d7dc}.pwu-description-form .adm-detail-content-table{width:100%}.pwu-description-form .adm-detail-content-table td{padding:7px 10px;vertical-align:top}.pwu-description-form-actions{margin-top:12px;padding-left:30%}.pwu-quick-actions{margin:0 18px 14px}</style>';
    echo '<div class="pwu-property-block">';
    echo '<h2>Описание значения свойства</h2>';

    if ((int)$binding['IBLOCK_ID'] <= 0 || (int)$binding['PROPERTY_ID'] <= 0 || (string)$binding['XML_ID'] === '') {
        echo CAdminMessage::ShowMessage('Не удалось определить инфоблок, свойство или XML_ID значения. Сохраните значение списка и откройте форму повторно.');
        echo '</div>';
        return;
    }

    $description = $postedDescription ?: $repository->findLinked((int)$binding['IBLOCK_ID'], (int)$binding['PROPERTY_ID'], (string)$binding['XML_ID']);
    $settingsUrl = prospektweb_propvalmanager_admin_url();
    echo '<div class="pwu-quick-actions"><a class="adm-btn" target="_blank" href="' . htmlspecialcharsbx($settingsUrl) . '">Открыть полные настройки модуля</a></div>';

    if ($description) {
        echo '<div class="adm-info-message">Описание уже привязано к значению ' . htmlspecialcharsbx((string)$description['UF_VALUE_NAME']) . ' (#' . (int)$description['UF_VALUE_ID'] . ', XML_ID: ' . htmlspecialcharsbx((string)$description['UF_VALUE_XML_ID']) . ').</div>';
        prospektweb_propvalmanager_render_description_form('update', (int)$description['ID'], $description, []);
    } else {
        echo '<div class="adm-info-message">Создаётся описание для значения ' . htmlspecialcharsbx((string)$binding['VALUE']) . ' (#' . (int)$binding['ID'] . ', XML_ID: ' . htmlspecialcharsbx((string)$binding['XML_ID']) . ').</div>';
        prospektweb_propvalmanager_render_description_form('create', 0, [], $binding);
    }

    echo '</div>';
}


function prospektweb_propvalmanager_binding_from_description_row(array $row): array
{
    return [
        'IBLOCK_ID' => (int)($row['UF_IBLOCK_ID'] ?? 0),
        'PROPERTY_ID' => (int)($row['UF_PROPERTY_ID'] ?? 0),
        'PROPERTY_CODE' => (string)($row['UF_PROPERTY_CODE'] ?? ''),
        'ID' => (int)($row['UF_VALUE_ID'] ?? 0),
        'XML_ID' => (string)($row['UF_VALUE_XML_ID'] ?? ''),
        'VALUE' => (string)($row['UF_VALUE_NAME'] ?? ''),
    ];
}

function prospektweb_propvalmanager_quick_binding_from_request($request): array
{
    $get = static function (string $name) use ($request): string {
        $postValue = $request->getPost($name);
        if ($postValue !== null && $postValue !== '') {
            return (string)$postValue;
        }

        $queryValue = $request->getQuery($name);
        return $queryValue !== null ? (string)$queryValue : '';
    };

    return [
        'IBLOCK_ID' => (int)$get('UF_IBLOCK_ID'),
        'PROPERTY_ID' => (int)$get('UF_PROPERTY_ID'),
        'PROPERTY_CODE' => $get('UF_PROPERTY_CODE'),
        'ID' => (int)$get('UF_VALUE_ID'),
        'XML_ID' => $get('UF_VALUE_XML_ID'),
        'VALUE' => $get('UF_VALUE_NAME'),
    ];
}

/** @param array<string, mixed> $params */
function prospektweb_propvalmanager_admin_url(array $params = []): string
{
    $baseParams = [
        'mid' => ADMIN_MODULE_NAME,
        'lang' => LANGUAGE_ID,
    ];

    return $GLOBALS['APPLICATION']->GetCurPage() . '?' . http_build_query(array_merge($baseParams, $params));
}

/** @param array<string, mixed> $row @param array<string, mixed> $binding */
function prospektweb_propvalmanager_render_description_form(string $action, int $descriptionId, array $row, array $binding = [], bool $readonly = false): void
{
    $isCreate = $action === 'create';
    $submitLabel = $isCreate ? 'Сохранить описание' : 'Сохранить изменения';

    echo '<div class="pwu-description-form">';
    if ($isCreate && !empty($binding)) {
        echo '<div class="adm-info-message">Создание описания для значения ' . htmlspecialcharsbx((string)$binding['VALUE']) . ' (#' . (int)$binding['ID'] . ', XML_ID: ' . htmlspecialcharsbx((string)$binding['XML_ID']) . ').</div>';
    }

    echo '<form method="post" enctype="multipart/form-data" action="' . PROSPEKTWEB_PROPVALMANAGER_SELF . '">';
    echo bitrix_sessid_post();
    echo '<input type="hidden" name="description_action" value="' . htmlspecialcharsbx($action) . '">';
    if ((string)\Bitrix\Main\Application::getInstance()->getContext()->getRequest()->getPost('pvm_quick') === 'Y' || (string)\Bitrix\Main\Application::getInstance()->getContext()->getRequest()->getQuery('pvm_quick') === 'Y') {
        echo '<input type="hidden" name="pvm_quick" value="Y">';
    }
    if ($descriptionId > 0) {
        echo '<input type="hidden" name="DESCRIPTION_ID" value="' . $descriptionId . '">';
    }
    if (!empty($binding)) {
        echo prospektweb_propvalmanager_hidden_binding($binding);
    }

    prospektweb_propvalmanager_render_content_fields($row, $readonly);
    if (!$readonly) {
        echo '<div class="pwu-description-form-actions"><input type="submit" class="adm-btn-save" value="' . htmlspecialcharsbx($submitLabel) . '"></div>';
    }
    echo '</form>';
    echo '</div>';
}

/** @param array<string, mixed> $value */
function prospektweb_propvalmanager_hidden_binding(array $value): string
{
    return '<input type="hidden" name="UF_IBLOCK_ID" value="' . (int)$value['IBLOCK_ID'] . '">' .
        '<input type="hidden" name="UF_PROPERTY_ID" value="' . (int)$value['PROPERTY_ID'] . '">' .
        '<input type="hidden" name="UF_PROPERTY_CODE" value="' . htmlspecialcharsbx((string)$value['PROPERTY_CODE']) . '">' .
        '<input type="hidden" name="UF_VALUE_ID" value="' . (int)$value['ID'] . '">' .
        '<input type="hidden" name="UF_VALUE_XML_ID" value="' . htmlspecialcharsbx((string)$value['XML_ID']) . '">' .
        '<input type="hidden" name="UF_VALUE_NAME" value="' . htmlspecialcharsbx((string)$value['VALUE']) . '">';
}

/** @param array<string, mixed> $row */
function prospektweb_propvalmanager_render_content_fields(array $row, bool $readonly = false): void
{
    $disabled = $readonly ? ' disabled' : '';
    $title = htmlspecialcharsbx((string)($row['UF_TITLE'] ?? ''));
    $description = htmlspecialcharsbx((string)($row['UF_DESCRIPTION'] ?? ''));
    $sort = htmlspecialcharsbx((string)($row['UF_SORT'] ?? ''));
    $link = htmlspecialcharsbx((string)prospektweb_propvalmanager_first_value($row['UF_LINK'] ?? ''));
    $linkText = htmlspecialcharsbx((string)prospektweb_propvalmanager_first_value($row['UF_LINK_TEXT'] ?? ''));
    $linkTarget = prospektweb_propvalmanager_normalize_link_target((string)prospektweb_propvalmanager_first_value($row['UF_LINK_TARGET'] ?? ''));

    echo '<table class="adm-detail-content-table edit-table">';
    echo '<tr><td width="30%">Активность</td><td><input type="checkbox" name="UF_ACTIVE" value="Y" ' . (((int)($row['UF_ACTIVE'] ?? 1) === 1) ? 'checked' : '') . $disabled . '></td></tr>';
    echo '<tr><td>Сортировка</td><td><input type="text" name="UF_SORT" value="' . $sort . '" size="20"' . $disabled . '></td></tr>';
    echo '<tr><td>Заголовок</td><td><input type="text" name="UF_TITLE" value="' . $title . '" size="80"' . $disabled . '></td></tr>';
    echo '<tr><td>Описание</td><td><textarea name="UF_DESCRIPTION" rows="5" cols="80"' . $disabled . '>' . $description . '</textarea></td></tr>';
    echo '<tr><td>Картинка</td><td>';
    if ((int)($row['UF_IMAGE'] ?? 0) > 0) {
        echo '<div>Текущий файл: #' . (int)$row['UF_IMAGE'] . '</div>';
        echo '<input type="hidden" name="UF_IMAGE" value="' . (int)$row['UF_IMAGE'] . '">';
    }
    echo '<input type="file" name="UF_IMAGE_FILE" accept="image/*"' . $disabled . '>';
    echo '</td></tr>';
    echo '<tr><td>Ссылка</td><td><input type="text" name="UF_LINK" value="' . $link . '" size="80"' . $disabled . '></td></tr>';
    echo '<tr><td>Текст ссылки</td><td><input type="text" name="UF_LINK_TEXT" value="' . $linkText . '" size="80"' . $disabled . '></td></tr>';
    echo '<tr><td>Режим ссылки</td><td><select name="UF_LINK_TARGET"' . $disabled . '>';
    echo '<option value="_self"' . ($linkTarget === '_self' ? ' selected' : '') . '>Открыть в текущем окне</option>';
    echo '<option value="_blank"' . ($linkTarget === '_blank' ? ' selected' : '') . '>Открыть в новом окне</option>';
    echo '</select></td></tr>';
    echo '</table>';
}

/** @param mixed $value @return mixed */
function prospektweb_propvalmanager_first_value($value)
{
    if (is_array($value)) {
        foreach ($value as $item) {
            if ((string)$item !== '') {
                return $item;
            }
        }

        return '';
    }

    return $value;
}

function prospektweb_propvalmanager_render_description_card(int $id, PropertyValueDescriptionRepository $repository, bool $readonly): void
{
    $row = $repository->getById($id);

    if (!$row) {
        echo CAdminMessage::ShowMessage('Описание #' . $id . ' не найдено.');
        return;
    }

    echo '<div class="pwu-property-block">';
    echo '<h2>' . ($readonly ? 'Просмотр' : 'Редактирование') . ' описания #' . $id . '</h2>';
    echo '<div class="adm-info-message">Связано: инфоблок #' . (int)$row['UF_IBLOCK_ID'] . ', свойство ' . htmlspecialcharsbx((string)$row['UF_PROPERTY_CODE']) . ' (#' . (int)$row['UF_PROPERTY_ID'] . '), значение ' . htmlspecialcharsbx((string)$row['UF_VALUE_NAME']) . ' (#' . (int)$row['UF_VALUE_ID'] . ', XML_ID: ' . htmlspecialcharsbx((string)$row['UF_VALUE_XML_ID']) . '). Технические идентификаторы доступны только для просмотра; изменяются только контентные данные.</div>';
    prospektweb_propvalmanager_render_description_form('update', $id, $row, [], $readonly);
    echo '</div>';
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
