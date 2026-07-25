<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Modules\Storage;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\Type\DateTime;

final class ModuleInstanceTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_pw_calc_module_instance';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
            (new StringField('INSTANCE_UID'))->configureRequired()->configureSize(64),
            (new IntegerField('PRESET_ID'))->configureRequired(),
            (new IntegerField('VERSION_ID'))->configureRequired(),
            (new IntegerField('REVISION'))->configureRequired()->configureDefaultValue(1),
            (new IntegerField('ENABLED'))->configureRequired()->configureDefaultValue(1),
            (new IntegerField('SORT'))->configureRequired()->configureDefaultValue(500),
            (new TextField('BINDINGS_JSON'))->configureRequired()->configureLong(),
            (new TextField('ENTITY_BINDINGS_JSON'))->configureRequired()->configureLong(),
            (new TextField('DEPENDENCY_LOCK_JSON'))->configureRequired()->configureLong(),
            (new TextField('CONTEXT_JSON'))->configureLong(),
            (new IntegerField('SNAPSHOT_ID'))->configureNullable(true),
            (new DatetimeField('CREATED_AT'))->configureRequired()->configureDefaultValue(static fn() => new DateTime()),
            (new IntegerField('CREATED_BY'))->configureRequired(),
            (new DatetimeField('UPDATED_AT'))->configureRequired()->configureDefaultValue(static fn() => new DateTime()),
            (new IntegerField('UPDATED_BY'))->configureRequired(),
        ];
    }
}
