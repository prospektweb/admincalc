<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Deployment/CatalogSchemaProfileService.php';

use Prospektweb\Calc\Deployment\CatalogSchemaProfileService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$service = new CatalogSchemaProfileService();
$profilePath = dirname(__DIR__) . '/resources/deployment/prospekt-print-typography-v1.json';
$profile = $service->load($profilePath);

$assert($profile['contract'] === CatalogSchemaProfileService::CONTRACT, 'Profile contract must be exact');
$assert($profile['profile']['content_included'] === false, 'Profile must not include content');
$assert(count($profile['roles']) === 2, 'Profile must contain products and offers');
$assert(count($profile['roles'][0]['properties']) === 77, 'Production product property snapshot must contain 77 properties');
$assert(count($profile['roles'][1]['properties']) === 57, 'Production offer property snapshot must contain 57 properties');

$current = $profile;
$current['profile']['id'] = 'current';
$plan = $service->analyze($profile, $current);
$assert($plan['summary']['create'] === 0, 'Identical profile must not create properties');
$assert($plan['summary']['update'] === 0, 'Identical profile must not update properties');
$assert($plan['summary']['delete'] === 0, 'Identical profile must not delete properties');

$removed = array_shift($current['roles'][0]['properties']);
$current['roles'][0]['properties'][0]['fields']['NAME'] .= ' changed';
$current['roles'][0]['properties'][] = [
    'key' => 'code:ASPRO_UNUSED_PROPERTY',
    'property_id' => 999001,
    'fields' => ['CODE' => 'ASPRO_UNUSED_PROPERTY', 'XML_ID' => '', 'NAME' => 'Unused'],
    'link_target' => null,
    'enums' => [],
    'features' => [],
];
$current['roles'][1]['properties'][] = [
    'key' => 'code:FRONTCALC_FUTURE_OWNED',
    'property_id' => 999002,
    'fields' => ['CODE' => 'FRONTCALC_FUTURE_OWNED', 'XML_ID' => '', 'NAME' => 'Owned'],
    'link_target' => null,
    'enums' => [],
    'features' => [],
];
$current['roles'][0]['properties'][] = [
    'key' => 'code:CALC_FUTURE_OWNED',
    'property_id' => 999003,
    'fields' => ['CODE' => 'CALC_FUTURE_OWNED', 'XML_ID' => '', 'NAME' => 'Owned calculator property'],
    'link_target' => null,
    'enums' => [],
    'features' => [],
];

$plan = $service->analyze($profile, $current);
$assert($plan['summary']['create'] === 1, 'Missing desired property must be created');
$assert($plan['summary']['update'] === 1, 'Changed desired property must be updated');
$assert($plan['summary']['delete'] === 1, 'Unowned extra Aspro property must be deleted');
$assert($plan['summary']['protected'] === 2, 'Future module-owned properties must be protected in both catalog roles');
$assert(
    count(array_filter($plan['operations'], static fn(array $operation): bool => $operation['key'] === $removed['key'])) === 1,
    'Plan must identify the removed production property by stable key'
);
$assert(strlen((string)$plan['plan_hash']) === 64, 'Plan must have a deterministic SHA-256 revision');

echo "catalog_schema_profile_service_test: OK\n";
