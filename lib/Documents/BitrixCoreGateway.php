<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

require_once dirname(__DIR__) . '/Services/CalcServerRequestSigner.php';
require_once __DIR__ . '/CoreExecutionFailure.php';

/** Authenticated transport. The request can never choose a URL, credential or scope. */
final class BitrixCoreGateway
{
    public function __invoke(array $command): array
    {
        $path = '/v1/calculators/commands';
        $base = rtrim((string)(new \Prospektweb\Calc\Config\ConfigManager())->getOption('CALC_SERVER_URL', ''), '/');
        if (str_ends_with($base, '/calculate')) { $base = substr($base, 0, -10); }
        $parts = parse_url($base);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \RuntimeException('Invalid configured calculation server URL.');
        }
        $client = trim((string)(getenv('PROSPEKTWEB_CALC_SERVER_CLIENT_ID') ?: getenv('PROSPEKTWEB_FRONTCALC_CLIENT_ID') ?: 'prospektprint-production'));
        $secret = trim((string)(getenv('PROSPEKTWEB_CALC_SERVER_SHARED_SECRET') ?: getenv('PROSPEKTWEB_FRONTCALC_SHARED_SECRET') ?: ''));
        if ($secret === '') {
            $file = trim((string)(getenv('PROSPEKTWEB_CALC_SERVER_SECRET_FILE') ?: getenv('PROSPEKTWEB_FRONTCALC_SECRET_FILE') ?: dirname(rtrim($_SERVER['DOCUMENT_ROOT'], '/')) . '/.frontcalc-secret'));
            if (!is_file($file) || !is_readable($file)) { throw new \RuntimeException('Calculation server authentication unavailable.'); }
            $secret = trim((string)file_get_contents($file));
        }
        $signer = new \Prospektweb\Calc\Services\CalcServerRequestSigner($client, $secret); unset($secret);
        $body = json_encode(['contract' => 'prospektweb.calculator/commands-v1'] + $command, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($body) > 8000000) { throw new \InvalidArgumentException('Core command exceeds byte limit.'); }
        $curl = curl_init($base . $path);
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $signer->headers($body, 'POST', $path)),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $response = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        if ($response === false) { throw new \RuntimeException('Calculation core is temporarily unavailable.', 503); }
        $decoded = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
        if ($status !== 200 || ($decoded['success'] ?? false) !== true) {
            if ($status === 422 && ($command['action'] ?? '') === 'preview' && ($command['includeReport'] ?? false) === true
                && isset($decoded['error']['failure'])) {
                if (($decoded['success'] ?? null) !== false || ($decoded['error']['code'] ?? '') !== 'CALCULATOR_EXECUTION_INVALID'
                    || !is_array($decoded['error']['failure']) || array_key_exists('data', $decoded)) {
                    throw new \RuntimeException('Invalid core failure response.', 503);
                }
                throw new CoreExecutionFailure($decoded['error']['failure']);
            }
            if ($status === 422) { throw new \InvalidArgumentException((string)($decoded['error']['message'] ?? 'Invalid calculator document.'), 422); }
            $code = $decoded['error']['code'] ?? '';
            $code = is_string($code) && preg_match('/^[A-Z_]{1,64}$/D', $code) ? $code : 'UNAVAILABLE';
            error_log('[prospektweb.core] status=' . $status . ' code=' . $code);
            throw new \RuntimeException('Calculation core rejected the command.', 503);
        }
        if (($decoded['contract'] ?? '') !== 'prospektweb.calculator/commands-v1' || !is_array($decoded['data'] ?? null)) { throw new \RuntimeException('Invalid core response.'); }
        return $decoded['data'];
    }
}
