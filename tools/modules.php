<?php

declare(strict_types=1);

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', false);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Prospektweb\Calc\Modules\ModuleLifecycleService;

header('Content-Type: application/json; charset=utf-8');

function moduleJson(mixed $value, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    die();
}

function modulePayload(mixed $raw): array
{
    if (is_array($raw)) {
        return $raw;
    }
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('payload must be a JSON object');
    }
    return $decoded;
}

function moduleNormalize(mixed $value): mixed
{
    if ($value instanceof \Bitrix\Main\Type\DateTime || $value instanceof \DateTimeInterface) {
        return $value->format(DATE_ATOM);
    }
    if (!is_array($value)) {
        return $value;
    }
    foreach ($value as $key => $child) {
        $value[$key] = moduleNormalize($child);
    }
    return $value;
}

function moduleCatalogOptions(): array
{
    $groups = [
        'presets' => ['option' => 'IBLOCK_CALC_PRESETS', 'entityType' => null],
        'materials' => ['option' => 'IBLOCK_CALC_MATERIALS', 'entityType' => 'material'],
        'materialVariants' => ['option' => 'IBLOCK_CALC_MATERIALS_VARIANTS', 'entityType' => 'materialVariant'],
        'operations' => ['option' => 'IBLOCK_CALC_OPERATIONS', 'entityType' => 'operation'],
        'operationVariants' => ['option' => 'IBLOCK_CALC_OPERATIONS_VARIANTS', 'entityType' => 'operationVariant'],
        'equipment' => ['option' => 'IBLOCK_CALC_EQUIPMENT', 'entityType' => 'equipment'],
    ];
    $result = [];
    foreach ($groups as $group => $definition) {
        $iblockId = (int)Option::get('prospektweb.calc', $definition['option'], 0);
        $result[$group] = [];
        if ($iblockId <= 0) {
            continue;
        }
        $rows = \CIBlockElement::GetList(
            ['NAME' => 'ASC', 'ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId],
            false,
            ['nTopCount' => 1000],
            ['ID', 'NAME', 'ACTIVE']
        );
        while ($row = $rows->Fetch()) {
            $result[$group][] = [
                'id' => (int)$row['ID'],
                'name' => (string)$row['NAME'],
                'active' => $row['ACTIVE'] === 'Y',
                'entityType' => $definition['entityType'],
            ];
        }
    }
    return $result;
}

global $USER;
if (!$USER || !$USER->IsAuthorized()) {
    moduleJson(['success' => false, 'error' => 'Требуется авторизация'], 401);
}
if (!check_bitrix_sessid()) {
    moduleJson(['success' => false, 'error' => 'Сессия истекла'], 403);
}
if (!Loader::includeModule('prospektweb.calc') || !Loader::includeModule('iblock')) {
    moduleJson(['success' => false, 'error' => 'Не удалось загрузить модули Bitrix'], 500);
}

$request = Application::getInstance()->getContext()->getRequest();
$action = trim((string)$request->get('action'));
$payload = modulePayload($request->get('payload'));
$service = new ModuleLifecycleService();
$actorId = (int)$USER->GetID();

try {
    switch ($action) {
        case 'catalog':
            $result = $service->listCatalog(($payload['includeDrafts'] ?? true) === true);
            break;
        case 'options':
            $result = moduleCatalogOptions();
            break;
        case 'instances':
            $result = $service->listPresetInstances((int)($payload['presetId'] ?? 0));
            break;
        case 'snapshots':
            $result = $service->listInstanceSnapshots((int)($payload['instanceId'] ?? 0));
            break;
        case 'audit':
            $result = $service->listAudit(
                isset($payload['familyId']) ? (int)$payload['familyId'] : null,
                isset($payload['instanceId']) ? (int)$payload['instanceId'] : null,
                (int)($payload['limit'] ?? 100)
            );
            break;
        case 'usage':
            $result = $service->listVersionUsage((int)($payload['versionId'] ?? 0));
            break;
        case 'pilot.install':
            $result = $service->installPilotStage($actorId);
            break;
        case 'family.create':
            $result = [
                'familyId' => $service->createFamily(
                    (string)($payload['code'] ?? ''),
                    (string)($payload['name'] ?? ''),
                    (string)($payload['description'] ?? ''),
                    $actorId
                ),
            ];
            break;
        case 'draft.create':
            $result = [
                'versionId' => $service->createDraft(
                    (int)($payload['familyId'] ?? 0),
                    (array)($payload['module'] ?? []),
                    $actorId
                ),
            ];
            break;
        case 'draft.update':
            $result = [
                'revision' => $service->updateDraft(
                    (int)($payload['versionId'] ?? 0),
                    (array)($payload['module'] ?? []),
                    (int)($payload['expectedRevision'] ?? 0),
                    $actorId
                ),
            ];
            break;
        case 'version.publish':
            $result = [
                'revision' => $service->publish(
                    (int)($payload['versionId'] ?? 0),
                    (int)($payload['expectedRevision'] ?? 0),
                    (array)($payload['testResults'] ?? []),
                    $actorId
                ),
            ];
            break;
        case 'version.status':
            $result = [
                'revision' => $service->changeStatus(
                    (int)($payload['versionId'] ?? 0),
                    (string)($payload['status'] ?? ''),
                    (int)($payload['expectedRevision'] ?? 0),
                    $actorId
                ),
            ];
            break;
        case 'instance.preview':
            $snapshot = $service->previewMaterialization(
                (int)($payload['versionId'] ?? 0),
                (array)($payload['instance'] ?? []),
                (array)($payload['options'] ?? [])
            );
            $result = ['snapshot' => $snapshot];
            if (is_array($payload['currentSnapshot'] ?? null)) {
                $result['diff'] = \Prospektweb\Calc\Modules\ModuleMaterializer::preview(
                    $payload['currentSnapshot'],
                    $snapshot
                );
            }
            break;
        case 'instance.apply':
            $result = $service->applyInstance(
                (int)($payload['presetId'] ?? 0),
                (int)($payload['versionId'] ?? 0),
                (array)($payload['instance'] ?? []),
                (array)($payload['options'] ?? []),
                isset($payload['instanceRowId']) ? (int)$payload['instanceRowId'] : null,
                isset($payload['expectedRevision']) ? (int)$payload['expectedRevision'] : null,
                is_array($payload['legacySnapshot'] ?? null) ? $payload['legacySnapshot'] : null,
                $actorId
            );
            break;
        case 'instance.rollback':
            $result = [
                'revision' => $service->rollbackToSnapshot(
                    (int)($payload['instanceId'] ?? 0),
                    (int)($payload['snapshotId'] ?? 0),
                    (int)($payload['expectedRevision'] ?? 0),
                    $actorId
                ),
            ];
            break;
        default:
            moduleJson(['success' => false, 'error' => 'Неизвестное действие'], 400);
    }
    moduleJson(['success' => true, 'data' => moduleNormalize($result)]);
} catch (\Throwable $error) {
    $message = $error->getMessage();
    $status = str_contains($message, 'Revision conflict') ? 409 : 400;
    moduleJson(['success' => false, 'error' => $message], $status);
}
