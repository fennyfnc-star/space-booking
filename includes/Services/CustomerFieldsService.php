<?php declare(strict_types=1);

namespace SpaceBooking\Services;

final class CustomerFieldsService
{
    public const OPTION_NAME = 'sb_customer_fields';

    /**
     * Get customer fields config from options.
     */
    public function get_fields(): array
    {
        $fields_json = get_option(self::OPTION_NAME, '');
        if (empty($fields_json)) {
            return $this->get_default_fields();
        }
        $fields = json_decode($fields_json, true);
        return is_array($fields) ? $fields : $this->get_default_fields();
    }

    /**
     * Save customer fields config to options.
     */
    public function save_fields(array $fields): bool
    {
        $valid_fields = [];
        foreach ($fields as $field) {
            if ($this->validate_single_field($field)) {
                $valid_fields[] = $field;
            }
        }
        if (empty($valid_fields)) {
            return false;
        }
        return update_option(self::OPTION_NAME, wp_json_encode($valid_fields, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Validate single field array.
     */
    private function validate_single_field(array $field): bool
    {
        $required = ['key', 'label', 'type'];
        foreach ($required as $req) {
            if (empty($field[$req]))
                return false;
        }
        $allowed_types = ['text', 'email', 'tel', 'textarea', 'checkbox', 'radio', 'number', 'select'];
        return in_array($field['type'], $allowed_types, true) &&
            preg_match('/^[a-zA-Z0-9_]+$/', $field['key']) &&
            strlen($field['label']) >= 2 &&
            strlen($field['label']) <= 100;
    }

    /**
     * Default fallback fields matching existing Step4Details.
     */
    private function get_default_fields(): array
    {
        return [
            [
                'key' => 'name',
                'label' => 'Full Name',
                'type' => 'text',
                'required' => true,
                'placeholder' => 'Enter your full name',
                'default' => ''
            ],
            [
                'key' => 'email',
                'label' => 'Email Address',
                'type' => 'email',
                'required' => true,
                'placeholder' => 'your@email.com',
                'default' => ''
            ],
            [
                'key' => 'phone',
                'label' => 'Phone',
                'type' => 'tel',
                'required' => false,
                'placeholder' => 'Optional',
                'default' => ''
            ],
            [
                'key' => 'notes',
                'label' => 'Special Requests',
                'type' => 'textarea',
                'required' => false,
                'placeholder' => 'Any special requirements?',
                'default' => ''
            ]
        ];
    }
}
?>