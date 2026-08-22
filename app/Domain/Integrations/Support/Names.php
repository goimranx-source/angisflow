<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

/**
 * A person's name, however the shop chose to send it.
 *
 * ── Why both shapes are offered ──────────────────────────────────────────────
 *
 * Almost every platform stores a person as `first_name` and `last_name`.
 * A handful send one full name. This application keeps one column.
 *
 * Offering only the single field forces somebody with a first/last shop to pick
 * one and lose the other — which is how a book of orders ends up addressed to
 * "Ada" and "Grace" with no surnames. Offering only the split pair forces the
 * opposite mistake on a shop that sends "Ada Lovelace" as one string.
 *
 * So both are mappable, and whichever was used is folded into the single column
 * here. A shop with one full name maps it to the first-name field and nothing is
 * lost; a shop with two maps both and they are joined in order.
 *
 * ── Why the parts are removed afterwards ─────────────────────────────────────
 *
 * `shipping_first_name` is a mapping target but not a column. Left in the
 * attributes it would reach `fill()` and throw on mass assignment — and dropping
 * it silently instead would leave the mapping looking configured while doing
 * nothing, which is worse.
 */
final class Names
{
    /**
     * The pairs this application folds, and the column each folds into.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PAIRS = [
        'shipping_name' => ['shipping_first_name', 'shipping_last_name'],
        'customer.name' => ['customer.first_name', 'customer.last_name'],
    ];

    /**
     * Fold any mapped name parts into the single field each belongs to.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function compose(array $attributes): array
    {
        foreach (self::PAIRS as $whole => [$first, $last]) {
            $parts = [];

            foreach ([$first, $last] as $key) {
                if (! array_key_exists($key, $attributes)) {
                    continue;
                }

                $piece = trim((string) $attributes[$key]);

                if ($piece !== '') {
                    $parts[] = $piece;
                }

                // Consumed either way: present-but-blank is still not a column.
                unset($attributes[$key]);
            }

            if ($parts === []) {
                continue;
            }

            /*
             * A directly mapped full name wins.
             *
             * If somebody mapped both the whole field and its parts, the whole
             * one is the more specific instruction — and overwriting it with a
             * join would silently change what they asked for.
             */
            if (trim((string) ($attributes[$whole] ?? '')) === '') {
                $attributes[$whole] = implode(' ', $parts);
            }
        }

        return $attributes;
    }
}
