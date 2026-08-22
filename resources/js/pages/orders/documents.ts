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

    const totalRow = (label: string, value: string, strong = false) => `
        <tr class="${strong ? 'grand' : ''}">
            <td class="label">${escape(label)}</td>
            <td class="num">${value}</td>
        </tr>`;

    return `
        <article class="doc">
            <header class="doc-head">
                <div>
                    <div class="doc-type">Invoice</div>
                    <h1>${escape(order.store?.name ?? 'Order')}</h1>
                </div>
                <dl class="doc-meta">
                    <div><dt>Order</dt><dd>${escape(
                        order.store_code && order.store_code !== 'WALK'
                            ? `${order.store_code}-${order.order_number}`
                            : order.order_number,
                    )}</dd></div>
                    <div><dt>Date</dt><dd>${escape(formatDate(order.date))}</dd></div>
                    <div><dt>Status</dt><dd>${escape(labels.status[order.status] ?? order.status)}</dd></div>
                    <div><dt>Payment</dt><dd>${escape(
                        labels.payment[order.payment_status] ?? order.payment_status,
                    )}${order.is_cod ? ' · cash on delivery' : ''}</dd></div>
                </dl>
            </header>

            <section class="parties">
                <div>
                    <h2>Billed to</h2>
                    <p>${escape(order.customer?.name ?? 'Walk-in customer')}</p>
                    ${order.customer?.email ? `<p class="muted">${escape(order.customer.email)}</p>` : ''}
                </div>
                ${
                    ship.length
                        ? `<div>
                                <h2>Delivered to</h2>
                                ${ship.map((line) => `<p>${escape(line)}</p>`).join('')}
                           </div>`
                        : ''
                }
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

            <table class="totals">
                ${totalRow('Subtotal', amount(order, order.native.subtotal))}
                ${order.native.discount > 0 ? totalRow('Discount', `−${amount(order, order.native.discount)}`) : ''}
                ${totalRow('Shipping', amount(order, order.native.shipping))}
                ${totalRow('Tax', amount(order, order.native.tax))}
                ${totalRow(`Total (${order.native.currency})`, amount(order, order.native.total), true)}
            </table>

            ${order.notes ? `<section class="notes"><h2>Note</h2><p>${escape(order.notes)}</p></section>` : ''}
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
    @page { size: A4; margin: 16mm; }
    body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; color: #1b1f24; font-size: 10.5pt; }
    .doc { page-break-after: always; }
    .doc:last-child { page-break-after: auto; }
    .doc-head { display: flex; justify-content: space-between; gap: 16mm; align-items: flex-start;
                border-bottom: 2px solid #1b1f24; padding-bottom: 6mm; margin-bottom: 8mm; }
    .doc-type { text-transform: uppercase; letter-spacing: .18em; font-size: 8.5pt; color: #6b7280; }
    h1 { font-size: 17pt; margin-top: 2mm; }
    .doc-meta { display: grid; grid-template-columns: auto auto; gap: 1mm 6mm; font-size: 9.5pt; }
    .doc-meta div { display: contents; }
    .doc-meta dt { color: #6b7280; }
    .doc-meta dd { text-align: right; font-weight: 600; }
    .parties { display: flex; gap: 16mm; margin-bottom: 8mm; }
    .parties h2 { font-size: 8.5pt; text-transform: uppercase; letter-spacing: .12em; color: #6b7280; margin-bottom: 2mm; }
    .parties p { line-height: 1.45; }
    .muted { color: #6b7280; }
    table { width: 100%; border-collapse: collapse; }
    .items th { text-align: left; font-size: 8.5pt; text-transform: uppercase; letter-spacing: .08em;
                color: #6b7280; border-bottom: 1px solid #d5dae1; padding: 0 0 2mm; }
    .items td { padding: 2.5mm 0; border-bottom: 1px solid #eef1f4; vertical-align: top; }
    .item-name { font-weight: 500; }
    .item-sku { font-size: 8.5pt; color: #6b7280; margin-top: .5mm; }
    .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .totals { width: 70mm; margin-left: auto; margin-top: 5mm; }
    .totals .label { color: #6b7280; padding: 1.5mm 0; }
    .totals .grand td { border-top: 1.5px solid #1b1f24; padding-top: 2.5mm; font-size: 12pt; font-weight: 700; }
    .notes { margin-top: 8mm; }
    .notes h2 { font-size: 8.5pt; text-transform: uppercase; letter-spacing: .12em; color: #6b7280; margin-bottom: 2mm; }
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
export function printOrderDocuments(orders: PrintableOrder[], kind: DocumentKind, labels: Labels): boolean {
    if (orders.length === 0) {
        return false;
    }

    const win = window.open('', '_blank');

    if (!win) {
        return false;
    }

    const body = orders
        .map((order) => (kind === 'invoice' ? invoiceBody(order, labels) : receiptBody(order, labels)))
        .join('');

    win.document.write(`<!DOCTYPE html>
        <html><head><meta charset="utf-8">
        <title>${kind === 'invoice' ? 'Invoice' : 'Receipt'} — ${escape(
            orders.length === 1 ? (orders[0]?.order_number ?? 'Order') : `${orders.length} orders`,
        )}</title>
        <style>* { margin: 0; padding: 0; box-sizing: border-box; }${
            kind === 'invoice' ? INVOICE_CSS : RECEIPT_CSS
        }</style>
        </head><body>${body}</body></html>`);

    win.document.close();
    win.focus();

    // The print dialog is opened once the document has actually laid out;
    // calling it immediately prints a blank page in several browsers.
    win.onload = () => {
        win.print();
    };

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
