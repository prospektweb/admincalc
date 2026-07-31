/**
 * Редактор кастомных полей калькулятора
 */
class CalcCustomFieldEditor {
    constructor(containerId, initialOptionCount = 0) {
        this.container = document.getElementById(containerId);
        if (!this.container) return;

        this.form = this.container.querySelector('#calc-field-form');
        this.typeSelector = this.container.querySelector('#field-type-selector');
        this.optionsList = this.container.querySelector('#options-list');
        this.previewContainer = this.container.querySelector('#field-preview');
        this.optionIndex = initialOptionCount;

        this.init();
    }

    init() {
        this.bindCodeNormalization();
        this.bindTypeSelector();
        this.bindOptionsEditor();
        this.bindPreviewUpdates();
        this.updatePreview();
    }

    bindCodeNormalization() {
        const codeInput = this.form?.querySelector('[data-field-code]');
        if (!codeInput) return;
        const originalCode = codeInput.value;

        codeInput.addEventListener('input', () => {
            const rawValue = codeInput.value;
            const isCaseOnlyEdit = originalCode
                && rawValue.toUpperCase() === originalCode.toUpperCase()
                && /^[A-Za-z][A-Za-z0-9_]*$/.test(rawValue);
            if (isCaseOnlyEdit) return;

            const cursor = codeInput.selectionStart;
            codeInput.value = rawValue.toUpperCase().replace(/[^A-Z0-9_]+/g, '_');
            if (cursor !== null) codeInput.setSelectionRange(cursor, cursor);
        });
        codeInput.addEventListener('blur', () => {
            codeInput.value = codeInput.value.replace(/^_+|_+$/g, '');
            if (codeInput.value && !/^[A-Z]/.test(codeInput.value)) {
                codeInput.value = `FIELD_${codeInput.value}`;
            }
        });
    }

    /**
     * Переключение типа поля
     */
    bindTypeSelector() {
        if (! this.typeSelector) return;

        const radios = this.typeSelector.querySelectorAll('input[type="radio"]');
        radios.forEach(radio => {
            radio.addEventListener('change', () => {
                // Обновляем активный класс
                this.typeSelector.querySelectorAll('.calc-type-option').forEach(opt => {
                    opt.classList.remove('active');
                });
                radio.closest('.calc-type-option').classList.add('active');

                this.updateVisibleParams();
                this.updatePreview();
            });
        });

        // Инициализация видимости
        this.updateVisibleParams();
    }

    /**
     * Показываем только релевантные параметры
     */
    updateVisibleParams() {
        const selectedType = this.form.querySelector('input[name="PROPERTY_VALUES[FIELD_TYPE]"]:checked')?.value || '';

        this.container.querySelectorAll('.calc-field-params').forEach(section => {
            const forType = section.dataset.forType;
            section.style.display = forType === selectedType ? '' : 'none';
        });
    }

    /**
     * Редактор опций для select
     */
    bindOptionsEditor() {
        const addBtn = this.container.querySelector('#add-option-btn');
        if (addBtn) {
            addBtn.addEventListener('click', () => this.addOption());
        }

        if (this.optionsList) {
            // Удаление опции
            this.optionsList.addEventListener('click', (e) => {
                if (e.target.closest('.calc-option-remove')) {
                    const row = e.target.closest('.calc-option-row');
                    row.remove();
                    this.updatePreview();
                }
            });

            // Обновление превью при вводе
            this.optionsList.addEventListener('input', () => {
                this.updateOptionRadioValues();
                this.updatePreview();
            });
        }
    }

    /**
     * Добавление новой опции
     */
    addOption() {
        const row = document.createElement('div');
        row.className = 'calc-option-row';
        row.dataset.index = this.optionIndex;
        row.innerHTML = `
            <div class="calc-option-default">
                <input type="radio" 
                       name="DEFAULT_OPTION" 
                       value=""
                       title="Сделать значением по умолчанию">
            </div>
            <div class="calc-option-value">
                <input type="text" 
                       name="PROPERTY_VALUES[OPTIONS][${this.optionIndex}][VALUE]" 
                       class="calc-input"
                       placeholder="glossy">
            </div>
            <div class="calc-option-label">
                <input type="text" 
                       name="PROPERTY_VALUES[OPTIONS][${this.optionIndex}][DESCRIPTION]" 
                       class="calc-input"
                       placeholder="Глянцевая">
            </div>
            <div class="calc-option-action">
                <button type="button" class="calc-option-remove" title="Удалить вариант">✕</button>
            </div>
        `;

        this.optionsList.appendChild(row);
        this.optionIndex++;

        // Фокус на новое поле
        row.querySelector('.calc-option-value input').focus();
    }

    /**
     * Синхронизация value для radio-кнопок опций
     */
    updateOptionRadioValues() {
        this.optionsList.querySelectorAll('.calc-option-row').forEach(row => {
            const valueInput = row.querySelector('.calc-option-value input');
            const radio = row.querySelector('.calc-option-default input[type="radio"]');
            if (valueInput && radio) {
                radio.value = valueInput.value;
            }
        });
    }

    /**
     * Привязка обновления превью
     */
    bindPreviewUpdates() {
        const inputs = this.form.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            input.addEventListener('input', () => this.updatePreview());
            input.addEventListener('change', () => this.updatePreview());
        });
    }

    /**
     * Генерация превью
     */
    updatePreview() {
        const type = this.form.querySelector('input[name="PROPERTY_VALUES[FIELD_TYPE]"]:checked')?.value || '';
        const name = this.form.querySelector('input[name="NAME"]')?.value || 'Название поля';
        const isRequired = this.form.querySelector('input[name="PROPERTY_VALUES[IS_REQUIRED]"]')?.checked || false;
        const unit = this.form.querySelector('input[name="PROPERTY_VALUES[UNIT]"]')?.value || '';

        if (! type) {
            this.previewContainer.innerHTML = '<div class="calc-preview-placeholder">Выберите тип поля для отображения превью</div>';
            return;
        }

        let html = `
            <div class="calc-preview-field">
                <label class="calc-preview-label">
                    ${this.escapeHtml(name)}
                    ${isRequired ? '<span class="calc-preview-required">*</span>' : ''}
                    ${unit ? `<span class="calc-preview-unit">(${this.escapeHtml(unit)})</span>` : ''}
                </label>
                <div class="calc-preview-input">
                    ${this.renderPreviewInput(type)}
                </div>
            </div>
        `;

        this.previewContainer.innerHTML = html;
    }

    /**
     * Рендер инпута для превью
     */
    renderPreviewInput(type) {
        switch (type) {
            case 'number':
                const min = this.form.querySelector('input[name="PROPERTY_VALUES[MIN_VALUE]"]')?.value || '';
                const max = this.form.querySelector('input[name="PROPERTY_VALUES[MAX_VALUE]"]')?.value || '';
                const step = this.form.querySelector('input[name="PROPERTY_VALUES[STEP_VALUE]"]')?.value || '1';
                const defaultNum = this.form.querySelector('[data-default-number]')?.value || '';
                return `
                    <input type="number" 
                           class="calc-preview-control" 
                           value="${this.escapeHtml(defaultNum)}"
                           ${min ?  `min="${min}"` : ''}
                           ${max ? `max="${max}"` : ''}
                           step="${step || 'any'}"
                           readonly>
                `;

            case 'text':
                const maxLength = this.form.querySelector('input[name="PROPERTY_VALUES[MAX_LENGTH]"]')?.value || '';
                const defaultText = this.form.querySelector('[data-default-text]')?.value || '';
                return `
                    <input type="text" 
                           class="calc-preview-control" 
                           value="${this.escapeHtml(defaultText)}"
                           ${maxLength ? `maxlength="${maxLength}"` : ''}
                           placeholder="Введите текст..."
                           readonly>
                    ${maxLength ? `<span class="calc-preview-hint">макс.${maxLength} символов</span>` : ''}
                `;

            case 'checkbox': 
                const defaultChecked = this.form.querySelector('[data-default-checkbox]')?.checked || false;
                return `
                    <label class="calc-preview-checkbox">
                        <input type="checkbox" ${defaultChecked ? 'checked' : ''} disabled>
                        <span>Да</span>
                    </label>
                `;

            case 'select': 
                const options = this.collectOptions();
                const defaultVal = this.form.querySelector('input[name="DEFAULT_OPTION"]:checked')?.value || '';
                if (options.length === 0) {
                    return `<span class="calc-preview-empty">Добавьте варианты списка</span>`;
                }
                return `
                    <select class="calc-preview-control" disabled>
                        ${options.map(opt => `
                            <option value="${this.escapeHtml(opt.value)}" ${opt.value === defaultVal ? 'selected' : ''}>
                                ${this.escapeHtml(opt.label || opt.value || '(пусто)')}
                            </option>
                        `).join('')}
                    </select>
                `;
        }

        return '';
    }

    /**
     * Сбор опций для select
     */
    collectOptions() {
        const options = [];
        if (! this.optionsList) return options;

        this.optionsList.querySelectorAll('.calc-option-row').forEach(row => {
            const value = row.querySelector('.calc-option-value input')?.value || '';
            const label = row.querySelector('.calc-option-label input')?.value || '';
            if (value || label) {
                options.push({ value, label });
            }
        });
        return options;
    }

    /**
     * Экранирование HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }
}

// Регистрация в глобальной области
window.CalcCustomFieldEditor = CalcCustomFieldEditor;
