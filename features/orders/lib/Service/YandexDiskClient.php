<?php
namespace Prospektweb\LayoutFiles\Service;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Prospektweb\LayoutFiles\Config;
use Prospektweb\LayoutFiles\Logger;

class YandexDiskClient
{
    private const API = 'https://cloud-api.yandex.net/v1/disk/resources';
    private const DISK_API = 'https://cloud-api.yandex.net/v1/disk/';
    private const TOKEN_URL = 'https://oauth.yandex.ru/token';

    private $token;
    private $refreshTried = false;

    public function __construct(?string $token = null)
    {
        $this->token = $token ?? Config::getToken();
    }

    public function getUploadHref(string $path, bool $overwrite = true): string
    {
        $response = $this->request('GET', self::API . '/upload', ['path' => $path, 'overwrite' => $overwrite ? 'true' : 'false']);
        if (empty($response['href'])) {
            throw new \RuntimeException('Яндекс.Диск не вернул ссылку загрузки.');
        }
        return (string)$response['href'];
    }

    public function getDownloadHref(string $path): string
    {
        $response = $this->request('GET', self::API . '/download', ['path' => $path]);
        if (empty($response['href'])) {
            throw new \RuntimeException('Яндекс.Диск не вернул ссылку скачивания.');
        }
        return (string)$response['href'];
    }

    public function getResource(string $path): array
    {
        return $this->request('GET', self::API, ['path' => $path]);
    }

    public function move(string $from, string $to, bool $overwrite = true): void
    {
        $this->request('POST', self::API . '/move', ['from' => $from, 'path' => $to, 'overwrite' => $overwrite ? 'true' : 'false']);
    }

    public function delete(string $path): void
    {
        try {
            $this->request('DELETE', self::API, ['path' => $path, 'permanently' => 'true']);
        } catch (\Throwable $e) {
            Logger::error('yadisk.delete', $e, ['path' => $path]);
        }
    }

    public function ensureDirectory(string $path): void
    {
        $parts = array_filter(explode('/', trim($path, '/')));
        $current = '';
        foreach ($parts as $part) {
            $current .= '/' . $part;
            try {
                $this->request('PUT', self::API, ['path' => $current]);
            } catch (\Throwable $e) {
                // Yandex.Disk returns an error for an already existing folder; this is expected.
            }
        }
    }

    public function getDiskInfo(): array
    {
        return $this->request('GET', self::DISK_API);
    }

    public function checkConnection(): array
    {
        $info = $this->getDiskInfo();
        $this->getResource(Config::getBaseFolder());
        return $info;
    }

    private function request(string $method, string $url, array $query = []): array
    {
        if ($this->token === '') {
            $this->refreshAccessToken();
        }

        $client = new HttpClient(['socketTimeout' => 20, 'streamTimeout' => 60]);
        $client->setHeader('Authorization', 'OAuth ' . $this->token);
        $fullUrl = $url . ($query ? '?' . http_build_query($query) : '');
        $body = $client->query($method, $fullUrl) ? $client->getResult() : '';
        $status = (int)$client->getStatus();

        if ($status === 401 && !$this->refreshTried) {
            $this->refreshAccessToken();
            return $this->request($method, $url, $query);
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Ошибка Яндекс.Диска: HTTP ' . $status . ' ' . $body);
        }

        return $body !== '' ? Json::decode($body) : [];
    }

    private function refreshAccessToken(): void
    {
        $this->refreshTried = true;
        $refreshToken = Config::getRefreshToken();
        $clientId = Config::getClientId();
        $clientSecret = Config::getClientSecret();

        if ($refreshToken === '' || $clientId === '' || $clientSecret === '') {
            throw new \RuntimeException('Подключение к Яндекс.Диску устарело. Повторно подключите Яндекс.Диск в настройках модуля.');
        }

        $client = new HttpClient(['socketTimeout' => 20, 'streamTimeout' => 60]);
        $client->setHeader('Content-Type', 'application/x-www-form-urlencoded');
        $body = $client->post(self::TOKEN_URL, http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]));

        $status = (int)$client->getStatus();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Подключение к Яндекс.Диску устарело. Повторно подключите Яндекс.Диск в настройках модуля.');
        }

        $data = $body !== '' ? Json::decode($body) : [];
        if (empty($data['access_token'])) {
            throw new \RuntimeException('Подключение к Яндекс.Диску устарело. Повторно подключите Яндекс.Диск в настройках модуля.');
        }

        $this->token = (string)$data['access_token'];
        Config::setToken($this->token);
        if (!empty($data['refresh_token'])) {
            Config::setRefreshToken((string)$data['refresh_token']);
        }
    }
}
