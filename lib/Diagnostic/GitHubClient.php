<?php

namespace Prospektweb\Calc\Diagnostic;

use Bitrix\Main\Config\Option;

/**
 * HTTP-клиент для GitHub API, совместимый с shared-хостингом.
 */
class GitHubClient
{
    const GITHUB_API_BASE = 'https://api.github.com/repos/prospektweb/appmarekttest';

    /** @var int Таймаут запросов в секундах */
    private int $timeout = 15;

    /** @var string|null Опциональный GitHub Token */
    private ?string $token;

    /** @var array Заголовки последнего ответа */
    private array $lastResponseHeaders = [];

    public function __construct()
    {
        $this->token = Option::get('prospektweb.calc', 'GITHUB_TOKEN', '') ?: null;
    }

    /**
     * Возвращает массив [path => sha] всех файлов репозитория из ветки main.
     *
     * @return array{files: array<string, string>, method: string, rate_limit_remaining: int|null, commit_sha: string}
     * @throws \RuntimeException
     */
    public function fetchRepositoryTree(): array
    {
        // Шаг 1: получаем SHA дерева из ветки main
        $branchData = $this->apiRequest('/branches/main');
        $treeSha = $branchData['commit']['commit']['tree']['sha'] ?? null;
        $commitSha = $branchData['commit']['sha'] ?? '';

        if (!$treeSha) {
            throw new \RuntimeException('Не удалось получить SHA дерева из ветки main');
        }

        // Шаг 2: получаем рекурсивное дерево файлов
        $treeData = $this->apiRequest('/git/trees/' . $treeSha . '?recursive=1');

        $files = [];
        foreach ($treeData['tree'] ?? [] as $item) {
            if ($item['type'] === 'blob') {
                $files[$item['path']] = $item['sha'];
            }
        }

        $rateLimitRemaining = $this->extractRateLimit();

        return [
            'files' => $files,
            'method' => $this->getPreferredMethod(),
            'rate_limit_remaining' => $rateLimitRemaining,
            'commit_sha' => $commitSha,
        ];
    }

    /**
     * Проверяет возможности сервера для выполнения HTTP-запросов.
     */
    public static function checkServerCapabilities(): array
    {
        $curlAvailable = function_exists('curl_init');
        $allowUrlFopen = (bool)ini_get('allow_url_fopen');
        $opensslAvailable = extension_loaded('openssl');
        $canMakeRequests = $curlAvailable || $allowUrlFopen;

        return [
            'curl_available' => $curlAvailable,
            'allow_url_fopen' => $allowUrlFopen,
            'openssl_available' => $opensslAvailable,
            'can_make_requests' => $canMakeRequests,
        ];
    }

    /**
     * Выполняет API-запрос: cURL → file_get_contents → исключение.
     */
    private function apiRequest(string $path): array
    {
        $url = self::GITHUB_API_BASE . $path;
        $this->lastResponseHeaders = [];

        if (function_exists('curl_init')) {
            return $this->requestViaCurl($url);
        }

        if (ini_get('allow_url_fopen')) {
            return $this->requestViaFileGetContents($url);
        }

        throw new \RuntimeException(
            'Нет доступных HTTP-методов. cURL не доступен, allow_url_fopen отключён. ' .
            'Обратитесь к хостинг-провайдеру для включения cURL или allow_url_fopen.'
        );
    }

    /**
     * Запрос через cURL.
     */
    private function requestViaCurl(string $url): array
    {
        $ch = curl_init();
        $responseHeaders = [];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => 'prospektweb-calc-diagnostic/1.0',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => $this->buildHeaders(),
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$responseHeaders) {
                $responseHeaders[] = trim($header);
                return strlen($header);
            },
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException($this->formatCurlError($errno, $error));
        }

        $this->lastResponseHeaders = $responseHeaders;

        return $this->parseResponse((string)$body, $httpCode, $responseHeaders);
    }

    /**
     * Запрос через file_get_contents.
     */
    private function requestViaFileGetContents(string $url): array
    {
        $contextOptions = [
            'http' => [
                'timeout' => $this->timeout,
                'user_agent' => 'prospektweb-calc-diagnostic/1.0',
                'method' => 'GET',
                'header' => implode("\r\n", $this->buildHeaders()),
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ];

        $context = stream_context_create($contextOptions);

        set_error_handler(function ($errno, $errstr) {
            throw new \RuntimeException('file_get_contents: ' . $errstr);
        });

        try {
            $body = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }

        if ($body === false) {
            throw new \RuntimeException('file_get_contents вернул false для URL: ' . $url);
        }

        // Получаем HTTP-код из $http_response_header
        $httpCode = 200;
        $rawHeaders = $http_response_header ?? [];
        if (!empty($rawHeaders[0]) && preg_match('/HTTP\/\d+\.?\d*\s+(\d+)/', $rawHeaders[0], $m)) {
            $httpCode = (int)$m[1];
        }

        $this->lastResponseHeaders = $rawHeaders;

        return $this->parseResponse($body, $httpCode, $rawHeaders);
    }

    /**
     * Разбирает тело ответа, проверяет HTTP-статус.
     */
    private function parseResponse(string $body, int $httpCode, array $headers): array
    {
        if ($httpCode === 403) {
            $rateLimitRemaining = $this->extractRateLimitFromHeaders($headers);
            $resetTime = $this->extractRateLimitResetFromHeaders($headers);

            $resetInfo = '';
            if ($resetTime > 0) {
                $resetDiff = $resetTime - time();
                $resetInfo = ' Сброс через ' . max(0, (int)($resetDiff / 60)) . ' мин.';
            }

            $hint = $rateLimitRemaining === 0
                ? ' Добавьте GitHub Token в настройках модуля (опция GITHUB_TOKEN) для увеличения лимита до 5000 запросов/час.'
                : '';

            throw new \RuntimeException(
                'GitHub API вернул 403 Forbidden.' . $resetInfo . $hint
            );
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException(
                'GitHub API вернул неожиданный HTTP-статус: ' . $httpCode
            );
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Не удалось разобрать JSON-ответ: ' . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Формирует список HTTP-заголовков для запроса.
     */
    private function buildHeaders(): array
    {
        $headers = [
            'Accept: application/vnd.github.v3+json',
        ];

        if ($this->token) {
            $headers[] = 'Authorization: token ' . $this->token;
        }

        return $headers;
    }

    /**
     * Определяет предпочтительный метод HTTP-запросов.
     */
    private function getPreferredMethod(): string
    {
        if (function_exists('curl_init')) {
            return 'curl';
        }
        if (ini_get('allow_url_fopen')) {
            return 'file_get_contents';
        }
        return 'none';
    }

    /**
     * Извлекает оставшийся rate-limit из последних заголовков ответа.
     */
    private function extractRateLimit(): ?int
    {
        return $this->extractRateLimitFromHeaders($this->lastResponseHeaders);
    }

    /**
     * Извлекает оставшийся rate-limit из заголовков.
     */
    private function extractRateLimitFromHeaders(array $headers): ?int
    {
        foreach ($headers as $header) {
            if (stripos($header, 'x-ratelimit-remaining:') === 0) {
                return (int)trim(substr($header, strlen('x-ratelimit-remaining:')));
            }
        }
        return null;
    }

    /**
     * Извлекает время сброса rate-limit из заголовков.
     */
    private function extractRateLimitResetFromHeaders(array $headers): int
    {
        foreach ($headers as $header) {
            if (stripos($header, 'x-ratelimit-reset:') === 0) {
                return (int)trim(substr($header, strlen('x-ratelimit-reset:')));
            }
        }
        return 0;
    }

    /**
     * Форматирует ошибку cURL с учётом типичных проблем shared-хостинга.
     */
    private function formatCurlError(int $errno, string $error): string
    {
        if (stripos($error, 'SSL certificate problem') !== false) {
            return 'cURL: Проблема с SSL-сертификатом. ' . $error .
                ' Проверьте актуальность CA-сертификатов на сервере.';
        }
        if (stripos($error, 'Could not resolve host') !== false || stripos($error, 'DNS') !== false) {
            return 'cURL: Не удалось разрешить DNS для api.github.com. ' .
                'Возможно, хостинг блокирует внешние DNS-запросы.';
        }
        if ($errno === CURLE_OPERATION_TIMEOUTED) {
            return 'cURL: Таймаут запроса (' . $this->timeout . ' сек). ' .
                'Сервер GitHub недоступен или заблокирован firewall хостинга.';
        }
        if (stripos($error, 'Connection refused') !== false || stripos($error, 'firewall') !== false) {
            return 'cURL: Соединение отклонено. Firewall хостинга может блокировать исходящие запросы к GitHub.';
        }

        return 'cURL ошибка #' . $errno . ': ' . $error;
    }
}
