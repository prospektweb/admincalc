<?php
declare(strict_types=1);
namespace Bitrix\Main {
    final class Loader {
        public static bool $available = true;
        public static bool $included = false;
        public static function includeModule(string $name): bool {
            if ($name !== 'iblock') { throw new \RuntimeException('Unexpected module'); }
            self::$included = self::$available; return self::$available;
        }
    }
}
namespace {
    final class CIBlockProperty {
        public static array $reads = [];
        public static function GetList(array $order, array $filter) {
            if (!\Bitrix\Main\Loader::$included) { throw new RuntimeException('Iblock API used before module bootstrap'); }
            self::$reads[] = $filter['IBLOCK_ID'];
            return new class { public function Fetch() { return false; } };
        }
    }
    require_once dirname(__DIR__) . '/lib/Services/CalculatorInputSourceCatalogService.php';
    $service = new \Prospektweb\Calc\Services\CalculatorInputSourceCatalogService();
    $catalog = $service->validationAuthorityForCatalogs(14, 15);
    if (CIBlockProperty::$reads !== [14, 15] || $catalog['properties'] !== []) {
        throw new RuntimeException('Native source catalog did not bootstrap both exact catalog reads');
    }
    \Bitrix\Main\Loader::$available = false; \Bitrix\Main\Loader::$included = false;
    try { $service->loadCatalogs(14, 15); throw new LogicException('Unavailable iblock module must fail closed'); }
    catch (RuntimeException $error) {
        if ($error->getCode() !== 503 || CIBlockProperty::$reads !== [14, 15]) { throw $error; }
    }
    echo "PASS native input catalog bootstraps iblock and rejects unavailable module before API use\n";
}
