<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__ . '/DocumentRepository.php';

/** Read-only version-scoped adapter for the existing AI audit service.
 * Authentication and CSRF belong to tools/documents.php; the gateway receives
 * only the explicit draft metadata, never document/site/Bitrix identities. */
final class DocumentLogicAudit
{
    private DocumentRepository $repository;
    private $gateway;
    public function __construct(DocumentRepository $repository, callable $gateway)
    {
        $this->repository = $repository; $this->gateway = $gateway;
    }
    public function command(array $request): array
    {
        $keys = array_keys($request); sort($keys);
        if ($keys !== ['action', 'contextTitle', 'expectedRevision', 'id', 'intent', 'items', 'versionId'] || $request['action'] !== 'auditVersionLogic') {
            throw new \InvalidArgumentException('Invalid audit command fields.');
        }
        foreach (['id', 'versionId', 'intent', 'contextTitle'] as $key) if (!is_string($request[$key])) throw new \InvalidArgumentException('Invalid audit text.');
        if (!is_int($request['expectedRevision']) || $request['expectedRevision'] < 1 || !is_array($request['items']) || !$request['items'] || count($request['items']) > 300
            || strlen($request['intent']) > 48000 || strlen($request['contextTitle']) > 2000) throw new \InvalidArgumentException('Invalid audit size or revision.');
        $id = $request['id']; $version = $request['versionId']; $expected = $request['expectedRevision'];
        $source = $this->repository->versions()->load($id, $version);
        if ($source['revision'] !== $expected || $source['archived'] || $source['versionArchived']) throw new DocumentConflict('Версия изменилась или находится в архиве.');
        $itemsJson = json_encode($request['items'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($itemsJson) > 2000000) throw new \InvalidArgumentException('Audit metadata is too large.');
        // The server provides the fingerprint, including on HTTP stage where
        // browser crypto.subtle is unavailable. It is bound to this exact draft.
        $fingerprint = 'sha256:' . hash('sha256', $itemsJson);
        $response = ($this->gateway)([
            'schema' => 'prospektweb.calc.ai-logic-audit-request/v1', 'baseFingerprint' => $fingerprint,
            'intent' => $request['intent'], 'contextTitle' => $request['contextTitle'],
            'items' => json_decode($itemsJson, true, 64, JSON_THROW_ON_ERROR),
        ]);
        $current = $this->repository->versions()->load($id, $version);
        if ($current['revision'] !== $expected || $current['archived'] || $current['versionArchived']) throw new DocumentConflict('Версия изменилась во время AI-анализа. Повторите анализ.');
        if (($response['status'] ?? '') !== 'ok' || !is_array($response['proposal'] ?? null)
            || ($response['proposal']['baseFingerprint'] ?? '') !== $fingerprint) throw new \RuntimeException('Invalid AI audit response.', 503);
        return ['baseFingerprint' => $fingerprint, 'proposal' => $response['proposal'], 'usage' => $response['usage'] ?? []];
    }
}
