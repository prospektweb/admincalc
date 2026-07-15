<?php
namespace Prospektweb\LayoutFiles\Internals;

use Bitrix\Main\Entity;
use Bitrix\Main\Type\DateTime;

class LayoutFileTable extends Entity\DataManager
{
    public static function getTableName(){ return 'b_prospekt_layout_files'; }
    public static function getMap(){ return [
        new Entity\IntegerField('ID', ['primary'=>true, 'autocomplete'=>true]),
        new Entity\StringField('SITE_ID', ['required'=>true]),
        new Entity\IntegerField('FUSER_ID'), new Entity\IntegerField('USER_ID'), new Entity\IntegerField('BASKET_ID'),
        new Entity\IntegerField('ORDER_ID'), new Entity\IntegerField('ORDER_BASKET_ID'), new Entity\IntegerField('PRODUCT_ID'),
        new Entity\StringField('ORIGINAL_NAME'), new Entity\StringField('STORAGE_NAME'), new Entity\StringField('YADISK_PATH'),
        new Entity\IntegerField('FILE_SIZE'), new Entity\StringField('EXTENSION'), new Entity\StringField('STATUS'), new Entity\StringField('DOWNLOAD_HASH'),
        new Entity\DatetimeField('CREATED_AT', ['default_value'=>new DateTime()]), new Entity\DatetimeField('UPDATED_AT', ['default_value'=>new DateTime()]),
    ]; }
}
