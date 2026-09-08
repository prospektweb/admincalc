<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__ . '/DocumentRepository.php';
require_once __DIR__ . '/BitrixResourceCardSnapshot.php';
require_once __DIR__ . '/BitrixResourceCardWriter.php';
require_once __DIR__ . '/ResourceCardWriteVerification.php';

/** Admin-authenticated, version-bound card. HTTP owns authentication/CSRF; this
 * service owns a single transaction and the resource compare-and-swap. */
final class DocumentResourceCard
{
    private DocumentRepository $repository;
    private $capture;
    private $write;
    public function __construct(private SqlConnection $db, string $scope, string $actor, string $provider, ?callable $write = null)
    {
        if (!preg_match('/^site:[A-Za-z0-9]{1,2}$/D', $scope) || !preg_match('/^user:[1-9][0-9]{0,8}$/D', $actor)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $provider)) throw new \InvalidArgumentException('Trusted site, actor and provider required.');
        $this->repository = new DocumentRepository($db, $scope, $actor);
        $this->capture = [new BitrixResourceCardSnapshot($db, $provider), 'capture'];
        $this->write = $write ?? [new BitrixResourceCardWriter($db), 'write'];
    }
    public function command(array $request): array
    {
        $action = $request['action'] ?? null; $save = $action === 'saveResourceCard';
        $keys = array_keys($request); sort($keys);
        $wanted = ['action', 'binding', 'expectedRevision', 'id', 'versionId'];
        if ($save) $wanted = array_merge($wanted, ['expectedFingerprint', 'rows']); sort($wanted);
        if (!in_array($action, ['loadResourceCard', 'saveResourceCard'], true) || $keys !== $wanted || !is_array($request['binding'])
            || !is_int($request['expectedRevision']) || $request['expectedRevision'] < 1) throw new \InvalidArgumentException('Invalid resource card command.');
        foreach (['id', 'versionId'] as $key) if (!is_string($request[$key]) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $request[$key])) throw new \InvalidArgumentException('Invalid card version identity.');
        if ($save && (!is_array($request['rows']) || !is_string($request['expectedFingerprint']) || !preg_match('/^[a-f0-9]{64}$/D', $request['expectedFingerprint']))) throw new \InvalidArgumentException('Invalid card save fields.');
        if ($this->db->inTransaction()) throw new \LogicException('Resource card must own its transaction.');
        $this->db->begin(!$save);
        try {
            if ($save && $this->db->dialect() === 'mysql') {
                $engines = $this->db->rows("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('b_pw_calc_document','b_pw_calc_revision','b_pw_calc_version') ORDER BY TABLE_NAME");
                if (array_column($engines, 'TABLE_NAME') !== ['b_pw_calc_document', 'b_pw_calc_revision', 'b_pw_calc_version']
                    || count(array_filter($engines, static fn(array $row): bool => strtoupper((string)$row['ENGINE']) === 'INNODB')) !== 3) throw new DocumentConflict('Версии калькулятора должны храниться транзакционно.');
            }
            if ($save) $this->repository->versions()->expectLocked($request['id'], $request['versionId'], $request['expectedRevision']);
            $source = $this->repository->versions()->loadInTransaction($request['id'], $request['versionId']);
            if ($source['revision'] !== $request['expectedRevision'] || $source['archived'] || $source['versionArchived']) throw new DocumentConflict('Версия изменилась или скрыта. Откройте карточку заново.');
            $before = ($this->capture)($request['binding'], $save); $after = $before; $receipt = null;
            if ($save) {
                if (!hash_equals($before['fingerprint'], $request['expectedFingerprint'])) throw new DocumentConflict('Ресурс изменился после открытия карточки. Ваши правки не перезаписаны; откройте актуальную карточку.');
                $plan = ResourceCardMutationPlan::build($before, $request['rows']); $created = [];
                if ($plan['mutations']) {
                    $created = ($this->write)($before, $plan);
                    $after = ($this->capture)($request['binding'], true);
                    ResourceCardWriteVerification::assert($before, $after, $plan, $created);
                }
                if ($source !== $this->repository->versions()->loadInTransaction($request['id'], $request['versionId'])) throw new DocumentConflict('Запись ресурса изменила версию калькулятора.');
                $receipt = ['fromFingerprint' => $before['fingerprint'], 'toFingerprint' => $after['fingerprint'],
                    'updatedRows' => count($plan['mutations']), 'createdVariantIds' => array_values(array_column($created, 'id'))];
            }
            $result = ['contract' => 'prospektweb.calculator/resource-card-v1', 'documentId' => $request['id'], 'versionId' => $request['versionId'],
                'revision' => $request['expectedRevision'], 'binding' => $after['binding'], 'entityType' => $after['type'],
                'fingerprint' => $after['fingerprint'], 'data' => $after['data']];
            if ($receipt !== null) $result['saveReceipt'] = $receipt;
            $this->db->commit(); return $result;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollback();
            throw $error;
        }
    }

    /** Preserve JSON list/object distinctions at the HTTP boundary. Only the
     * declared object positions become PHP maps; unknown fields still reach
     * the strict command/planner validation and are never silently dropped. */
    public function commandFromJson(\stdClass $command): array
    {
        $request = get_object_vars($command);
        if (($request['binding'] ?? null) instanceof \stdClass) $request['binding'] = get_object_vars($request['binding']);
        if (is_array($request['rows'] ?? null)) foreach ($request['rows'] as &$row) {
            if (!$row instanceof \stdClass) throw new \InvalidArgumentException('Строка карточки должна быть JSON-объектом.');
            $row = get_object_vars($row);
            if (($row['catalog'] ?? null) instanceof \stdClass) $row['catalog'] = get_object_vars($row['catalog']);
            else throw new \InvalidArgumentException('Поля каталога должны быть JSON-объектом.');
            foreach (['parameters', 'sourceLinks'] as $field) if (is_array($row[$field] ?? null)) foreach ($row[$field] as &$value) {
                if (!$value instanceof \stdClass) throw new \InvalidArgumentException('Параметр или источник должен быть JSON-объектом.');
                $value = get_object_vars($value);
            }
            unset($value);
        }
        unset($row);
        return $this->command($request);
    }
}
