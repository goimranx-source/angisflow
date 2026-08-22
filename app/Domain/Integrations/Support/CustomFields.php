<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

use App\Domain\Tenancy\Models\Business;
use Illuminate\Support\Str;

/**
 * Fields a business added to this tool, beyond the ones it ships with.
 *
 * ── Why these are named once and reused ──────────────────────────────────────
 *
 * A shop sends `_manage_stock`; another sends `manageStock`; a third sends
 * `stock_managed`. They are the same fact. Left to be named per mapping, each
 * lands under the key it arrived with, and nothing can bring them together —
 * a report asking "which products track stock" would have to know all three
 * spellings.
 *
 * So a business names the field once — "Manage Stock" — and every shop's own
 * spelling is pointed at it. The name is what a person reads; the key is what
 * is stored, derived from it and never typed.
 *
 * ── Where the value goes ─────────────────────────────────────────────────────
 *
 * On the link between the record and the shop, not on the record. The same
 * product sold through two shops can carry a different value in each, and there
 * is no single true one to put on the product itself.
 */
final class CustomFields
{
    /**
     * Every custom field this business has defined for one kind of record.
     *
     * @return list<array{key: string, label: string, type: string}>
     */
    public static function for(?Business $business, string $entity): array
    {
        $stored = data_get($business?->custom_fields ?? [], $entity, []);

        if (! is_array($stored)) {
            return [];
        }

        $fields = [];

        foreach ($stored as $row) {
            if (! is_array($row)) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            $key = self::key($label !== '' ? $label : (string) ($row['key'] ?? ''));

            if ($key === '') {
                continue;
            }

            $fields[$key] = [
                'key' => $key,
                'label' => $label !== '' ? $label : Str::headline($key),
                'type' => Transform::exists((string) ($row['type'] ?? '')) ? (string) $row['type'] : 'trim',
            ];
        }

        return array_values($fields);
    }

    /**
     * Define one, returning its key.
     *
     * Named in words — "Manage Stock" — and keyed from that, so adding it twice
     * under two spellings is impossible and the stored key never depends on how
     * somebody happened to type it.
     */
    public static function add(Business $business, string $entity, string $label, string $type = 'trim'): ?string
    {
        $label = trim($label);
        $key = self::key($label);

        if ($key === '' || ! EntityFields::isEntity($entity)) {
            return null;
        }

        $fields = self::for($business, $entity);

        foreach ($fields as $existing) {
            if ($existing['key'] === $key) {
                return $key;
            }
        }

        $fields[] = [
            'key' => $key,
            'label' => $label,
            'type' => Transform::exists($type) ? $type : 'trim',
        ];

        $all = $business->custom_fields ?? [];
        $all[$entity] = $fields;
        $business->custom_fields = $all;
        $business->save();

        return $key;
    }

    public static function remove(Business $business, string $entity, string $key): bool
    {
        $key = self::key($key);
        $fields = array_values(array_filter(
            self::for($business, $entity),
            fn (array $field): bool => $field['key'] !== $key,
        ));

        $all = $business->custom_fields ?? [];
        $all[$entity] = $fields;
        $business->custom_fields = $all;
        $business->save();

        return true;
    }

    /**
     * The stored form of a field name.
     *
     * Lowercase with single underscores, and a leading underscore stripped —
     * WordPress marks its private meta that way and the underscore means
     * nothing here.
     */
    public static function key(string $name): string
    {
        $key = mb_strtolower(trim($name));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key;

        return trim($key, '_');
    }
}
