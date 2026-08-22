# Angisflow — build roadmap

What is left to make this tool live, in the order it should be built and with the
reasoning behind that order. Kept in the repository rather than in a chat so the
next session — mine or anyone's — starts from the same picture.

Last updated: 2026-08-16

---

## Where things actually stand

The shell is finished and holds up: header, sidebar, context picker, settings,
and the dashboard. Those set the standard everything below is measured against —
design tokens rather than per-page CSS, money through one path, and every query
scoped so a business or currency switch cannot serve a stale answer.

**The module pages are not built.** This is the single most important thing to
understand before planning anything:

| Page | File | Endpoint |
|---|---|---|
| Orders | 834 lines | `/api/v1/orders` → **404** |
| Products | 1013 lines | `/api/v1/products` → **404** |
| Invoicing | 383 lines | `/api/v1/invoices` → **404** |
| Stock | — | `/api/v1/stock` → **404** |
| Payments | — | `/api/v1/payments` → **404** |
| Integrations | 707 lines | `/api/v1/integrations` → **404** |
| Customers | 520 lines | `/api/v1/customers` → **200** ✓ |

`modules.is_built = 1` in the database records intent, not reality. These pages
render a skeleton and then an error. Customers is the only one with a real
endpoint behind it.

There is a second, quieter fault: `Orders.tsx` formats money with a hardcoded
`currency: 'USD'`. Wiring it up without fixing that would show dollars whatever
the workspace reports in — the same class of bug removed from the dashboard.

So this is mostly **backend work**, with the front ends rebuilt onto shared
components rather than polished in place.

---

## The standard every page is held to

Set by the dashboard; not negotiable per page, because the moment two pages
disagree the product reads as assembled from parts of other products.

- **Money** through `useMoney()` only. Workspace reporting currency, symbol not
  code, compact on screen (`৳871.1K`) with the exact figure in the `title`.
  No page calls `Intl` currency formatting directly or hardcodes a code.
- **Query keys** carry `businessScope` and `moneyScope`, and both go into the
  URL. Without this, React Query and the browser's own 15-second cache both
  answer with the currency or business you just left — the fault that took a
  reload to correct.
- **Conversion at read time, never on write.** A record stores what actually
  changed hands, in its own currency. `CurrencyService` converts when it is
  read. A missing rate shows "No rate", never a figure converted at par.
- **Empty is not an error.** A shop that opened this morning and a failed
  request must not look the same.
- **Tokens, not literals.** No hex in a component. Both themes, every screen.
- **Tenancy** through `BelongsToAccount`; scoping is never re-derived per query.

---

## Order of work

Integrations come first, at the owner's direction: the tool is worth more
connected to a live shop than with more of its own screens.

### Phase 1 — Integrations (#12–#17)

Connect a **business** to any external platform, both directions.

- **#12 Driver layer and model.** `api_integrations` already has the right shape
  (business-scoped, `provider`, `configuration`, `field_mappings`,
  `sync_settings`, `bidirectional`, stats) with nothing behind it.
  A `PlatformDriver` interface, not a `match` statement — Prism branches on
  platform in five places, which makes a third platform a five-site edit and a
  bespoke site impossible without a commit. `GenericRestDriver` is configured
  from the UI so a custom Laravel shop is a form, not a release.
- **#13 Entity links and field mapping.** `integration_links` as its own table:
  one product can live on three stores under three external ids, which a column
  cannot express. Carry Prism's dot-path resolver — it already handles the
  key/value shapes platforms use for custom fields (WooCommerce `meta_data`,
  Shopify `note_attributes`/`metafields`) — and extend it to write as well.
- **#14 Pull sync.** Queued, cursor-based, idempotent on
  `(business_id, channel, external_ref)`. Writes through the domain models so a
  pulled order gets the same journal entries and stock movements a local one
  does; an import that skips the ledger produces books that do not balance.
- **#15 Push sync.** Echo detection by content fingerprint — Prism's approach and
  the right one. Blocking all webhooks for N seconds after a push also discards
  genuine edits made in that window. Conflict policy per integration, with the
  losing version kept.
- **#16 Inbound webhooks.** Per-integration URL and token, signature verified per
  driver, 200 immediately then queue, every delivery recorded and replayable.
- **#17 The page, and the connected-store selector.** The point of the feature:
  adding a product or order here offers the connected stores for this business
  so it is created in the right place.

### Phase 2 — Retail and e-commerce pages (#1–#11)

Retail & E-commerce turns on 39 modules; 27 nominally built, 12 not.

- **#1 The list-page kit.** First, because without it 27 pages each grow their
  own formatter and their own CSS — precisely how Prism ended up with inline
  `<style>` blocks and `#2563eb` hardcoded per page.
- **#2 Products** → **#3 Orders** → **#5 Invoicing** → **#6 Payments**, in that
  order: order lines need variants, invoices come from orders, payments settle
  invoices.
- **#4 Customers** sits early and unblocked — it already has an endpoint, which
  makes it the cheapest real test of whether the kit works against code that was
  not written for it.
- **#7 Stock** hangs off Products. **#8 Returns**, **#9 POS**, **#10 Courier**
  hang off Orders. Returns and COD/RTO are normal outcomes in this market, not
  edge cases — the schema already carries `is_cod` and a courier-receivable
  account for exactly that.
- **#11 Cross-page verification.** Deliberately its own task: every serious bug
  this session was a cross-page one, and none would have been caught by testing
  a page alone.

### Later

Categories beyond retail (hospitality, professional, field, wellness, education,
industrial, rental), and the 46 modules not built for any of them. The kit and
the integration layer are what make those cheap; neither should be shortcut to
reach them sooner.

---

## What to take from Prism, and what not to

Prism is `../prism/prism` — the previous build. Worth reading for what it
learned, not for how it was written.

**Take:**
- Content-fingerprint echo detection (`SyncEchoDetector`). Genuinely good.
- The dot-path field resolver, including the platform-specific custom-field
  array shapes.
- Screen options — per-page and column visibility — sortable headers, the inline
  status picker, and the settle-payment modal.
- Treating COD and RTO as ordinary.

**Leave:**
- The implementation. `orders/index.blade.php` is 1,525 lines with an inline
  `<style>` block and hex colours in it.
- `match ($platform)` branching for anything extensible.
- Integrations scoped to a user rather than a business.

---

## Demo data

`php artisan demo:trading --business=<public_id> --fresh` fills a business with
120 days of trading: 6 products, 12 customers, ~310 orders spread across trading
hours, invoices for the unpaid ones, and a balanced ledger.

Deliberately a command and not a seeder: it invents revenue, and a seeded
`৳18,500` reads as a real month to whoever opens the screen.
