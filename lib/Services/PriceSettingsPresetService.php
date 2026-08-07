<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Config\Option;

class PriceSettingsPresetService
{
    private const MODULE_ID = 'prospektweb.calc';
    private const OPTION_NAME = 'PRICE_SETTINGS_PRESETS_JSON';

    public function list(): array
    {
        $decoded = json_decode((string)Option::get(self::MODULE_ID, self::OPTION_NAME, '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $preset) {
            $normalized = $this->normalize($preset);
            if ($normalized !== null) {
                $result[] = $normalized;
            }
        }
        return $result;
    }

    public function save(string $name, string $mode, array $prices): array
    {
        $normalized = $this->normalize([
            'id' => 'price_' . bin2hex(random_bytes(8)),
            'name' => trim($name),
            'mode' => $mode,
            'prices' => $prices,
        ]);
        if ($normalized === null) {
            return ['status' => 'error', 'message' => 'Некорректный пресет отпускных цен'];
        }

        $items = $this->list();
        foreach ($items as $index => $item) {
            if (mb_strtolower($item['name']) === mb_strtolower($normalized['name'])) {
                $normalized['id'] = $item['id'];
                $items[$index] = $normalized;
                Option::set(self::MODULE_ID, self::OPTION_NAME, json_encode($items, JSON_UNESCAPED_UNICODE));
                return ['status' => 'ok', 'preset' => $normalized, 'presets' => $items];
            }
        }

        $items[] = $normalized;
        Option::set(self::MODULE_ID, self::OPTION_NAME, json_encode($items, JSON_UNESCAPED_UNICODE));
        return ['status' => 'ok', 'preset' => $normalized, 'presets' => $items];
    }

    public function rename(string $id, string $name): array
    {
        $id = trim($id);
        $name = trim($name);
        if ($id === '' || $name === '') {
            return ['status' => 'error', 'message' => 'Укажите шаблон и новое название'];
        }
        if (mb_strlen($name) > 120) {
            return ['status' => 'error', 'message' => 'Название шаблона не должно превышать 120 символов'];
        }

        $items = $this->list();
        $targetIndex = null;
        foreach ($items as $index => $item) {
            if ($item['id'] === $id) {
                $targetIndex = $index;
                continue;
            }
            if (mb_strtolower($item['name']) === mb_strtolower($name)) {
                return ['status' => 'error', 'message' => 'Шаблон с таким названием уже существует'];
            }
        }
        if ($targetIndex === null) {
            return ['status' => 'error', 'message' => 'Шаблон отпускных цен не найден'];
        }

        $items[$targetIndex]['name'] = $name;
        Option::set(self::MODULE_ID, self::OPTION_NAME, json_encode($items, JSON_UNESCAPED_UNICODE));
        return ['status' => 'ok', 'preset' => $items[$targetIndex], 'presets' => $items];
    }

    public function delete(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            return ['status' => 'error', 'message' => 'Не указан шаблон отпускных цен'];
        }

        $items = $this->list();
        $remaining = array_values(array_filter($items, static fn(array $item): bool => $item['id'] !== $id));
        if (count($remaining) === count($items)) {
            return ['status' => 'error', 'message' => 'Шаблон отпускных цен не найден'];
        }
        Option::set(self::MODULE_ID, self::OPTION_NAME, json_encode($remaining, JSON_UNESCAPED_UNICODE));
        return ['status' => 'ok', 'deletedId' => $id, 'presets' => $remaining];
    }

    private function normalize($preset): ?array
    {
        if (!is_array($preset)) {
            return null;
        }
        $name = trim((string)($preset['name'] ?? ''));
        $mode = (string)($preset['mode'] ?? 'markup');
        if ($name === '' || !in_array($mode, ['markup', 'margin'], true)) {
            return null;
        }

        $prices = [];
        foreach ((array)($preset['prices'] ?? []) as $range) {
            if (!is_array($range)) continue;
            $currency = (string)($range['currency'] ?? 'PRC');
            if (!in_array($currency, ['RUB', 'PRC', 'MRG'], true)) continue;
            $price = (float)($range['price'] ?? 0);
            if ($price < 0 || ($currency === 'MRG' && $price >= 100)) continue;
            $limitRub = isset($range['limitRub']) ? max(0.0, (float)$range['limitRub']) : 0.0;
            $prices[] = [
                'typeId' => (int)($range['typeId'] ?? 0),
                'price' => $price,
                'currency' => $currency,
                'quantityFrom' => isset($range['quantityFrom']) ? (int)$range['quantityFrom'] : null,
                'quantityTo' => isset($range['quantityTo']) ? (int)$range['quantityTo'] : null,
                'limitRub' => $limitRub > 0 ? $limitRub : null,
            ];
        }
        if (!$prices) return null;

        return [
            'id' => trim((string)($preset['id'] ?? '')) ?: 'price_' . bin2hex(random_bytes(8)),
            'name' => $name,
            'mode' => $mode,
            'prices' => $prices,
        ];
    }
}
