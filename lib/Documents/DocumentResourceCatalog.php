<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__ . '/DocumentRepository.php';

/** Read-only, revision-bound resource browser. Site and provider authority stay on the server. */
final class DocumentResourceCatalog
{
    private DocumentRepository $repository;
    private $browse;
    public function __construct(DocumentRepository $repository, callable $browse)
    { $this->repository = $repository; $this->browse = $browse; }

    public function command(array $request): array
    {
        $keys = array_keys($request); sort($keys);
        if (($request['action'] ?? '') !== 'resourceCatalogVersion' || $keys !== ['action', 'expectedRevision', 'id', 'versionId']) throw new \InvalidArgumentException('Invalid resource catalog command fields.');
        foreach (['id', 'versionId'] as $key) if (!is_string($request[$key]) || $request[$key] === '' || strlen($request[$key]) > 128) throw new \InvalidArgumentException('Invalid resource catalog identity.');
        if (!is_int($request['expectedRevision']) || $request['expectedRevision'] < 1) throw new \InvalidArgumentException('Invalid resource catalog revision.');
        $read = fn(): array => $this->repository->versions()->load($request['id'], $request['versionId']);
        $assertCurrent = static function (array $source) use ($request): void {
            if ($source['revision'] !== $request['expectedRevision']) throw new DocumentConflict('Версия изменилась. Откройте выбор ресурса заново.');
        };
        $assertCurrent($read());
        $catalog = ($this->browse)();
        $assertCurrent($read());
        return ['contract' => 'prospektweb.calculator/resource-catalog-v1', 'documentId' => $request['id'],
            'versionId' => $request['versionId'], 'revision' => $request['expectedRevision'], 'catalog' => $catalog];
    }
}
