<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Deployment;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;

final class CatalogSchemaDeploymentService
{
    public const APPLY_CONFIRMATION = 'ПРИМЕНИТЬ ТИПОГРАФСКИЙ ПРОФИЛЬ';
    public const PLAN_CONTRACT = 'prospektweb.bitrix.catalog-profile-plan/v1';

    private CatalogSchemaProfileService $profiles;
    private ConfigManager $config;
    private string $profilePath;

    public function __construct(
        ?CatalogSchemaProfileService $profiles = null,
        ?ConfigManager $config = null,
        ?string $profilePath = null
    ) {
        $this->profiles = $profiles ?? new CatalogSchemaProfileService();
        $this->config = $config ?? new ConfigManager();
        $this->profilePath = $profilePath
            ?? dirname(__DIR__, 2) . '/resources/deployment/prospekt-print-typography-v1.json';
    }

    /** @return array<string,mixed> */
    public function analyze(): array
    {
        $this->assertModules();
        $profile = $this->profiles->load($this->profilePath);
        $current = $this->captureCurrentProfile();
        $plan = $this->profiles->analyze($profile, $current);
        $counts = $this->contentCounts();
        $plan['catalog'] = [
            'products_iblock_id' => $this->config->getProductIblockId(),
            'offers_iblock_id' => $this->config->getSkuIblockId(),
            'content_counts' => $counts,
            'populated' => array_sum($counts) > 0,
        ];
        $plan['confirmation_phrase'] = self::APPLY_CONFIRMATION;
        $plan['warnings'] = $this->warnings($plan);
        return $plan;
    }

    /**
     * @return array<string,mixed>
     */
    public function apply(
        string $expectedPlanHash,
        string $confirmation,
        bool $allowPopulatedCatalog,
        int $actorUserId
    ): array {
        if (!hash_equals(self::APPLY_CONFIRMATION, trim($confirmation))) {
            throw new \InvalidArgumentException('Не введена точная подтверждающая фраза.');
        }
        if ($actorUserId <= 0) {
            throw new \InvalidArgumentException('Не определён административный пользователь.');
        }

        $plan = $this->analyze();
        if ($expectedPlanHash === '' || !hash_equals((string)$plan['plan_hash'], $expectedPlanHash)) {
            throw new \RuntimeException('Схема каталога изменилась после анализа. Выполните dry-run повторно.', 409);
        }
        if (!empty($plan['catalog']['populated']) && !$allowPopulatedCatalog) {
            throw new \RuntimeException(
                'Каталог содержит элементы или разделы. Для чистого стенда подтвердите работу с демо-контентом; '
                . 'для рабочего сайта сначала создайте полный backup.',
                409
            );
        }

        $this->preflightPlan($plan);

        $backupPath = $this->persistBackup($this->captureCurrentProfile(), $plan, $actorUserId);
        $connection = Application::getConnection();
        $connection->startTransaction();
        $applied = [];
        try {
            foreach ((array)$plan['operations'] as $operation) {
                $action = (string)($operation['action'] ?? '');
                if ($action === 'create') {
                    $propertyId = $this->createProperty((string)$operation['role'], (array)$operation['desired']);
                    $applied[] = ['action' => 'create', 'role' => $operation['role'], 'key' => $operation['key'], 'property_id' => $propertyId];
                    continue;
                }
                if ($action === 'update') {
                    $propertyId = (int)($operation['property_id'] ?? 0);
                    $this->updateProperty($propertyId, (string)$operation['role'], (array)$operation['desired']);
                    $applied[] = ['action' => 'update', 'role' => $operation['role'], 'key' => $operation['key'], 'property_id' => $propertyId];
                    continue;
                }
                if ($action === 'delete') {
                    $propertyId = (int)($operation['property_id'] ?? 0);
                    if ($propertyId <= 0 || !\CIBlockProperty::Delete($propertyId)) {
                        throw new \RuntimeException('Не удалось удалить свойство ' . (string)($operation['key'] ?? '') . '.');
                    }
                    $applied[] = ['action' => 'delete', 'role' => $operation['role'], 'key' => $operation['key'], 'property_id' => $propertyId];
                    continue;
                }
                throw new \RuntimeException('План содержит неизвестное действие: ' . $action);
            }
            $connection->commitTransaction();
        } catch (\Throwable $error) {
            $connection->rollbackTransaction();
            throw new \RuntimeException(
                'Применение профиля остановлено; выполнен rollback транзакции. Backup: ' . basename($backupPath),
                0,
                $error
            );
        }

        $readback = $this->analyze();
        $remaining = (int)$readback['summary']['create']
            + (int)$readback['summary']['update']
            + (int)$readback['summary']['delete'];
        if ($remaining !== 0) {
            throw new \RuntimeException(
                'Профиль применён не полностью: после перечитывания осталось операций: ' . $remaining
                . '. Backup: ' . basename($backupPath)
            );
        }

        return [
            'contract' => 'prospektweb.bitrix.catalog-profile-apply-result/v1',
            'success' => true,
            'profile_id' => $plan['profile_id'],
            'plan_hash' => $plan['plan_hash'],
            'backup_file' => basename($backupPath),
            'applied' => $applied,
            'readback' => $readback['summary'],
        ];
    }

    /** @return array<string,mixed> */
    public function captureCurrentProfile(): array
    {
        $this->assertModules();
        $roles = [];
        foreach ([
            'products' => $this->config->getProductIblockId(),
            'offers' => $this->config->getSkuIblockId(),
        ] as $role => $iblockId) {
            if ($iblockId <= 0) {
                throw new \RuntimeException('Не настроен инфоблок роли ' . $role . '.');
            }
            $iblock = \CIBlock::GetList([], ['ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'])->Fetch();
            if (!$iblock) {
                throw new \RuntimeException('Инфоблок роли ' . $role . ' не найден.');
            }
            $properties = [];
            $cursor = \CIBlockProperty::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['IBLOCK_ID' => $iblockId]);
            while ($property = $cursor->Fetch()) {
                $propertyId = (int)$property['ID'];
                unset($property['~NAME'], $property['~DEFAULT_VALUE']);
                $property['USER_TYPE_SETTINGS'] = $this->normalizeSerialized($property['USER_TYPE_SETTINGS'] ?? null);
                $fields = $this->profileFields($property);
                $properties[] = [
                    'key' => $this->profiles->propertyKey($fields),
                    'property_id' => $propertyId,
                    'fields' => $fields,
                    'link_target' => $this->linkTarget((int)($property['LINK_IBLOCK_ID'] ?? 0)),
                    'enums' => $this->propertyEnums($propertyId),
                    'features' => $this->propertyFeatures($propertyId),
                ];
            }
            $roles[] = [
                'role' => $role,
                'iblock' => [
                    'iblock_type_id' => (string)$iblock['IBLOCK_TYPE_ID'],
                    'code' => (string)$iblock['CODE'],
                    'xml_id' => (string)$iblock['XML_ID'],
                    'name' => (string)$iblock['NAME'],
                ],
                'properties' => $properties,
            ];
        }

        return [
            'contract' => CatalogSchemaProfileService::CONTRACT,
            'profile' => [
                'id' => 'current-catalog-schema',
                'name' => 'Текущая схема каталога',
                'mode' => 'exact-properties',
                'content_included' => false,
            ],
            'roles' => $roles,
        ];
    }

    private function createProperty(string $role, array $desired): int
    {
        $iblockId = $this->roleIblockId($role);
        $fields = $this->propertyWriteFields((array)$desired['fields'], $iblockId, $desired['link_target'] ?? null);
        $this->assertUserTypeAvailable((string)($fields['USER_TYPE'] ?? ''));
        $api = new \CIBlockProperty();
        $propertyId = (int)$api->Add($fields);
        if ($propertyId <= 0) {
            throw new \RuntimeException('Не удалось создать свойство ' . (string)($desired['key'] ?? '') . ': ' . (string)$api->LAST_ERROR);
        }
        $this->syncEnums($propertyId, (array)($desired['enums'] ?? []));
        $this->syncFeatures($propertyId, (array)($desired['features'] ?? []));
        return $propertyId;
    }

    private function updateProperty(int $propertyId, string $role, array $desired): void
    {
        if ($propertyId <= 0) {
            throw new \InvalidArgumentException('Некорректный ID свойства для обновления.');
        }
        $fields = $this->propertyWriteFields(
            (array)$desired['fields'],
            $this->roleIblockId($role),
            $desired['link_target'] ?? null
        );
        $this->assertUserTypeAvailable((string)($fields['USER_TYPE'] ?? ''));
        $api = new \CIBlockProperty();
        if (!$api->Update($propertyId, $fields)) {
            throw new \RuntimeException('Не удалось обновить свойство ' . (string)($desired['key'] ?? '') . ': ' . (string)$api->LAST_ERROR);
        }
        $this->syncEnums($propertyId, (array)($desired['enums'] ?? []));
        $this->syncFeatures($propertyId, (array)($desired['features'] ?? []));
    }

    /** @param array<string,mixed> $fields @return array<string,mixed> */
    private function propertyWriteFields(array $fields, int $iblockId, $linkTarget): array
    {
        $write = $this->profileFields($fields);
        $write['IBLOCK_ID'] = $iblockId;
        $write['LINK_IBLOCK_ID'] = is_array($linkTarget) ? $this->resolveLinkTarget($linkTarget) : 0;
        return $write;
    }

    /** @param array<string,mixed> $fields @return array<string,mixed> */
    private function profileFields(array $fields): array
    {
        $allowed = [
            'NAME', 'ACTIVE', 'SORT', 'CODE', 'DEFAULT_VALUE', 'PROPERTY_TYPE', 'ROW_COUNT', 'COL_COUNT',
            'LIST_TYPE', 'MULTIPLE', 'XML_ID', 'FILE_TYPE', 'MULTIPLE_CNT', 'WITH_DESCRIPTION', 'SEARCHABLE',
            'FILTRABLE', 'IS_REQUIRED', 'VERSION', 'USER_TYPE', 'USER_TYPE_SETTINGS', 'HINT',
        ];
        $result = [];
        foreach ($allowed as $name) {
            $result[$name] = $fields[$name] ?? null;
        }
        return $result;
    }

    /** @param array<string,mixed> $target */
    private function resolveLinkTarget(array $target): int
    {
        $code = trim((string)($target['code'] ?? ''));
        $xmlId = trim((string)($target['xml_id'] ?? ''));
        $type = trim((string)($target['iblock_type_id'] ?? ''));
        $filter = ['CHECK_PERMISSIONS' => 'N'];
        if ($code !== '') {
            $filter['CODE'] = $code;
        } elseif ($xmlId !== '') {
            $filter['XML_ID'] = $xmlId;
        } else {
            throw new \RuntimeException('Связанный инфоблок не имеет стабильного кода или XML_ID.');
        }
        if ($type !== '') {
            $filter['TYPE'] = $type;
        }
        $cursor = \CIBlock::GetList(['ID' => 'ASC'], $filter);
        $ids = [];
        while ($row = $cursor->Fetch()) {
            if ($code !== '' && (string)$row['CODE'] !== $code) {
                continue;
            }
            if ($xmlId !== '' && (string)$row['XML_ID'] !== $xmlId) {
                continue;
            }
            if ($type !== '' && (string)$row['IBLOCK_TYPE_ID'] !== $type) {
                continue;
            }
            $ids[] = (int)$row['ID'];
        }
        if (count($ids) !== 1) {
            throw new \RuntimeException('Связанный инфоблок ' . ($code !== '' ? $code : $xmlId) . ' найден неоднозначно.');
        }
        return $ids[0];
    }

    /** @param array<string,mixed> $plan */
    private function preflightPlan(array $plan): void
    {
        foreach ((array)($plan['operations'] ?? []) as $operation) {
            $action = (string)($operation['action'] ?? '');
            if ($action === 'delete') {
                if ((int)($operation['property_id'] ?? 0) <= 0) {
                    throw new \RuntimeException('План содержит свойство без корректного ID для удаления.');
                }
                continue;
            }
            if (!in_array($action, ['create', 'update'], true)) {
                throw new \RuntimeException('План содержит неизвестное действие: ' . $action);
            }
            $desired = (array)($operation['desired'] ?? []);
            $fields = (array)($desired['fields'] ?? []);
            $this->assertUserTypeAvailable((string)($fields['USER_TYPE'] ?? ''));
            if (is_array($desired['link_target'] ?? null)) {
                $this->resolveLinkTarget((array)$desired['link_target']);
            }
            if ($action === 'update' && (int)($operation['property_id'] ?? 0) <= 0) {
                throw new \RuntimeException('План содержит свойство без корректного ID для обновления.');
            }
        }
    }

    private function roleIblockId(string $role): int
    {
        if ($role === 'products') {
            return $this->config->getProductIblockId();
        }
        if ($role === 'offers') {
            return $this->config->getSkuIblockId();
        }
        throw new \InvalidArgumentException('Неизвестная роль инфоблока: ' . $role);
    }

    /** @return array<string,mixed>|null */
    private function linkTarget(int $iblockId): ?array
    {
        if ($iblockId <= 0) {
            return null;
        }
        $row = \CIBlock::GetList([], ['ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'])->Fetch();
        if (!$row) {
            return null;
        }
        return [
            'iblock_type_id' => (string)$row['IBLOCK_TYPE_ID'],
            'code' => (string)$row['CODE'],
            'xml_id' => (string)$row['XML_ID'],
            'name' => (string)$row['NAME'],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function propertyEnums(int $propertyId): array
    {
        $rows = [];
        $cursor = \CIBlockPropertyEnum::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['PROPERTY_ID' => $propertyId]);
        while ($row = $cursor->Fetch()) {
            $rows[] = [
                'value' => (string)$row['VALUE'],
                'xml_id' => (string)$row['XML_ID'],
                'sort' => (int)$row['SORT'],
                'default' => (string)$row['DEF'],
            ];
        }
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    private function propertyFeatures(int $propertyId): array
    {
        $rows = [];
        $result = Application::getConnection()->query(
            'SELECT FEATURE_ID, IS_ENABLED FROM b_iblock_property_feature WHERE PROPERTY_ID = '
            . $propertyId . ' ORDER BY FEATURE_ID'
        );
        while ($row = $result->fetch()) {
            $rows[] = [
                'feature_id' => (string)$row['FEATURE_ID'],
                'is_enabled' => (string)$row['IS_ENABLED'],
            ];
        }
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $desired */
    private function syncEnums(int $propertyId, array $desired): void
    {
        $existing = [];
        $cursor = \CIBlockPropertyEnum::GetList(['ID' => 'ASC'], ['PROPERTY_ID' => $propertyId]);
        while ($row = $cursor->Fetch()) {
            $key = trim((string)$row['XML_ID']) !== ''
                ? 'xml:' . (string)$row['XML_ID']
                : 'value:' . (string)$row['VALUE'];
            $existing[$key] = $row;
        }
        $wanted = [];
        foreach ($desired as $entry) {
            $xmlId = (string)($entry['xml_id'] ?? '');
            $value = (string)($entry['value'] ?? '');
            $key = $xmlId !== '' ? 'xml:' . $xmlId : 'value:' . $value;
            if (isset($wanted[$key])) {
                throw new \RuntimeException('Профиль содержит дублирующее значение списка ' . $key . '.');
            }
            $wanted[$key] = true;
            $fields = [
                'PROPERTY_ID' => $propertyId,
                'VALUE' => $value,
                'XML_ID' => $xmlId,
                'SORT' => (int)($entry['sort'] ?? 500),
                'DEF' => (string)($entry['default'] ?? 'N'),
            ];
            $enumApi = new \CIBlockPropertyEnum();
            if (isset($existing[$key])) {
                if (!$enumApi->Update((int)$existing[$key]['ID'], $fields)) {
                    throw new \RuntimeException('Не удалось обновить значение списка ' . $key . '.');
                }
            } elseif ((int)$enumApi->Add($fields) <= 0) {
                throw new \RuntimeException('Не удалось создать значение списка ' . $key . '.');
            }
        }
        foreach ($existing as $key => $row) {
            if (!isset($wanted[$key]) && !\CIBlockPropertyEnum::Delete((int)$row['ID'])) {
                throw new \RuntimeException('Не удалось удалить лишнее значение списка ' . $key . '.');
            }
        }
    }

    /** @param array<int,array<string,mixed>> $desired */
    private function syncFeatures(int $propertyId, array $desired): void
    {
        $connection = Application::getConnection();
        $connection->queryExecute('DELETE FROM b_iblock_property_feature WHERE PROPERTY_ID = ' . $propertyId);
        $helper = $connection->getSqlHelper();
        foreach ($desired as $entry) {
            $featureId = trim((string)($entry['feature_id'] ?? ''));
            $enabled = (string)($entry['is_enabled'] ?? 'N') === 'Y' ? 'Y' : 'N';
            if ($featureId === '') {
                continue;
            }
            $connection->queryExecute(
                "INSERT INTO b_iblock_property_feature (PROPERTY_ID, FEATURE_ID, IS_ENABLED) VALUES ("
                . $propertyId . ", '" . $helper->forSql($featureId) . "', '" . $enabled . "')"
            );
        }
    }

    private function assertUserTypeAvailable(string $userType): void
    {
        if ($userType === '') {
            return;
        }
        $definition = \CIBlockProperty::GetUserType($userType);
        if (!is_array($definition) || $definition === []) {
            throw new \RuntimeException('Не зарегистрирован пользовательский тип свойства ' . $userType . '.');
        }
    }

    /** @return array<string,int> */
    private function contentCounts(): array
    {
        $result = [];
        foreach (['products' => $this->config->getProductIblockId(), 'offers' => $this->config->getSkuIblockId()] as $role => $iblockId) {
            $result[$role . '_elements'] = (int)\CIBlockElement::GetList(
                [],
                ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'],
                []
            );
            $result[$role . '_sections'] = (int)\CIBlockSection::GetCount([
                'IBLOCK_ID' => $iblockId,
                'CHECK_PERMISSIONS' => 'N',
            ]);
        }
        return $result;
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $plan */
    private function persistBackup(array $current, array $plan, int $actorUserId): string
    {
        $directory = rtrim(Application::getDocumentRoot(), '/\\') . '/bitrix/backup/prospektweb-deployment';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Не удалось создать защищённый каталог backup.');
        }
        $document = [
            'contract' => 'prospektweb.bitrix.catalog-profile-backup/v1',
            'created_at' => gmdate('c'),
            'actor_user_id' => $actorUserId,
            'plan_hash' => (string)$plan['plan_hash'],
            'schema_only' => true,
            'warning' => 'Значения свойств элементов не включены; для заполненного каталога требуется полный backup сайта.',
            'current' => $current,
        ];
        $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $path = $directory . '/catalog-profile-before-' . gmdate('Ymd-His') . '-' . substr((string)$plan['plan_hash'], 0, 12) . '.json';
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось сохранить schema backup.');
        }
        return $path;
    }

    /** @param array<string,mixed> $plan @return array<int,string> */
    private function warnings(array $plan): array
    {
        $warnings = [];
        if ((int)$plan['summary']['delete'] > 0) {
            $warnings[] = 'Лишние свойства Аспро будут удалены вместе с их значениями.';
        }
        if (!empty($plan['catalog']['populated'])) {
            $warnings[] = 'Каталог заполнен. Schema backup не сохраняет значения удаляемых свойств.';
        }
        return $warnings;
    }

    /** @return mixed */
    private function normalizeSerialized($value)
    {
        if (!is_string($value)) {
            return $value;
        }
        $decoded = @unserialize($value, ['allowed_classes' => false]);
        return ($decoded !== false || $value === 'b:0;') ? $decoded : $value;
    }

    private function assertModules(): void
    {
        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            throw new \RuntimeException('Для профиля каталога требуются модули iblock и catalog.');
        }
    }
}
