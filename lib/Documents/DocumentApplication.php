<?php
declare(strict_types=1);

namespace Prospektweb\Calc\Documents;

require_once __DIR__ . '/DocumentRepository.php';

/** CMS-independent use cases. Identity/authorization are owned by the outer adapter.
 * Only compile/publish/preview load external resources; list/load/save never do. */
final class DocumentApplication
{
    private DocumentRepository $repository;
    private $core;
    private $resources;

    public function __construct(DocumentRepository $repository, callable $core, callable $resources)
    {
        $this->repository = $repository; $this->core = $core; $this->resources = $resources;
    }

    public function command(array $request): array
    {
        $action = $request['action'] ?? null;
        $fields = [
            'list' => ['limit', 'offset', 'archived'], 'load' => ['id', 'revision'],
            'history' => ['id', 'limit', 'beforeRevision'], 'create' => ['documentJson'],
            'save' => ['id', 'expectedRevision', 'documentJson'],
            'restore' => ['id', 'expectedRevision', 'revision'],
            'archive' => ['id', 'expectedRevision', 'archived'],
            'publish' => ['id', 'expectedRevision', 'expectedPublication'],
            'preview' => ['id', 'revision', 'values', 'execution', 'name'],
        ];
        if (!is_string($action) || !isset($fields[$action]) || array_diff(array_keys($request), array_merge(['action'], $fields[$action]))) {
            throw new \InvalidArgumentException('Unknown document command or field.');
        }
        if ($action === 'list') {
            return ['items' => $this->repository->listing(self::integer($request, 'limit', 50), self::integer($request, 'offset', 0), self::boolean($request, 'archived', false))];
        }
        if ($action === 'create') {
            return $this->repository->create($this->validate(self::text($request, 'documentJson')));
        }
        $id = self::text($request, 'id');
        if ($action === 'load') { return $this->repository->load($id, isset($request['revision']) ? self::integer($request, 'revision') : null); }
        if ($action === 'history') { return ['items' => $this->repository->history($id, self::integer($request, 'limit', 50), self::integer($request, 'beforeRevision', 2147483647))]; }
        if ($action === 'preview') {
            $revision = $this->repository->load($id, self::integer($request, 'revision'));
            $document = json_decode($revision['bodyJson'], false, 64, JSON_THROW_ON_ERROR);
            return ($this->core)(['action' => 'preview', 'document' => $document, 'resources' => ($this->resources)($document),
                'values' => $request['values'] ?? new \stdClass(), 'execution' => $request['execution'] ?? null, 'name' => $request['name'] ?? $document->name]);
        }
        $expected = self::integer($request, 'expectedRevision');
        if ($action === 'archive') { return $this->repository->archive($id, $expected, self::boolean($request, 'archived')); }
        if ($action === 'save' || $action === 'restore') {
            $json = $action === 'restore' ? $this->repository->load($id, self::integer($request, 'revision'))['bodyJson'] : self::text($request, 'documentJson');
            return $this->repository->save($id, $expected, $this->validate($json));
        }
        if (!array_key_exists('expectedPublication', $request) || ($request['expectedPublication'] !== null && !is_string($request['expectedPublication']))) {
            throw new \InvalidArgumentException('Expected publication pointer is required.');
        }
        $source = $this->repository->load($id);
        if ($source['revision'] !== $expected || $source['activePublication'] !== $request['expectedPublication']) { throw new DocumentConflict(); }
        $document = json_decode($source['bodyJson'], false, 64, JSON_THROW_ON_ERROR);
        $compiled = ($this->core)(['action' => 'compile', 'document' => $document, 'resources' => ($this->resources)($document)]);
        // Compilation/network happens outside a SQL transaction. Repository rechecks both pointers under a row lock.
        return $this->repository->publish($id, $expected, $request['expectedPublication'], $compiled['engineVersion'], $compiled['snapshotJson']);
    }

    private function validate(string $json): string
    {
        if (strlen($json) > 8000000) { throw new \InvalidArgumentException('Document exceeds byte limit.'); }
        $document = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        if (!$document instanceof \stdClass) { throw new \InvalidArgumentException('Expected a JSON document.'); }
        $result = ($this->core)(['action' => 'validate', 'document' => $document]);
        if (!is_string($result['documentJson'] ?? null) || !hash_equals(hash('sha256', $result['documentJson']), $result['documentHash'] ?? '')) {
            throw new \RuntimeException('Core canonical document integrity failed.');
        }
        return $result['documentJson'];
    }
    private static function text(array $r, string $key): string
    {
        if (!is_string($r[$key] ?? null) || $r[$key] === '') { throw new \InvalidArgumentException('Expected string: ' . $key); }
        return $r[$key];
    }
    private static function integer(array $r, string $key, ?int $default = null): int
    {
        $value = $r[$key] ?? $default;
        if (!is_int($value)) { throw new \InvalidArgumentException('Expected integer: ' . $key); }
        return $value;
    }
    private static function boolean(array $r, string $key, ?bool $default = null): bool
    {
        $value = $r[$key] ?? $default;
        if (!is_bool($value)) { throw new \InvalidArgumentException('Expected boolean: ' . $key); }
        return $value;
    }
}
