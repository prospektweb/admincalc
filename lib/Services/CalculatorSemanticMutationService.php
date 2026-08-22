<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Services;

use Prospektweb\Calc\Calculator\ElementDataService;
use Prospektweb\Calc\Calculator\InitPayloadService;

/**
 * One CAS/audit/readback boundary for every preset-owned calculator mutation.
 */
final class CalculatorSemanticMutationService
{
    public const CONTRACT = 'prospektweb.calc.semantic-mutation/v1';

    /** @var string[] */
    private const ACTIONS = [
        'addDetailsToBinding',
        'addDetailToBinding',
        'addNewDetail',
        'addNewGroup',
        'addNewStage',
        'addStage',
        'changeCustomFieldsValue',
        'changeDetailLevel',
        'changeDetailSort',
        'changeEntityMeta',
        'changeEquipment',
        'changeMaterialVariant',
        'changeNameDetail',
        'changeOperationVariant',
        'changePricePreset',
        'changeProductType',
        'changeRootDetailSort',
        'changeSettings',
        'changeSortStage',
        'changeStageName',
        'cloneDetail',
        'cloneDetails',
        'createCustomField',
        'clearPreset',
        'deleteDetail',
        'deleteStage',
        'duplicateStage',
        'enrichPreset',
        'moveStage',
        'removeDetail',
        'renameDetail',
        'resolveCalculatorContract',
        'saveCalcLogic',
        'saveCalculatorGlobals',
        'saveGlobalSymbols',
        'saveAiCalculatorContext',
        'savePresetGlobals',
        'saveStageGroups',
        'saveStageUsedEntities',
        'selectFields',
        'updateSettingsProperty',
        'updateStageProperty',
    ];

    /** @var array<string,callable> */
    private array $adapters;

    /** @param array<string,callable> $adapters */
    public function __construct(array $adapters = [])
    {
        $this->adapters = $adapters;
    }

    public static function isSemanticAction(string $action): bool
    {
        return in_array($action, self::ACTIONS, true);
    }

    /** @param array<string,mixed> $initPayload @return array<string,mixed> */
    public static function readbackFromInitPayload(array $initPayload): array
    {
        return [
            'preset' => is_array($initPayload['preset'] ?? null) ? $initPayload['preset'] : null,
            'elementsStore' => is_array($initPayload['elementsStore'] ?? null)
                ? $initPayload['elementsStore']
                : [],
            'globalSymbols' => is_array($initPayload['globalSymbols'] ?? null)
                ? array_values($initPayload['globalSymbols'])
                : [],
        ];
    }

    /** @param array<string,mixed> $initPayload */
    public static function revisionFromInitPayload(array $initPayload): string
    {
        return PresetMutationCoordinatorService::hashCanonical(
            self::readbackFromInitPayload($initPayload)
        );
    }

    /**
     * @param array<int,array<string,mixed>> $payload
     * @return array<int,array<string,mixed>>
     */
    public function mutatePayload(array $payload, string $expectedRevision, string $siteId): array
    {
        if (!array_is_list($payload) || count($payload) !== 1 || !is_array($payload[0] ?? null)) {
            throw new \InvalidArgumentException(
                'A semantic refresh payload must contain exactly one aggregate mutation.',
                422
            );
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedRevision) !== 1) {
            throw new \InvalidArgumentException('expectedSemanticRevision must be an exact SHA-256.', 422);
        }
        $request = $payload[0];
        $action = is_string($request['action'] ?? null) ? $request['action'] : '';
        if (!self::isSemanticAction($action)) {
            throw new \InvalidArgumentException('Unsupported semantic refresh action.', 422);
        }
        $presetId = (int)($request['presetId'] ?? 0);
        if ($presetId <= 0) {
            throw new \InvalidArgumentException('Semantic mutation requires an exact preset ID.', 422);
        }
        $lastRevision = '';

        $coordinator = isset($this->adapters['coordinator'])
            ? call_user_func($this->adapters['coordinator'])
            : new PresetMutationCoordinatorService();
        if (!is_object($coordinator) || !is_callable([$coordinator, 'mutate'])) {
            throw new \RuntimeException('Semantic mutation coordinator is unavailable.');
        }

        $result = $coordinator->mutate(
            $presetId,
            [
                'action' => 'semantic_' . strtolower((string)preg_replace('/([a-z])([A-Z])/', '$1_$2', $action)),
                'entity_type' => 'calculator_semantic_aggregate',
                'entity_id' => (string)$presetId,
                'expected_revision' => $expectedRevision,
                'expected_before_sha256' => $expectedRevision,
                'product_ids' => [],
            ],
            function ($authority) use ($action, $request, $presetId): array {
                if (!$authority instanceof CalculatorMutationAuthorityService) {
                    if (isset($this->adapters['mutation'])) {
                        return $this->assertSuccessfulResult(
                            (array)call_user_func($this->adapters['mutation'], $action, $request)
                        );
                    }
                    throw new \RuntimeException('Calculator mutation did not receive calculator authority.');
                }
                $pinnedIblockIds = $authority->lockedIblockIds();
                $elementData = new ElementDataService();
                if ($action === 'saveCalcLogic') {
                    return $this->assertSuccessfulResult(
                        $elementData->saveCalcLogicLocked($request, $authority, $pinnedIblockIds)
                    );
                }
                if ($action === 'saveGlobalSymbols') {
                    return $this->assertSuccessfulResult((new GlobalSymbolService())->saveLocked(
                        is_array($request['symbols'] ?? null) ? $request['symbols'] : [],
                        $presetId,
                        $authority,
                        $pinnedIblockIds
                    ));
                }
                if ($action === 'savePresetGlobals') {
                    return $this->assertSuccessfulResult(
                        $elementData->savePresetGlobalsLocked($request, $authority, $pinnedIblockIds)
                    );
                }
                if ($action === 'saveAiCalculatorContext') {
                    return $this->assertSuccessfulResult(
                        (new AiCalculatorContextService())->saveLocked(
                            $request,
                            $presetId,
                            $authority,
                            $pinnedIblockIds
                        )
                    );
                }

                if ($action !== 'saveCalculatorGlobals') {
                    $rows = (new ElementDataService([], $authority))->prepareRefreshPayload([$request]);
                    if (count($rows) !== 1 || !is_array($rows[0] ?? null)) {
                        throw new \RuntimeException('Calculator mutation returned an invalid result.');
                    }
                    return $this->assertSuccessfulResult($rows[0]);
                }

                $this->assertExactAggregateGlobalKeys($request);
                $registry = (new GlobalSymbolService())->saveLocked(
                    is_array($request['symbols'] ?? null) ? $request['symbols'] : [],
                    $presetId,
                    $authority,
                    $pinnedIblockIds
                );
                $preset = $elementData->savePresetGlobalsLocked([
                    'presetId' => $presetId,
                    'variables' => is_array($request['variables'] ?? null) ? $request['variables'] : [],
                    'constants' => is_array($request['constants'] ?? null) ? $request['constants'] : [],
                ], $authority, $pinnedIblockIds);
                return $this->assertSuccessfulResult([
                    'status' => 'ok',
                    'presetId' => $presetId,
                    'symbols' => $registry['symbols'] ?? [],
                    'initPayload' => $preset['initPayload'] ?? null,
                ]);
            },
            function ($authority) use ($presetId, $siteId, &$lastRevision): array {
                $readback = isset($this->adapters['readback'])
                    ? call_user_func($this->adapters['readback'], $presetId)
                    : self::readbackFromInitPayload(
                        (new InitPayloadService())->preparePresetPayload($presetId, $siteId)
                    );
                if (!is_array($readback)) {
                    throw new \RuntimeException('Semantic aggregate readback is invalid.');
                }
                $lastRevision = PresetMutationCoordinatorService::hashCanonical($readback);
                return $readback;
            }
        );

        if (!is_array($result) || preg_match('/^[a-f0-9]{64}$/D', $lastRevision) !== 1) {
            throw new \RuntimeException('Semantic mutation receipt is invalid.');
        }
        $result['semanticRevision'] = $lastRevision;
        if (is_array($result['initPayload'] ?? null)) {
            $result['initPayload']['semanticRevision'] = $lastRevision;
        }
        return [$result];
    }

    /** @param array<string,mixed> $request */
    private function assertExactAggregateGlobalKeys(array $request): void
    {
        $keys = array_keys($request);
        sort($keys, SORT_STRING);
        $expected = ['action', 'constants', 'presetId', 'symbols', 'variables'];
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new \InvalidArgumentException(
                'saveCalculatorGlobals contains unsupported or missing fields.',
                422
            );
        }
        foreach (['symbols', 'variables', 'constants'] as $field) {
            if (!is_array($request[$field] ?? null)) {
                throw new \InvalidArgumentException('saveCalculatorGlobals.' . $field . ' must be an array.', 422);
            }
        }
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function assertSuccessfulResult(array $result): array
    {
        if (($result['status'] ?? null) !== 'ok') {
            $message = trim((string)($result['message'] ?? ''));
            throw new \RuntimeException(
                $message !== '' ? $message : 'Calculator mutation failed before authoritative readback.',
                409
            );
        }
        return $result;
    }
}
