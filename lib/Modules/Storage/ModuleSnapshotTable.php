<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Modules\Storage;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\Type\DateTime;

final class ModuleSnapshotTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_pw_calc_module_snapshot';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
            (new StringField('SNAPSHOT_UID'))->configureRequired()->configureSize(64),
            (new IntegerField('INSTANCE_ID'))->configureRequired(),
            (new IntegerField('INSTANCE_REVISION'))->configureRequired(),
            (new IntegerField('PRESET_ID'))->configureRequired(),
            (new TextField('SNAPSHOT_JSON'))->configureRequired()->configureLong(),
            (new StringField('SNAPSHOT_HASH'))->configureRequired()->configureSize(64),
            (new TextField('LEGACY_SNAPSHOT_JSON'))->configureLong(),
            (new DatetimeField('CREATED_AT'))->configureRequired()->configureDefaultValue(static fn() => new DateTime()),
            (new IntegerField('CREATED_BY'))->configureRequired(),
        ];
    }
}
