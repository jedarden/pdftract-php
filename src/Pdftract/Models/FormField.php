<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Models;

/**
 * JSON representation of a form field
 *
 * Represents a single interactive form field from the PDF's
 * AcroForm or XFA data, including its type, value, and metadata.
 */
class FormField
{
    /**
     * The absolute (dot-joined) field name from the AcroForm
     * Example: "employer_signature" or "form.employee_sig"
     */
    public string $name;

    /**
     * The field type variant (text, button, choice, or signature)
     */
    public string $type;

    /**
     * The current value of the form field
     *
     * This field's structure varies by type:
     * - text: string value
     * - button: boolean selected state
     * - choice: string or array of strings (for multi-select)
     * - signature: signature reference number (or null if unsigned)
     *
     * @var mixed
     */
    public $value;

    /**
     * The default value (/DV entry) if present
     *
     * @var mixed|null
     */
    public $default = null;

    /**
     * Zero-based page index where this field's widget appears
     *
     * None if the field has no visual representation (form-only field).
     */
    public ?int $page_index = null;

    /**
     * Bounding box in PDF user-space points
     *
     * Format: [x0, y0, x1, y1] where (x0, y0) is the bottom-left corner.
     * None if the field has no visual appearance.
     *
     * @var array<float>|null
     */
    public ?array $rect = null;

    /**
     * Whether this field is required (bit 2 of /Ff flags)
     */
    public bool $required;

    /**
     * Whether this field is read-only (bit 1 of /Ff flags)
     */
    public bool $read_only;

    /**
     * Whether this text field supports multiple lines (bit 13 of /Ff)
     *
     * Only present for text fields.
     */
    public ?bool $multiline = null;

    /**
     * Maximum length for text fields (/MaxLen entry)
     *
     * Only present for text fields that have a max length set.
     */
    public ?int $max_length = null;

    /**
     * Available options for choice fields
     *
     * Each option is a [export_value, display_name] pair.
     * Only present for choice fields.
     *
     * @var array<array<string>>|null
     */
    public ?array $options = null;

    /**
     * Whether this choice field supports multiple selections (bit 21 of /Ff)
     *
     * Only present for choice fields.
     */
    public ?bool $multi_select = null;

    /**
     * Selected state for button fields
     *
     * True = checked/selected, False = unchecked.
     * Only present for button fields.
     */
    public ?bool $selected = null;

    /**
     * Appearance state name for button fields
     *
     * E.g., "Yes", "Off", or custom state names.
     * Only present for button fields.
     */
    public ?string $state_name = null;

    /**
     * Whether this button is a pushbutton (bit 26 of /Ff)
     *
     * Only present for button fields.
     */
    public ?bool $pushbutton = null;

    /**
     * Whether this button is a radio button (bit 25 of /Ff)
     *
     * Only present for button fields.
     */
    public ?bool $radio = null;

    /**
     * Create FormField from JSON array
     *
     * @param array<string,mixed> $data JSON data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $field = new self();
        $field->name = $data['name'];
        $field->type = $data['type'];
        $field->value = $data['value'] ?? null;
        $field->default = $data['default'] ?? null;
        $field->page_index = $data['page_index'] ?? null;
        $field->rect = $data['rect'] ?? null;
        $field->required = $data['required'] ?? false;
        $field->read_only = $data['read_only'] ?? false;
        $field->multiline = $data['multiline'] ?? null;
        $field->max_length = $data['max_length'] ?? null;
        $field->options = $data['options'] ?? null;
        $field->multi_select = $data['multi_select'] ?? null;
        $field->selected = $data['selected'] ?? null;
        $field->state_name = $data['state_name'] ?? null;
        $field->pushbutton = $data['pushbutton'] ?? null;
        $field->radio = $data['radio'] ?? null;

        return $field;
    }

    /**
     * Convert to JSON array
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'type' => $this->type,
            'value' => $this->value,
            'required' => $this->required,
            'read_only' => $this->read_only,
        ];

        if ($this->default !== null) {
            $data['default'] = $this->default;
        }

        if ($this->page_index !== null) {
            $data['page_index'] = $this->page_index;
        }

        if ($this->rect !== null) {
            $data['rect'] = $this->rect;
        }

        if ($this->multiline !== null) {
            $data['multiline'] = $this->multiline;
        }

        if ($this->max_length !== null) {
            $data['max_length'] = $this->max_length;
        }

        if ($this->options !== null) {
            $data['options'] = $this->options;
        }

        if ($this->multi_select !== null) {
            $data['multi_select'] = $this->multi_select;
        }

        if ($this->selected !== null) {
            $data['selected'] = $this->selected;
        }

        if ($this->state_name !== null) {
            $data['state_name'] = $this->state_name;
        }

        if ($this->pushbutton !== null) {
            $data['pushbutton'] = $this->pushbutton;
        }

        if ($this->radio !== null) {
            $data['radio'] = $this->radio;
        }

        return $data;
    }
}
