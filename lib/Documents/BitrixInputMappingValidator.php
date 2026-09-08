<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

/** Site adapter for read-only mapping checks. No preset lookup or publication. */
final class BitrixInputMappingValidator
{
    private string $provider;
    public function __construct(string $provider) { $this->provider = $provider; }

    public function __invoke(object $document, object $connection): array
    {
        if (!\Bitrix\Main\Loader::includeModule('prospektweb.frontcalc') || !\Bitrix\Main\Loader::includeModule('iblock')) {
            throw new \RuntimeException('Site input catalog adapter unavailable.', 503);
        }
        $config = new \Prospektweb\Frontcalc\Config\ConfigManager();
        $products = $config->getProductIblockId(); $offers = $config->getSkuIblockId();
        if ($this->provider === '' || $connection->provider !== $this->provider
            || $connection->productsCatalog !== (string)$products || $connection->offersCatalog !== (string)$offers) {
            throw new \InvalidArgumentException('Подключение версии не соответствует каталогам текущего сайта.');
        }
        require_once dirname(__DIR__) . '/Services/CalculatorInputSourceCatalogService.php';
        require_once dirname(__DIR__) . '/Services/CalculatorInputMappingService.php';
        $authority = (new \Prospektweb\Calc\Services\CalculatorInputSourceCatalogService())->validationAuthorityForCatalogs($products, $offers);
        // The shared semantic validator consumes form fields/bindings, not compiled
        // pricing or presentations. A mapping check must not claim full publication readiness.
        $form = ['formDefinition' => $document->form, 'bindingDefinition' => $connection->formBindings];
        return (new \Prospektweb\Calc\Services\CalculatorInputMappingService())->validateDocumentMappings(
            json_decode(json_encode($connection->inputMappings, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR),
            json_decode(json_encode($form, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR), $authority
        );
    }
}
