/**
 * Where a field a shop invented belongs on this form.
 *
 * ── The problem ──────────────────────────────────────────────────────────────
 *
 * Every platform lets a shop invent fields, and every shop does. A WooCommerce
 * order arrives carrying `_billing_thana`, `order_source`, `_delivery_slot`,
 * `customer_payment_amount` — names this application has never seen and cannot
 * be taught in advance, because the next shop will have different ones.
 *
 * They were all rendered into one box at the foot of the form called
 * "Additional fields". That is where a field goes when nobody has thought about
 * it: a delivery instruction sitting three sections below the delivery address,
 * a second phone number nowhere near the first, a payment reference under the
 * notes. The form has sections for exactly these things and was not using them.
 *
 * ── Why a name is enough to go on ────────────────────────────────────────────
 *
 * Because the names are not random. Somebody naming a field for a delivery slot
 * calls it a delivery slot. The words that recur — billing, shipping, phone,
 * paid, invoice, courier — are the same words this form already uses for its own
 * sections, which is not a coincidence: both are named after the same parts of
 * the same job.
 *
 * So a name is a good guess, and a guess is what this is. It is allowed to be
 * wrong, and the cost of being wrong is a field one section away from where
 * somebody expected it — which is the cost of the old behaviour on every field,
 * every time.
 *
 * ── Why type is consulted first ──────────────────────────────────────────────
 *
 * A type is a fact where a name is an inference. A field typed as money is
 * money whatever it is called, and one typed as an image is an attachment. Only
 * when the type says nothing useful — and most types are just "text" — does the
 * name get a say.
 */

/** The parts of the form a field can land in. */
export type Placement = 'customer' | 'delivery' | 'payment' | 'dates' | 'media' | 'other';

/**
 * Words that place a field, narrowest first.
 *
 * ── Why the order matters ────────────────────────────────────────────────────
 *
 * "shipping_paid_at" contains both a delivery word and a payment word and is a
 * date. Whichever list is consulted first wins, so the lists run from most
 * specific to least: a rule that matches two things should be decided by the
 * more particular of them.
 *
 * ── Why whole words ──────────────────────────────────────────────────────────
 *
 * `state` inside `estate_agent` is not a state, `bill` inside `billboard` is not
 * a bill, and `pay` inside `payload` is not a payment. Matching substrings puts
 * fields in confidently wrong places, which is worse than the honest bottom of
 * the form — a field nobody can find is at least obviously missing, where a
 * field in the wrong section looks like it belongs there.
 */
const RULES: ReadonlyArray<{ place: Placement; words: readonly string[] }> = [
    {
        place: 'delivery',
        words: [
            'shipping', 'shipment', 'delivery', 'deliver', 'courier', 'dispatch',
            'tracking', 'consignment', 'waybill', 'parcel', 'thana', 'upazila',
            'district', 'zone', 'area', 'pickup', 'dropoff', 'slot', 'route',
        ],
    },
    {
        place: 'payment',
        words: [
            'payment', 'paid', 'transaction', 'invoice', 'receipt', 'refund',
            'cod', 'gateway', 'settlement', 'commission', 'charge', 'fee',
            'discount', 'coupon', 'voucher', 'currency', 'amount', 'balance',
        ],
    },
    {
        place: 'customer',
        words: [
            'customer', 'buyer', 'client', 'billing', 'contact', 'phone',
            'mobile', 'email', 'company', 'vat', 'nid', 'gender', 'agent',
        ],
    },
];

/**
 * Placement decided by the field's type, where the type decides it.
 *
 * These are the transform names the mapping screen uses, so a field means the
 * same thing here as it does there.
 */
const BY_TYPE: Readonly<Record<string, Placement>> = {
    money: 'payment',
    money_minor: 'payment',
    date: 'dates',
    datetime: 'dates',
    image: 'media',
    image_list: 'media',
    file: 'media',
};

/** Split a field key into the words it is made of. */
function words(key: string): string[] {
    return key
        // WordPress marks meta private with a leading underscore, and that
        // underscore carries no meaning outside WordPress.
        .replace(/^_+/, '')
        .split(/[^a-z0-9]+/i)
        .map((word) => word.toLowerCase())
        .filter(Boolean);
}

/**
 * Which section a custom field belongs to.
 *
 * @param key   the field's own key, e.g. `_billing_thana`
 * @param type  a transform name, e.g. `money` or `trim`
 * @param label what the field is called on screen, if it differs from the key
 */
export function placementFor(key: string, type = 'trim', label = ''): Placement {
    const byType = BY_TYPE[type];

    if (byType) {
        return byType;
    }

    /*
     * The label as well as the key, because the two are named by different
     * people. A shop's key is `meta_2` often enough, and the label somebody
     * typed beside it — "Delivery slot" — is the part with the meaning in it.
     */
    const found = new Set([...words(key), ...words(label)]);

    for (const rule of RULES) {
        if (rule.words.some((word) => found.has(word))) {
            return rule.place;
        }
    }

    return 'other';
}
