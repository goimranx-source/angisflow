/**
 * The paper an order turns into, and the file it exports as.
 *
 * ── Why an invoice and a receipt are not one document ────────────────────────
 *
 * They are read by different people for different reasons, and printing one
 * when somebody wanted the other is not a formatting inconvenience. An invoice
 * is a demand for payment: it needs both addresses, the tax broken out, the
 * order reference and terms, and it lives on A4 in a filing cabinet or an
 * accountant's inbox. A receipt is proof a sale happened: it goes in the box
 * with the goods, is read once, and on the thermal printer most shops in this
 * market actually own it is eighty millimetres wide.
 *
 * Producing one general "order printout" for both meant it served neither —
 * which is what was here before, and why it carried no line items at all.
 *
 * ── Why this is not in the page ──────────────────────────────────────────────
 *
 * Because it is document generation, not interface. It has no state, touches no
 * hooks, and the page it came out of was already nineteen hundred lines.
 */

/** Just enough of an order to put on paper. Structural, so the page owns its own type. */
export type PrintableOrder = {
    order_number: string;
    status: string;
    payment_status: string;
    is_cod: boolean;

    /*
     * Deliberately permissive on everything optional.
     *
     * The page's own Order type marks some of these absent and others null for
     * the same idea — a walk-in has no customer, an unshipped order no address.
     * A stricter shape here would not make the data better, it would only stop
     * the page passing what it has.
     */
    store_code?: string | null;
    date?: string | null;
    notes?: string | null;
    customer?: { name?: string | null; email?: string | null } | null;
    store?: { name?: string | null } | null;

    /*
     * Who the paperwork is from, resolved by the server.
     *
     * Shop first, then the business - a document should not have to work the
     * fallback out, and a counter sale has no shop to ask.
     */
    brand?: { name?: string | null; logo_url?: string | null } | null;
    shipping_address?: {
        line1?: string | null;
        line2?: string | null;
        city?: string | null;
        state?: string | null;
        postal_code?: string | null;
        country?: string | null;
    } | null;
    native: {
        currency: string;
        symbol: string;
        subtotal: number;
        discount: number;
        tax: number;
        shipping: number;
        total: number;
    };
    items: Array<{
        description: string;
        sku?: string | null;
        quantity: number;
        unit_price: number;
        total: number;
    }>;
};

export type DocumentKind = 'invoice' | 'receipt';

type Labels = {
    status: Record<string, string>;
    payment: Record<string, string>;
};

/** Anything a person typed can contain a bracket; none of it is markup. */
function escape(value: unknown): string {
    return String(value ?? '').replace(
        /[&<>"']/g,
        (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] as string,
    );
}

function amount(order: PrintableOrder, value: number): string {
    return `${order.native.symbol}${value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

function addressLines(order: PrintableOrder): string[] {
    const a = order.shipping_address;

    if (!a) {
        return [];
    }

    return [
        a.line1,
        a.line2,
        [a.city, a.state, a.postal_code].filter(Boolean).join(', '),
        a.country,
    ].filter((line): line is string => Boolean(line && line.trim()));
}

function formatDate(value: string | null | undefined): string {
    return value ? new Date(value).toLocaleDateString() : '—';
}

/* ────────────────────────────────────────────────────────────────────────────
 * Invoice — A4, for filing and for being paid against.
 * ──────────────────────────────────────────────────────────────────────────── */

function invoiceBody(order: PrintableOrder, labels: Labels): string {
    const ship = addressLines(order);
    const paid = order.payment_status === 'paid';

    const reference =
        order.store_code && order.store_code !== 'WALK'
            ? `${order.store_code}-${order.order_number}`
            : order.order_number;

    /*
     * The mark, or the name set as one.
     *
     * A logo that fails to load must not leave the masthead empty, so the name
     * is the alt text rather than a decorative blank — a broken image with no
     * alt is a document that looks like it came from nobody.
     */
    const brandName = order.brand?.name ?? order.store?.name ?? 'Invoice';
    const logo = order.brand?.logo_url;

    const masthead = logo
        ? `<img class="brand-logo" src="${escape(logo)}" alt="${escape(brandName)}">`
        : `<div class="brand-name">${escape(brandName)}</div>`;

    const rows = order.items.length
        ? order.items
              .map(
                  (item) => `
                    <tr>
                        <td>
                            <div class="item-name">${escape(item.description)}</div>
                            ${item.sku ? `<div class="item-sku">${escape(item.sku)}</div>` : ''}
                        </td>
                        <td class="num">${item.quantity.toLocaleString()}</td>
                        <td class="num">${amount(order, item.unit_price)}</td>
                        <td class="num">${amount(order, item.total)}</td>
                    </tr>`,
              )
              .join('')
        : `<tr><td colspan="4" class="muted">No items were recorded against this order.</td></tr>`;

    const totalRow = (label: string, value: string, cls = '') => `
        <tr class="${cls}">
            <td class="label">${escape(label)}</td>
            <td class="num">${value}</td>
        </tr>`;

    return `
        <article class="doc">
            <header class="doc-head">
                <div>
                    ${masthead}
                    ${logo ? `<div class="brand-sub">${escape(brandName)}</div>` : ''}
                </div>
                <div class="doc-title">
                    <div class="doc-type">Invoice</div>
                    <div class="doc-ref">${escape(reference)}</div>
                    <div class="doc-date">${escape(formatDate(order.date))}</div>
                </div>
            </header>

            <section class="parties">
                <div class="party">
                    <h2>Billed to</h2>
                    <p class="who">${escape(order.customer?.name ?? 'Walk-in customer')}</p>
                    ${order.customer?.email ? `<p class="muted">${escape(order.customer.email)}</p>` : ''}
                </div>

                ${
                    ship.length
                        ? `<div class="party">
                                <h2>Delivered to</h2>
                                ${ship.map((line) => `<p>${escape(line)}</p>`).join('')}
                           </div>`
                        : ''
                }

                <div class="facts">
                    <h2>Details</h2>
                    <dl>
                        <dt>Status</dt><dd>${escape(labels.status[order.status] ?? order.status)}</dd>
                        <dt>Payment</dt><dd>${escape(labels.payment[order.payment_status] ?? order.payment_status)}</dd>
                        ${order.is_cod ? '<dt>Method</dt><dd>Cash on delivery</dd>' : ''}
                        <dt>Currency</dt><dd>${escape(order.native.currency)}</dd>
                    </dl>
                </div>
            </section>

            <table class="items">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="num">Qty</th>
                        <th class="num">Unit price</th>
                        <th class="num">Amount</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>

            <section class="summary">
                <div>
                    <table class="totals">
                        ${totalRow('Subtotal', amount(order, order.native.subtotal))}
                        ${
                            order.native.discount > 0
                                ? totalRow('Discount', `−${amount(order, order.native.discount)}`)
                                : ''
                        }
                        ${order.native.shipping > 0 ? totalRow('Shipping', amount(order, order.native.shipping)) : ''}
                        ${order.native.tax > 0 ? totalRow('Tax', amount(order, order.native.tax)) : ''}
                        ${totalRow('Total', amount(order, order.native.total), 'grand')}
                        ${
                            paid
                                ? ''
                                : totalRow('Balance due', amount(order, order.native.total), 'due')
                        }
                    </table>

                    <div class="stamp ${paid ? 'stamp-paid' : 'stamp-due'}">
                        ${paid ? 'Paid in full' : 'Payment due'}
                    </div>
                </div>
            </section>

            ${
                order.notes
                    ? `<section class="notes">
                            <h2>Notes</h2>
                            <p>${escape(order.notes)}</p>
                       </section>`
                    : ''
            }

            <footer class="doc-foot">
                <span>${escape(brandName)}</span>
                <span>${escape(reference)}</span>
            </footer>
        </article>`;
}

/* ────────────────────────────────────────────────────────────────────────────
 * Receipt — 80mm, for the box with the goods.
 * ──────────────────────────────────────────────────────────────────────────── */

function receiptBody(order: PrintableOrder, labels: Labels): string {
    const rows = order.items
        .map(
            (item) => `
                <div class="line">
                    <div class="line-name">${escape(item.description)}</div>
                    <div class="line-figures">
                        <span>${item.quantity.toLocaleString()} × ${amount(order, item.unit_price)}</span>
                        <span>${amount(order, item.total)}</span>
                    </div>
                </div>`,
        )
        .join('');

    const row = (label: string, value: string, strong = false) => `
        <div class="tot ${strong ? 'grand' : ''}">
            <span>${escape(label)}</span><span>${value}</span>
        </div>`;

    return `
        <article class="receipt">
            <div class="r-head">
                <div class="r-shop">${escape(order.store?.name ?? 'Receipt')}</div>
                <div class="r-meta">${escape(
                    order.store_code && order.store_code !== 'WALK'
                        ? `${order.store_code}-${order.order_number}`
                        : order.order_number,
                )}</div>
                <div class="r-meta">${escape(formatDate(order.date))}</div>
            </div>

            <div class="rule"></div>
            ${rows || '<div class="line"><div class="line-name">No items recorded</div></div>'}
            <div class="rule"></div>

            ${row('Subtotal', amount(order, order.native.subtotal))}
            ${order.native.discount > 0 ? row('Discount', `−${amount(order, order.native.discount)}`) : ''}
            ${row('Shipping', amount(order, order.native.shipping))}
            ${row('Tax', amount(order, order.native.tax))}
            <div class="rule"></div>
            ${row('TOTAL', amount(order, order.native.total), true)}

            <div class="r-foot">
                <div>${escape(labels.payment[order.payment_status] ?? order.payment_status)}${
                    order.is_cod ? ' · cash on delivery' : ''
                }</div>
                <div>Thank you</div>
            </div>
        </article>`;
}

const INVOICE_CSS = `
    @page { size: A4; margin: 14mm 16mm 18mm; }

    body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; color: #1b1f24;
           font-size: 10pt; line-height: 1.5; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

    .doc { page-break-after: always; position: relative; }
    .doc:last-child { page-break-after: auto; }

    /* ── Masthead ─────────────────────────────────────────────────────────── */

    .doc-head { display: flex; justify-content: space-between; align-items: flex-start;
                gap: 12mm; padding-bottom: 6mm; margin-bottom: 7mm;
                border-bottom: 1px solid #e3e7ec; }

    /*
     * Constrained by height, not width: logos arrive square, wide and
     * everything between, and a rule on width alone lets a tall one push the
     * whole masthead down the page.
     */
    .brand-logo { max-height: 16mm; max-width: 55mm; display: block; }
    .brand-name { font-size: 15pt; font-weight: 700; letter-spacing: -.01em; }
    .brand-sub { font-size: 9pt; color: #6b7280; margin-top: 1mm; }

    .doc-title { text-align: right; }
    .doc-type { font-size: 20pt; font-weight: 700; letter-spacing: .04em;
                text-transform: uppercase; color: #1b1f24; line-height: 1; }
    .doc-ref { font-size: 11pt; font-weight: 600; margin-top: 2mm; font-variant-numeric: tabular-nums; }
    .doc-date { font-size: 9pt; color: #6b7280; margin-top: 1mm; }

    /* ── Parties ──────────────────────────────────────────────────────────── */

    .parties { display: flex; gap: 10mm; margin-bottom: 7mm; }
    .party { flex: 1; }
    .party h2, .facts h2 { font-size: 7.5pt; text-transform: uppercase; letter-spacing: .14em;
                           color: #8a93a0; margin-bottom: 2mm; font-weight: 600; }
    .party p { line-height: 1.45; }
    .party .who { font-weight: 600; }
    .muted { color: #6b7280; }

    /* A boxed column of the facts that decide how the invoice is treated. */
    .facts { flex: 0 0 52mm; background: #f6f8fa; border-radius: 2mm; padding: 4mm; }
    .facts dl { display: grid; grid-template-columns: auto auto; gap: 1.5mm 4mm; font-size: 9pt; }
    .facts dt { color: #6b7280; }
    .facts dd { text-align: right; font-weight: 600; }

    /* ── Items ────────────────────────────────────────────────────────────── */

    table { width: 100%; border-collapse: collapse; }

    .items { margin-bottom: 4mm; }
    .items thead th { text-align: left; font-size: 7.5pt; text-transform: uppercase;
                      letter-spacing: .1em; color: #8a93a0; font-weight: 600;
                      padding: 0 2mm 2mm; border-bottom: 1.5px solid #1b1f24; }
    .items thead th:first-child { padding-left: 0; }
    .items thead th:last-child { padding-right: 0; }
    .items td { padding: 2.5mm 2mm; border-bottom: 1px solid #eef1f4; vertical-align: top; }
    .items td:first-child { padding-left: 0; }
    .items td:last-child { padding-right: 0; }
    /* Rows must not be split across a page break mid-item. */
    .items tr { page-break-inside: avoid; }
    .item-name { font-weight: 500; }
    .item-sku { font-size: 8pt; color: #8a93a0; margin-top: .5mm; font-family: ui-monospace, "SF Mono", Menlo, monospace; }

    .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }

    /* ── Totals ───────────────────────────────────────────────────────────── */

    .summary { display: flex; justify-content: flex-end; page-break-inside: avoid; }
    .totals { width: 74mm; }
    .totals td { padding: 1.5mm 0; }
    .totals .label { color: #6b7280; }
    .totals .grand td { border-top: 1.5px solid #1b1f24; padding-top: 3mm; font-size: 13pt; font-weight: 700; }
    .totals .due td { color: #92400e; font-weight: 700; padding-top: 2mm; }

    /*
     * Stamped rather than merely stated.
     *
     * "Paid" in a list of fields is read at the same weight as the postcode.
     * The one thing somebody picking up an invoice needs to know before
     * anything else is whether money is still owed.
     */
    .stamp { display: inline-block; margin-top: 4mm; padding: 1.5mm 4mm; border-radius: 1mm;
             font-size: 9pt; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; }
    .stamp-paid { color: #0f6b3f; background: #e7f6ee; border: 1px solid #b7e2c9; }
    .stamp-due { color: #92400e; background: #fdf3e3; border: 1px solid #f0d9a8; }

    /* ── Foot ─────────────────────────────────────────────────────────────── */

    .notes { margin-top: 8mm; page-break-inside: avoid; }
    .notes h2 { font-size: 7.5pt; text-transform: uppercase; letter-spacing: .14em;
                color: #8a93a0; margin-bottom: 2mm; font-weight: 600; }
    .notes p { white-space: pre-line; }

    .doc-foot { margin-top: 10mm; padding-top: 4mm; border-top: 1px solid #e3e7ec;
                font-size: 8.5pt; color: #8a93a0; display: flex; justify-content: space-between; gap: 8mm; }
`;

const RECEIPT_CSS = `
    @page { size: 80mm auto; margin: 4mm; }
    body { font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; color: #000; font-size: 9pt; }
    .receipt { width: 72mm; page-break-after: always; }
    .receipt:last-child { page-break-after: auto; }
    .r-head { text-align: center; margin-bottom: 3mm; }
    .r-shop { font-size: 11pt; font-weight: 700; }
    .r-meta { font-size: 8.5pt; }
    .rule { border-top: 1px dashed #000; margin: 2mm 0; }
    .line { margin-bottom: 1.5mm; }
    .line-name { word-break: break-word; }
    .line-figures { display: flex; justify-content: space-between; }
    .tot { display: flex; justify-content: space-between; }
    .tot.grand { font-size: 11pt; font-weight: 700; margin-top: 1mm; }
    .r-foot { text-align: center; margin-top: 4mm; font-size: 8.5pt; }
`;

/**
 * Put orders on paper.
 *
 * Opened in a window rather than an iframe so the browser's own print preview
 * shows the document being printed, and so a person can save it as a PDF —
 * which is what most of these are actually used for.
 */
/**
 * Print without leaving anything behind.
 *
 * ── Why not a new tab ────────────────────────────────────────────────────────
 *
 * Because nothing closes it. A tab opened for printing has served its whole
 * purpose the moment the dialog is answered — printed or cancelled, either way
 * — and it cannot close itself: window.close() is refused for a document the
 * script did not open by user gesture in several browsers, and after a cancel
 * there is no event to hang it on at all. So it sits there, and after a run of
 * twenty invoices somebody has twenty tabs to shut by hand.
 *
 * A hidden iframe in the page prints exactly the same document — the print
 * dialog belongs to the frame, not the tab — and is removed afterwards by us,
 * because we own it. Nothing is opened, so nothing is left open.
 *
 * ── Why a frame at all, rather than printing this page ───────────────────────
 *
 * A print stylesheet on the application itself would have to hide the entire
 * interface and then re-lay the invoice inside a screen that was never built
 * for it. The frame gives the document its own page box, its own stylesheet and
 * its own margins, and leaves the application untouched behind it.
 */
export function printOrderDocuments(orders: PrintableOrder[], kind: DocumentKind, labels: Labels): boolean {
    if (orders.length === 0) {
        return false;
    }

    const body = orders
        .map((order) => (kind === 'invoice' ? invoiceBody(order, labels) : receiptBody(order, labels)))
        .join('');

    const title = `${kind === 'invoice' ? 'Invoice' : 'Receipt'} — ${
        orders.length === 1 ? (orders[0]?.order_number ?? 'Order') : `${orders.length} orders`
    }`;

    const frame = document.createElement('iframe');

    /*
     * Off-screen rather than display:none.
     *
     * A frame that is not displayed is not laid out, and a frame that has never
     * been laid out prints blank. Given a real size and moved out of sight, it
     * renders exactly as it will on paper.
     */
    frame.setAttribute('aria-hidden', 'true');
    frame.style.cssText =
        'position:fixed;right:0;bottom:0;width:210mm;height:297mm;border:0;visibility:hidden;';

    document.body.appendChild(frame);

    const doc = frame.contentDocument;
    const win = frame.contentWindow;

    if (!doc || !win) {
        frame.remove();

        return false;
    }

    let done = false;

    /*
     * Removed once, however the dialog ended.
     *
     * afterprint covers printing and cancelling in every current browser, but
     * it does not fire everywhere and has fired twice in some. The guard makes
     * a second call harmless, and the timeout means a browser that never fires
     * it still does not leak a frame into the page.
     */
    const cleanUp = (): void => {
        if (done) {
            return;
        }

        done = true;
        window.setTimeout(() => frame.remove(), 0);
    };

    win.addEventListener('afterprint', cleanUp);
    window.setTimeout(cleanUp, 60_000);

    doc.open();
    doc.write(`<!DOCTYPE html>
        <html><head><meta charset="utf-8">
        <title>${escape(title)}</title>
        <style>* { margin: 0; padding: 0; box-sizing: border-box; }${
            kind === 'invoice' ? INVOICE_CSS : RECEIPT_CSS
        }</style>
        </head><body>${body}</body></html>`);
    doc.close();

    /*
     * Printed once the frame has actually laid out. Calling straight after
     * close() prints a blank page in several browsers, and waiting on load
     * rather than a timer means a document full of logos is not cut off
     * mid-image.
     */
    const start = (): void => {
        win.focus();
        win.print();
    };

    if (doc.readyState === 'complete') {
        window.setTimeout(start, 0);
    } else {
        win.addEventListener('load', start, { once: true });
    }

    return true;
}

/* ────────────────────────────────────────────────────────────────────────────
 * Export
 * ──────────────────────────────────────────────────────────────────────────── */

/**
 * The exact column names the importer understands.
 *
 * ── Why these and not everything we know ─────────────────────────────────────
 *
 * Because "export" is only useful if the file can come back. An export shaped
 * to look complete — line items, internal ids, converted figures — is a file
 * that opens nicely in a spreadsheet and is rejected on import, which is the
 * one thing somebody exporting an order is most likely to want to do with it.
 *
 * So these are the importer's own canonical headings, in its order. See
 * OrderImporter, which maps each of them along with several aliases.
 */
const IMPORT_COLUMNS: Array<[string, (order: PrintableOrder) => string | number]> = [
    ['order_number', (o) => o.order_number],
    ['ordered_on', (o) => o.date ?? ''],
    ['status', (o) => o.status],
    ['payment_status', (o) => o.payment_status],
    ['customer_name', (o) => o.customer?.name ?? ''],
    ['customer_email', (o) => o.customer?.email ?? ''],
    ['shipping_name', (o) => o.customer?.name ?? ''],
    ['shipping_address', (o) => o.shipping_address?.line1 ?? ''],
    ['shipping_city', (o) => o.shipping_address?.city ?? ''],
    ['shipping_postcode', (o) => o.shipping_address?.postal_code ?? ''],
    ['shipping_country', (o) => o.shipping_address?.country ?? ''],
    ['currency', (o) => o.native.currency],
    ['subtotal', (o) => o.native.subtotal],
    ['discount', (o) => o.native.discount],
    ['shipping', (o) => o.native.shipping],
    ['tax', (o) => o.native.tax],
    ['total', (o) => o.native.total],
    ['notes', (o) => o.notes ?? ''],
];

function csvCell(value: string | number): string {
    const text = String(value ?? '');

    return /[",\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

/** One row per order, under headings the importer reads back. */
export function orderImportCsv(orders: PrintableOrder[]): string {
    const header = IMPORT_COLUMNS.map(([name]) => name).join(',');
    const rows = orders.map((order) =>
        IMPORT_COLUMNS.map(([, read]) => csvCell(read(order))).join(','),
    );

    return [header, ...rows].join('\n');
}

/**
 * Hand the file over.
 *
 * The byte order mark is not decoration: without it Excel reads a UTF-8 CSV as
 * the local code page, and every non-Latin name in the file — which here is
 * most of them — arrives as mojibake.
 */
export function downloadCsv(filename: string, csv: string): void {
    const blob = new Blob([`﻿${csv}`], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = filename;
    link.click();

    URL.revokeObjectURL(url);
}
