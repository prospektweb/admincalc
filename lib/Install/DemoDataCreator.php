<?php

namespace Prospektweb\Calc\Install;

/**
 * Legacy-заглушка: демо-данные больше не поддерживаются.
 */
class DemoDataCreator
{
    public function create(array $iblockIds): array
    {
        return [
            'created' => [],
            'errors' => ['Создание демо-данных удалено. Используйте импорт snapshot файла.'],
        ];
    }
}
