<?php

namespace Prospektweb\Frontcalc\Service;

class CalcServerClient
{
    public function calculate(string $baseUrl, int $timeout, array $initPayload): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        if ($baseUrl === '') return $this->errorResult('CALC_SERVER_URL_EMPTY', 'CALC_SERVER_URL is empty');
        if (!function_exists('curl_init')) return $this->errorResult('CURL_UNAVAILABLE', 'PHP curl extension is not available');
        $url = preg_match('#/calculate$#', $baseUrl) ? $baseUrl : $baseUrl . '/calculate';
        $requestBody = json_encode(['requestType' => 'frontcalc', 'initPayload' => $initPayload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($requestBody === false) return $this->errorResult('PAYLOAD_ENCODE_ERROR', 'Не удалось сериализовать payload calc-server');

        $startedAt = microtime(true);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(1, $timeout));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, max(1, $timeout)));
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $durationMs = (int)round((microtime(true) - $startedAt) * 1000);

        if ($error !== '') return $this->errorResult('HTTP_CLIENT_ERROR', 'Сервер калькуляций временно недоступен', $httpCode, $durationMs, ['technical_message' => $error]);
        $decoded = json_decode((string)$response, true);
        if ($httpCode !== 200 || !is_array($decoded)) return $this->errorResult('INVALID_RESPONSE', 'calc-server returned invalid response', $httpCode, $durationMs);
        $data = $decoded;
        if (array_key_exists('success', $decoded)) {
            if (($decoded['success'] ?? false) !== true) return $this->errorResult('CALC_SERVER_ERROR', $this->extractErrorMessage($decoded), $httpCode, $durationMs);
            if (!is_array($decoded['data'] ?? null) || !$this->isList($decoded['data'])) return $this->errorResult('INVALID_RESPONSE', 'calc-server returned invalid response', $httpCode, $durationMs);
            $data = $decoded['data'];
        } elseif (!$this->isList($decoded)) {
            return $this->errorResult('INVALID_RESPONSE', 'calc-server returned invalid response', $httpCode, $durationMs);
        }
        return ['success' => true, 'data' => $data, 'meta' => ['http_status' => $httpCode, 'duration_ms' => $durationMs], 'warnings' => []];
    }

    private function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_) if ($key !== $expected++) return false;
        return true;
    }

    private function extractErrorMessage(array $decoded): string
    {
        if (is_array($decoded['error'] ?? null)) return (string)($decoded['error']['message'] ?? 'calc-server error');
        return (string)($decoded['message'] ?? $decoded['error'] ?? 'calc-server error');
    }

    private function errorResult(string $code, string $message, int $httpStatus = 0, int $durationMs = 0, array $extra = []): array
    {
        return array_merge(['success' => false, 'data' => [], 'meta' => ['http_status' => $httpStatus, 'duration_ms' => $durationMs], 'warnings' => [], 'error' => ['code' => $code, 'message' => $message], 'message' => $message], $extra);
    }
}
