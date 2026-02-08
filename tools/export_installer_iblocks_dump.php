<?php
/**
 * Snapshot-экспорт текущих данных инфоблоков модуля prospektweb.calc.
 *
 * Запуск: Битрикс -> Настройки -> Инструменты -> Командная PHP-строка.
 */

use Bitrix\Main\Loader;
use Prospektweb\Calc\Install\SnapshotManager;

@set_time_limit(0);
@ini_set('memory_limit', '1024M');

if (!Loader::includeModule('prospektweb.calc')) {
    die("Не удалось подключить модуль prospektweb.calc\n");
}

$manager = new SnapshotManager();

try {
    $file = $manager->exportToFile();
    echo "Snapshot готов: {$file}\n";
} catch (\Throwable $e) {
    die('Ошибка экспорта: ' . $e->getMessage() . "\n");
}
