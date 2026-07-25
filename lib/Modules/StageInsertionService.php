<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Modules;

use Bitrix\Main\Application;
use Prospektweb\Calc\Config\ConfigManager;
use Prospektweb\Calc\Services\PresetEnrichmentService;

/**
 * Materializes a pinned stage module into an exact position of a legacy detail.
 *
 * The position contract uses both neighbours and a deterministic revision token.
 * This prevents a stale operator preview from silently inserting into another
 * place after a concurrent edit.
 */
final class StageInsertionService
{
    private int $detailsIblockId;
    private int $stagesIblockId;
    private int $settingsIblockId;

    public function __construct()
    {
        $config = new ConfigManager();
        $this->detailsIblockId = $config->getIblockId('CALC_DETAILS');
        $this->stagesIblockId = $config->getIblockId('CALC_STAGES');
        $this->settingsIblockId = $config->getIblockId('CALC_SETTINGS');
    }

    public function preview(array $target): array
    {
        $detailId = (int)($target['detailId'] ?? 0);
        if ($detailId <= 0) {
            throw new \InvalidArgumentException('STAGE_TARGET_INVALID: Не выбрана целевая деталь');
        }

        $this->lockDetail($detailId, false);
        $order = $this->readStageIds($detailId);
        $index = (int)($target['insertionIndex'] ?? -1);

        $existingStageId = self::nullableId($target['stageId'] ?? null);
        if ($existingStageId !== null) {
            $existingIndex = array_search($existingStageId, $order, true);
            if ($existingIndex === false || $existingIndex !== $index) {
                throw new \DomainException('STAGE_POSITION_STALE: Материализованный этап перемещён или больше не принадлежит детали');
            }
            $beforeStageId = $existingIndex > 0 ? $order[$existingIndex - 1] : null;
            $afterStageId = $existingIndex < count($order) - 1 ? $order[$existingIndex + 1] : null;
            $revision = self::revisionToken($detailId, $order);
            if (
                isset($target['detailRevision'])
                && trim((string)$target['detailRevision']) !== ''
                && !hash_equals($revision, (string)$target['detailRevision'])
            ) {
                throw new \DomainException('REVISION_CONFLICT: Деталь была изменена. Обновите состояние и повторите preview');
            }
            return [
                'detailId' => $detailId,
                'stageId' => $existingStageId,
                'insertionIndex' => $existingIndex,
                'beforeStageId' => $beforeStageId,
                'afterStageId' => $afterStageId,
                'detailRevision' => $revision,
                'currentOrder' => $order,
            ];
        }

        if ($index < 0 || $index > count($order)) {
            throw new \DomainException('STAGE_POSITION_STALE: Позиция вставки больше не существует. Обновите состояние');
        }

        $beforeStageId = $index > 0 ? $order[$index - 1] : null;
        $afterStageId = $index < count($order) ? $order[$index] : null;
        $revision = self::revisionToken($detailId, $order);

        if (
            array_key_exists('beforeStageId', $target)
            && self::nullableId($target['beforeStageId']) !== $beforeStageId
        ) {
            throw new \DomainException('STAGE_POSITION_STALE: Изменился этап перед выбранной позицией');
        }
        if (
            array_key_exists('afterStageId', $target)
            && self::nullableId($target['afterStageId']) !== $afterStageId
        ) {
            throw new \DomainException('STAGE_POSITION_STALE: Изменился этап после выбранной позиции');
        }
        if (
            isset($target['detailRevision'])
            && trim((string)$target['detailRevision']) !== ''
            && !hash_equals($revision, (string)$target['detailRevision'])
        ) {
            throw new \DomainException('REVISION_CONFLICT: Деталь была изменена. Обновите состояние и повторите preview');
        }

        return [
            'detailId' => $detailId,
            'insertionIndex' => $index,
            'beforeStageId' => $beforeStageId,
            'afterStageId' => $afterStageId,
            'detailRevision' => $revision,
            'currentOrder' => $order,
        ];
    }

    public function insert(
        array $target,
        array $module,
        array $snapshot,
        array $instance,
        int $presetId,
        int $actorId
    ): array {
        if (($module['kind'] ?? null) !== 'stage') {
            throw new \DomainException('MODULE_KIND_INVALID: В деталь можно вставить только модуль этапа');
        }

        $this->lockDetail((int)($target['detailId'] ?? 0), true);
        $position = $this->preview($target);
        $settingsId = null;
        $stageId = null;

        try {
            $settingsId = $this->createSettings($module);
            $stageId = $this->createStage($module, $snapshot, $instance, $settingsId);

            $nextOrder = $position['currentOrder'];
            array_splice($nextOrder, $position['insertionIndex'], 0, [$stageId]);
            $this->writeStageIds($position['detailId'], $nextOrder);
            if ($this->readStageIds($position['detailId']) !== $nextOrder) {
                throw new \RuntimeException('STAGE_INSERT_FAILED: Битрикс не сохранил точный порядок этапов');
            }

            if ($presetId > 0) {
                (new PresetEnrichmentService())->addStageToPreset($presetId, $stageId);
            }

            $storedIndex = array_search($stageId, $nextOrder, true);
            $storedTarget = [
                'detailId' => $position['detailId'],
                'stageId' => $stageId,
                'settingsId' => $settingsId,
                'insertionIndex' => $storedIndex,
                'beforeStageId' => $storedIndex > 0 ? $nextOrder[$storedIndex - 1] : null,
                'afterStageId' => $storedIndex < count($nextOrder) - 1 ? $nextOrder[$storedIndex + 1] : null,
                'detailRevision' => self::revisionToken($position['detailId'], $nextOrder),
                'insertedAt' => gmdate('c'),
                'insertedBy' => $actorId,
            ];

            return [
                'stageId' => $stageId,
                'settingsId' => $settingsId,
                'stageOrder' => $nextOrder,
                'target' => $storedTarget,
            ];
        } catch (\Throwable $error) {
            if ($stageId !== null) {
                \CIBlockElement::Delete($stageId);
            }
            if ($settingsId !== null) {
                \CIBlockElement::Delete($settingsId);
            }
            throw $error;
        }
    }

    public function synchronize(
        array $target,
        array $snapshot,
        ?array $module = null,
        ?array $instance = null
    ): array
    {
        $detailId = (int)($target['detailId'] ?? 0);
        $stageId = (int)($target['stageId'] ?? 0);
        if ($detailId <= 0 || $stageId <= 0) {
            throw new \DomainException('STAGE_TARGET_INVALID: У экземпляра отсутствует материализованный этап');
        }

        $this->lockDetail($detailId, true);
        $order = $this->readStageIds($detailId);
        $index = array_search($stageId, $order, true);
        if ($index === false) {
            throw new \DomainException('STAGE_POSITION_STALE: Материализованный этап больше не принадлежит детали');
        }

        $node = $this->rootNode($snapshot);
        $name = trim((string)($module['name'] ?? $node['name'] ?? ''))
            ?: (string)($snapshot['familyId'] ?? 'Готовый этап');
        $preview = sprintf(
            'Зафиксированный модуль %s@%s',
            (string)($snapshot['familyId'] ?? ''),
            (string)($snapshot['version'] ?? '')
        );
        $element = new \CIBlockElement();
        if (!$element->Update($stageId, [
            'NAME' => $name,
            'PREVIEW_TEXT' => $preview,
            'PREVIEW_TEXT_TYPE' => 'text',
        ])) {
            throw new \RuntimeException('STAGE_SYNC_FAILED: Не удалось обновить материализованный этап');
        }

        $settingsId = (int)($target['settingsId'] ?? 0);
        if ($settingsId > 0 && is_array($node['logic'] ?? null)) {
            $settingsProperties = [
                'LOGIC_JSON' => json_encode(
                    $node['logic'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
            ];
            if ($module !== null) {
                $settingsProperties['PARAMS'] = $this->settingsParams($module) ?: false;
            }
            \CIBlockElement::SetPropertyValuesEx($settingsId, $this->settingsIblockId, $settingsProperties);
        }
        if ($module !== null && $instance !== null) {
            $properties = $this->stageProperties($module, $snapshot, $instance);
            if ($settingsId > 0) {
                $properties['CALC_SETTINGS'] = $settingsId;
            }
            \CIBlockElement::SetPropertyValuesEx($stageId, $this->stagesIblockId, $properties);
        } else {
            $this->writeSnapshotWiring($stageId, $snapshot);
        }

        return [
            'detailId' => $detailId,
            'stageId' => $stageId,
            'settingsId' => $settingsId ?: null,
            'insertionIndex' => $index,
            'beforeStageId' => $index > 0 ? $order[$index - 1] : null,
            'afterStageId' => $index < count($order) - 1 ? $order[$index + 1] : null,
            'detailRevision' => self::revisionToken($detailId, $order),
        ];
    }

    public static function revisionToken(int $detailId, array $stageIds): string
    {
        return hash('sha256', json_encode(
            ['detailId' => $detailId, 'stageIds' => array_values(array_map('intval', $stageIds))],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function createSettings(array $module): ?int
    {
        $rootNode = $this->rootNodeFromModule($module);
        if (!is_array($rootNode['logic'] ?? null)) {
            return null;
        }
        if ($this->settingsIblockId <= 0) {
            throw new \RuntimeException('STAGE_INSERT_FAILED: Инфоблок калькуляторов не настроен');
        }

        $name = sprintf('%s · %s@%s', (string)$module['name'], (string)$module['familyId'], (string)$module['version']);
        $params = $this->settingsParams($module);
        $element = new \CIBlockElement();
        $settingsId = $element->Add([
            'IBLOCK_ID' => $this->settingsIblockId,
            'NAME' => $name,
            'CODE' => $this->uniqueCode($this->settingsIblockId, $name),
            'ACTIVE' => 'Y',
            'PREVIEW_TEXT' => (string)($module['description'] ?? ''),
            'PREVIEW_TEXT_TYPE' => 'text',
            'PROPERTY_VALUES' => [
                'LOGIC_JSON' => json_encode(
                    $rootNode['logic'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                'PARAMS' => $params ?: false,
            ],
        ]);
        if (!$settingsId) {
            throw new \RuntimeException('STAGE_INSERT_FAILED: Не удалось создать калькулятор опубликованного этапа');
        }
        return (int)$settingsId;
    }

    private function settingsParams(array $module): array
    {
        $params = [];
        foreach ((array)($module['ports'] ?? []) as $port) {
            if (in_array((string)($port['direction'] ?? ''), ['output', 'global-output'], true)) {
                continue;
            }
            $passport = [(string)($port['valueType'] ?? 'string')];
            if (trim((string)($port['name'] ?? '')) !== '') {
                $passport[] = (string)$port['name'];
            }
            if (trim((string)($port['description'] ?? '')) !== '') {
                $passport[] = (string)$port['description'];
            }
            $params[] = [
                'VALUE' => (string)($port['code'] ?? ''),
                'DESCRIPTION' => implode('|', $passport),
            ];
        }
        return $params;
    }

    private function createStage(array $module, array $snapshot, array $instance, ?int $settingsId): int
    {
        if ($this->stagesIblockId <= 0) {
            throw new \RuntimeException('STAGE_INSERT_FAILED: Инфоблок этапов не настроен');
        }
        $name = trim((string)($module['name'] ?? '')) ?: (string)$module['familyId'];
        $properties = $this->stageProperties($module, $snapshot, $instance);
        if ($settingsId !== null) {
            $properties['CALC_SETTINGS'] = $settingsId;
        }

        $element = new \CIBlockElement();
        $stageId = $element->Add([
            'IBLOCK_ID' => $this->stagesIblockId,
            'NAME' => $name,
            'CODE' => $this->uniqueCode($this->stagesIblockId, $name),
            'ACTIVE' => 'Y',
            'PREVIEW_TEXT' => sprintf(
                'Зафиксированный модуль %s@%s',
                (string)$module['familyId'],
                (string)$module['version']
            ),
            'PREVIEW_TEXT_TYPE' => 'text',
            'PROPERTY_VALUES' => $properties,
        ]);
        if (!$stageId) {
            throw new \RuntimeException('STAGE_INSERT_FAILED: Не удалось создать материализованный этап');
        }
        return (int)$stageId;
    }

    private function stageProperties(array $module, array $snapshot, array $instance): array
    {
        $bindings = [];
        foreach ((array)($instance['bindings'] ?? []) as $binding) {
            $bindings[(string)($binding['portCode'] ?? '')] = (array)($binding['target'] ?? []);
        }
        $inputs = [];
        $outputs = [];
        foreach ((array)($module['ports'] ?? []) as $port) {
            $code = (string)($port['code'] ?? '');
            if ($code === '') {
                continue;
            }
            if (($port['direction'] ?? null) === 'output' || ($port['direction'] ?? null) === 'global-output') {
                $outputs[] = ['VALUE' => $code, 'DESCRIPTION' => $code];
                continue;
            }
            if (isset($bindings[$code])) {
                $inputs[] = ['VALUE' => $code, 'DESCRIPTION' => self::bindingPath($bindings[$code])];
            }
        }
        $properties = [
            'INPUTS' => $inputs ?: false,
            'OUTPUTS' => $outputs ?: false,
        ];

        foreach ((array)($instance['entityBindings'] ?? []) as $binding) {
            $ids = array_values(array_filter(array_map('intval', (array)($binding['localElementIds'] ?? []))));
            if ($ids === []) {
                continue;
            }
            $property = match ((string)($binding['entityType'] ?? '')) {
                'operationVariant' => 'OPERATION_VARIANT',
                'materialVariant' => 'MATERIAL_VARIANT',
                'equipment' => 'EQUIPMENT',
                'customField' => 'CUSTOM_FIELDS',
                default => null,
            };
            if ($property !== null) {
                $properties[$property] = count($ids) === 1 ? $ids[0] : $ids;
            }
        }

        $assignments = (array)($snapshot['materialization']['globalAssignments'] ?? []);
        if ($assignments !== []) {
            $properties['GLOBAL_ASSIGNMENTS'] = json_encode(
                ['version' => 1, 'assignments' => $assignments],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        }
        $activation = $snapshot['materialization']['activationCondition'] ?? null;
        if ($activation !== null) {
            $properties['ACTIVATION_CONDITION'] = json_encode(
                $activation,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        }
        return $properties;
    }

    private function writeSnapshotWiring(int $stageId, array $snapshot): void
    {
        $inputs = [];
        foreach ((array)($snapshot['materialization']['bindings'] ?? []) as $binding) {
            $code = (string)($binding['portCode'] ?? '');
            if ($code !== '') {
                $inputs[] = [
                    'VALUE' => $code,
                    'DESCRIPTION' => self::bindingPath((array)($binding['target'] ?? [])),
                ];
            }
        }
        \CIBlockElement::SetPropertyValuesEx($stageId, $this->stagesIblockId, [
            'INPUTS' => $inputs ?: false,
        ]);
    }

    private static function bindingPath(array $target): string
    {
        $kind = (string)($target['kind'] ?? '');
        $value = $target['value'] ?? null;
        if ($kind === 'literal') {
            return '__literal__:' . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($kind === 'global') {
            return 'globals.' . ltrim((string)$value, '.');
        }
        if ($kind === 'module-port' && is_array($value)) {
            return sprintf(
                'stage_%s.outputVar.%s',
                (string)($value['stageId'] ?? $value['instanceId'] ?? ''),
                (string)($value['portCode'] ?? '')
            );
        }
        return (string)$value;
    }

    private function rootNodeFromModule(array $module): array
    {
        $rootNodeId = (string)($module['content']['rootNodeId'] ?? '');
        foreach ((array)($module['content']['nodes'] ?? []) as $node) {
            if ((string)($node['nodeId'] ?? '') === $rootNodeId) {
                return $node;
            }
        }
        return [];
    }

    private function rootNode(array $snapshot): array
    {
        $root = (string)($snapshot['resolvedGraph']['rootNodeId'] ?? '');
        foreach ((array)($snapshot['resolvedGraph']['nodes'] ?? []) as $node) {
            if (
                (string)($node['instanceNodeId'] ?? '') === $root
                || str_ends_with($root, ':' . (string)($node['nodeId'] ?? ''))
            ) {
                return $node;
            }
        }
        return [];
    }

    private function readStageIds(int $detailId): array
    {
        $result = [];
        $rows = \CIBlockElement::GetProperty(
            $this->detailsIblockId,
            $detailId,
            ['sort' => 'asc', 'id' => 'asc'],
            ['CODE' => 'CALC_STAGES']
        );
        while ($row = $rows->Fetch()) {
            $id = (int)($row['VALUE'] ?? 0);
            if ($id > 0 && !in_array($id, $result, true)) {
                $result[] = $id;
            }
        }
        return $result;
    }

    private function writeStageIds(int $detailId, array $stageIds): void
    {
        \CIBlockElement::SetPropertyValuesEx($detailId, $this->detailsIblockId, ['CALC_STAGES' => false]);
        if ($stageIds !== []) {
            \CIBlockElement::SetPropertyValuesEx(
                $detailId,
                $this->detailsIblockId,
                ['CALC_STAGES' => array_values(array_map('intval', $stageIds))]
            );
        }
    }

    private function lockDetail(int $detailId, bool $forUpdate): void
    {
        if ($detailId <= 0) {
            throw new \InvalidArgumentException('STAGE_TARGET_INVALID: Не выбрана целевая деталь');
        }
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $row = Application::getConnection()
            ->query('SELECT ID FROM b_iblock_element WHERE ID = ' . $detailId . $suffix)
            ->fetch();
        if (!$row) {
            throw new \DomainException('STAGE_TARGET_INVALID: Целевая деталь не найдена');
        }
    }

    private function uniqueCode(int $iblockId, string $name): string
    {
        $base = trim((string)\CUtil::translit($name, 'ru', [
            'replace_space' => '-',
            'replace_other' => '-',
            'change_case' => 'L',
            'delete_repeat_replace' => true,
        ]), '-');
        $base = $base !== '' ? $base : 'module-stage';
        $code = $base;
        $suffix = 2;
        while (\CIBlockElement::GetList([], [
            'IBLOCK_ID' => $iblockId,
            '=CODE' => $code,
        ], false, ['nTopCount' => 1], ['ID'])->Fetch()) {
            $code = $base . '-' . $suffix++;
        }
        return $code;
    }

    private static function nullableId(mixed $value): ?int
    {
        $id = (int)$value;
        return $id > 0 ? $id : null;
    }
}
