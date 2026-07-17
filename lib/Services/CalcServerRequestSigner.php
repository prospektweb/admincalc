<?php

namespace Prospektweb\Calc\Services;

class CalcServerRequestSigner
{
    private string $clientId;
    private string $secret;

    public function __construct(string $clientId, string $secret)
    {
        $this->clientId = trim($clientId);
        $this->secret = trim($secret);
        if ($this->clientId === '' || strlen($this->secret) < 32) {
            throw new \InvalidArgumentException('Calc-server request signing credentials are invalid.');
        }
    }

    public function headers(string $requestBody, string $method = 'POST', string $path = '/calculate', ?int $timestamp = null, ?string $nonce = null): array
    {
        $timestamp = $timestamp ?? time();
        $nonce = $nonce ?? $this->createNonce();
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce)) {
            throw new \InvalidArgumentException('Calc-server request nonce is invalid.');
        }

        $canonical = self::canonical(
            $this->clientId,
            (string)$timestamp,
            $nonce,
            $method,
            $path,
            hash('sha256', $requestBody)
        );

        return [
            'X-Frontcalc-Client: ' . $this->clientId,
            'X-Frontcalc-Timestamp: ' . $timestamp,
            'X-Frontcalc-Nonce: ' . $nonce,
            'X-Frontcalc-Signature: ' . hash_hmac('sha256', $canonical, $this->secret),
        ];
    }

    public static function canonical(string $clientId, string $timestamp, string $nonce, string $method, string $path, string $bodyHash): string
    {
        return implode("\n", [$clientId, $timestamp, $nonce, strtoupper($method), $path, $bodyHash]);
    }

    private function createNonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}
