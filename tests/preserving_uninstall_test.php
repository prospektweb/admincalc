<?php
declare(strict_types=1);
namespace Bitrix\Main {
    final class ModuleManager {
        public static array $registered = ['prospektweb.calc'=>true, 'prospektweb.frontcalc'=>true];
        public static function isModuleInstalled($id): bool { return self::$registered[$id] ?? false; }
        public static function unRegisterModule($id): void { unset(self::$registered[$id]); }
    }
    final class Loader { public static bool $load = true; public static function includeModule($id): bool { return self::$load; } }
    final class EventManager {
        public static function getInstance(): self { return new self(); }
        public function unRegisterEventHandler(...$args): void { $GLOBALS['events'][] = $args; }
    }
    final class Application {
        public static function getDocumentRoot(): string { return sys_get_temp_dir() . '/pw-calc-uninstall-no-files-' . getmypid(); }
        public static function getConnection(): object { return new class { public function queryExecute($sql): void { $GLOBALS['sql'][] = $sql; } }; }
    }
}
namespace Bitrix\Main\Localization { final class Loc { public static function loadMessages($file): void {} public static function getMessage($key): string { return $key; } } }
namespace Prospektweb\Calc\Services { final class AsproAiPatchManager { public function remove(): array { return ['state'=>'not_installed']; } } }
namespace Prospektweb\Frontcalc\Service {
    if (($argv[1] ?? '') !== 'old') {
        final class CalcDependencyAvailability {
            public const ERROR_CODE = 'FRONTCALC_CALC_UNAVAILABLE';
            public static function assertAvailable(): void {}
        }
    }
}
namespace {
    class CModule {}
    function check_bitrix_sessid(): bool { return $GLOBALS['csrf']; }
    function ShowError($error): void { $GLOBALS['errors'][] = $error; }
    function htmlspecialcharsbx($value): string { return htmlspecialchars($value); }
    function DeleteDirFilesEx($path): void { throw new \RuntimeException('Unexpected file deletion in empty test root'); }
    $mode = $argv[1] ?? null;
    if ($mode === null) {
        foreach (['safe', 'absent', 'old', 'loader-fails', 'get', 'csrf', 'nonadmin'] as $case) {
            passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($case), $exit);
            if ($exit !== 0) throw new \RuntimeException('Failed scenario: ' . $case);
        }
        echo "preserving_uninstall_test: 7 scenarios PASS\n"; return;
    }
    if ($mode === 'absent') unset(\Bitrix\Main\ModuleManager::$registered['prospektweb.frontcalc']);
    if ($mode === 'loader-fails') \Bitrix\Main\Loader::$load = false;
    define('B_PROLOG_INCLUDED', true);
    $csrf = $mode !== 'csrf'; $errors = []; $events = []; $sql = [];
    $USER = new class($mode) { public function __construct(private string $mode) {} public function IsAdmin(): bool { return $this->mode !== 'nonadmin'; } };
    $_SERVER['REQUEST_METHOD'] = $mode === 'get' ? 'GET' : 'POST';
    // Retained domain data and settings have no deletion API in this harness.
    $retained = ['documents'=>['id'=>'qa-id','revision'=>5], 'options'=>['site_id'=>'s1'], 'links'=>[1312=>1311]];
    $before = hash('sha256', serialize($retained));
    ob_start(); require dirname(__DIR__) . '/install/unstep2.php'; ob_end_clean();
    $allowed = in_array($mode, ['safe','absent'], true);
    if (\Bitrix\Main\ModuleManager::isModuleInstalled('prospektweb.calc') === $allowed) throw new \RuntimeException('Registration state incorrect');
    if ($mode !== 'absent' && !\Bitrix\Main\ModuleManager::isModuleInstalled('prospektweb.frontcalc')) throw new \RuntimeException('Consumer removed');
    if ($before !== hash('sha256', serialize($retained))) throw new \RuntimeException('Retained data changed');
    if (!$allowed && ($events || $sql || !$errors)) throw new \RuntimeException('Rejected request performed side effects');
    foreach ($sql as $statement) if ($statement !== "DELETE FROM b_module_to_module WHERE TO_MODULE_ID = 'prospektweb.calc' AND MESSAGE_ID = 'OnBuildGlobalMenu'") throw new \RuntimeException('Unexpected SQL');
    echo $mode . ": PASS\n";
}
