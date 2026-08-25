/**
 * What kind of control a field's type deserves.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 *
 * A business defines its own fields on an order — a delivery slot, a gift
 * message, a proof-of-delivery photo — and gives each one a type from the same
 * vocabulary the sync and the push already use. A form that did not understand
 * those types would have two options, both bad: render everything as a text box,
 * so a date is typed by hand and a photo is a URL somebody pastes; or hard-code
 * a list of field names, so every new field needs the page rewritten.
 *
 * Deciding from the type means a field defined once is understood everywhere,
 * and a field added tomorrow lands in the right place without anybody touching
 * this screen.
 *
 * ── Why media is separated from the rest ─────────────────────────────────────
 *
 * Because it is the one group that cannot share a column with the others. A
 * picture needs to be seen at a useful size, and a row of labelled inputs is
 * exactly the wrong shape for that — so anything visual goes to the column
 * beside the form rather than interrupting it.
 */

/** The transform vocabulary, as the mapping screen and the sync use it. */
export type FieldType =
    | 'trim' | 'title' | 'strip_tags' | 'first_line' | 'strip_hash' | 'slug'
    | 'upper' | 'lower' | 'none'
    | 'integer' | 'decimal' | 'money_minor' | 'percent'
    | 'email' | 'digits' | 'url'
    | 'date' | 'datetime'
    | 'boolean'
    | 'select' | 'radio' | 'checkbox'
    | 'country' | 'state' | 'area'
    | 'textarea'
    | 'image' | 'image_list' | 'file' | 'video'
    | 'rich_text' | 'list' | 'colour' | 'json';

/** How a field should be drawn. */
export type Control =
    | 'text'
    | 'number'
    | 'money'
    | 'email'
    | 'tel'
    | 'url'
    | 'date'
    | 'datetime'
    | 'switch'
    /** A fixed set of legal answers, drawn as the searchable picker. */
    | 'options'
    | 'colour'
    | 'textarea'
    | 'lines'
    | 'code'
    | 'image'
    | 'gallery'
    | 'file'
    | 'video';

const CONTROLS: Record<string, Control> = {
    trim: 'text',
    title: 'text',
    strip_tags: 'text',
    first_line: 'text',
    strip_hash: 'text',
    slug: 'text',
    upper: 'text',
    lower: 'text',
    none: 'text',

    integer: 'number',
    decimal: 'number',
    percent: 'number',
    money_minor: 'money',

    email: 'email',
    digits: 'tel',
    url: 'url',

    date: 'date',
    datetime: 'datetime',

    boolean: 'switch',

    /*
     * ── Everything with a fixed set of answers ───────────────────────────────
     *
     * A country is not text that happens to be upper-case, and a thana is not a
     * city somebody types. Each has a list — 250 and 581 of them respectively —
     * and the list is what makes the difference between a field somebody can
     * fill in correctly and one they can only guess at. All of them get the
     * same searchable picker; where the answers come from is the caller's
     * business, not this map's.
     */
    select: 'options',
    radio: 'options',
    country: 'options',
    state: 'options',
    area: 'options',

    // No multi-select control yet, so the honest fallback is one per line
    // rather than a picker that can only hold one of several answers.
    checkbox: 'lines',

    textarea: 'textarea',
    colour: 'colour',

    rich_text: 'textarea',
    list: 'lines',
    json: 'code',

    image: 'image',
    image_list: 'gallery',
    file: 'file',
    video: 'video',
};

export function controlFor(type: string | null | undefined): Control {
    return CONTROLS[String(type ?? 'trim')] ?? 'text';
}

/** Belongs in the column beside the form rather than in it. */
export function isMedia(type: string | null | undefined): boolean {
    const control = controlFor(type);

    return control === 'image' || control === 'gallery' || control === 'file' || control === 'video';
}

/**
 * Fields that earn the full width of the form.
 *
 * Everything else sits two to a row. A note or a block of JSON in a half-width
 * box is a box you cannot read what you typed into.
 */
export function isWide(type: string | null | undefined): boolean {
    const control = controlFor(type);

    return control === 'textarea' || control === 'code' || control === 'lines';
}
