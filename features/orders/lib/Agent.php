<?php
namespace Prospektweb\LayoutFiles;

use Bitrix\Main\Type\DateTime;
use Prospektweb\LayoutFiles\Internals\LayoutFileTable;
use Prospektweb\LayoutFiles\Service\YandexDiskClient;

class Agent
{
    public static function cleanup(): string
    {
        $border = new DateTime(); $border->add('-' . Config::getTempLifetimeHours() . ' hours');
        $client = new YandexDiskClient();
        $rows = LayoutFileTable::getList(['filter'=>['@STATUS'=>['created','uploading','uploaded'], '<UPDATED_AT'=>$border, '=ORDER_ID'=>0], 'limit'=>100]);
        while ($row = $rows->fetch()) { $client->delete($row['YADISK_PATH']); LayoutFileTable::update((int)$row['ID'], ['STATUS'=>'deleted', 'UPDATED_AT'=>new DateTime()]); }
        return '\\Prospektweb\\LayoutFiles\\Agent::cleanup();';
    }
}
