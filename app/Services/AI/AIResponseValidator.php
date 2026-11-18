<?php

namespace App\Services\AI;

/**
 * Minimal JSON-Schema validator for the subset MotionForge uses (type, properties, required,
 * additionalProperties, items, enum, minimum, maximum, minItems, maxItems, minLength, maxLength).
 * AI output is untrusted: every structured response is validated here even when the provider
 * claims schema enforcement.
 */
class AIResponseValidator
{
    /** @return list<string> errors */
    public function validate(mixed $data, array $schema, string $path = '$'): array
    {
        $errors = [];
        $type = $schema['type'] ?? null;
        $types = (array) $type;
        if ($type !== null && ! $this->matchesType($data, $types)) {
            return ["{$path}: expected ".implode('|', $types).', got '.get_debug_type($data)];
        }
        if (isset($schema['enum']) && ! in_array($data, $schema['enum'], true)) {
            $errors[] = "{$path}: must be one of ".implode(', ', array_map('json_encode', $schema['enum']));
        }
        if (is_string($data)) {
            if (isset($schema['minLength']) && mb_strlen($data) < $schema['minLength']) $errors[] = "{$path}: too short";
            if (isset($schema['maxLength']) && mb_strlen($data) > $schema['maxLength']) $errors[] = "{$path}: too long";
        }
        if (is_int($data) || is_float($data)) {
            if (isset($schema['minimum']) && $data < $schema['minimum']) $errors[] = "{$path}: below minimum {$schema['minimum']}";
            if (isset($schema['maximum']) && $data > $schema['maximum']) $errors[] = "{$path}: above maximum {$schema['maximum']}";
        }
        if (in_array('object', $types, true) && is_array($data) && (! array_is_list($data) || $data === [])) {
            foreach ($schema['required'] ?? [] as $req) {
                if (! array_key_exists($req, $data)) $errors[] = "{$path}.{$req}: required";
            }
            foreach ($data as $k => $v) {
                if (isset($schema['properties'][$k])) {
                    array_push($errors, ...$this->validate($v, $schema['properties'][$k], "{$path}.{$k}"));
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "{$path}.{$k}: unexpected property";
                }
            }
        }
        if (in_array('array', $types, true) && is_array($data) && array_is_list($data)) {
            if (isset($schema['minItems']) && count($data) < $schema['minItems']) $errors[] = "{$path}: needs at least {$schema['minItems']} items";
            if (isset($schema['maxItems']) && count($data) > $schema['maxItems']) $errors[] = "{$path}: at most {$schema['maxItems']} items";
            if (isset($schema['items'])) {
                foreach ($data as $i => $item) array_push($errors, ...$this->validate($item, $schema['items'], "{$path}[{$i}]"));
            }
        }

        return array_slice($errors, 0, 50);
    }

    private function matchesType(mixed $v, array $types): bool
    {
        foreach ($types as $t) {
            $ok = match ($t) {
                'object' => is_array($v) && (! array_is_list($v) || $v === []),
                'array' => is_array($v) && array_is_list($v),
                'string' => is_string($v),
                'integer' => is_int($v),
                'number' => is_int($v) || is_float($v),
                'boolean' => is_bool($v),
                'null' => $v === null,
                default => false,
            };
            if ($ok) return true;
        }

        return false;
    }
}
