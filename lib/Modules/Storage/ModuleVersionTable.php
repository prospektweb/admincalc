<?php

declare(strict_types=1);

namespace Prospektweb\Calc\Modules\Storage;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\Type\DateTime;

final class ModuleVersionTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_pw_calc_module_version';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
            (new IntegerField('FAMILY_ID'))->configureRequired(),
            (new StringField('VERSION'))->configureRequired()->configureSize(64),
            (new StringField('KIND'))->configureRequired()->configureSize(32),
            (new StringField('STATUS'))->configureRequired()->configureSize(32),
            (new TextField('CONTENT_JSON'))->configureRequired()->configureLong(),
            (new StringField('CONTENT_HASH'))->configureRequired()->configureSize(64),
            (new TextField('TEST_RESULTS_JSON'))->configureLong(),
            (new IntegerField('REVISION'))->configureRequired()->configureDefaultValue(1),
            (new DatetimeField('PUBLISHED_AT')),
            (new IntegerField('PUBLISHED_BY')),
            (new DatetimeField('CREATED_AT'))->configureRequired()->configureDefaultValue(static fn() => new DateTime()),
            (new IntegerField('CREATED_BY'))->configureRequired(),
            (new DatetimeField('UPDATED_AT'))->configureRequired()->configureDefaultValue(static fn() => new DateTime()),
            (new IntegerField('UPDATED_BY'))->configureRequired(),
        ];
    }
}
