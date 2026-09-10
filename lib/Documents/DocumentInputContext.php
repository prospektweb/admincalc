<?php
declare(strict_types=1);
namespace Prospektweb\Calc\Documents;

/** Derived from the captured document, never a caller-supplied label. The colon
 * namespace cannot collide with an authored form field identifier. */
final class DocumentInputContext
{
    public static function values(object $document, $values, string $storefrontId = 'BASE', bool $public = false): object
    {
        if (!is_object($values)) throw new \InvalidArgumentException('Input values must be an object.');
        $name = $document->name;
        if ($storefrontId !== 'BASE') {
            $matches = array_values(array_filter($document->presentations->views ?? [], static fn($view) => $view->id === $storefrontId));
            if (count($matches) !== 1) throw new \InvalidArgumentException('Выбранная витрина недоступна.');
            $view = $matches[0];
            if (!$public || ($view->active === true && $view->public === true)) $name = $view->name;
        }
        $result = clone $values;
        $result->{'storefront:name'} = $name;
        return $result;
    }
}
