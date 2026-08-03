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
            $prices[] = [
                'typeId' => (int)($range['typeId'] ?? 0),
                'price' => $price,
                'currency' => $currency,
                'quantityFrom' => isset($range['quantityFrom']) ? (int)$range['quantityFrom'] : null,
                'quantityTo' => isset($range['quantityTo']) ? (int)$range['quantityTo'] : null,
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
