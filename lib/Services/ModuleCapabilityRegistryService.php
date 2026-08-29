<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

/**
 * Versioned, allowlisted catalog of PROSPEKT modules and storefront/admin
 * capabilities. Phase 3A deliberately changes only provider-owned options
 * that are already enforced by their runtime modules.
 */
class ModuleCapabilityRegistryService
{
    public const CONTRACT = 'prospektweb.control-plane/catalog/v1';

    private const MODULES = [
        [
            'id' => 'prospektweb.calc',
            'name' => 'Калькуляции PROSPEKT',
            'description' => 'Центр управления, пресеты, справочники и редактор калькуляций.',
            'capabilities' => [
                [
                    'id' => 'admin.calculator.context_tools',
                    'name' => 'Контекстные инструменты калькулятора',
                    'description' => 'Кнопки и инструменты калькуляций в административных карточках.',
                    'surface' => 'admin',
                    'defaultEnabled' => true,
                    'mutable' => false,
                    'state' => 'managed-later',
                    'risk' => 'medium',
                    'requiresReload' => true,
                ],
            ],
        ],
        [
            'id' => 'prospektweb.frontcalc',
            'name' => 'Калькулятор витрины',
            'description' => 'Публичный калькулятор продукции и расчётные позиции корзины.',
            'capabilities' => [
                [
                    'id' => 'storefront.calculator',
                    'name' => 'Калькулятор на витрине',
                    'description' => 'Публичный калькулятор, расчёт цены и добавление результата в корзину.',
                    'surface' => 'storefront',
                    'defaultEnabled' => true,
                    'mutable' => false,
                    'state' => 'managed-later',
                    'risk' => 'high',
                    'requiresReload' => true,
                ],
                [
                    'id' => 'storefront.cart.calculation_edit',
                    'name' => 'Расчётные позиции корзины',
                    'description' => 'Отображение и редактирование параметров рассчитанных товаров в корзине.',
                    'surface' => 'storefront',
                    'defaultEnabled' => true,
                    'mutable' => false,
                    'state' => 'managed-later',
                    'risk' => 'high',
                    'requiresReload' => true,
                ],
            ],
        ],
        [
            'id' => 'prospektweb.propvalmanager',
            'name' => 'Описания свойств',
            'description' => 'Подсказки и описания значений свойств товаров и торговых предложений.',
            'capabilities' => [
                [
                    'id' => 'storefront.property_descriptions',
                    'name' => 'Подсказки к свойствам',
                    'description' => 'Показывает покупателям и администраторам пояснения к значениям свойств.',
                    'surface' => 'storefront+admin',
                    'defaultEnabled' => true,
                    'mutable' => true,
                    'state' => 'managed',
                    'risk' => 'low',
                    'requiresReload' => true,
                    'optionModule' => 'prospektweb.propvalmanager',
                    'optionName' => 'ENABLED',
                    'optionDefault' => 'Y',
                ],
                [
                    'id' => 'storefront.catalog.sort_popularity_by_sort',
                    'name' => 'Замена сортировки по популярности обычной сортировкой по SORT',
                    'description' => 'Название «Сначала популярные» на витрине не меняется, но порядок товаров определяется полем «Сортировка» в карточке товара.',
                    'surface' => 'storefront',
                    'group' => 'Каталог',
                    'tooltip' => 'Администратор самостоятельно определяет популярность значениями поля SORT: меньшие значения показываются раньше.',
                    'defaultEnabled' => true,
                    'mutable' => true,
                    'state' => 'managed',
                    'risk' => 'medium',
                    'requiresReload' => true,
                    'optionModule' => 'prospektweb.propvalmanager',
                    'optionName' => 'SORT_POPULARITY_BY_SORT',
                    'optionDefault' => 'Y',
                ],
                [
                    'id' => 'storefront.catalog.advanced_section_filter',
                    'name' => 'Расширенный фильтр в разделах',
                    'description' => 'Показывает расширенный фильтр только в разделах каталога; в детальной карточке товара фильтр не выводится.',
                    'surface' => 'storefront',
                    'group' => 'Каталог',
                    'tooltip' => '',
                    'defaultEnabled' => true,
                    'mutable' => true,
                    'state' => 'managed',
                    'risk' => 'low',
                    'requiresReload' => true,
                    'optionModule' => 'prospektweb.propvalmanager',
                    'optionName' => 'ADVANCED_SECTION_FILTER',
                    'optionDefault' => 'Y',
                ],
                [
                    'id' => 'storefront.catalog.characteristics_accordion',
                    'name' => 'Характеристики товара в аккордеоне',
                    'description' => 'Скрывает свойства товара и не участвующие в выборе свойства ТП в компактный аккордеон «Характеристики» на детальной странице и в списках.',
                    'surface' => 'storefront',
                    'group' => 'Каталог',
                    'tooltip' => 'Выбранное торговое предложение дополняет аккордеон своими свойствами, даже если они не используются шаблоном выбора ТП.',
                    'defaultEnabled' => true,
                    'mutable' => true,
                    'state' => 'managed',
                    'risk' => 'low',
                    'requiresReload' => true,
                    'optionModule' => 'prospektweb.propvalmanager',
                    'optionName' => 'CHARACTERISTICS_ACCORDION',
                    'optionDefault' => 'Y',
                ],
            ],
        ],
        [
            'id' => 'prospektweb.storefrontui',
            'name' => 'Публичный интерфейс',
            'description' => 'Управляемые визуальные и адаптивные доработки публичного шаблона Aspro Premier.',
            'capabilities' => [
                [
                    'id' => 'storefront.catalog.characteristics_presentation',
                    'name' => 'Компактное оформление характеристик',
                    'description' => 'Показывает характеристики в единой компактной таблице в списках, быстром просмотре и нижнем табе детальной карточки.',
                    'surface' => 'storefront',
                    'group' => 'Каталог',
                    'tooltip' => 'На мобильных сохраняются две колонки 42/58, разделители, многоточие и доступные подсказки без горизонтального скролла.',
                    'defaultEnabled' => true,
                    'mutable' => true,
                    'state' => 'managed',
                    'risk' => 'low',
                    'requiresReload' => true,
                    'optionModule' => 'prospektweb.storefrontui',
                    'optionName' => 'CHARACTERISTICS_PRESENTATION',
                    'optionDefault' => 'Y',
                ],
                [
                    'id' => 'storefront.catalog.image_cover',
                    'name' => 'Заполнение области изображениями',
                    'description' => 'Заполняет изображением всю рамку товара в каталогах и мобильной галерее детальной карточки.',
                    'surface' => 'storefront',
                    'group' => 'Каталог',
                    'tooltip' => 'Реальные изображения кадрируются по центру; штатная заглушка без изображения не обрезается.',
                    'defaultEnabled' => true,
                    'mutable' => true,
                    'state' => 'managed',
                    'risk' => 'low',
                    'requiresReload' => true,
                    'optionModule' => 'prospektweb.storefrontui',
                    'optionName' => 'CATALOG_IMAGE_COVER',
                    'optionDefault' => 'Y',
                ],
                [
                    'id' => 'mobile.catalog.section_description_expand',
                    'name' => 'Стрелка раскрытия описания раздела',
                    'description' => 'На мобильных краткое SEO-описание любого раздела или подраздела сворачивается и раскрывается одной стрелкой.',
                    'surface' => 'storefront-mobile',
                    'group' => 'Мобильная версия',
                    'tooltip' => 'Частичное скрытие краткого описания раздела (SEO-описания) и отображение по клику целиком без второго слоя «Показать полностью».',
                    'defaultEnabled' => true,
                    'mutable' => true,
                    'state' => 'managed',
                    'risk' => 'low',
                    'requiresReload' => true,
                    'optionModule' => 'prospektweb.storefrontui',
                    'optionName' => 'MOBILE_SECTION_DESCRIPTION',
                    'optionDefault' => 'Y',
                ],
                [
                    'id' => 'storefront.homepage.banner_readability',
                    'name' => 'Читаемость баннеров главной страницы',
                    'description' => 'Добавляет контролируемый градиент, контраст текста и фокус-состояния баннерам главной страницы.',
                    'surface' => 'storefront',
                    'group' => 'Главная страница',
                    'tooltip' => '',
                    'defaultEnabled' => true,
                    'mutable' => true,
                    'state' => 'managed',
                    'risk' => 'low',
                    'requiresReload' => true,
                    'optionModule' => 'prospektweb.storefrontui',
                    'optionName' => 'HOMEPAGE_BANNER_READABILITY',
                    'optionDefault' => 'Y',
                ],
                [
                    'id' => 'mobile.header.logo_size',
                    'name' => 'Размер логотипа мобильной шапки',
                    'description' => 'Сохраняет проверенный размер логотипа в мобильной шапке.',
                    'surface' => 'storefront-mobile',
                    'group' => 'Мобильная версия',
                    'tooltip' => '',
                    'defaultEnabled' => true,
                    'mutable' => true,
                    'state' => 'managed',
                    'risk' => 'low',
                    'requiresReload' => true,
                    'optionModule' => 'prospektweb.storefrontui',
                    'optionName' => 'MOBILE_HEADER_LOGO',
                    'optionDefault' => 'Y',
                ],
                [
                    'id' => 'storefront.product_detail.editorial_typography',
                    'name' => 'Типографика описания товара',
                    'description' => 'Выравнивает размеры заголовков описания и инструкции по подготовке макета.',
                    'surface' => 'storefront',
                    'group' => 'Карточка товара',
                    'tooltip' => '',
                    'defaultEnabled' => true,
                    'mutable' => true,
                    'state' => 'managed',
                    'risk' => 'low',
                    'requiresReload' => true,
                    'optionModule' => 'prospektweb.storefrontui',
                    'optionName' => 'PRODUCT_DETAIL_EDITORIAL',
                    'optionDefault' => 'Y',
                ],
                [
                    'id' => 'storefront.header.wide_menu_image_density',
                    'name' => 'Чёткость изображений широкого меню',
                    'description' => 'Запрашивает изображения меню с повышенной плотностью без изменения их видимого размера.',
                    'surface' => 'storefront',
                    'group' => 'Шапка сайта',
                    'tooltip' => '',
                    'defaultEnabled' => true,
                    'mutable' => true,
                    'state' => 'managed',
                    'risk' => 'low',
                    'requiresReload' => true,
                    'optionModule' => 'prospektweb.storefrontui',
                    'optionName' => 'HEADER_MENU_IMAGE_DENSITY',
                    'optionDefault' => 'Y',
                ],
            ],
        ],
        [
            'id' => 'prospektweb.companyrequisites',
            'name' => 'Реквизиты компаний',
            'description' => 'Подсказки организаций, банков и адресов при оформлении заказа.',
            'capabilities' => [
                [
                    'id' => 'storefront.checkout.company_suggestions',
                    'name' => 'Подсказки реквизитов',
                    'description' => 'Автозаполнение реквизитов компании и адреса в форме заказа.',
                    'surface' => 'storefront',
                    'defaultEnabled' => true,
                    'mutable' => true,
                    'state' => 'managed',
                    'risk' => 'medium',
                    'requiresReload' => true,
                    'optionModule' => 'prospektweb.companyrequisites',
                    'optionName' => 'enabled',
                    'optionDefault' => 'Y',
                ],
            ],
        ],
        [
            'id' => 'prospektweb.partnermanager',
            'name' => 'Управление партнёрами',
            'description' => 'Группы партнёров товаров, договорный доступ и внутренняя техническая информация.',
            'capabilities' => [
                [
                    'id' => 'storefront.product.partners',
                    'name' => 'Партнёры в карточке товара',
                    'description' => 'Показывает сгруппированных партнёров одиночным блоком или слайдером.',
                    'surface' => 'storefront+admin',
                    'group' => 'Партнёры',
                    'tooltip' => 'Состав партнёров хранится в BRAND, обязательные группы и описания — в PARTNER_GROUPS.',
                    'defaultEnabled' => true,
                    'mutable' => true,
                    'state' => 'managed',
                    'risk' => 'medium',
                    'requiresReload' => true,
                    'optionModule' => 'prospektweb.partnermanager',
                    'optionName' => 'ENABLED',
                    'optionDefault' => 'Y',
                ],
                [
                    'id' => 'storefront.partner.access_policy',
                    'name' => 'Договорный доступ к партнёрам',
                    'description' => 'Открывает партнёров с договором всем, остальных — только разрешённым группам.',
                    'surface' => 'storefront',
                    'group' => 'Партнёры',
                    'tooltip' => 'Включайте после классификации партнёров и устранения товаров без групп.',
                    'defaultEnabled' => false,
                    'mutable' => false,
                    'state' => 'managed-in-workspace',
                    'risk' => 'high',
                    'requiresReload' => true,
                ],
            ],
        ],
        [
            'id' => 'prospektweb.layoutfiles',
            'name' => 'Макеты и дата получения',
            'description' => 'Файлы макетов в корзине и выбор желаемой даты получения заказа.',
            'capabilities' => [
                [
                    'id' => 'storefront.cart.layout_uploads',
                    'name' => 'Загрузка макетов',
                    'description' => 'Прикрепление и хранение файлов макетов для позиций корзины.',
                    'surface' => 'storefront',
                    'defaultEnabled' => true,
                    'mutable' => false,
                    'state' => 'managed-later',
                    'risk' => 'high',
                    'requiresReload' => true,
                ],
                [
                    'id' => 'storefront.cart.desired_receive_date',
                    'name' => 'Желаемая дата получения',
                    'description' => 'Выбор допустимой даты и времени получения заказа в корзине.',
                    'surface' => 'storefront',
                    'defaultEnabled' => true,
                    'mutable' => false,
                    'state' => 'managed-later',
                    'risk' => 'medium',
                    'requiresReload' => true,
                ],
                [
                    'id' => 'storefront.contacts.gallery',
                    'name' => 'Фотогалерея на странице контактов',
                    'description' => 'Показывает до шести фотографий горизонтальной плиткой, остальные открывает в штатном просмотрщике.',
                    'surface' => 'storefront',
                    'group' => 'Контакты',
                    'tooltip' => 'Фотографии и их порядок настраиваются в разделе «Настройки → Витрина».',
                    'defaultEnabled' => false,
                    'mutable' => true,
                    'state' => 'managed',
                    'risk' => 'low',
                    'requiresReload' => true,
                    'optionModule' => 'prospektweb.layoutfiles',
                    'optionName' => 'CONTACT_GALLERY_ENABLED',
                    'optionDefault' => 'N',
                ],
            ],
        ],
        [
            'id' => 'prospektweb.offerfilter',
            'name' => 'Инструменты торговых предложений',
            'description' => 'Фильтрация и массовая генерация торговых предложений в админке.',
            'capabilities' => [
                [
                    'id' => 'admin.offers.generator',
                    'name' => 'Генератор торговых предложений',
                    'description' => 'Административные инструменты генерации и фильтрации предложений.',
                    'surface' => 'admin',
                    'defaultEnabled' => true,
                    'mutable' => false,
                    'state' => 'managed-later',
                    'risk' => 'medium',
                    'requiresReload' => true,
                ],
                [
                    'id' => 'admin.offers.mass_property_editor',
                    'name' => 'Массовое изменение свойств ТП',
                    'description' => 'Изменяет выбранные торговые предложения или, после подтверждения, все ТП товара. Поддерживает явную очистку значений.',
                    'surface' => 'admin+public-edit-mode',
                    'group' => 'Торговые предложения',
                    'tooltip' => 'По умолчанию свойства не выбраны. Рабочий набор сохраняется для администратора; каждое свойство можно не менять, установить или очистить.',
                    'defaultEnabled' => true,
                    'mutable' => true,
                    'state' => 'managed',
                    'risk' => 'high',
                    'requiresReload' => true,
                    'optionModule' => 'prospektweb.offerfilter',
                    'optionName' => 'MASS_PROPERTY_EDITOR_ENABLED',
                    'optionDefault' => 'Y',
                ],
            ],
        ],
    ];

    /** @return array<string, mixed> */
    public function getCatalog(): array
    {
        $modules = [];
        $installedCount = 0;
        $capabilityCount = 0;
        $mutableCount = 0;
        $enabledCount = 0;

        foreach (self::MODULES as $moduleDefinition) {
            $moduleId = (string)$moduleDefinition['id'];
            $installed = ModuleManager::isModuleInstalled($moduleId);
            $version = $installed ? trim((string)ModuleManager::getVersion($moduleId)) : '';
            $capabilities = [];

            if ($installed) {
                $installedCount++;
            }

            foreach ($moduleDefinition['capabilities'] as $capabilityDefinition) {
                $mutable = (bool)$capabilityDefinition['mutable'];
                $enabled = $installed && $this->readCapabilityEnabled($capabilityDefinition);
                $capabilities[] = [
                    'id' => (string)$capabilityDefinition['id'],
                    'name' => (string)$capabilityDefinition['name'],
                    'description' => (string)$capabilityDefinition['description'],
                    'surface' => (string)$capabilityDefinition['surface'],
                    'group' => (string)($capabilityDefinition['group'] ?? 'Основные функции'),
                    'tooltip' => (string)($capabilityDefinition['tooltip'] ?? ''),
                    'enabled' => $enabled,
                    'defaultEnabled' => (bool)$capabilityDefinition['defaultEnabled'],
                    'mutable' => $mutable && $installed,
                    'state' => $installed ? (string)$capabilityDefinition['state'] : 'unavailable',
                    'risk' => (string)$capabilityDefinition['risk'],
                    'requiresReload' => (bool)$capabilityDefinition['requiresReload'],
                ];

                $capabilityCount++;
                if ($mutable && $installed) {
                    $mutableCount++;
                }
                if ($enabled) {
                    $enabledCount++;
                }
            }

            $modules[] = [
                'id' => $moduleId,
                'name' => (string)$moduleDefinition['name'],
                'description' => (string)$moduleDefinition['description'],
                'version' => $version,
                'installed' => $installed,
                'status' => $installed ? 'installed' : 'not-installed',
                'capabilities' => $capabilities,
            ];
        }

        $payload = [
            'contract' => self::CONTRACT,
            'summary' => [
                'modules' => count($modules),
                'installedModules' => $installedCount,
                'capabilities' => $capabilityCount,
                'mutableCapabilities' => $mutableCount,
                'enabledCapabilities' => $enabledCount,
            ],
            'modules' => $modules,
        ];
        $payload['revision'] = $this->buildRevision($payload);

        return [
            'contract' => $payload['contract'],
            'revision' => $payload['revision'],
            'summary' => $payload['summary'],
            'modules' => $payload['modules'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function setCapability(string $capabilityId, bool $enabled, string $expectedRevision, int $userId): array
    {
        if ($capabilityId === '') {
            throw new \InvalidArgumentException('capabilityId is required');
        }
        if ($expectedRevision === '') {
            throw new \InvalidArgumentException('revision is required');
        }

        return $this->withWriteLock(function () use ($capabilityId, $enabled, $expectedRevision, $userId): array {
            $currentCatalog = $this->getCatalog();
            if (!hash_equals((string)$currentCatalog['revision'], $expectedRevision)) {
                throw new \RuntimeException('CATALOG_REVISION_CONFLICT', 409);
            }

            [$moduleDefinition, $capabilityDefinition] = $this->findCapability($capabilityId);
            $moduleId = (string)$moduleDefinition['id'];
            if (!(bool)$capabilityDefinition['mutable']) {
                throw new \InvalidArgumentException('Capability is read-only in control-plane v1');
            }
            if (!ModuleManager::isModuleInstalled($moduleId)) {
                throw new \InvalidArgumentException('Capability module is not installed');
            }
            if (!class_exists('CEventLog')) {
                throw new \RuntimeException('Bitrix event log is unavailable');
            }

            $before = $this->readCapabilityEnabled($capabilityDefinition);
            if ($before === $enabled) {
                return $currentCatalog;
            }

            $optionModule = (string)$capabilityDefinition['optionModule'];
            $optionName = (string)$capabilityDefinition['optionName'];
            Option::set($optionModule, $optionName, $enabled ? 'Y' : 'N');

            $readBack = $this->readCapabilityEnabled($capabilityDefinition);
            if ($readBack !== $enabled) {
                Option::set($optionModule, $optionName, $before ? 'Y' : 'N');
                throw new \RuntimeException('Capability option write verification failed');
            }

            try {
                $updatedCatalog = $this->getCatalog();
                $auditResult = \CEventLog::Add([
                    'SEVERITY' => 'SECURITY',
                    'AUDIT_TYPE_ID' => 'PROSPEKTWEB_CONTROL_CENTER_CAPABILITY_CHANGED',
                    'MODULE_ID' => 'prospektweb.calc',
                    'ITEM_ID' => $capabilityId,
                    'DESCRIPTION' => $this->encodeJson([
                        'contract' => self::CONTRACT,
                        'userId' => max(0, $userId),
                        'capabilityId' => $capabilityId,
                        'providerModuleId' => $optionModule,
                        'optionName' => $optionName,
                        'beforeEnabled' => $before,
                        'afterEnabled' => $enabled,
                        'beforeRevision' => (string)$currentCatalog['revision'],
                        'afterRevision' => (string)$updatedCatalog['revision'],
                    ]),
                ]);
                if ($auditResult === false) {
                    throw new \RuntimeException('Bitrix event log rejected the capability change');
                }
            } catch (\Throwable $exception) {
                try {
                    Option::set($optionModule, $optionName, $before ? 'Y' : 'N');
                    if ($this->readCapabilityEnabled($capabilityDefinition) !== $before) {
                        throw new \RuntimeException('Capability option rollback verification failed');
                    }
                } catch (\Throwable $rollbackException) {
                    throw new \RuntimeException('Capability audit failed and option rollback failed', 0, $rollbackException);
                }

                throw new \RuntimeException('Capability audit failed and option change was rolled back', 0, $exception);
            }

            $this->clearCapabilityPublicCache($capabilityId);

            return $updatedCatalog;
        });
    }

    private function clearCapabilityPublicCache(string $capabilityId): void
    {
        if ($capabilityId !== 'storefront.contacts.gallery') {
            return;
        }

        try {
            if (
                Loader::includeModule('prospektweb.layoutfiles')
                && class_exists('\\Prospektweb\\LayoutFiles\\ContactGalleryManager')
            ) {
                \Prospektweb\LayoutFiles\ContactGalleryManager::clearPublicCache();
            }
        } catch (\Throwable $exception) {
            // The audited option write remains authoritative when public cache is temporarily unavailable.
        }
    }

    /** @param array<string, mixed> $definition */
    private function readCapabilityEnabled(array $definition): bool
    {
        if (!(bool)$definition['mutable']) {
            return (bool)$definition['defaultEnabled'];
        }

        return Option::get(
            (string)$definition['optionModule'],
            (string)$definition['optionName'],
            (string)$definition['optionDefault']
        ) === 'Y';
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function findCapability(string $capabilityId): array
    {
        foreach (self::MODULES as $moduleDefinition) {
            foreach ($moduleDefinition['capabilities'] as $capabilityDefinition) {
                if ((string)$capabilityDefinition['id'] === $capabilityId) {
                    return [$moduleDefinition, $capabilityDefinition];
                }
            }
        }

        throw new \InvalidArgumentException('Unknown capabilityId');
    }

    /** @param array<string, mixed> $payload */
    private function buildRevision(array $payload): string
    {
        return hash('sha256', $this->encodeJson($this->canonicalize($payload)));
    }

    /** @return mixed */
    private function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $isList = $value === [] || array_keys($value) === range(0, count($value) - 1);
        if (!$isList) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /** @param mixed $value */
    private function encodeJson($value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Unable to encode control-plane catalog');
        }

        return $encoded;
    }

    /** @template T @param callable():T $callback @return T */
    private function withWriteLock(callable $callback)
    {
        $lockPath = rtrim(sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR
            . 'prospektweb-control-plane-'
            . substr(hash('sha256', __FILE__), 0, 24)
            . '.lock';
        $handle = fopen($lockPath, 'c+');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Unable to open capability write lock');
        }
        @chmod($lockPath, 0600);

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Unable to acquire capability write lock');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
