<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Modules/CanonicalJson.php';
require_once dirname(__DIR__) . '/lib/Modules/ModuleValidator.php';
require_once dirname(__DIR__) . '/lib/Modules/DependencyResolver.php';

use Prospektweb\Calc\Modules\CanonicalJson;
use Prospektweb\Calc\Modules\DependencyResolver;
use Prospektweb\Calc\Modules\ModuleValidator;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$vector = [
    'numbers' => [333333333.33333329, 1E30, 4.50, 2e-3, 1e-27, 1e-6, 1e20, -0.0],
    'string' => "€$\u{000F}\nA'B\"\\\\\"/",
    'literals' => [null, true, false],
];
$expected = '{"literals":[null,true,false],"numbers":[333333333.3333333,1e+30,4.5,0.002,1e-27,0.000001,100000000000000000000,0],"string":"€$\\u000f\\nA\'B\\"\\\\\\\\\\"/"}';
$assert(CanonicalJson::encode($vector) === $expected, 'RFC 8785 canonical vector differs');
$assert(
    CanonicalJson::hash($vector) === '967c80d7de75f96737188db38d067a9e299815b5a24d84e0e93172d26ddc263f',
    'RFC 8785 SHA-256 vector differs'
);

$makeModule = static function (
    string $familyId,
    string $version,
    array $dependencies = [],
    string $status = 'published'
): array {
    $module = [
        'schema' => 'prospektweb.calc.module/v1',
        'familyId' => $familyId,
        'version' => $version,
        'kind' => 'stage',
        'status' => $status,
        'name' => $familyId,
        'content' => [
            'rootNodeId' => 'root',
            'nodes' => [
                [
                    'nodeId' => 'root',
                    'nodeType' => 'stage',
                    'order' => 0,
                    'logic' => ['version' => 1, 'variables' => []],
                ],
            ],
        ],
        'ports' => [],
        'entityRoles' => [],
        'dependencies' => $dependencies,
        'tests' => [],
        'contentHash' => str_repeat('0', 64),
        'provenance' => ['createdAt' => '2026-07-25T00:00:00Z', 'createdBy' => 'test'],
    ];
    $module['contentHash'] = CanonicalJson::moduleContentHash($module);
    return $module;
};

$paper100 = $makeModule('paper', '1.0.0');
$paper110 = $makeModule('paper', '1.1.0');
$print = $makeModule('print', '1.0.0', [
    ['ref' => 'paper', 'familyId' => 'paper', 'versionRange' => '^1.0.0'],
]);
$product = $makeModule('product', '1.0.0', [
    ['ref' => 'print', 'familyId' => 'print', 'versionRange' => '1.0.0'],
]);

$assert(ModuleValidator::validate($product) === [], 'valid module rejected');
$resolved = DependencyResolver::resolve($product, [$paper100, $paper110, $print]);
$assert(
    $resolved['executionOrder'] === ['paper@1.1.0', 'print@1.0.0', 'product@1.0.0'],
    'dependencies must be locked newest-first and execute before consumers'
);
$assert($resolved['dependencyLock'][0]['version'] === '1.0.0', 'direct dependency lock missing');
$assert($resolved['dependencyLock'][1]['version'] === '1.1.0', 'transitive dependency lock missing');

$legacy = $makeModule('legacy', '1.0.0');
$legacy['content']['nodes'][0]['logic']['formula'] = 'stage_12628.width';
$legacy['contentHash'] = CanonicalJson::moduleContentHash($legacy);
$assert(
    str_contains(implode('; ', ModuleValidator::validate($legacy)), 'Legacy stage ID'),
    'numeric stage aliases must be rejected from reusable content'
);
$invalidPort = $makeModule('invalidPort', '1.0.0');
$invalidPort['ports'] = [[
    'code' => 'quantity',
    'direction' => 'sideways',
    'valueType' => 'money',
    'required' => 'Y',
]];
$invalidPort['contentHash'] = CanonicalJson::moduleContentHash($invalidPort);
$invalidPortErrors = implode('; ', ModuleValidator::validate($invalidPort));
$assert(str_contains($invalidPortErrors, 'direction is invalid'), 'invalid port direction must fail');
$assert(str_contains($invalidPortErrors, 'valueType is invalid'), 'invalid port type must fail');
$assert(str_contains($invalidPortErrors, 'required must be boolean'), 'invalid port required flag must fail');
$assert(ModuleValidator::validate([]) !== [], 'malformed module must return errors instead of throwing');

$cycleA = $makeModule('cycleA', '1.0.0', [
    ['ref' => 'b', 'familyId' => 'cycleB', 'versionRange' => '1.0.0'],
]);
$cycleB = $makeModule('cycleB', '1.0.0', [
    ['ref' => 'a', 'familyId' => 'cycleA', 'versionRange' => '1.0.0'],
]);
try {
    DependencyResolver::resolve($cycleA, [$cycleA, $cycleB]);
    $assert(false, 'dependency cycle must fail');
} catch (RuntimeException $error) {
    $assert(str_contains($error->getMessage(), 'Dependency cycle'), 'cycle error must be explicit');
}

echo "Calculation module resolver tests passed\n";
