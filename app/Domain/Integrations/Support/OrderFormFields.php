<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

use App\Domain\Integrations\Models\Integration;
use App\Domain\Sales\Models\Order;

/**
 * What each box on the order form actually is.
 *
 * ── The mistake this corrects ────────────────────────────────────────────────
 *
 * The edit form decided its own controls. Somebody wrote a text box for
 * `shipping_country` because a country is words, and a text box for
 * `customer_billing_city` because a city is words, and both were wrong: this
 * application stores a country as a code, and this shop maps its billing city
 * from `meta_data._shipping_thana`, which arrives as `BD-58-05`. So the form
 * offered free text for a value with exactly 250 legal answers, and another for
 * one with 581 — and displayed `BD-58-05` where the mapping screen two clicks
 * away had been showing "Satkhira Sadar" all along.
 *
 * Meanwhile the custom fields on the same form were rendered correctly, from
 * their type, by machinery that already existed. Two systems on one screen, and
 * the hand-written one was the broken one.
 *
 * ── Where a field's type comes from ──────────────────────────────────────────
 *
 * The transform: the same vocabulary the sync, the push and the mapping screen
 * already speak. Two places, in this order.
 *
 * The mapping, when this shop has one. `customer.billing_city` defaults to
 * plain text and this shop maps it as an `area` — so on this shop's orders it
 * is an area, and the form should say so.
 *
 * The field's own default otherwise, from EntityFields, where every order field
 * this application knows about is declared with its type.
 *
 * Nothing here is guessed from the field's name. That is what went wrong.
 */
final class OrderFormFields
{
    /**
     * The form's own names for fields, where they differ from the mapping's.
     *
     * The form has one Name box; the mapping offers first and last separately,
     * because most platforms store them that way. Whichever target this shop
     * actually maps is the one that decides the box's type and its sync mark.
     *
     * @var array<string, list<string>>
     */
    private const TARGETS = [
        'customer_name' => ['customer.name', 'customer.first_name', 'customer.last_name'],
        'customer_email' => ['customer.email'],
        'customer_phone' => ['customer.phone'],
        'customer_company' => ['customer.company'],
        'customer_tax_number' => ['customer.tax_number'],
        'customer_billing_address' => ['customer.billing_address'],
        'customer_billing_city' => ['customer.billing_city'],
        'customer_billing_postcode' => ['customer.billing_postcode'],
        'customer_billing_country' => ['customer.billing_country'],
        'customer_notes' => ['customer.notes'],

        'shipping_name' => ['shipping_name', 'shipping_first_name', 'shipping_last_name'],

        // The form types money in major units; the mapping names the column.
        'shipping' => ['shipping_minor'],
        'tax' => ['tax_minor'],
        'subtotal' => ['subtotal_minor'],
        'discount' => ['discount_minor'],
        'total' => ['total_minor'],
        'paid' => ['paid_minor'],
    ];

    /**
     * Every field the form draws, with its type and its choices.
     *
     * @return array<string, array{label: string, type: string, mapped: bool, options: list<array{value: string, label: string, note?: string}>|null}>
     */
    public static function describe(Order $order, ?Integration $integration): array
    {
        $configured = self::configuredTransforms($integration);

        /** @var array<string, array{label: string, type: string, mapped: bool, options: list<array<string, string>>|null}> $described */
        $described = [];

        foreach (EntityFields::options('order') as $field) {
            $target = (string) $field['value'];
            $key = self::formKey($target);

            /*
             * Two targets can share one box — first name and last name both
             * land in Name. The mapped one wins, so the box takes the type of
             * the field this shop actually sends rather than the type of
             * whichever target happened to be declared first.
             */
            if (($described[$key]['mapped'] ?? false) === true) {
                continue;
            }

            $type = $configured[$target] ?? (string) $field['transform'];

            $described[$key] = [
                'label' => (string) $field['label'],
                'type' => $type,
                'mapped' => array_key_exists($target, $configured),
                'options' => self::optionsFor($type, $order),
            ];
        }

        return $described;
    }

    /** The form's name for a mapping target. */
    private static function formKey(string $target): string
    {
        foreach (self::TARGETS as $key => $targets) {
            if (in_array($target, $targets, true)) {
                return $key;
            }
        }

        return $target;
    }

    /**
     * The transform each mapped target actually uses on this shop.
     *
     * @return array<string, string>
     */
    private static function configuredTransforms(?Integration $integration): array
    {
        if ($integration === null) {
            return [];
        }

        $transforms = [];

        foreach (FieldMapSet::for($integration, 'order')->maps as $map) {
            $transforms[$map->target] = $map->transform;
        }

        return $transforms;
    }


    /**
     * Which country's divisions to offer.
     *
     * ── Why it is four guesses and not one ───────────────────────────────────
     *
     * The obvious answer is the order's shipping country, and on this business
     * every order has that field empty — the shop maps it, and the value has
     * never arrived. So the obvious answer produces an empty picker on the one
     * field that most needs one, which reads as the feature being broken.
     *
     * The codes themselves are the reliable signal. `BD-58-05` says Bangladesh
     * in its first two characters, and a field that already holds one is
     * telling us what list it belongs to more certainly than any other column
     * on the order. The business's own country is the last resort, and worth
     * having: it is right for a business that sells in one country, which is
     * most of them.
     */
    private static function countryOf(Order $order): string
    {
        $candidates = [
            $order->shipping_country,

            // The code in the field itself. See above: this is the one that
            // works when the country column was never filled in.
            $order->customer?->billing_city,
            $order->shipping_city,

            $order->customer?->billing_country,
            $order->business?->country,
        ];

        foreach ($candidates as $candidate) {
            $value = mb_strtoupper(trim((string) $candidate));

            if ($value === '') {
                continue;
            }

            // Either a bare country code, or the country prefix of a division
            // code. Anything else — a city typed as words — says nothing.
            $code = str_contains($value, '-') ? explode('-', $value)[0] : $value;

            if (mb_strlen($code) === 2 && Geography::countryName($code) !== null) {
                return $code;
            }
        }

        return '';
    }

    /**
     * The legal answers, for the types that have a fixed set of them.
     *
     * ── Why areas are listed flat ────────────────────────────────────────────
     *
     * An area code carries its district in it — BD-58-05 is the fifth thana of
     * the fifty-eighth district — so the tidy thing would be to pick a district
     * first and a thana second. That is two controls and an order in which they
     * must be used, for a field somebody is usually correcting rather than
     * filling in from nothing.
     *
     * All of them in one searchable list instead, each carrying its district as
     * a note. Typing "satkhira" narrows it to seven; typing "sadar" finds every
     * district town in the country, which is the other way people look for
     * these.
     *
     * @return list<array{value: string, label: string, note?: string}>|null
     */
    private static function optionsFor(string $type, Order $order): ?array
    {
        if ($type === 'country') {
            return array_map(
                static fn (array $one): array => [
                    'value' => (string) $one['value'],
                    'label' => (string) $one['label'],
                ],
                Geography::countryOptions(),
            );
        }

        if ($type !== 'state' && $type !== 'area') {
            return null;
        }

        $country = self::countryOf($order);

        if ($country === '') {
            return [];
        }

        if ($type === 'state') {
            return array_map(
                static fn (array $one): array => [
                    'value' => (string) $one['value'],
                    'label' => (string) $one['label'],
                ],
                Geography::stateOptions($country),
            );
        }

        $options = [];

        foreach (Geography::areas($country) as $state => $areas) {
            $district = Geography::stateName((string) $state, $country);

            foreach ($areas as $code => $name) {
                $options[] = [
                    'value' => (string) $code,
                    'label' => (string) $name,
                    'note' => $district ?? (string) $state,
                ];
            }
        }

        return $options;
    }
}
