<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Services/AiGatewayService.php';

use Prospektweb\Calc\Services\AiGatewayService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$reflection = new ReflectionClass(AiGatewayService::class);
$service = $reflection->newInstanceWithoutConstructor();
$schema = $reflection->getReflectionConstant('LOGIC_STRUCTURE_PILOT_RESPONSE_SCHEMA')->getValue();
$validate = $reflection->getMethod('validatePilotStructureQuality');
$validate->setAccessible(true);

foreach ($schema['catalogObjects'] as $index => &$object) {
    $object['title'] = 'Конкретная сущность ' . ($index + 1);
    $object['description'] = 'Назначение, ожидаемые входы и будущие сопоставления сущности ' . ($index + 1) . '.';
}
unset($object);

$stageTitles = ['Подготовка основы', 'Печать', 'Сушка', 'Ламинация', 'Резка', 'Упаковка'];
foreach ($schema['stages'] as $index => &$stage) {
    $stage['title'] = $stageTitles[$index];
    $stage['description'] = 'Отдельный производственный этап.';
}
unset($stage);

$errors = $validate->invoke($service, $schema, 'detailed');
$assert($errors === [], 'Six-stage conditional schema must satisfy the detailed quality gate: ' . implode(' ', $errors));

$withoutCondition = $schema;
$withoutCondition['groups'] = array_values(array_filter($withoutCondition['groups'], static fn(array $group): bool => $group['kind'] !== 'condition'));
$errors = $validate->invoke($service, $withoutCondition, 'detailed');
$assert((bool)array_filter($errors, static fn(string $error): bool => str_contains($error, 'хотя бы одно условие')), 'Detailed draft without a condition must be rejected');

$compositeStage = $schema;
$compositeStage['stages'][5]['title'] = 'Люверсы, накатка или натяжка';
$errors = $validate->invoke($service, $compositeStage, 'detailed');
$assert((bool)array_filter($errors, static fn(string $error): bool => str_contains($error, 'объединяет альтернативные')), 'Composite stage card must be rejected');

echo "AI logic pilot quality checks passed\n";
