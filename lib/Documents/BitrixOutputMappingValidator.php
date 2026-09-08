<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__ . '/DocumentOutputMappings.php';

/** Read-only site capability check; never enables or executes a catalog write. */
final class BitrixOutputMappingValidator
{
    private string $provider;
    public function __construct(string $provider) { $this->provider = $provider; }
    public function __invoke(object $document, object $connection): array
    {
        if (!\Bitrix\Main\Loader::includeModule('prospektweb.frontcalc')) {
            throw new \RuntimeException('Site catalog adapter unavailable.', 503);
        }
        $config = new \Prospektweb\Frontcalc\Config\ConfigManager();
        if ($this->provider === '' || $connection->provider !== $this->provider
            || $connection->productsCatalog !== (string)$config->getProductIblockId()
            || $connection->offersCatalog !== (string)$config->getSkuIblockId()) {
            throw new \InvalidArgumentException('Подключение версии не соответствует каталогам текущего сайта.');
        }
        DocumentOutputMappings::validate($connection->outputMappings);
        $issues = [];
        if ($connection->outputMappings === []) {
            $issues[] = ['severity' => 'warning', 'code' => 'output_mappings.not_configured', 'path' => 'outputMappings',
                'message' => 'Выходные сопоставления ещё не добавлены в версию.'];
        }
        $issues[] = ['severity' => 'warning', 'code' => 'output_mappings.writeback_unavailable', 'path' => 'outputMappings',
            'message' => 'Проверен контракт сопоставлений. Запись результатов в ТП в документном режиме ещё не подключена.'];
        return $issues;
    }
}
