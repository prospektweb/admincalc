<?php

namespace Prospektweb\Frontcalc\Service;

final class ServerQuoteCalculator
{
    /** @var callable|null */
    private $priceRounder;

    public function __construct(?callable $priceRounder = null)
    {
        $this->priceRounder = $priceRounder;
    }

    public function calculate(array $offers, array $selectedValues, int $targetQuantity, int $catalogGroupId, string $deadlineType, array $config): array
    {
        if ($targetQuantity <= 0 || $catalogGroupId <= 0 || !in_array($deadlineType, ['strict','urgent','flexible'], true)) {
            return ['success' => false, 'code' => 'FRONTCALC_QUOTE_NOT_FOUND'];
        }
        $validation = $this->validateSelection($selectedValues, $config);
        if ($validation !== true) { return ['success' => false, 'code' => 'FRONTCALC_QUOTE_SELECTION_INVALID']; }
        $points = [];
        foreach ($offers as $index => $offer) {
            if (!is_array($offer) || !$this->matches($offer, $selectedValues)) { continue; }
            // Catalog quantity ranges describe how many print runs/SKUs are in
            // the basket, not the circulation stored inside this SKU.
            $range = $this->findRange(is_array($offer['pricing']['ranges'] ?? null) ? $offer['pricing']['ranges'] : [], 1, $catalogGroupId);
            if ($range === null) { continue; }
            $quantity = (int)($offer['quantity'] ?? 0);
            if ($quantity <= 0) { continue; }
            $points[] = ['quantity'=>$quantity,'price'=>(float)$range['price'],'currency'=>strtoupper(trim((string)($range['currency'] ?? ''))),'offerKey'=>(string)($offer['offerKey'] ?? ''),'priority'=>$this->priority($offer),'index'=>$index,'offer'=>$offer];
        }
        if (empty($points)) { return ['success'=>false,'code'=>'FRONTCALC_QUOTE_NOT_FOUND']; }
        usort($points, static fn($a,$b) => [$a['quantity'],$a['priority'],$a['index']] <=> [$b['quantity'],$b['priority'],$b['index']]);
        $uniq = [];
        foreach ($points as $p) { if (!isset($uniq[$p['quantity']])) { $uniq[$p['quantity']] = $p; } }
        $points = array_values($uniq);
        $pair = $this->selectPair($points, $targetQuantity);
        if ($pair === null) { return ['success'=>false,'code'=>'FRONTCALC_QUOTE_NOT_FOUND']; }
        [$left, $right] = $pair;
        if ($left['currency'] !== $right['currency']) { return ['success'=>false,'code'=>'FRONTCALC_QUOTE_CURRENCY_MISMATCH']; }
        $price = $left['quantity'] === $right['quantity'] ? $left['price'] : $left['price'] + ($targetQuantity - $left['quantity']) * ($right['price'] - $left['price']) / ($right['quantity'] - $left['quantity']);
        $price = $this->roundPrice($catalogGroupId, $this->applyDeadline(max(0.0, $price), $deadlineType, $targetQuantity, $config), $left['currency']);
        $mode = $this->mode($points, $left, $right, $targetQuantity);
        return ['success'=>true,'price'=>max(0.0, $price),'currency'=>$left['currency'],'name'=>$this->resolveName($left, $right, $targetQuantity),'catalogGroupId'=>$catalogGroupId,'quantity'=>$targetQuantity,'deadlineType'=>$deadlineType,'mode'=>$mode,'sourceOfferKeys'=>array_values(array_unique([$left['offerKey'],$right['offerKey']])),'normalizedSelectedValues'=>$this->normalizedSelectedValues([$left['offer'],$right['offer']], $selectedValues)];
    }

    private function validateSelection(array $selected, array $config)
    {
        $required = is_array($config['requiredPropertyCodes'] ?? null) ? $config['requiredPropertyCodes'] : [];
        foreach ($selected as $code => $row) {
            $code = (string)$code;
            if (strpos($code, 'CALC_PROP_') === 0 && $code !== 'CALC_PROP_VOLUME' && !in_array($code, $required, true)) { return false; }
            if (!is_array($row)) { return false; }
            foreach (['value','xmlId','xml_id'] as $k) {
                if (array_key_exists($k, $row) && !(is_string($row[$k]) || is_int($row[$k]) || is_float($row[$k]) || $row[$k] === null)) { return false; }
            }
        }
        foreach ($required as $code) {
            $code = (string)$code;
            if ($code === 'CALC_PROP_VOLUME') { continue; }
            if (!array_key_exists($code, $selected) || !is_array($selected[$code])) { return false; }
            if ($this->token($selected[$code]) === '') { return false; }
        }
        return true;
    }

    private function matches(array $offer, array $selected): bool
    {
        $props = is_array($offer['properties'] ?? null) ? $offer['properties'] : [];
        foreach ($selected as $code => $value) {
            $code = (string)$code;
            if (strpos($code, 'CALC_PROP_') !== 0 || $code === 'CALC_PROP_VOLUME') { continue; }
            if (!array_key_exists($code, $props) || !is_array($value)) { return false; }
            $wanted = $this->token($value);
            $actual = $this->token(is_array($props[$code]) ? $props[$code] : ['value'=>$props[$code]]);
            if ($wanted === '' || $actual === '' || $wanted !== $actual) { return false; }
        }
        return true;
    }

    private function token(array $row): string
    {
        $raw = $row['xmlId'] ?? $row['xml_id'] ?? $row['VALUE_XML_ID'] ?? '';
        if (!(is_string($raw) || is_int($raw) || is_float($raw) || $raw === null)) { return ''; }
        $v = (string)$raw;
        if (trim($v) === '') {
            $raw = $row['value'] ?? $row['VALUE'] ?? '';
            if (!(is_string($raw) || is_int($raw) || is_float($raw) || $raw === null)) { return ''; }
            $v = (string)$raw;
        }
        $v = trim($v);
        if (is_numeric(str_replace(' ', '', $v))) { $v = rtrim(rtrim(sprintf('%.10F', (float)str_replace(' ', '', $v)), '0'), '.'); }
        return $v;
    }

    private function findRange(array $ranges, int $qty, int $group): ?array
    {
        foreach ($ranges as $r) {
            if ((int)($r['typeId'] ?? $r['catalog_group_id'] ?? 0) !== $group) { continue; }
            $from = array_key_exists('quantityFrom', $r) ? $r['quantityFrom'] : ($r['quantity_from'] ?? null);
            $to = array_key_exists('quantityTo', $r) ? $r['quantityTo'] : ($r['quantity_to'] ?? null);
            if (($from === null || $qty >= (int)$from) && ($to === null || $qty <= (int)$to)) { return ['price'=>(float)($r['price'] ?? 0),'currency'=>(string)($r['currency'] ?? '')]; }
        }
        return null;
    }
    private function priority(array $offer): int { return ((string)($offer['source'] ?? '') === 'bitrix' && empty($offer['isVirtual'])) ? 0 : ((string)($offer['source'] ?? '') === 'calc-server' ? 1 : 2); }
    private function selectPair(array $p, int $q): ?array
    {
        $n = count($p); if ($n === 0) return null; if ($n === 1) return [$p[0],$p[0]];
        foreach ($p as $point) { if ($point['quantity'] === $q) return [$point,$point]; }
        for ($i=0;$i<$n-1;$i++) { if ($p[$i]['quantity'] < $q && $q < $p[$i+1]['quantity']) return [$p[$i],$p[$i+1]]; }
        return $q < $p[0]['quantity'] ? [$p[0],$p[1]] : [$p[$n-2],$p[$n-1]];
    }
    private function mode(array $points, array $left, array $right, int $q): string
    {
        if ($left['quantity'] === $right['quantity'] && $left['quantity'] === $q) { return 'exact'; }
        if (count($points) === 1) { return 'single'; }
        if ($q > min($left['quantity'], $right['quantity']) && $q < max($left['quantity'], $right['quantity'])) { return 'interpolated'; }
        return 'extrapolated';
    }

    private function normalizedSelectedValues(array $offers, array $selected): array
    {
        $out = [];
        foreach ($selected as $code => $_) {
            $code = (string)$code;
            if (strpos($code, 'CALC_PROP_') !== 0 || $code === 'CALC_PROP_VOLUME') { continue; }
            foreach ($offers as $offer) {
                $props = is_array($offer['properties'] ?? null) ? $offer['properties'] : [];
                if (!array_key_exists($code, $props)) { continue; }
                $row = is_array($props[$code]) ? $props[$code] : ['value' => $props[$code]];
                $out[$code] = ['value'=>(string)($row['value'] ?? $row['VALUE'] ?? ''),'xmlId'=>(string)($row['xml_id'] ?? $row['xmlId'] ?? $row['VALUE_XML_ID'] ?? '')];
                break;
            }
        }
        return $out;
    }

    private function applyDeadline(float $price, string $type, int $qty, array $config): float
    {
        if ($type === 'strict') return $price;
        $d = is_array($config['deadline_adjustments'] ?? null) ? $config['deadline_adjustments'] : [];
        $percent = 0.0;
        if (($d['mode'] ?? 'simple') === 'advanced') {
            $rows = $type === 'urgent' ? ($d['advanced']['urgent_markup'] ?? []) : ($d['advanced']['flexible_discount'] ?? []);
            foreach (is_array($rows) ? $rows : [] as $row) { if (is_array($row) && $qty >= (int)($row['volume'] ?? 0)) $percent = (float)($row['percent'] ?? 0); }
        } else { $percent = (float)($type === 'urgent' ? ($d['urgent_markup'] ?? $d['urgent_percent'] ?? 0) : ($d['flexible_discount'] ?? $d['flexible_percent'] ?? 0)); }
        return max(0.0, $type === 'urgent' ? $price * (1 + $percent / 100) : $price * (1 - $percent / 100));
    }

    private function roundPrice(int $catalogGroupId, float $price, string $currency): float
    {
        if ($this->priceRounder !== null) {
            return (float)call_user_func($this->priceRounder, $catalogGroupId, $price, $currency);
        }
        if (class_exists('\\Bitrix\\Catalog\\Product\\Price')) {
            return (float)\Bitrix\Catalog\Product\Price::roundPrice($catalogGroupId, $price, $currency);
        }
        return round($price, 2);
    }

    private function resolveName(array $left, array $right, int $targetQuantity): string
    {
        $preferred = abs((int)$left['quantity'] - $targetQuantity) <= abs((int)$right['quantity'] - $targetQuantity) ? $left : $right;
        $name = trim((string)($preferred['offer']['name'] ?? ''));
        if ($name !== '') { return $name; }
        return trim((string)($left['offer']['name'] ?? $right['offer']['name'] ?? ''));
    }
}
