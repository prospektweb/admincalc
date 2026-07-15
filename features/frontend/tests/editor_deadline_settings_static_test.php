<?php

$editor = file_get_contents(__DIR__ . '/../admin/editor.php');

function editor_deadline_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

editor_deadline_assert(strpos($editor, "root.addEventListener('input'") !== false, 'deadline values must sync while editing');
editor_deadline_assert(strpos($editor, "form.addEventListener('submit', syncSchemaInput, true)") !== false, 'schema sync must run in submit capture phase');
editor_deadline_assert(strpos($editor, 'function collectEditorSchema()') !== false, 'schema serialization must have a single reusable function');
editor_deadline_assert(strpos($editor, 'js-deadline-accordion-toggle') !== false, 'advanced deadline rows must be collapsible');
editor_deadline_assert(strpos($editor, 'fc-deadline-accordion-body') !== false, 'advanced deadline accordion body must be styled');
editor_deadline_assert(strpos($editor, '$advMarkupByVolume[(string)$enumVolumeNumber]') !== false, 'normalized numeric volumes must restore markup values');
editor_deadline_assert(strpos($editor, '$advDiscountByVolume[(string)$enumVolumeNumber]') !== false, 'normalized numeric volumes must restore discount values');

fwrite(STDOUT, "Editor deadline settings tests passed\n");
