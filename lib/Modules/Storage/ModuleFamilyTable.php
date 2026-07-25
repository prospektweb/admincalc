<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Modules\Storage;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Type\DateTime;

final class ModuleFamilyTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_pw_calc_module_family';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
            (new StringField('CODE'))->configureRequired()->configureSize(190),
            (new StringField('NAME'))->configureRequired()->configureSize(255),
            (new StringField('DESCRIPTION'))->configureSize(1000),
            (new IntegerField('REVISION'))->configureRequired()->configureDefaultValue(1),
            (new DatetimeField('CREATED_AT'))->configureRequired()->configureDefaultValue(static fn() => new DateTime()),
            (new IntegerField('CREATED_BY'))->configureRequired(),
            (new DatetimeField('UPDATED_AT'))->configureRequired()->configureDefaultValue(static fn() => new DateTime()),
            (new IntegerField('UPDATED_BY'))->configureRequired(),
        ];
    }
}
