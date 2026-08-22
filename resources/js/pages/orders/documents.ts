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
    brand?: {
        name?: string | null;
        tagline?: string | null;
        logo_url?: string | null;
        address?: string | null;
        phone?: string | null;
        email?: string | null;
    } | null;
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
        paid?: number;
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

    const brandName = order.brand?.name ?? order.store?.name ?? 'Invoice';
    const logo = order.brand?.logo_url;

    /*
     * The seller block, built from what exists.
     *
     * Each line is dropped rather than printed empty: an invoice with a blank
     * space where the phone number belongs looks broken, one with no phone line
     * simply has no phone. Nothing here is required, because a business that
     * has not filled its address in should still be able to invoice.
     */
    const sellerLines = [
        order.brand?.tagline ? `A brand of ${order.brand.tagline}` : null,
        order.brand?.address,
        order.brand?.phone ? `Phone: ${order.brand.phone}` : null,
        order.brand?.email,
    ].filter((line): line is string => Boolean(line && String(line).trim()));

    const rows = order.items.length
        ? order.items
              .map(
                  (item) => `
                    <tr>
                        <td>${escape(item.description)}${
                            item.sku ? `<span class="sku">${escape(item.sku)}</span>` : ''
                        }</td>
                        <td class="num">${item.quantity.toLocaleString()}</td>
                        <td class="num">${amount(order, item.unit_price)}</td>
                        <td class="num strong">${amount(order, item.total)}</td>
                    </tr>`,
              )
              .join('')
        : `<tr><td colspan="4">No items were recorded against this order.</td></tr>`;

    const line = (label: string, value: string) =>
        `<tr><td class="lbl">${escape(label)}</td><td class="val">${value}</td></tr>`;

    const outstanding = Math.max(0, order.native.total - (order.native.paid ?? 0));

    return `
        <article class="doc">
            ${paid ? '' : '<div class="watermark">PENDING PAYMENT</div>'}

            <div class="sheet">
                <div class="wordmark">Invoice</div>

                <section class="top">
                    <div class="seller">
                        ${logo ? `<img class="seller-logo" src="${escape(logo)}" alt="${escape(brandName)}">` : ''}
                        <div class="seller-name">${escape(brandName)}</div>
                        ${sellerLines.map((l) => `<p>${escape(l)}</p>`).join('')}
                    </div>

                    <table class="meta">
                        <tr><th>Invoice number</th><td>${escape(reference)}</td></tr>
                        <tr><th>Order ID</th><td>${escape(order.order_number)}</td></tr>
                        <tr><th>Invoice date</th><td>${escape(formatDate(order.date))}</td></tr>
                        <tr><th>Order Total</th><td>${amount(order, order.native.total)}</td></tr>
                    </table>
                </section>

                <section class="parties">
                    <div class="party">
                        <h2>Bill To</h2>
                        <p class="who">${escape(order.customer?.name ?? 'Walk-in customer')}</p>
                        ${ship.map((l) => `<p>${escape(l)}</p>`).join('')}
                        ${order.customer?.email ? `<p>Email: ${escape(order.customer.email)}</p>` : ''}
                    </div>

                    <div class="party">
                        <h2>Ship To</h2>
                        <p class="who">${escape(order.customer?.name ?? 'Walk-in customer')}</p>
                        ${
                            ship.length
                                ? ship.map((l) => `<p>${escape(l)}</p>`).join('')
                                : '<p>Collected in person</p>'
                        }
                    </div>
                </section>

                <table class="grid">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th class="num">Quantity</th>
                            <th class="num">Unit price</th>
                            <th class="num">Amount</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>

                <table class="grid">
                    <thead>
                        <tr>
                            <th>Payment Method</th>
                            <th class="num">Amount</th>
                            <th class="num">Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>${order.is_cod ? 'COD' : escape(labels.payment[order.payment_status] ?? order.payment_status)}</td>
                            <td class="num">${amount(order, order.native.paid ?? 0)}</td>
                            <td class="num">N/A</td>
                        </tr>
                    </tbody>
                </table>

                <table class="totals">
                    ${line('Subtotal', amount(order, order.native.subtotal))}
                    ${order.native.discount > 0 ? line('Discount', `−${amount(order, order.native.discount)}`) : ''}
                    ${order.native.shipping > 0 ? line('Shipping', amount(order, order.native.shipping)) : ''}
                    ${order.native.tax > 0 ? line('Tax', amount(order, order.native.tax)) : ''}

                    <tr class="grand">
                        <td class="lbl">Total</td>
                        <td class="val">${amount(order, order.native.total)} ${escape(order.native.currency)}</td>
                    </tr>

                    <tr class="spacer"><td colspan="2"></td></tr>
                    ${line('Amount Paid', amount(order, order.native.paid ?? 0))}
                    ${outstanding > 0 ? line('Outstanding', amount(order, outstanding)) : ''}
                </table>

                <section class="note">
                    <h2>Note to recipient(s)</h2>
                    <p>${escape(order.notes ?? `Thank you for your business with ${brandName}!`)}</p>
                </section>

                ${
                    order.brand?.email || order.brand?.phone
                        ? `<div class="foot">For inquiries: ${[
                              order.brand?.email,
                              order.brand?.phone,
                          ]
                              .filter(Boolean)
                              .map((v) => escape(String(v)))
                              .join(' | ')}</div>`
                        : ''
                }
            </div>
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
    @page { size: A4; margin: 14mm 13mm; }

    body { font-family: "Segoe UI", system-ui, -apple-system, sans-serif; color: #3c4043;
           font-size: 10pt; line-height: 1.55;
           -webkit-print-color-adjust: exact; print-color-adjust: exact; }

    .doc { page-break-after: always; position: relative; }
    .doc:last-child { page-break-after: auto; }

    /*
     * Diagonal, behind everything, and only when money is owed.
     *
     * A stamp in the corner is read after the total; a watermark is read
     * before anything at all, which is the right order for the one fact that
     * changes what somebody does with the page.
     */
    .watermark { position: absolute; top: 42%; left: 50%; z-index: 0;
                 transform: translate(-50%, -50%) rotate(-38deg);
                 font-size: 46pt; font-weight: 700; letter-spacing: .06em;
                 color: #f6b8b8; opacity: .55; white-space: nowrap; pointer-events: none; }

    .sheet { position: relative; z-index: 1; }

    /* ── Wordmark ─────────────────────────────────────────────────────────── */

    .wordmark { text-align: right; font-size: 26pt; font-weight: 300; letter-spacing: .16em;
                color: #d2d6da; text-transform: uppercase; line-height: 1; margin-bottom: 9mm; }

    /* ── Seller, and the meta table beside it ─────────────────────────────── */

    .top { display: flex; justify-content: space-between; gap: 10mm; margin-bottom: 9mm; }

    .seller { max-width: 88mm; }
    .seller-logo { max-height: 15mm; max-width: 52mm; display: block; margin-bottom: 3mm; }
    .seller-name { font-size: 12.5pt; font-weight: 700; color: #26292d; margin-bottom: 2mm; }
    .seller p { color: #6f757c; }

    .meta { border-collapse: separate; border-spacing: 0 1.6mm; width: 78mm; }
    .meta th { background: #e4f1f7; color: #3c4043; font-weight: 600; text-align: left;
               padding: 2.4mm 3mm; width: 38mm; }
    .meta td { background: #f5f6f7; padding: 2.4mm 3mm; }

    /* ── Bill to / Ship to ────────────────────────────────────────────────── */

    .parties { display: flex; gap: 10mm; margin-bottom: 8mm; }
    .party { flex: 1; }
    .party h2 { font-size: 10pt; font-weight: 700; color: #26292d; margin-bottom: 2mm; }
    .party .who { font-weight: 700; color: #26292d; }
    .party p { color: #6f757c; }

    /* ── Tables ───────────────────────────────────────────────────────────── */

    table.grid { width: 100%; border-collapse: collapse; margin-bottom: 7mm; }
    table.grid th { background: #e4f1f7; color: #26292d; font-weight: 600; text-align: left;
                    padding: 3mm; border: 1px solid #dfe3e6; }
    table.grid td { padding: 3mm; border: 1px solid #e8ebee; color: #3c4043; vertical-align: top; }
    table.grid tr { page-break-inside: avoid; }

    .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .strong { font-weight: 700; color: #26292d; }
    .sku { display: block; font-size: 8pt; color: #9aa1a8; margin-top: .6mm;
           font-family: ui-monospace, "SF Mono", Menlo, monospace; }

    /* ── Totals ───────────────────────────────────────────────────────────── */

    .totals { width: 100%; border-collapse: collapse; page-break-inside: avoid; }
    .totals td { padding: 2.2mm 3mm; }
    .totals .lbl { text-align: right; color: #6f757c; width: 100%; }
    .totals .val { text-align: right; white-space: nowrap; font-weight: 700; color: #26292d;
                   font-variant-numeric: tabular-nums; padding-right: 0; }

    /* The one row that is a band rather than a line. */
    .totals .grand td { background: #e4f1f7; font-size: 11.5pt; padding: 3.2mm 3mm; }
    .totals .grand .lbl { color: #26292d; font-weight: 700; }

    .totals .spacer td { padding: 2mm 0 0; }

    /* ── Note and foot ────────────────────────────────────────────────────── */

    .note { margin-top: 8mm; page-break-inside: avoid; }
    .note h2 { font-size: 10pt; font-weight: 700; color: #26292d; margin-bottom: 1.5mm; }
    .note p { color: #6f757c; white-space: pre-line; }

    .foot { margin-top: 10mm; padding-top: 4mm; border-top: 1px solid #e8ebee;
            color: #9aa1a8; font-size: 9pt; }
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
