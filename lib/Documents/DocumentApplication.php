<?php
declare(strict_types=1);

namespace Prospektweb\Calc\Documents;

require_once __DIR__ . '/DocumentRepository.php';

/** CMS-independent use cases. Identity/authorization are owned by the outer adapter.
 * Only explicit context/check/compile/publish/preview load external resources;
 * list/load/save never do. */
final class DocumentApplication
{
    private DocumentRepository $repository;
    private $core;
    private $resources;
    private $siteCompiler;
    private $inputMappingValidator;
    private $outputMappingValidator;

    public function __construct(DocumentRepository $repository, callable $core, callable $resources, ?callable $siteCompiler = null, ?callable $inputMappingValidator = null, ?callable $outputMappingValidator = null)
    {
        $this->repository = $repository; $this->core = $core; $this->resources = $resources;
        $this->siteCompiler = $siteCompiler;
        $this->inputMappingValidator = $inputMappingValidator;
        $this->outputMappingValidator = $outputMappingValidator;
    }

    public function command(array $request): array
    {
        $action = $request['action'] ?? null;
        $fields = [
            'registry' => ['query', 'status', 'sort', 'page', 'pageSize', 'sectionId'],
            'catalog' => [],
            'createSection' => ['expectedCatalogRevision', 'name', 'parentId'],
            'renameSection' => ['expectedCatalogRevision', 'id', 'name'],
            'deleteSection' => ['expectedCatalogRevision', 'id'],
            'moveToSection' => ['expectedCatalogRevision', 'id', 'sectionId'],
            'versions' => ['id'], 'loadVersion' => ['id', 'versionId'],
            'createVersion' => ['id', 'expectedVersionsRevision', 'name', 'creationMode', 'basedOnVersionId', 'expectedContentHash', 'documentJson'],
            'renameVersion' => ['id', 'versionId', 'expectedVersionsRevision', 'name'],
            'archiveVersion' => ['id', 'versionId', 'expectedVersionsRevision', 'archived'],
            'deleteVersion' => ['id', 'versionId', 'expectedVersionsRevision'],
            'saveVersion' => ['id', 'versionId', 'expectedRevision', 'documentJson', 'connectionJson'],
            'checkVersion' => ['id', 'versionId', 'expectedRevision', 'documentJson', 'connectionJson'],
            'checkInputMappings' => ['id', 'versionId', 'expectedRevision', 'documentJson', 'connectionJson'],
            'checkOutputMappings' => ['id', 'versionId', 'expectedRevision', 'documentJson', 'connectionJson'],
            'contextVersion' => ['id', 'versionId', 'expectedRevision', 'documentJson'],
            'saveVersionConnection' => ['id', 'versionId', 'expectedRevision', 'connectionJson'],
            'restoreVersionRevision' => ['id', 'versionId', 'expectedRevision', 'revision'],
            'activateVersion' => ['id', 'versionId', 'expectedRevision', 'expectedVersionsRevision', 'expectedSitePublication'],
            'previewVersion' => ['id', 'versionId', 'revision', 'values', 'execution', 'name'],
            'list' => ['limit', 'offset', 'archived'], 'load' => ['id', 'revision'],
            'history' => ['id', 'limit', 'beforeRevision'], 'create' => ['documentJson', 'sectionId', 'expectedCatalogRevision'],
            'save' => ['id', 'expectedRevision', 'documentJson'],
            'restore' => ['id', 'expectedRevision', 'revision'],
            'archive' => ['id', 'expectedRevision', 'archived'],
            'publish' => ['id', 'expectedRevision', 'expectedPublication'],
            'saveConnection' => ['id', 'expectedRevision', 'connectionJson'],
            'publishSite' => ['id', 'expectedRevision', 'expectedSitePublication'],
            'preview' => ['id', 'revision', 'values', 'execution', 'name'],
        ];
        if (!is_string($action) || !isset($fields[$action]) || array_diff(array_keys($request), array_merge(['action'], $fields[$action]))) {
            throw new \InvalidArgumentException('Unknown document command or field.');
        }
        if ($action === 'list') {
            return ['items' => $this->repository->listing(self::integer($request, 'limit', 50), self::integer($request, 'offset', 0), self::boolean($request, 'archived', false))];
        }
        if ($action === 'catalog') { return $this->repository->catalog(); }
        if (in_array($action, ['createSection', 'renameSection', 'deleteSection', 'moveToSection'], true)) {
            $values = [];
            foreach ($fields[$action] as $key) {
                if ($key === 'expectedCatalogRevision') { continue; }
                $values[$key] = in_array($key, ['parentId', 'sectionId'], true) ? self::nullableText($request, $key) : self::text($request, $key);
            }
            return $this->repository->changeCatalog($action, self::integer($request, 'expectedCatalogRevision'), $values);
        }
        if ($action === 'registry') {
            $query = $request['query'] ?? '';
            if (!is_string($query)) { throw new \InvalidArgumentException('Expected string: query'); }
            return $this->repository->registry($query, self::text($request + ['status' => 'all'], 'status'),
                self::text($request + ['sort' => 'updated_desc'], 'sort'), self::integer($request, 'page', 1), self::integer($request, 'pageSize', 30), self::nullableText($request + ['sectionId' => null], 'sectionId'));
        }
        if ($action === 'create') {
            return $this->repository->create($this->validate(self::text($request, 'documentJson')), self::nullableText($request + ['sectionId' => null], 'sectionId'),
                array_key_exists('expectedCatalogRevision', $request) ? self::integer($request, 'expectedCatalogRevision') : null);
        }
        $id = self::text($request, 'id');
        if ($action === 'versions') { return $this->repository->versions()->listing($id); }
        if ($action === 'loadVersion') { return $this->repository->versions()->load($id, self::text($request, 'versionId')); }
        if ($action === 'createVersion') {
            $mode = self::text($request, 'creationMode');
            if (!in_array($mode, ['blank', 'clone'], true)
                || ($mode === 'blank' && (array_key_exists('basedOnVersionId', $request) || array_key_exists('expectedContentHash', $request)))
                || ($mode === 'clone' && array_key_exists('documentJson', $request))) { throw new \InvalidArgumentException('Invalid version creation mode or fields.'); }
            return $this->repository->versions()->create($id, self::integer($request, 'expectedVersionsRevision'), self::text($request, 'name'),
                $mode === 'clone' ? self::text($request, 'basedOnVersionId') : null,
                $mode === 'clone' ? self::text($request, 'expectedContentHash') : null,
                $mode === 'blank' ? $this->validate(self::text($request, 'documentJson')) : null);
        }
        if (in_array($action, ['renameVersion', 'archiveVersion', 'deleteVersion'], true)) {
            return $this->repository->versions()->change($id, self::text($request, 'versionId'), self::integer($request, 'expectedVersionsRevision'), $action,
                $action === 'renameVersion' ? ['name' => self::text($request, 'name')] : ($action === 'archiveVersion' ? ['archived' => self::boolean($request, 'archived')] : []));
        }
        if ($action === 'restoreVersionRevision') {
            $source = $this->repository->load($id, self::integer($request, 'revision'));
            return $this->repository->versions()->save($id, self::text($request, 'versionId'), self::integer($request, 'expectedRevision'), $this->validate($source['bodyJson']), $source['connectionJson'], true);
        }
        if ($action === 'contextVersion') {
            $versionId = self::text($request, 'versionId'); $expected = self::integer($request, 'expectedRevision');
            $source = $this->repository->versions()->load($id, $versionId);
            if ($source['revision'] !== $expected) { throw new DocumentConflict(); }
            $body = $this->validate(self::text($request, 'documentJson'));
            $document = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
            if ($document->id !== $id) { throw new \InvalidArgumentException('Document identity mismatch.'); }
            // The provider enforces allowed catalogs/bindings and owns a read
            // snapshot. Never resolve a legacy graph or change site mappings.
            $resources = ($this->resources)($document);
            if ($this->repository->versions()->load($id, $versionId)['revision'] !== $expected) { throw new DocumentConflict(); }
            return ['contract' => 'prospektweb.calculator/resource-context-v1', 'documentId' => $id,
                'versionId' => $versionId, 'revision' => $expected, 'bodyHash' => hash('sha256', $body), 'resources' => $resources];
        }
        if (in_array($action, ['checkVersion', 'checkInputMappings', 'checkOutputMappings'], true)) {
            $versionId = self::text($request, 'versionId'); $expected = self::integer($request, 'expectedRevision');
            $source = $this->repository->versions()->load($id, $versionId);
            if ($source['revision'] !== $expected) { throw new DocumentConflict(); }
            $body = $this->validate(self::text($request, 'documentJson'));
            $document = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
            if ($document->id !== $id) { throw new \InvalidArgumentException('Document identity mismatch.'); }
            $connectionJson = array_key_exists('connectionJson', $request) ? self::text($request, 'connectionJson') : $source['connectionJson'];
            if ($connectionJson === null) { throw new \InvalidArgumentException('Настройте подключение сайта перед проверкой активации.'); }
            $connectionJson = SiteConnection::canonical($connectionJson, json_decode($body, true, 64, JSON_THROW_ON_ERROR));
            if ($action === 'checkInputMappings' || $action === 'checkOutputMappings') {
                $validator = $action === 'checkInputMappings' ? $this->inputMappingValidator : $this->outputMappingValidator;
                if (!is_callable($validator)) { throw new \RuntimeException('Mapping validator is unavailable.', 503); }
                $issues = $validator($document, json_decode($connectionJson, false, 64, JSON_THROW_ON_ERROR));
                if ($this->repository->versions()->load($id, $versionId)['revision'] !== $expected) { throw new DocumentConflict(); }
                return ['contract' => $action === 'checkInputMappings' ? 'prospektweb.calculator/input-mapping-check-v1' : 'prospektweb.calculator/output-mapping-check-v1', 'documentId' => $id,
                    'versionId' => $versionId, 'revision' => $expected, 'valid' => true, 'issues' => $issues,
                    'bodyHash' => hash('sha256', $body), 'connectionHash' => hash('sha256', $connectionJson)];
            }
            if (!is_callable($this->siteCompiler)) { throw new \RuntimeException('Site publication compiler is unavailable.', 503); }
            $runtime = ($this->siteCompiler)($document, json_decode($connectionJson, false, 64, JSON_THROW_ON_ERROR), $expected);
            ($this->core)(['action' => 'compile', 'document' => $document, 'resources' => ($this->resources)($document)]);
            if ($this->repository->versions()->load($id, $versionId)['revision'] !== $expected) { throw new DocumentConflict(); }
            return ['documentId' => $id, 'versionId' => $versionId, 'revision' => $expected, 'valid' => true,
                'bodyHash' => hash('sha256', $body), 'connectionHash' => hash('sha256', $connectionJson), 'runtime' => $runtime];
        }
        if ($action === 'saveVersion' || $action === 'saveVersionConnection') {
            $versionId = self::text($request, 'versionId'); $expected = self::integer($request, 'expectedRevision');
            if ($action === 'saveVersionConnection') {
                $source = $this->repository->versions()->load($id, $versionId);
                if ($source['revision'] !== $expected) { throw new DocumentConflict(); }
                return $this->repository->versions()->save($id, $versionId, $expected, $source['bodyJson'], self::text($request, 'connectionJson'), true);
            }
            // Form authoring can change field identities and their site mappings
            // together. Validate/store the pair under the same branch-head CAS;
            // never expose an intermediate body with the previous connections.
            $replaceConnection = array_key_exists('connectionJson', $request);
            $connection = $replaceConnection ? self::text($request, 'connectionJson') : null;
            return $this->repository->versions()->save($id, $versionId, $expected,
                $this->validate(self::text($request, 'documentJson')), $connection, $replaceConnection);
        }
        if ($action === 'load') { return $this->repository->load($id, isset($request['revision']) ? self::integer($request, 'revision') : null); }
        if ($action === 'history') { return ['items' => $this->repository->history($id, self::integer($request, 'limit', 50), self::integer($request, 'beforeRevision', 2147483647))]; }
        if ($action === 'preview' || $action === 'previewVersion') {
            $revision = $action === 'previewVersion' ? $this->repository->versions()->load($id, self::text($request, 'versionId')) : $this->repository->load($id, self::integer($request, 'revision'));
            if ($revision['revision'] !== self::integer($request, 'revision')) { throw new DocumentConflict(); }
            $document = json_decode($revision['bodyJson'], false, 64, JSON_THROW_ON_ERROR);
            return ($this->core)(['action' => 'preview', 'document' => $document, 'resources' => ($this->resources)($document),
                'values' => $request['values'] ?? new \stdClass(), 'execution' => $request['execution'] ?? null, 'name' => $request['name'] ?? $document->name]);
        }
        $expected = self::integer($request, 'expectedRevision');
        if ($action === 'saveConnection') {
            $source = $this->repository->load($id);
            if ($source['revision'] !== $expected) { throw new DocumentConflict(); }
            return $this->repository->save($id, $expected, $source['bodyJson'], self::text($request, 'connectionJson'), true);
        }
        if ($action === 'archive') { return $this->repository->archive($id, $expected, self::boolean($request, 'archived')); }
        if ($action === 'save' || $action === 'restore') {
            $historical = $action === 'restore' ? $this->repository->load($id, self::integer($request, 'revision')) : null;
            $json = $historical !== null ? $historical['bodyJson'] : self::text($request, 'documentJson');
            return $this->repository->save($id, $expected, $this->validate($json), $historical['connectionJson'] ?? null, $historical !== null);
        }
        if ($action === 'publishSite' || $action === 'activateVersion') {
            if (!is_callable($this->siteCompiler)) { throw new \RuntimeException('Site publication compiler is unavailable.', 503); }
            if (!array_key_exists('expectedSitePublication', $request) || ($request['expectedSitePublication'] !== null && !is_string($request['expectedSitePublication']))) {
                throw new \InvalidArgumentException('Expected site publication pointer is required.');
            }
            $versionId = $action === 'activateVersion' ? self::text($request, 'versionId') : null;
            $versionsRevision = $versionId === null ? null : self::integer($request, 'expectedVersionsRevision');
            $source = $versionId === null ? $this->repository->load($id) : $this->repository->versions()->load($id, $versionId);
            if ($versionsRevision !== null && $this->repository->versions()->listing($id)['registryRevision'] !== $versionsRevision) { throw new DocumentConflict('Список версий изменился.'); }
            if ($source['revision'] !== $expected || $source['activeSitePublication'] !== $request['expectedSitePublication']) { throw new DocumentConflict(); }
            if ($source['connectionJson'] === null) { throw new \InvalidArgumentException('Configure the site connection before publishing.'); }
            $document = json_decode($source['bodyJson'], false, 64, JSON_THROW_ON_ERROR);
            $connection = json_decode($source['connectionJson'], false, 64, JSON_THROW_ON_ERROR);
            // Old stored snapshots may predate the explicit output allowlist.
            DocumentOutputMappings::validate($connection->outputMappings ?? null);
            $runtime = ($this->siteCompiler)($document, $connection, $expected);
            $compiled = ($this->core)(['action' => 'compile', 'document' => $document, 'resources' => ($this->resources)($document)]);
            $snapshot = (object)['contract' => 'prospektweb.calculator/site-publication-v1', 'documentId' => $id, 'sourceRevision' => $expected,
                'documentHash' => $source['bodyHash'], 'connectionHash' => $source['connectionHash'], 'connection' => $connection,
                'core' => json_decode($compiled['snapshotJson'], false, 64, JSON_THROW_ON_ERROR), 'runtime' => $runtime];
            $published = $this->repository->publishSite($id, $expected, $request['expectedSitePublication'], SiteConnection::encode($snapshot), $versionId, $versionsRevision);
            return $versionId === null ? $published : $this->repository->versions()->listing($id);
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
    private static function nullableText(array $r, string $key): ?string
    {
        if (!array_key_exists($key, $r) || ($r[$key] !== null && !is_string($r[$key]))) { throw new \InvalidArgumentException('Expected string or null: ' . $key); }
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
