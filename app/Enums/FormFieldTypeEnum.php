<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Input types an admin can add to a ticket type's form.
 */
enum FormFieldTypeEnum: string implements HasLabel
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Select = 'select';
    case Date = 'date';
    case Checkbox = 'checkbox';

    public function getLabel(): string
    {
        return match ($this) {
            self::Text => __('ui.field_type_text'),
            self::Textarea => __('ui.field_type_textarea'),
            self::Number => __('ui.field_type_number'),
            self::Select => __('ui.field_type_select'),
            self::Date => __('ui.field_type_date'),
            self::Checkbox => __('ui.field_type_checkbox'),
        };
    }

    public function hasOptions(): bool
    {
        return $this === self::Select;
    }
}
