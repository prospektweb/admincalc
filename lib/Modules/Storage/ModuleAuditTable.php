<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Modules\Storage;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\Type\DateTime;

final class ModuleAuditTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_pw_calc_module_audit';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
            (new StringField('ACTION'))->configureRequired()->configureSize(64),
            (new IntegerField('ACTOR_ID'))->configureRequired(),
            (new IntegerField('FAMILY_ID')),
            (new IntegerField('VERSION_ID')),
            (new IntegerField('INSTANCE_ID')),
            (new IntegerField('SNAPSHOT_ID')),
            (new TextField('PAYLOAD_JSON'))->configureLong(),
            (new DatetimeField('CREATED_AT'))->configureRequired()->configureDefaultValue(static fn() => new DateTime()),
        ];
    }
}
