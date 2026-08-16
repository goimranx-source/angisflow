# Prism — build handoff

You are continuing an in-progress build. This document is the whole context you
need. Read it before touching anything.

**Project root:** `D:\Povaly Group\Applications\prism-new\prism-erp`
**Reference codebase:** `D:\Povaly Group\Applications\prism` — an earlier version
the owner built. Read it for domain ideas; do not copy its structure. Several of
its designs were actively wrong and are called out below.

---

## 1. What this is

A multi-tenant SaaS ERP. One subscriber runs their whole business in it:
inventory, accounting, orders, delivery, messaging, staff. Aimed at every kind
of business (retail, service, offline, online) in **every country** — which is
why nothing is hardcoded to one market.

The owner is not a developer. They describe outcomes, not implementations, and
have explicitly said: *"I have no idea about this, so you should think and
research about this and then make decision what is best and industry standard
and professional."* Treat that as standing instruction — make the professional
call, and explain the reasoning.

**Stack:** Laravel 12 · React 19 SPA · React Router v8 · TanStack Query v5 ·
Tailwind v4 · SQLite in dev.

---

## 2. Non-negotiable architecture

These are decided. Do not relitigate them; a lot of the codebase assumes them.

### Money is integer minor units, always
`App\Domain\Shared\ValueObjects\Money` — `minor` (int) + `currency`. Never float,
never decimal-as-string. `MoneyCast` persists it with its currency. Quantities
*are* `DECIMAL(18,4)` — stock is genuinely fractional; money is not.

### Two identifiers on every row
`id` (BIGINT, for FKs and indexes) and `public_id` (ULID, for URLs and API).
Trait: `HasPublicId`. Never expose `id`.

### Tenancy is two layers
- `BelongsToAccount` — the **security** boundary (one subscriber's data).
- `BelongsToBusiness` — the **accounting** boundary (one set of books).

A missing `account_id` is filled from context. A missing `business_id` **throws**
— quietly posting into whichever book was last opened is how a month's figures
end up in the wrong place, and nobody notices until year-end.

Anything unauthenticated (webhooks, chat widget) must set tenancy explicitly
from the request; no middleware has.

### Services own the invariants; models do not
`Ledger`, `StockService`, `OrderService`, `Invoicing`, `Payments` etc. are the
only writers for their tables. Every rule that makes the data trustworthy is a
rule about a whole operation, and a caller writing rows directly gets none of
them. Follow this pattern for new work.

### The subscriber's vocabulary is data; ours is code
Repeated four times now and it should be repeated again:
- Module catalogue → tables
- Courier statuses → `ShipmentStatus` enum (ours, fixed) + mapping rows (theirs)
- Pipeline stages → rows, with a fixed `outcome` (open/won/lost)
- Bot rules → rows

A canonical vocabulary that each subscriber can redefine is not canonical, and
no report can span accounts. Flexibility belongs in the *mapping*.

### Store first, parse second (all inbound webhooks)
Write the raw body, answer 200, then try to understand it. A platform renaming a
field must not become a 500 that exhausts their retry queue and loses a day of
data. `webhook_deliveries` holds every inbound request including rejected ones.
Almost nothing returns an error — a non-2xx tells a retry queue to give up.

### Lazy loading is disabled
`Model::preventLazyLoading()` is on. Every relation must be eager-loaded. **This
has caused four separate bugs so far** — always check what a method reaches for
transitively (`lines.variant.product`, not `lines.variant`).

---

## 3. What is built (tasks 1–22 of 38)

Migrations `2026_08_10_*` and `2026_08_11_*`. All run and verified.

| Area | Key classes |
|---|---|
| Module catalogue, per-workspace enablement | `Catalogue\ModuleAccess`, `ModuleProvisioner` |
| Double-entry ledger, chart of accounts | `Ledger\Ledger`, `ChartOfAccounts`, `FinancialReports` |
| Invoicing, payments, allocation | `Sales\Invoicing`, `Sales\Payments` |
| AP: suppliers, bills | `Purchasing\Purchasing` |
| Product catalogue + variants + schema drivers | `Catalogue\ProductCatalogue`, `Catalogue\Schema\*` |
| Stock: batches, movements, reservations | `Stock\StockService` |
| BOM and production orders | `Production\ProductionService` |
| Orders, POS | `Sales\OrderService`, `Sales\PointOfSale` |
| Courier framework, adapters, dashboard, COD | `Delivery\*` |
| Returns and RTO | `Returns\ReturnService` |
| CRM: leads, deals, quotes | `Crm\CrmService` |
| Customer identity, merge, segments | `Sales\CustomerDirectory` |
| Risk scoring | `Risk\RiskScorer` |
| Omnichannel inbox | `Inbox\InboxService` |
| Live chat widget (backend) | `Inbox\WebchatService` |
| Messaging adapters | `Inbox\Adapters\MetaAdapter`, `GenericMessageAdapter` |
| Helpdesk, KB, bot | `Helpdesk\HelpdeskService` |

### Chart of accounts (memorise these codes — services reference them)
```
1000 Cash          1100 Bank         1200 Accounts Receivable
1250 Courier Receivable              1400 Inventory — Finished Goods
1410 Raw Materials 1420 Packaging
2000 Accounts Payable                2200 Tax Payable
4100 Sales Income  4150 Delivery Income
4900 Sales Returns (contra)          4950 Discounts Given (contra)
5000 COGS          5200 Inventory Write-off
6100 Rent … 6700 Courier Charge      6500 Other Expense
```

### Accounting rules already encoded — do not break these
- **Tax collected is a liability (2200), never revenue.**
- **A discount is contra-revenue (4950), never an expense.**
- **COGS posts at fulfilment, not at order.** Goods are stock until they leave.
- **COD delivery transfers a debt** (DR 1250 / CR 1200); cash appears only at
  remittance. Booking it as a cash sale is the single most common COD error.
- **Contra accounts grow on the opposite side to their type.** This caused a
  real bug where returns *added* to net revenue.
- **Posted entries are reversed, never deleted.**

---

## 4. Remaining tasks (23–38)

23 Employees, org structure, attendance · 24 Payroll · 25 Commissions and
rewards · 26 Partners, KYC, profit distribution · 27 Projects and timesheets ·
28 Bookings and scheduling · 29 Field service and fleet · 30 Localization packs ·
31 Roles and permissions · 32 Public API and integrations · 33 Storefront,
booking pages, customer portal · 34 Growth: campaigns, loyalty, reviews ·
35 Dashboards and AI · 36 Credential vault · 37 Entitlement resolution ·
38 Operator admin panel

**Ordering constraints:** 24 needs 23. 25 needs 23 + 15. 38 needs 37.
36 should ideally come before any new integration work — see below.

---

## 5. Known gaps — do not rediscover these, fix or respect them

1. **`/chat/widget.js` does not exist.** The embed snippet points at it; the
   whole backend works. The browser bundle belongs with task 33.
2. **Credentials are stored as `credential_ref` pointers to a vault that does
   not exist yet** (task 36). `courier_connections` and `inbox_channels` both
   have the column. Nothing should ever put a live token in a plain column.
3. **Perpetual inventory is half-wired.** Fulfilment correctly posts
   DR COGS / CR Inventory. But a *bill* for a stocked product still posts to
   whatever expense account the line names, so Inventory is never debited on
   purchase. Needs bills to know which lines are stocked products.
4. **Expense claims and budgets are schema-only** (in `2026_08_11_000003`). No
   services yet. Claims settle through payroll, so they pair with task 24.
5. **`tests/Feature/ExampleTest.php` fails** — stock scaffolding hitting `/`
   against an unmigrated in-memory SQLite. Pre-existing, unrelated, ignore it
   (or fix it properly, but don't let it mask a real failure).

---

## 6. Recurring traps

- **Eloquent's `Model::$connection`** — never name a relation `connection`. It
  silently returns the string `"sqlite"` and fails as a *warning*.
- **Detecting a batch in a payload** — check every element is an array *and* the
  keys are a list. Testing only the first element mistakes a nested object for a
  batch. Bit twice, in two different adapters.
- **Rates and percentages must be `null`, not `0`, when the denominator is
  meaningless.** A delivery rate of 0% on one parcel ranks a good courier below
  a bad one. Same for margins on zero revenue and helpfulness on two votes.
- **Durations must clamp at zero.** A pause longer than the elapsed span
  underflows, and a negative response time gets averaged into a report and
  quietly improves it.
- **Model defaults must match column defaults.** `Model::create()` leaves
  unspecified fields `null` in memory even though the DB stored a default.
- **A unique index is better than a heuristic.** Echo detection, webhook
  idempotency and till retries all use the platform's own id. Comparing text and
  timestamps fails exactly when two customers both say "ok".

---

## 7. How to work

The owner expects each task to be **built, verified against real data, and
reported honestly**. The established rhythm:

1. Read the old codebase for the domain idea where one exists.
2. Write the migration with the reasoning in the docblock — *why this shape, and
   what shape was rejected*. These comments are the deliverable as much as the
   code; write them for a competent reader who was not there.
3. Models, then a service that owns the invariants.
4. Write a verification script in the scratchpad that exercises the happy path
   **and the refusals**, and run it via `php artisan tinker --execute="require '…'"`.
5. Fix what it exposes. It has exposed a real bug in almost every task.
6. Delete the test data. Leave the demo database as you found it.
7. `php artisan test` and `php artisan optimize:clear`.
8. Report: what was built, the one or two decisions that mattered, the verified
   output, and any bug found — **stated plainly, including when the bug was
   yours**. If something is not built, say so; do not let them find it.

**Scratchpad:** `C:\Users\imran\AppData\Local\Temp\claude\D--Povaly-Group-Applications-prism-new\<session>\scratchpad`

### Verification harness that works

```php
$b = Business::withoutGlobalScopes()->whereNull('deleted_at')->where('account_id', 1)->first();
$t = app(TenantContext::class);
$t->setAccount(Account::withoutGlobalScopes()->find(1));
$t->setBusiness($b);

$try = function (string $label, callable $fn) {
    try { $out = $fn(); echo "  OK      {$label}".($out ? " -> {$out}" : '').PHP_EOL; }
    catch (Throwable $e) { echo "  REFUSED {$label} -> ".$e->getMessage().PHP_EOL; }
};
```

Demo data: account 1 = `owner@prism.local` / `prism-dev-password`, two
workspaces, one business. **Restore anything you change.**

---

## 8. Tone

Error messages are written for the person reading them, not the developer who
wrote them. Compare:

- ✗ `Validation failed on field short_code`
- ✓ `The short code RN is already used by another business.`
- ✓ `Only 50 of CEYLONTE can still be promised at Main Warehouse — 130 is on
  hand and 80 is already spoken for.`
- ✓ `A lost deal needs a reason — it is the only way to learn anything from it.`

Say what happened, in what quantity, and what to do next. Comments follow the
same rule: explain *why*, and what the alternative would have broken.
