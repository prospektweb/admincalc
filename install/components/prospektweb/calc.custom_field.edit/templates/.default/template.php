<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

/** @var array $arResult */
/** @var array $arParams */
/** @var CMain $APPLICATION */

use Bitrix\Main\UI\Extension;

// Подключаем UI-библиотеки Битрикса
Extension::load(['ui.buttons', 'ui.forms', 'ui.alerts', 'ui.icons.b24']);

$element = $arResult['ELEMENT'] ?? [];
$props   = $arResult['PROPERTIES'] ?? [];
$isNew   = $arResult['IS_NEW'] ?? true;
$errors  = $arResult['ERRORS'] ?? [];

// Получаем значения свойств
// Для свойств типа "Список" используем VALUE_XML_ID
$currentType = $props['FIELD_TYPE']['VALUE_XML_ID'] ?? '';
$isRequired  = (($props['IS_REQUIRED']['VALUE_XML_ID'] ?? 'N') === 'Y');

// Для строковых/числовых свойств используем VALUE
$fieldCode    = $props['FIELD_CODE']['VALUE'] ?? '';
$defaultValue = $props['DEFAULT_VALUE']['VALUE'] ?? '';
$unit         = $props['UNIT']['VALUE'] ?? '';
$minValue     = $props['MIN_VALUE']['VALUE'] ?? '';
$maxValue     = $props['MAX_VALUE']['VALUE'] ?? '';
$stepValue    = $props['STEP_VALUE']['VALUE'] ?? '';
$maxLength    = $props['MAX_LENGTH']['VALUE'] ?? '';
$sortOrder    = $props['SORT_ORDER']['VALUE'] ?? 500;

// Для множественного свойства OPTIONS
$options = $props['OPTIONS']['VALUES'] ?? [];

// Название элемента
$elementName   = $element['NAME'] ?? '';
$elementActive = (($element['ACTIVE'] ?? 'Y') === 'Y');

// URL для возврата
$backUrl = $arParams['BACK_URL']
    ?? ('/bitrix/admin/iblock_list_admin.php?IBLOCK_ID=' . (int)($arParams['IBLOCK_ID'] ?? 0) . '&type=calculator&lang=' . LANGUAGE_ID);
?>

<div class="calc-custom-field-editor" id="calc-field-editor">

    <?php if (!empty($errors)): ?>
        <div class="ui-alert ui-alert-danger">
            <span class="ui-alert-message">
                <?php foreach ($errors as $error): ?>
                    <div><?= htmlspecialcharsbx($error) ?></div>
                <?php endforeach; ?>
            </span>
        </div>
    <?php endif; ?>

    <?php if (!empty($arResult['SUCCESS_MESSAGE'])): ?>
        <div class="ui-alert ui-alert-success">
            <span class="ui-alert-message"><?= htmlspecialcharsbx($arResult['SUCCESS_MESSAGE']) ?></span>
        </div>
    <?php endif; ?>

    <form method="post" id="calc-field-form" class="calc-field-form">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="IBLOCK_ID" value="<?= (int)($arParams['IBLOCK_ID'] ?? 0) ?>">
        <input type="hidden" name="ID" value="<?= (int)($arParams['ELEMENT_ID'] ?? 0) ?>">

        <!-- ============================================== -->
        <!-- ОСНОВНЫЕ НАСТРОЙКИ -->
        <!-- ============================================== -->
        <div class="calc-field-section">
            <div class="calc-field-section-title">Основные настройки</div>

            <!-- Активность -->
            <div class="calc-field-row">
                <div class="calc-field-label">Активность</div>
                <div class="calc-field-control">
                    <label class="calc-checkbox">
                        <input type="checkbox"
                               name="ACTIVE"
                               value="Y"
                               <?= $elementActive ? 'checked' : '' ?>>
                        <span>Элемент активен</span>
                    </label>
                </div>
            </div>

            <!-- Название поля -->
            <div class="calc-field-row">
                <div class="calc-field-label">
                    Название поля <span class="calc-required">*</span>
                </div>
                <div class="calc-field-control">
                    <input type="text"
                           name="NAME"
                           class="calc-input calc-input-wide"
                           value="<?= htmlspecialcharsbx($elementName) ?>"
                           required
                           placeholder="Например: Вылеты, Тип бумаги"
                           data-preview-label>
                </div>
            </div>

            <!-- Символьный код -->
            <div class="calc-field-row">
                <div class="calc-field-label">
                    Символьный код <span class="calc-required">*</span>
                </div>
                <div class="calc-field-control">
                    <input type="text"
                           name="PROPERTY_VALUES[FIELD_CODE]"
                           class="calc-input calc-input-code"
                           value="<?= htmlspecialcharsbx($fieldCode) ?>"
                           pattern="[A-Z][A-Z0-9_]*"
                           title="Только заглавные латинские буквы, цифры и подчёркивание. Должен начинаться с буквы."
                           required
                           placeholder="PAPER_TYPE">
                    <div class="calc-field-hint">Только заглавные буквы, цифры и _ (например: BLEED, PAPER_TYPE)</div>
                </div>
            </div>

            <!-- Тип поля -->
            <div class="calc-field-row">
                <div class="calc-field-label">
                    Тип поля <span class="calc-required">*</span>
                </div>
                <div class="calc-field-control">
                    <div class="calc-type-selector" id="field-type-selector">
                        <label class="calc-type-option <?= $currentType === 'number' ? 'active' : '' ?>">
                            <input type="radio"
                                   name="PROPERTY_VALUES[FIELD_TYPE]"
                                   value="number"
                                   <?= $currentType === 'number' ? 'checked' : '' ?>
                                   required>
                            <span class="calc-type-icon">123</span>
                            <span class="calc-type-name">Число</span>
                        </label>

                        <label class="calc-type-option <?= $currentType === 'text' ? 'active' : '' ?>">
                            <input type="radio"
                                   name="PROPERTY_VALUES[FIELD_TYPE]"
                                   value="text"
                                   <?= $currentType === 'text' ? 'checked' : '' ?>>
                            <span class="calc-type-icon">Aa</span>
                            <span class="calc-type-name">Текст</span>
                        </label>

                        <label class="calc-type-option <?= $currentType === 'checkbox' ? 'active' : '' ?>">
                            <input type="radio"
                                   name="PROPERTY_VALUES[FIELD_TYPE]"
                                   value="checkbox"
                                   <?= $currentType === 'checkbox' ? 'checked' : '' ?>>
                            <span class="calc-type-icon">☑</span>
                            <span class="calc-type-name">Чекбокс</span>
                        </label>

                        <label class="calc-type-option <?= $currentType === 'select' ? 'active' : '' ?>">
                            <input type="radio"
                                   name="PROPERTY_VALUES[FIELD_TYPE]"
                                   value="select"
                                   <?= $currentType === 'select' ? 'checked' : '' ?>>
                            <span class="calc-type-icon">▼</span>
                            <span class="calc-type-name">Список</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Обязательное -->
            <div class="calc-field-row">
                <div class="calc-field-label">Обязательное</div>
                <div class="calc-field-control">
                    <label class="calc-checkbox">
                        <input type="checkbox"
                               name="PROPERTY_VALUES[IS_REQUIRED]"
                               value="Y"
                               <?= $isRequired ? 'checked' : '' ?>
                               data-preview-required>
                        <span>Поле обязательно для заполнения</span>
                    </label>
                </div>
            </div>

            <!-- Сортировка -->
            <div class="calc-field-row">
                <div class="calc-field-label">Сортировка</div>
                <div class="calc-field-control">
                    <input type="number"
                           name="PROPERTY_VALUES[SORT_ORDER]"
                           class="calc-input calc-input-small"
                           value="<?= (int)$sortOrder ?>"
                           min="0"
                           step="10">
                </div>
            </div>
        </div>

        <!-- ============================================== -->
        <!-- ПАРАМЕТРЫ ДЛЯ ЧИСЛОВОГО ПОЛЯ (number) -->
        <!-- ============================================== -->
        <div class="calc-field-section calc-field-params"
             data-for-type="number"
             style="<?= ($currentType === 'number' || empty($currentType)) ? '' : 'display: none;' ?>">
            <div class="calc-field-section-title">Параметры числового поля</div>

            <div class="calc-field-row-inline">
                <!-- Единица измерения -->
                <div class="calc-field-row">
                    <div class="calc-field-label">Единица измерения</div>
                    <div class="calc-field-control">
                        <input type="text"
                               name="PROPERTY_VALUES[UNIT]"
                               class="calc-input calc-input-small"
                               value="<?= htmlspecialcharsbx($unit) ?>"
                               placeholder="мм"
                               data-preview-unit>
                    </div>
                </div>

                <!-- Мин. значение -->
                <div class="calc-field-row">
                    <div class="calc-field-label">Мин.</div>
                    <div class="calc-field-control">
                        <input type="number"
                               name="PROPERTY_VALUES[MIN_VALUE]"
                               class="calc-input calc-input-small"
                               value="<?= htmlspecialcharsbx($minValue) ?>"
                               step="any"
                               data-preview-min>
                    </div>
                </div>

                <!-- Макс. значение -->
                <div class="calc-field-row">
                    <div class="calc-field-label">Макс.</div>
                    <div class="calc-field-control">
                        <input type="number"
                               name="PROPERTY_VALUES[MAX_VALUE]"
                               class="calc-input calc-input-small"
                               value="<?= htmlspecialcharsbx($maxValue) ?>"
                               step="any"
                               data-preview-max>
                    </div>
                </div>

                <!-- Шаг -->
                <div class="calc-field-row">
                    <div class="calc-field-label">Шаг</div>
                    <div class="calc-field-control">
                        <input type="number"
                               name="PROPERTY_VALUES[STEP_VALUE]"
                               class="calc-input calc-input-small"
                               value="<?= htmlspecialcharsbx($stepValue) ?>"
                               step="any"
                               data-preview-step>
                    </div>
                </div>
            </div>

            <!-- Значение по умолчанию для number -->
            <div class="calc-field-row">
                <div class="calc-field-label">Значение по умолчанию</div>
                <div class="calc-field-control">
                    <input type="number"
                           name="PROPERTY_VALUES[DEFAULT_VALUE_NUMBER]"
                           class="calc-input calc-input-medium"
                           value="<? = $currentType === 'number' ? htmlspecialcharsbx($defaultValue) : '' ?>"
                           step="any"
                           data-default-number>
                </div>
            </div>
        </div>

        <!-- ============================================== -->
        <!-- ПАРАМЕТРЫ ДЛЯ ТЕКСТОВОГО ПОЛЯ (text) -->
        <!-- ============================================== -->
        <div class="calc-field-section calc-field-params"
             data-for-type="text"
             style="<?= $currentType === 'text' ? '' : 'display: none;' ?>">
            <div class="calc-field-section-title">Параметры текстового поля</div>

            <!-- Макс. длина -->
            <div class="calc-field-row">
                <div class="calc-field-label">Максимальная длина</div>
                <div class="calc-field-control">
                    <input type="number"
                           name="PROPERTY_VALUES[MAX_LENGTH]"
                           class="calc-input calc-input-small"
                           value="<?= htmlspecialcharsbx($maxLength) ?>"
                           min="1"
                           data-preview-maxlength>
                    <span class="calc-field-hint-inline">символов</span>
                </div>
            </div>

            <!-- Значение по умолчанию для text -->
            <div class="calc-field-row">
                <div class="calc-field-label">Значение по умолчанию</div>
                <div class="calc-field-control">
                    <input type="text"
                           name="PROPERTY_VALUES[DEFAULT_VALUE_TEXT]"
                           class="calc-input calc-input-wide"
                           value="<?= $currentType === 'text' ? htmlspecialcharsbx($defaultValue) : '' ?>"
                           data-default-text>
                </div>
            </div>
        </div>

        <!-- ============================================== -->
        <!-- ПАРАМЕТРЫ ДЛЯ ЧЕКБОКСА (checkbox) -->
        <!-- ============================================== -->
        <div class="calc-field-section calc-field-params"
             data-for-type="checkbox"
             style="<?= $currentType === 'checkbox' ? '' : 'display: none;' ?>">
            <div class="calc-field-section-title">Параметры чекбокса</div>

            <div class="calc-field-row">
                <div class="calc-field-label">По умолчанию</div>
                <div class="calc-field-control">
                    <label class="calc-checkbox">
                        <input type="checkbox"
                               name="PROPERTY_VALUES[DEFAULT_VALUE]"
                               value="Y"
                               <?= ($currentType === 'checkbox' && $defaultValue === 'Y') ? 'checked' : '' ?>
                               data-default-checkbox>
                        <span>Включено по умолчанию</span>
                    </label>
                </div>
            </div>
        </div>

        <!-- ============================================== -->
        <!-- ПАРАМЕТРЫ ДЛЯ СПИСКА (select) -->
        <!-- ============================================== -->
        <div class="calc-field-section calc-field-params"
             data-for-type="select"
             style="<?= $currentType === 'select' ? '' : 'display: none;' ?>">
            <div class="calc-field-section-title">Варианты списка</div>

            <div class="calc-options-editor" id="options-editor">
                <div class="calc-options-header">
                    <div class="calc-options-header-default">По умолч.</div>
                    <div class="calc-options-header-value">Код (value)</div>
                    <div class="calc-options-header-label">Название (label)</div>
                    <div class="calc-options-header-action"></div>
                </div>

                <div class="calc-options-list" id="options-list">
                    <?php if (!empty($options)): ?>
                        <?php foreach ($options as $index => $option): ?>
                            <div class="calc-option-row" data-index="<?= (int)$index ?>">
                                <div class="calc-option-default">
                                    <input type="radio"
                                           name="DEFAULT_OPTION"
                                           value="<?= htmlspecialcharsbx($option['VALUE'] ?? '') ?>"
                                           <?= ($defaultValue === ($option['VALUE'] ?? '')) ? 'checked' : '' ?>
                                           title="Сделать значением по умолчанию">
                                </div>
                                <div class="calc-option-value">
                                    <input type="text"
                                           name="PROPERTY_VALUES[OPTIONS][<?= (int)$index ?>][VALUE]"
                                           class="calc-input"
                                           value="<?= htmlspecialcharsbx($option['VALUE'] ?? '') ?>"
                                           placeholder="glossy">
                                </div>
                                <div class="calc-option-label">
                                    <input type="text"
                                           name="PROPERTY_VALUES[OPTIONS][<?= (int)$index ?>][DESCRIPTION]"
                                           class="calc-input"
                                           value="<?= htmlspecialcharsbx($option['DESCRIPTION'] ?? '') ?>"
                                           placeholder="Глянцевая">
                                </div>
                                <div class="calc-option-action">
                                    <button type="button" class="calc-option-remove" title="Удалить вариант">✕</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <button type="button" class="calc-option-add" id="add-option-btn">
                    <span class="calc-option-add-icon">+</span>
                    <span>Добавить вариант</span>
                </button>
            </div>
        </div>

        <!-- ============================================== -->
        <!-- ПРЕВЬЮ -->
        <!-- ============================================== -->
        <div class="calc-field-section calc-preview-section">
            <div class="calc-field-section-title">Превью поля</div>
            <div class="calc-preview-container" id="field-preview">
                <!-- Генерируется через JS -->
                <div class="calc-preview-placeholder">Выберите тип поля для отображения превью</div>
            </div>
        </div>

        <!-- ============================================== -->
        <!-- КНОПКИ -->
        <!-- ============================================== -->
        <div class="calc-field-buttons">
            <button type="submit" name="save" value="Y" class="calc-btn calc-btn-success">Сохранить</button>
            <button type="submit" name="apply" value="Y" class="calc-btn calc-btn-primary">Применить</button>
            <a href="<?= htmlspecialcharsbx($backUrl) ?>" class="calc-btn calc-btn-link">Отмена</a>
        </div>
    </form>
</div>

<script>
    BX.ready(function() {
        new CalcCustomFieldEditor('calc-field-editor', <?= (int)count($options) ?>);
    });
</script>
