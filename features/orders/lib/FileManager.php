<?php
namespace Prospektweb\LayoutFiles;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sale;
use Prospektweb\LayoutFiles\Internals\LayoutFileTable;
use Prospektweb\LayoutFiles\Service\YandexDiskClient;

class FileManager
{
    public static function getFuserId(): int
    {
        Loader::includeModule('sale');
        return (int)Sale\Fuser::getId();
    }

    public static function init(array $data): array
    {
        $basketId = (int)$data['basketId'];
        self::assertBasketOwner($basketId);

        $name = trim((string)$data['name']);
        $size = (int)$data['size'];
        self::validate($name, $size);

        $old = self::getActiveByBasket(self::getFuserId(), $basketId);
        if ($old) {
            self::deleteByRow($old);
            self::clearBasketProperties($basketId);
        }

        $storage = self::safeName($name);
        $path = Config::getBaseFolder() . '/tmp/' . self::getFuserId() . '/' . $basketId . '/' . $storage;

        $client = new YandexDiskClient();
        $client->ensureDirectory(dirname($path));
        $href = $client->getUploadHref($path, true);

        $now = new DateTime();
        $hash = bin2hex(Random::getBytes(16));
        $add = LayoutFileTable::add([
            'SITE_ID' => SITE_ID,
            'FUSER_ID' => self::getFuserId(),
            'USER_ID' => (int)CurrentUser::get()->getId(),
            'BASKET_ID' => $basketId,
            'PRODUCT_ID' => (int)($data['productId'] ?? 0),
            'ORIGINAL_NAME' => $name,
            'STORAGE_NAME' => $storage,
            'YADISK_PATH' => $path,
            'FILE_SIZE' => $size,
            'EXTENSION' => Config::getExtension($name),
            'STATUS' => 'uploading',
            'DOWNLOAD_HASH' => $hash,
            'CREATED_AT' => $now,
            'UPDATED_AT' => $now,
        ]);

        if (!$add->isSuccess()) {
            throw new \RuntimeException(implode('; ', $add->getErrorMessages()));
        }

        return ['fileId' => $add->getId(), 'uploadHref' => $href, 'hash' => $hash];
    }

    public static function complete(int $id, string $hash): array
    {
        $row = self::getByIdHash($id, $hash);
        self::assertBasketAccess($row);

        $resource = (new YandexDiskClient())->getResource($row['YADISK_PATH']);
        if (($resource['type'] ?? '') !== 'file') {
            throw new \RuntimeException('Файл не найден на Яндекс.Диске.');
        }

        $size = (int)($resource['size'] ?? 0);
        self::validate($row['ORIGINAL_NAME'], $size);

        LayoutFileTable::update($id, ['FILE_SIZE' => $size, 'STATUS' => 'uploaded', 'UPDATED_AT' => new DateTime()]);
        self::syncBasketProperties((int)$row['BASKET_ID'], $id, $hash, $row['ORIGINAL_NAME']);

        $row['FILE_SIZE'] = $size;
        $row['STATUS'] = 'uploaded';
        return self::format($row);
    }

    public static function delete(int $id, string $hash): void
    {
        $row = self::getByIdHash($id, $hash);
        self::assertBasketAccess($row);
        self::deleteByRow($row);
        self::clearBasketProperties((int)$row['BASKET_ID']);
    }

    public static function listForBasket(int $basketId): array
    {
        self::assertBasketOwner($basketId);
        $row = self::getActiveByBasket(self::getFuserId(), $basketId);
        return $row ? self::format($row) : [];
    }

    public static function getByIdHash(int $id, string $hash): array
    {
        $row = LayoutFileTable::getList(['filter' => ['=ID' => $id, '=DOWNLOAD_HASH' => $hash]])->fetch();
        if (!$row) {
            throw new \RuntimeException('Файл не найден.');
        }
        return $row;
    }

    public static function canDownload(array $row): bool
    {
        global $USER;
        if (is_object($USER) && $USER->IsAdmin()) {
            return true;
        }

        if ((int)$row['ORDER_ID'] <= 0) {
            return (int)$row['FUSER_ID'] === self::getFuserId();
        }

        if (!Loader::includeModule('sale')) {
            return false;
        }

        $order = Sale\Order::load((int)$row['ORDER_ID']);
        if (!$order) {
            return false;
        }

        if ((int)$order->getUserId() > 0 && (int)$order->getUserId() === (int)CurrentUser::get()->getId()) {
            return true;
        }

        return is_object($USER) && $USER->CanDoOperation('view_all_orders');
    }

    public static function getDownloadUrl(array $row): string
    {
        return '/local/tools/prospekt_layout/download.php?id=' . (int)$row['ID'] . '&hash=' . urlencode($row['DOWNLOAD_HASH']);
    }

    public static function attachOrder(Sale\Order $order): void
    {
        $basket = $order->getBasket();
        if (!$basket) {
            return;
        }

        $client = new YandexDiskClient();
        $changed = false;

        foreach ($basket as $item) {
            $row = self::findRowForOrderItem($basket, $item);
            if (!$row || in_array($row['STATUS'], ['attached_to_order', 'deleted'], true)) {
                continue;
            }

            $newPath = Config::getBaseFolder() . '/orders/' . (int)$order->getId() . '/' . (int)$item->getId() . '/' . $row['STORAGE_NAME'];
            try {
                $client->ensureDirectory(dirname($newPath));
                $client->move($row['YADISK_PATH'], $newPath, true);
            } catch (\Throwable $e) {
                Logger::error('order.bind.move', $e, ['fileId' => $row['ID'], 'from' => $row['YADISK_PATH'], 'to' => $newPath]);
                $newPath = $row['YADISK_PATH'];
            }

            LayoutFileTable::update((int)$row['ID'], [
                'ORDER_ID' => (int)$order->getId(),
                'ORDER_BASKET_ID' => (int)$item->getId(),
                'YADISK_PATH' => $newPath,
                'STATUS' => 'attached_to_order',
                'UPDATED_AT' => new DateTime(),
            ]);

            $row['YADISK_PATH'] = $newPath;
            $row['ORDER_ID'] = (int)$order->getId();
            $row['ORDER_BASKET_ID'] = (int)$item->getId();
            $row['STATUS'] = 'attached_to_order';

            self::setBasketItemProperty($item, 'LAYOUT_FILE_ID', 'ID файла макета', (string)$row['ID']);
            self::setBasketItemProperty($item, 'LAYOUT_FILE_NAME', 'Имя файла макета', $row['ORIGINAL_NAME']);
            self::setBasketItemProperty($item, 'LAYOUT_FILE_LINK', 'Ссылка на макет', self::getDownloadUrl($row));
            $changed = true;
        }

        if ($changed) {
            $basket->save();
        }
    }

    private static function findRowForOrderItem($basket, Sale\BasketItem $item): ?array
    {
        $propertyFileId = (int)self::getBasketItemPropertyValue($item, 'LAYOUT_FILE_ID');
        if ($propertyFileId > 0) {
            $row = LayoutFileTable::getList(['filter' => ['=ID' => $propertyFileId], 'limit' => 1])->fetch();
            if ($row) {
                return $row;
            }
        }

        $fuserId = method_exists($basket, 'getFUserId') ? (int)$basket->getFUserId() : 0;
        if ($fuserId > 0) {
            $row = self::getActiveByBasket($fuserId, (int)$item->getId());
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    private static function validate(string $name, int $size): void
    {
        if ($name === '' || !Config::isAllowedExtension($name)) {
            throw new \RuntimeException('Недопустимое расширение файла.');
        }
        if ($size <= 0 || $size > Config::getMaxSize()) {
            throw new \RuntimeException('Размер файла превышает лимит.');
        }
    }

    private static function safeName(string $name): string
    {
        $ext = Config::getExtension($name);
        $base = preg_replace('/[^a-zA-Z0-9._-]+/u', '_', pathinfo($name, PATHINFO_FILENAME));
        return date('Ymd_His') . '_' . Random::getString(8) . '_' . $base . '.' . $ext;
    }

    private static function getActiveByBasket(int $fuserId, int $basketId): ?array
    {
        return LayoutFileTable::getList([
            'filter' => ['=FUSER_ID' => $fuserId, '=BASKET_ID' => $basketId, '@STATUS' => ['uploading', 'uploaded']],
            'order' => ['ID' => 'DESC'],
            'limit' => 1,
        ])->fetch() ?: null;
    }

    private static function deleteByRow(array $row): void
    {
        (new YandexDiskClient())->delete($row['YADISK_PATH']);
        LayoutFileTable::update((int)$row['ID'], ['STATUS' => 'deleted', 'UPDATED_AT' => new DateTime()]);
    }

    private static function assertBasketAccess(array $row): void
    {
        if ((int)$row['FUSER_ID'] !== self::getFuserId()) {
            throw new \RuntimeException('Нет доступа к файлу.');
        }
    }

    private static function loadBasketItem(int $basketId): ?Sale\BasketItem
    {
        $basket = Sale\Basket::loadItemsForFUser(self::getFuserId(), SITE_ID);
        foreach ($basket as $item) {
            if ((int)$item->getId() === $basketId) {
                return $item;
            }
        }
        return null;
    }

    private static function assertBasketOwner(int $basketId): void
    {
        if ($basketId <= 0 || !Loader::includeModule('sale')) {
            throw new \RuntimeException('Позиция корзины не найдена.');
        }
        if (!self::loadBasketItem($basketId)) {
            throw new \RuntimeException('Позиция корзины не найдена.');
        }
    }

    private static function syncBasketProperties(int $basketId, int $fileId, string $hash, string $name): void
    {
        $item = self::loadBasketItem($basketId);
        if (!$item) {
            return;
        }

        self::setBasketItemProperty($item, 'LAYOUT_FILE_ID', 'ID файла макета', (string)$fileId);
        self::setBasketItemProperty($item, 'LAYOUT_FILE_LINK', 'Ссылка на макет', '/local/tools/prospekt_layout/download.php?id=' . $fileId . '&hash=' . urlencode($hash));
        self::setBasketItemProperty($item, 'LAYOUT_FILE_NAME', 'Имя файла макета', $name);
        $item->getCollection()->save();
    }

    private static function clearBasketProperties(int $basketId): void
    {
        $item = self::loadBasketItem($basketId);
        if (!$item) {
            return;
        }

        foreach (['LAYOUT_FILE_ID', 'LAYOUT_FILE_LINK', 'LAYOUT_FILE_NAME'] as $code) {
            self::setBasketItemProperty($item, $code, $code, '');
        }
        $item->getCollection()->save();
    }

    private static function setBasketItemProperty(Sale\BasketItem $item, string $code, string $name, string $value): void
    {
        $collection = $item->getPropertyCollection();
        $property = null;
        foreach ($collection as $p) {
            if ($p->getField('CODE') === $code) {
                $property = $p;
                break;
            }
        }

        if ($property) {
            $property->setFields(['NAME' => $name, 'VALUE' => $value]);
        } else {
            $collection->createItem()->setFields(['NAME' => $name, 'CODE' => $code, 'VALUE' => $value, 'SORT' => 500]);
        }
    }

    private static function getBasketItemPropertyValue(Sale\BasketItem $item, string $code): string
    {
        foreach ($item->getPropertyCollection() as $property) {
            if ($property->getField('CODE') === $code) {
                return (string)$property->getField('VALUE');
            }
        }
        return '';
    }

    private static function format(array $row): array
    {
        return [
            'id' => (int)$row['ID'],
            'hash' => $row['DOWNLOAD_HASH'],
            'name' => $row['ORIGINAL_NAME'],
            'size' => (int)$row['FILE_SIZE'],
            'sizeFormatted' => \CFile::FormatSize((int)$row['FILE_SIZE']),
            'downloadUrl' => self::getDownloadUrl($row),
            'status' => $row['STATUS'],
        ];
    }
}
