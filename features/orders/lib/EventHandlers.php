<?php
namespace Prospektweb\LayoutFiles;

use Bitrix\Main\Event;
use Bitrix\Sale\BasketItem;
use Bitrix\Sale\Order;

class EventHandlers
{
    private static $processedOrderIds = [];

    public static function onSaleOrderSaved(Event $event): void
    {
        /** @var Order|null $order */
        $order = $event->getParameter('ENTITY');
        $isNew = (bool)$event->getParameter('IS_NEW');

        if (!$isNew || !($order instanceof Order) || (int)$order->getId() <= 0) {
            return;
        }

        $orderId = (int)$order->getId();
        if (isset(self::$processedOrderIds[$orderId])) {
            return;
        }
        self::$processedOrderIds[$orderId] = true;

        try {
            FileManager::attachOrder($order);
        } catch (\Throwable $e) {
            Logger::error('order.bind', $e, ['orderId' => $orderId]);
        }
    }

    public static function onSaleBasketItemSaved(Event $event): void
    {
        $item = $event->getParameter('ENTITY');
        if (!($item instanceof BasketItem)) {
            return;
        }

        try {
            DesiredReceiveDateManager::applyToItem($item);
        } catch (\Throwable $e) {
            Logger::error('desired_receive_date.basket_item_saved', $e);
        }
    }
}
