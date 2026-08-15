<?php

declare(strict_types=1);

final class NeutralOwnershipCursor
{
    /** @var array<int,array<string,mixed>> */
    private array $rows;

    /** @param array<int,array<string,mixed>> $rows */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    /** @return array<string,mixed>|false */
    public function Fetch()
    {
        return array_shift($this->rows) ?? false;
    }
}

if (!class_exists('CIBlockElement', false)) {
    final class CIBlockElement
    {
        public static int $writes = 0;

        public static function GetProperty(int $iblockId, int $elementId, array $order, array $filter): object
        {
            $code = (string)($filter['CODE'] ?? '');
            $values = [];
            if ($iblockId === 41 && $elementId === 12740 && $code === 'CALC_DETAILS') {
                $values = [100];
            } elseif ($iblockId === 43 && $elementId === 100 && $code === 'DETAILS') {
                $values = [101];
            } elseif ($iblockId === 43 && $elementId === 100 && $code === 'CALC_STAGES') {
                $values = [500];
            } elseif ($iblockId === 43 && $elementId === 200 && $code === 'CALC_STAGES') {
                $values = [500];
            } elseif ($iblockId === 43 && $elementId === 201 && $code === 'DETAILS') {
                $values = [101];
            }
            return new NeutralOwnershipCursor(array_map(
                static fn(int $value): array => ['VALUE' => $value],
                $values
            ));
        }

        /** @param array<string,mixed> $filter */
        public static function GetList(array $order, array $filter): object
        {
            $id = (int)($filter['ID'] ?? 0);
            $iblockId = (int)($filter['IBLOCK_ID'] ?? 0);
            return new NeutralOwnershipCursor(
                in_array($id, [100, 101, 200, 201], true) && $iblockId === 43
                    ? [['ID' => $id, 'IBLOCK_ID' => $iblockId]]
                    : []
            );
        }

        /** @param array<string,mixed> $values */
        public static function SetPropertyValuesEx(int $elementId, int $iblockId, array $values): void
        {
            self::$writes++;
        }
    }
}

require_once dirname(__DIR__) . '/lib/Services/NeutralFormulaPolicy.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$policy = new \Prospektweb\Calc\Services\NeutralFormulaPolicy();
$locked = (new ReflectionClass($policy))->getProperty('lockedIblockIds');
$locked->setAccessible(true);
$locked->setValue($policy, [
    'CALC_PRESETS' => 41,
    'CALC_DETAILS' => 43,
    'CALC_SETTINGS' => 44,
    'CALC_STAGES' => 42,
]);

try {
    $policy->assertStructuralMutationAllowed(999, [101], true, 'stages');
    $assert(false, 'forged non-neutral preset id must not bypass direct neutral detail ownership');
} catch (Throwable $error) {
    $assert($error->getCode() === 409, 'owned detail mutation is rejected as a conflict');
}
$assert(CIBlockElement::$writes === 0, 'ownership rejection occurs before any structural DML');

try {
    $policy->assertStructuralMutationAllowed(12740, [777], true, 'sorting');
    $assert(false, 'protected preset 12740 sorting must fail before inspecting caller-controlled detail ids');
} catch (Throwable $error) {
    $assert($error->getCode() === 409, 'protected preset sorting is rejected as a conflict');
}
$assert(CIBlockElement::$writes === 0, 'protected preset sorting rejection performs no DML');

try {
    $policy->assertStageMoveAllowed(999, 500, 777, 778, true);
    $assert(false, 'forged non-neutral preset id must not bypass direct neutral stage ownership');
} catch (Throwable $error) {
    $assert($error->getCode() === 409, 'owned stage move is rejected as a conflict');
}
$assert(CIBlockElement::$writes === 0, 'stage ownership rejection occurs before move DML');

try {
    $policy->assertStageStructuralMutationAllowed(999, 500, true, 'stage deletion');
    $assert(false, 'forged non-neutral preset id must not bypass direct neutral stage deletion ownership');
} catch (Throwable $error) {
    $assert($error->getCode() === 409, 'owned stage deletion is rejected as a conflict');
}
$assert(CIBlockElement::$writes === 0, 'stage deletion ownership rejection occurs before DML');

foreach ([200, 201] as $sharedTopologyDetailId) {
    try {
        $policy->assertDetailDeletionCascadeAllowed(
            $sharedTopologyDetailId,
            true,
            'shared topology deletion'
        );
        $assert(false, 'destructive detail cascade must reject a shared neutral stage or child');
    } catch (Throwable $error) {
        $assert($error->getCode() === 409, 'shared neutral descendants reject destructive cascades');
    }
}
$assert(CIBlockElement::$writes === 0, 'destructive cascade rejection occurs before DML');

$policy->assertStructuralMutationAllowed(999, [777], true, 'stages');
$policy->assertStructuralMutationAllowed(999, [101], false, 'stages');
$assert(CIBlockElement::$writes === 0, 'safe/non-protected ownership probes remain read-only');

fwrite(STDOUT, "OK\n");
