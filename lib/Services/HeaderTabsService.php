<?php

namespace Prospektweb\Calc\Services;

use Prospektweb\Calc\Calculator\ElementDataService;
use Prospektweb\Calc\Config\ConfigManager;

class HeaderTabsService
{
    protected ConfigManager $configManager;
    protected ElementDataService $elementDataService;

    public function __construct()
    {
        $this->configManager = new ConfigManager();
        $this->elementDataService = new ElementDataService();
    }

    /**
     * Возвращает карту [iblockId => entityType] для вкладок шапки калькуляции.
     *
     * @return array<string,int>
     */
    public function getHeaderIblockMap(): array
    {
        $map = [
            'materials' => $this->configManager->getIblockId('CALC_MATERIALS'),
            'materialsVariants' => $this->configManager->getIblockId('CALC_MATERIALS_VARIANTS'),
            'operations' => $this->configManager->getIblockId('CALC_OPERATIONS'),
            'operationsVariants' => $this->configManager->getIblockId('CALC_OPERATIONS_VARIANTS'),
            'details' => $this->configManager->getIblockId('CALC_DETAILS'),
            'equipment' => $this->configManager->getIblockId('CALC_EQUIPMENT'),
        ];

        return array_filter(
            $map,
            static fn ($id) => (int)$id > 0
        );
    }

    /**
     * Возвращает тип сущности по ID инфоблока.
     */
    public function resolveEntityTypeByIblockId(int $iblockId): ?string
    {
        $map = $this->getHeaderIblockMap();

        foreach ($map as $type => $id) {
            if ((int)$id === $iblockId) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Собирает данные элементов для вкладок шапки калькуляции.
     *
     * @param string   $entityType materials|materialsVariants|operations|operationsVariants|details|detailsVariants|equipment
     * @param int      $iblockId   ID инфоблока.
     * @param int[]    $itemIds    ID элементов.
     *
     * @return array
     */
    public function prepareHeaderItems(string $entityType, int $iblockId, array $itemIds): array
    {
        $payload = $this->elementDataService->prepareRefreshPayload([
            [
                'iblockId' => $iblockId,
                'iblockType' => null,
                'ids' => $itemIds,
            ],
        ]);

        $items = $payload[0]['data'] ?? [];

        return array_map(function (array $item) use ($entityType) {
            $itemId = (int)($item['id'] ?? 0);

            return [
                'id' => $this->buildHeaderItemId($entityType, $itemId),
                'itemId' => $itemId,
                'productId' => $item['productId'] ?? null,
                'name' => $item['name'] ?? '',
                'fields' => $item['fields'] ?? [],
                'measure' => $item['measure'] ?? null,
                'measureRatio' => $item['measureRatio'] ?? null,
                'prices' => $item['prices'] ?? [],
                'properties' => $item['properties'] ?? [],
            ];
        }, $items);
    }

    protected function buildHeaderItemId(string $entityType, int $itemId): string
    {
        return match ($entityType) {
            'materials' => 'header-material-' . $itemId,
            'materialsVariants' => 'header-material-' . $itemId,
            'operations' => 'header-operation-' . $itemId,
            'operationsVariants' => 'header-operation-' . $itemId,
            'details' => 'header-detail-' . $itemId,
            'detailsVariants' => 'header-detail-' . $itemId,
            'equipment' => 'header-equipment-' . $itemId,
            default => 'header-item-' . $itemId,
        };
    }
}
