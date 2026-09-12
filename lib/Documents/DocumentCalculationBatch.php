<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__ . '/DocumentBatchForm.php';

/** Browser-driven durable packet. No remote work runs under the document lock. */
final class DocumentCalculationBatch
{
    public function __construct(private SqlConnection $db, private string $scope, private string $actor, private DocumentRepository $documents) {}

    private function lock(string $id, callable $fn): mixed
    {
        $this->db->begin();
        try {
            if (!$this->db->rows('SELECT id FROM b_pw_calc_document WHERE scope_id = ? AND id = ?' . ($this->db->dialect() === 'mysql' ? ' FOR UPDATE' : ''), [$this->scope, $id])) throw new \RuntimeException('Document not found.', 404);
            $result = $fn(); $this->db->commit(); return $result;
        } catch (\Throwable $e) { $this->db->rollback(); throw $e; }
    }

    private function rows(string $id, string $version, ?string $key = null): array
    {
        return $this->db->rows('SELECT ' . ($key !== null ? '*' : 'id, state_json, request_hash, created_at') . ' FROM b_pw_calc_batch WHERE scope_id = ? AND document_id = ? AND version_id = ? AND actor_id = ?' . ($key !== null ? ' AND id = ?' : '') . ' ORDER BY created_at DESC, id DESC', $key !== null ? [$this->scope, $id, $version, $this->actor, $key] : [$this->scope, $id, $version, $this->actor]);
    }
    private function load(string $id, string $version, string $key): array
    {
        $row = $this->rows($id, $version, $key)[0] ?? null;
        if (!$row) throw new \RuntimeException('Пакет не найден.', 404);
        // Keep JSON object/array identity inside the compiled document and inputs.
        $artifact = (array)json_decode($row['artifact_json'], false, 64, JSON_THROW_ON_ERROR);
        $artifact['source'] = (array)$artifact['source'];
        return ['artifact' => $artifact, 'state' => json_decode($row['state_json'], true, 64, JSON_THROW_ON_ERROR), 'hash' => $row['request_hash']];
    }
    private function activePacket(string $id, string $version, ?string $except = null): ?array
    {
        foreach ($this->rows($id, $version) as $row) {
            $state = json_decode($row['state_json'], true, 64, JSON_THROW_ON_ERROR);
            if ($row['id'] !== $except && in_array($state['status'], ['running', 'paused'], true)) return $this->view($row['id'], $state);
        }
        return null;
    }
    private function save(string $key, array $state): void
    {
        $this->db->execute('UPDATE b_pw_calc_batch SET state_json = ? WHERE id = ?', [json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $key]);
    }
    private function settleCancelled(array $state): array
    {
        if ($state['status'] !== 'cancelled') return $state;
        foreach ($state['items'] as &$item) {
            if ($item['status'] === 'running' && ($item['lease'] ?? 0) <= time()) {
                // Revoke the claim; a delayed worker can no longer append a snapshot.
                $item = ['description' => $item['description'], 'status' => 'cancelled'];
                $state['message'] = 'Пакет отменён. Срок ожидания текущего варианта истёк; поздний результат не будет добавлен.';
            }
        }
        unset($item); return $state;
    }
    private function available(string $id, string $version): int
    {
        return max(0, 500 - (int)$this->db->rows('SELECT COUNT(*) AS total FROM b_pw_calc_snapshot WHERE scope_id = ? AND document_id = ? AND version_id = ? AND actor_id = ?', [$this->scope, $id, $version, $this->actor])[0]['total']);
    }
    private function view(string $key, array $state): array
    {
        foreach ($state['items'] as &$item) unset($item['token'], $item['lease']);
        unset($item);
        return ['id' => $key] + $state;
    }

    public function command(array $r, callable $core, callable $resources, callable $form): array
    {
        $id = $r['id']; $version = $r['versionId']; $op = $r['operation'] ?? 'status';
        if ($op === 'latest') {
            if ($active = $this->activePacket($id, $version)) return ['packet' => $active];
            $row = $this->rows($id, $version)[0] ?? null;
            return ['packet' => $row ? $this->view($row['id'], json_decode($row['state_json'], true, 64, JSON_THROW_ON_ERROR)) : null];
        }
        $requestId = $r['packetId'] ?? '';
        if (!is_string($requestId) || !preg_match('/^[a-zA-Z0-9_-]{16,128}$/D', $requestId)) throw new \InvalidArgumentException('Некорректный ключ пакета.');
        $key = hash('sha256', $this->scope . '|' . $this->actor . '|' . $id . '|' . $version . '|' . $requestId);
        if ($op === 'prepare') return $this->prepare($id, $version, $key, $r, $core, $resources, $form);
        // Client sends the opaque durable key returned by prepare/status.
        if (preg_match('/^[a-f0-9]{64}$/D', $requestId) && $this->rows($id, $version, $requestId)) $key = $requestId;
        if ($op === 'step') return $this->step($id, $version, $key, $core);
        return $this->lock($id, function() use ($id, $version, $key, $op, $r) {
            $packet = $this->load($id, $version, $key); $s = $this->settleCancelled($packet['state']);
            if ($op === 'cancel') {
                foreach ($s['items'] as &$item) if ($item['status'] === 'pending') $item['status'] = 'cancelled';
                unset($item);
                $s['status'] = 'cancelled'; $s['message'] = 'Остановлено. Уже начатый вариант может завершиться; готовые снимки сохранены.';
            }
            elseif ($op === 'start' || $op === 'resume') {
                if ($this->activePacket($id, $version, $key)) throw new DocumentConflict('Сначала завершите или отмените текущий пакет этой версии.');
                if (!in_array($s['status'], ['ready', 'running', 'paused'], true)) throw new DocumentConflict('Этот пакет нельзя продолжить.');
                $current = $this->documents->versions()->loadInTransaction($id, $version);
                if ($current['bodyHash'] !== $packet['artifact']['source']['bodyHash'] || $current['revision'] !== $packet['artifact']['source']['revision']) throw new DocumentConflict('Версия изменилась. Создайте новый пакет; готовые снимки сохранены.');
                $pending = count(array_filter($s['items'], fn($i) => in_array($i['status'], ['pending', 'running'], true)));
                if ($pending > $this->available($id, $version)) throw new DocumentConflict('Недостаточно места для пакета: доступно ' . $this->available($id, $version) . ' из 500 снимков.');
                $s['status'] = 'running'; $s['message'] = 'Выполняется, пока страница открыта.';
            } elseif ($op === 'retry') {
                if ($this->activePacket($id, $version, $key)) throw new DocumentConflict('Сначала завершите или отмените текущий пакет этой версии.');
                if (in_array($s['status'], ['stopped', 'ready', 'running'], true) || array_filter($s['items'], fn($i) => $i['status'] === 'running')) throw new DocumentConflict('Дождитесь завершения текущего варианта перед повтором ошибок.');
                $indexes = $r['itemIds'] ?? [];
                if (!is_array($indexes) || !$indexes) throw new \InvalidArgumentException('Выберите ошибочные варианты.');
                foreach ($indexes as $index) {
                    if (!is_int($index) || !isset($s['items'][$index]) || $s['items'][$index]['status'] !== 'error') throw new DocumentConflict('Повтор разрешён только для ошибочных вариантов.');
                    $s['items'][$index]['status'] = 'pending'; unset($s['items'][$index]['error']);
                }
                $s['status'] = 'paused'; $s['message'] = 'Ошибочные варианты подготовлены к повтору. Нажмите продолжить.';
            } elseif ($op !== 'status') throw new \InvalidArgumentException('Неизвестная операция пакета.');
            $s = $this->settleCancelled($s);
            $this->save($key, $s); return $this->view($key, $s);
        });
    }

    private function prepare(string $id, string $version, string $key, array $r, callable $core, callable $resources, callable $form): array
    {
        $hash = hash('sha256', DocumentBatchForm::canonical($r));
        $existing = $this->rows($id, $version, $key);
        if ($existing) {
            if ($existing[0]['request_hash'] !== $hash) throw new DocumentConflict('Ключ пакета уже использован для другого ввода.');
            return $this->view($key, json_decode($existing[0]['state_json'], true, 64, JSON_THROW_ON_ERROR));
        }
        $source = $this->documents->versions()->load($id, $version);
        if ($source['revision'] !== ($r['revision'] ?? null)) throw new DocumentConflict();
        $document = json_decode($source['bodyJson']); $view = $r['storefrontId'] ?? 'BASE';
        $runtime = $form($document, $source['revision']);
        $candidates = $r['candidates'] ?? null;
        if (!is_array($candidates) || !array_is_list($candidates) || !$candidates || count($candidates) > 500) throw new \InvalidArgumentException('Пакет: от 1 до 500 вариантов.');
        $seen = []; $items = []; $valid = []; $referenceShape = null;
        $activationHash = null;
        foreach ($candidates as $candidate) {
            if (!is_object($candidate) || !is_object($candidate->values ?? null) || !is_object($candidate->execution ?? null) || !is_object($candidate->activation ?? null)) throw new \InvalidArgumentException('Некорректный вариант пакета.');
            $activation = DocumentBatchForm::canonical($candidate->activation);
            if ($activationHash !== null && $activationHash !== $activation) throw new \InvalidArgumentException('Включение разделов фиксируется на весь пакет.');
            $activationHash = $activation;
            $signature = DocumentBatchForm::canonical($candidate);
            if (isset($seen[$signature])) continue;
            $seen[$signature] = true;
            $item = ['status' => 'pending'];
            try {
                $shape = DocumentBatchForm::validate($runtime, $view, $candidate->values, $candidate->activation, $candidate->execution);
                if ($referenceShape !== null) foreach ($shape as $field => $projection) {
                    if (DocumentBatchForm::canonical($projection) !== DocumentBatchForm::canonical($referenceShape[$field])) {
                        $names = array_column($runtime['formDefinition']['fields'], 'label', 'fieldId');
                        throw new \InvalidArgumentException('Нельзя объединить в один пакет: меняется видимость, допустимость или обязательность поля «' . ($names[$field] ?? $field) . '». Выберите управляющий параметр одним значением.');
                    }
                }
                $referenceShape ??= $shape;
            }
            catch (\InvalidArgumentException $e) { $item = ['status' => 'invalid', 'error' => $e->getMessage()]; }
            $labels = [];
            foreach ($runtime['formDefinition']['fields'] as $field) {
                $value = $candidate->values->{$field['fieldId']} ?? null;
                if ($value === null || $value === '' || $value === [] || $value === false) continue;
                $options = array_column($field['options'], 'label', 'id');
                $label = static fn($v) => $options[(string)$v] ?? (string)$v;
                $text = is_object($value) ? implode(' × ', array_values((array)$value)) : (is_array($value) ? implode(', ', array_map($label, $value)) : $label($value));
                $labels[] = $field['label'] . ': ' . $text;
            }
            $item['description'] = mb_substr(implode('; ', $labels), 0, 2000);
            $items[] = $item; $valid[] = $candidate;
        }
        $count = count(array_filter($items, fn($i) => $i['status'] === 'pending'));
        if ($count > $this->available($id, $version)) throw new DocumentConflict('Недостаточно места: доступно ' . $this->available($id, $version) . ' из 500 снимков.');
        $rows = $resources($document);
        $compiled = $core(['action' => 'compile', 'document' => $document, 'resources' => $rows]);
        if (!preg_match('/^[a-f0-9]{64}$/D', $compiled['runtimeFingerprint'] ?? '')) throw new \RuntimeException('Движок ещё не поддерживает фиксированные пакеты.');
        $artifact = ['source' => $source, 'publication' => json_decode($compiled['snapshotJson']), 'runtimeFingerprint' => $compiled['runtimeFingerprint'], 'candidates' => $valid];
        $bytes = json_encode($artifact, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($bytes) > 8000000) throw new \InvalidArgumentException('Пакет превышает 8 МБ. Уменьшите число вариантов.');
        $state = ['status' => 'ready', 'storefrontId' => $view, 'sourceRevision' => $source['revision'], 'total' => count($items), 'valid' => $count, 'items' => $items, 'message' => 'Проверено сервером. Запуск ещё не принят.'];
        return $this->lock($id, function() use ($id, $version, $key, $hash, $source, $bytes, $state, $count) {
            $current = $this->documents->versions()->loadInTransaction($id, $version);
            if ($current['bodyHash'] !== $source['bodyHash'] || $current['revision'] !== $source['revision']) throw new DocumentConflict();
            $existing = $this->rows($id, $version, $key);
            if ($existing) {
                if ($existing[0]['request_hash'] !== $hash) throw new DocumentConflict();
                return $this->view($key, json_decode($existing[0]['state_json'], true));
            }
            if ($count > $this->available($id, $version)) throw new DocumentConflict('Место занято другой вкладкой. Проверьте пакет заново.');
            $this->db->execute('INSERT INTO b_pw_calc_batch (id, scope_id, document_id, version_id, actor_id, request_hash, artifact_json, state_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$key, $this->scope, $id, $version, $this->actor, $hash, $bytes, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), gmdate('c')]);
            return $this->view($key, $state);
        });
    }

    private function step(string $id, string $version, string $key, callable $core): array
    {
        $claim = $this->lock($id, function() use ($id, $version, $key) {
            $p = $this->load($id, $version, $key); $s = $this->settleCancelled($p['state']);
            if ($s !== $p['state']) $this->save($key, $s);
            if ($s['status'] !== 'running') return ['view' => $this->view($key, $s)];
            $source = $this->documents->versions()->loadInTransaction($id, $version);
            if ($source['revision'] !== $p['artifact']['source']['revision'] || $source['bodyHash'] !== $p['artifact']['source']['bodyHash'] || $this->available($id, $version) === 0) {
                $s['status'] = 'stopped'; $s['message'] = 'Версия изменилась или список заполнен. Готовые снимки сохранены; создайте новый пакет.';
                $this->save($key, $s); return ['view' => $this->view($key, $s)];
            }
            foreach ($s['items'] as $i => $item) if ($item['status'] === 'running' && ($item['lease'] ?? 0) > time()) return ['view' => $this->view($key, $s)];
            foreach ($s['items'] as $i => $item) if (in_array($item['status'], ['pending', 'running'], true)) {
                $token = bin2hex(random_bytes(16)); $s['items'][$i] = ['description' => $item['description'], 'status' => 'running', 'token' => $token, 'lease' => time() + 120];
                $this->save($key, $s); return ['artifact' => $p['artifact'], 'state' => $s, 'index' => $i, 'token' => $token];
            }
            $s['status'] = 'completed'; $s['message'] = 'Пакет завершён.'; $this->save($key, $s);
            return ['view' => $this->view($key, $s)];
        });
        if (isset($claim['view'])) return $claim['view'];
        $a = $claim['artifact']; $candidate = (array)$a['candidates'][$claim['index']];
        $document = json_decode($a['source']['bodyJson']); $error = null; $result = null;
        try {
            $result = $core(['action' => 'execute', 'publication' => json_decode(json_encode($a['publication'])), 'expectedRuntimeFingerprint' => $a['runtimeFingerprint'], 'includeReport' => true,
                'values' => DocumentInputContext::values($document, (object)$candidate['values'], $claim['state']['storefrontId']), 'execution' => (object)$candidate['execution'], 'name' => $document->name]);
        } catch (\Throwable $e) { $error = $e; }
        return $this->lock($id, function() use ($id, $version, $key, $claim, $a, $candidate, $result, $error) {
            $p = $this->load($id, $version, $key); $s = $this->settleCancelled($p['state']); $i = $claim['index'];
            if ($s !== $p['state']) $this->save($key, $s);
            if (($s['items'][$i]['token'] ?? '') !== $claim['token']) return $this->view($key, $s);
            if ($error !== null) {
                $s['items'][$i] = ['description' => $s['items'][$i]['description'], 'status' => 'error', 'error' => mb_substr($error->getMessage(), 0, 2000)];
                if ($error->getCode() === 409) { $s['status'] = 'stopped'; $s['message'] = $error->getMessage(); }
            } else {
                $response = $result + ['source' => ['documentId' => $id, 'versionId' => $version, 'revision' => $a['source']['revision'], 'bodyHash' => $a['source']['bodyHash']]];
                try {
                    $snapshot = $this->documents->snapshots()->capture($id, $version, $a['source'], $response,
                        ['values' => (object)$candidate['values'], 'sectionActivation' => (object)$candidate['activation'], 'execution' => $candidate['execution'], 'storefrontId' => $s['storefrontId']], $a['publication']->resources);
                    $s['items'][$i] = ['description' => $s['items'][$i]['description'], 'status' => 'success', 'snapshotId' => $snapshot];
                } catch (\Throwable $e) {
                    $s['items'][$i] = ['description' => $s['items'][$i]['description'], 'status' => 'error', 'error' => $e->getMessage()]; $s['status'] = 'stopped';
                    $s['message'] = 'Результат не сохранён: ' . $e->getMessage() . ' Готовые снимки сохранены; создайте новый пакет.';
                }
            }
            if ($s['status'] === 'running' && !array_filter($s['items'], fn($i) => in_array($i['status'], ['pending', 'running'], true))) { $s['status'] = 'completed'; $s['message'] = 'Пакет завершён.'; }
            $this->save($key, $s); return $this->view($key, $s);
        });
    }
}
