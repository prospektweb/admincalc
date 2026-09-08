<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;
require_once __DIR__ . '/DocumentLibrary.php';
require_once __DIR__ . '/PriceTemplate.php';

/** Authentication/site/actor belong to the outer adapter, not the payload. */
final class PriceTemplateApplication
{
    private DocumentLibrary $library;
    public function __construct(SqlConnection $db, string $scope, string $actor) { $this->library = new DocumentLibrary($db, $scope, $actor, 'pricing'); }
    public function command(array $command): array
    {
        $fields = [
            'priceTemplates' => [], 'loadPriceTemplate' => ['id', 'expectedRevision'],
            'createPriceTemplate' => ['expectedCatalogRevision', 'name', 'templateJson'],
            'savePriceTemplate' => ['id', 'expectedRevision', 'expectedCatalogRevision', 'templateJson'],
            'renamePriceTemplate' => ['id', 'expectedRevision', 'expectedCatalogRevision', 'name'],
            'deletePriceTemplate' => ['id', 'expectedRevision', 'expectedCatalogRevision'],
        ];
        $action = $command['action'] ?? null;
        if (!is_string($action) || !isset($fields[$action])) { throw new \InvalidArgumentException('Unknown price template command.'); }
        $keys = array_keys($command); sort($keys); $expected = array_merge(['action'], $fields[$action]); sort($expected);
        if ($keys !== $expected) { throw new \InvalidArgumentException('Unknown or missing price template field.'); }
        foreach ($fields[$action] as $field) {
            if (str_starts_with($field, 'expected')) {
                if (!is_int($command[$field]) || $command[$field] < ($field === 'expectedCatalogRevision' ? 0 : 1) || $command[$field] > 2147483646) { throw new \InvalidArgumentException('Expected valid integer revision.'); }
            } elseif (!is_string($command[$field])) { throw new \InvalidArgumentException('Expected string template field.'); }
        }
        if ($action === 'priceTemplates') { return $this->library->listing(); }
        if ($action === 'loadPriceTemplate') {
            $record = $this->library->load($command['id']);
            if ($record['revision'] !== $command['expectedRevision']) { throw new DocumentConflict('Шаблон изменился. Обновите список.'); }
            PriceTemplate::canonical($record['bodyJson']); return $record;
        }
        $json = isset($command['templateJson']) ? PriceTemplate::canonical($command['templateJson']) : null;
        if ($action === 'createPriceTemplate') { return $this->library->create($command['expectedCatalogRevision'], $command['name'], $json); }
        return $this->library->change(['savePriceTemplate' => 'save', 'renamePriceTemplate' => 'rename', 'deletePriceTemplate' => 'delete'][$action],
            $command['id'], $command['expectedCatalogRevision'], $command['expectedRevision'], $json, $command['name'] ?? null);
    }
}
