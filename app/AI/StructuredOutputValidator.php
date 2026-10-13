<?php

namespace App\AI;

class StructuredOutputValidator
{
    /** @param array<string, mixed> $schema @return list<string> */
    public function validate(mixed $value, array $schema, string $path = '$'): array
    {
        $errors = [];
        $type = $schema['type'] ?? null;
        if (is_array($type)) {
            if (! collect($type)->contains(fn ($candidate) => $this->matches($value, $candidate))) {
                return ["{$path} must be one of: ".implode(', ', $type).'.'];
            }
        } elseif ($type && ! $this->matches($value, $type)) {
            return ["{$path} must be {$type}."];
        }

        if (array_key_exists('enum', $schema) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path} is not an allowed value.";
        }
        if ($type === 'object' && is_array($value)) {
            foreach ($schema['required'] ?? [] as $required) {
                if (! array_key_exists($required, $value)) {
                    $errors[] = "{$path}.{$required} is required.";
                }
            }
            $properties = $schema['properties'] ?? [];
            foreach ($value as $key => $child) {
                if (isset($properties[$key])) {
                    $errors = [...$errors, ...$this->validate($child, $properties[$key], "{$path}.{$key}")];
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "{$path}.{$key} is not allowed.";
                }
            }
        }
        if ($type === 'array' && is_array($value) && isset($schema['items'])) {
            foreach ($value as $index => $child) {
                $errors = [...$errors, ...$this->validate($child, $schema['items'], "{$path}[{$index}]")];
            }
        }

        return $errors;
    }

    private function matches(mixed $value, string $type): bool
    {
        return match ($type) {
            // json_decode(..., true) turns {} into [], so an empty array is also a valid (empty) object.
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }
}
