<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Settings;

/**
 * Closed set of input types a module settings schema may declare.
 *
 * Adding a new type is a coordinated change: it must be supported here, in the
 * host app's generic BaseSchemaForm.vue renderer (so the field type maps to a
 * concrete Vue component), and in the host app's ModuleSettingsController which
 * builds the Laravel validator rules from the schema.
 */
enum FieldType: string
{
    case Text = 'text';
    case Password = 'password';
    case Textarea = 'textarea';
    case Switch_ = 'switch';
    case Number = 'number';
    case Select = 'select';
    case MultiSelect = 'multiselect';
}
