<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__ . '/BitrixConnection.php';
require_once __DIR__ . '/ResourceCatalogRegistry.php';
require_once __DIR__ . '/BitrixResourceLinks.php';

/** Catalog metadata only: no prices, document mutation, legacy graph, or repair writes. */
final class BitrixResourceCatalog
{
    private const KINDS = ['CALC_MATERIALS' => 'material', 'CALC_MATERIALS_VARIANTS' => 'materialVariant',
        'CALC_OPERATIONS' => 'operation', 'CALC_OPERATIONS_VARIANTS' => 'operationVariant', 'CALC_EQUIPMENT' => 'equipment'];
    private const LIMIT = 20000;
    private string $provider;
    private $catalogId;
    private $links;
    public function __construct(string $provider, ?callable $catalogId = null, ?callable $links = null)
    { $this->provider = $provider; $this->catalogId = $catalogId ?? [new ResourceCatalogRegistry(), 'getIblockId']; $this->links = $links; }

    public function __invoke(): array
    {
        if ($this->provider === '' || strlen($this->provider) > 128) throw new \RuntimeException('Resource provider is not configured.', 409);
        if (!\Bitrix\Main\Loader::includeModule('iblock')) throw new \RuntimeException('Resource catalog unavailable.', 503);
        $connection = \Bitrix\Main\Application::getConnection();
        if (\Prospektweb\Calc\Services\BitrixTransactionStateAuthority::isActive($connection)) throw new \LogicException('Resource browser owns its transaction.');
        $db = new BitrixConnection($connection); $db->begin(true);
        try { $result = $this->read($db); $db->commit(); return $result; }
        catch (\Throwable $error) { $db->rollback(); throw $error; }
    }

    private function read(SqlConnection $db): array
    {
        $sections = []; $items = []; $identities = [];
        $links = $this->links ?? [new BitrixResourceLinks($db), 'load'];
        foreach (self::KINDS as $catalog => $kind) {
            $iblockId = ($this->catalogId)($catalog);
            if (!is_int($iblockId) || $iblockId < 1) throw new \RuntimeException('Resource directory unavailable.', 409);
            $cursor = \CIBlockSection::GetList(['LEFT_MARGIN' => 'ASC', 'ID' => 'ASC'], ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'Y'], false,
                ['ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'NAME']);
            while ($row = $cursor->Fetch()) {
                if (count($sections) >= self::LIMIT) throw new \InvalidArgumentException('Справочник слишком велик для полного дерева. Данные не усечены.');
                $id = self::identity($row, $iblockId); $key = $catalog . ':section:' . $id;
                if (isset($identities[$key])) throw new \RuntimeException('Duplicate resource section.', 409);
                $identities[$key] = true;
                $sections[] = ['key' => $key, 'parentKey' => (int)$row['IBLOCK_SECTION_ID'] > 0 ? $catalog . ':section:' . $row['IBLOCK_SECTION_ID'] : null,
                    'catalog' => $catalog, 'name' => (string)$row['NAME']];
            }
            $filter = ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'Y'];
            $rows = []; $cursor = \CIBlockElement::GetList(['SORT' => 'ASC', 'NAME' => 'ASC', 'ID' => 'ASC'], $filter, false,
                ['nTopCount' => self::LIMIT + 1], ['ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'NAME', 'CODE', 'PREVIEW_TEXT']);
            while ($row = $cursor->Fetch()) {
                $id = self::identity($row, $iblockId);
                if (isset($rows[$id])) throw new \RuntimeException('Duplicate resource identity.', 409);
                if (count($items) + count($rows) >= self::LIMIT) throw new \InvalidArgumentException('Справочник слишком велик для полного дерева. Данные не усечены.');
                $row['PROPERTIES'] = []; $rows[$id] = $row;
            }
            // Batched authoritative links: the Bitrix property API can write its V2 cache on read.
            foreach (array_chunk($rows, 250, true) as $batch) {
                $properties = $links($iblockId, array_keys($batch));
                foreach ($batch as $id => $row) {
                    $props = $properties[$id]; $parent = (string)($props['CML2_LINK'][0] ?? '');
                    $parentCatalog = $kind === 'materialVariant' ? 'CALC_MATERIALS' : ($kind === 'operationVariant' ? 'CALC_OPERATIONS' : null);
                    $items[] = ['binding' => $this->binding($catalog, (string)$id), 'kind' => $kind, 'name' => (string)$row['NAME'],
                        'description' => (string)($row['PREVIEW_TEXT'] ?? ''), 'code' => (string)($row['CODE'] ?? ''),
                        'sectionKey' => (int)$row['IBLOCK_SECTION_ID'] > 0 ? $catalog . ':section:' . $row['IBLOCK_SECTION_ID'] : null,
                        'parentBinding' => $parentCatalog && preg_match('/^[1-9][0-9]*$/D', $parent) ? $this->binding($parentCatalog, $parent) : null,
                        'supportedEquipmentKeys' => self::keys($props['SUPPORTED_EQUIPMENT_LIST'] ?? []),
                        'supportedMaterialVariantKeys' => self::keys($props['SUPPORTED_MATERIALS_VARIANTS_LIST'] ?? [])];
                }
            }
        }
        return ['provider' => $this->provider, 'sections' => $sections, 'items' => $items];
    }
    private function binding(string $catalog, string $key): array
    { return ['provider' => $this->provider, 'catalog' => $catalog, 'key' => $key]; }
    private static function identity(array $row, int $iblockId): string
    {
        if ((int)($row['IBLOCK_ID'] ?? 0) !== $iblockId || !preg_match('/^[1-9][0-9]*$/D', (string)($row['ID'] ?? ''))) throw new \RuntimeException('Resource catalog identity mismatch.', 409);
        return (string)$row['ID'];
    }
    private static function keys($values): array
    { return array_values(array_unique(array_filter(array_map('strval', (array)$values), static fn(string $id): bool => preg_match('/^[1-9][0-9]*$/D', $id) === 1))); }
}
