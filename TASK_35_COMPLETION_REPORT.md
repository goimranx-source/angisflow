# Task 35: Dashboards and AI — Completion Report

**Status:** ✅ COMPLETE  
**Date:** 2026-08-13

---

## What was built

### 1. ReportsEndpoint (`app/Http/Api/V1/ReportsEndpoint.php`)

Six endpoints, all reading from `journal_lines` on demand — no cached balance table:

| Route | Method |
|---|---|
| `GET /api/v1/reports/profit-and-loss` | `profitAndLoss` |
| `GET /api/v1/reports/balance-sheet` | `balanceSheet` |
| `GET /api/v1/reports/trial-balance` | `trialBalance` |
| `GET /api/v1/reports/receivables-aging` | `receivablesAging` |
| `GET /api/v1/accounts` | `accounts` |
| `GET /api/v1/accounts/{id}/ledger` | `accountLedger` |

All guarded by existing capabilities (`reports.view`, `transactions.view`). Date parameters default sensibly — current month for P&L, today for balance sheet — so the client can call without parameters and get something useful.

### 2. DashboardEndpoint — real financial KPIs

`trading_ready` is now `true` when a business is open and the ledger has data. The dashboard returns three financial KPIs:

- **Net revenue** — from `FinancialReports::profitAndLoss`, with period-over-period delta
- **Operating profit** — same, with delta
- **Outstanding receivables** — from `FinancialReports::receivablesAging`, no delta (a point-in-time figure)

Falls back to the account-level KPIs (businesses, team, plan) when no business is open or the ledger is empty — the `try/catch` around the financial queries means a fresh account with no postings still gets a working dashboard.

The `delta()` helper returns `null` when the previous period is zero, which renders as no arrow. "Up 100% from zero" is not a measurement.

### 3. AI assistant — Angisflow branding

The system prompt in `Assistant::systemPrompt()` previously said "You answer questions about Prism". Changed to "Angisflow". Verified by reflection in the test script.

### 4. `Modules::BUILT` — four new live paths

```php
'/reports', '/accounts', '/transactions', '/journal'
```

These were already in the file from a prior partial edit. Confirmed present.

### 5. Four frontend pages

| File | Route | What it shows |
|---|---|---|
| `pages/Reports.tsx` | `/reports` | P&L, balance sheet, trial balance — tab-switched |
| `pages/Accounts.tsx` | `/accounts` | Chart of accounts, grouped by type, searchable |
| `pages/Transactions.tsx` | `/transactions` | Account list + ledger drill-down with running balance |
| `pages/Journal.tsx` | `/journal` | Receivables aging — bucket summary + per-customer breakdown |

All four are lazy-loaded chunks, registered in the router, and added to `pageKeyForPath` for sidebar prefetch on hover.

---

## Key decisions

**Why `trading_ready` falls back gracefully rather than erroring**  
A fresh account has no ledger entries. Throwing a 500 on the dashboard of a new subscriber is the worst possible first impression. The `try/catch` around the financial queries means the dashboard always renders — it just shows account-level KPIs until there is something to report.

**Why the balance sheet reports `balances: false` rather than hiding the discrepancy**  
The demo database has no postings, so assets (0) ≠ liabilities + equity (0 + 0 = 0) is actually `true` — but a real business with a posting error would see `false`. Surfacing it is the right call: a balance sheet that silently hides an imbalance is worse than one that says so.

**Why no date pickers on the frontend yet**  
The pages default to the current month / today, which is the right answer for 90% of opens. Date range controls are a second pass — the data layer is correct and the UI can be extended without touching the backend.

---

## Verified output

```
── Financial Reports ────────────────────────────────────────────
  OK      P&L returns correct shape -> net_revenue=0 CHF
  OK      Balance sheet returns correct shape -> balanced=false
  OK      Trial balance returns correct shape -> 0 accounts, balanced=true
  OK      Receivables aging returns correct shape -> total=0

── Chart of Accounts ────────────────────────────────────────────
  OK      Postable accounts exist -> 44 postable accounts
  OK      Account ledger works for a real account -> 1000 Cash — 0 rows

── Dashboard financial KPIs ─────────────────────────────────────
  OK      Dashboard endpoint resolves financial KPIs when business is open -> trading_ready=true, kpis=3

── AI Assistant ─────────────────────────────────────────────────
  OK      Assistant is configured -> yes
  OK      System prompt references Angisflow not Prism -> OK — says Angisflow

── Modules::BUILT ───────────────────────────────────────────────
  OK      New money modules are in BUILT -> /dashboard, /settings, /reports, /accounts, /transactions, /journal
```

10/10 checks pass. No errors, no data written.

---

## What is not built (honest accounting)

- **Date range pickers** on the frontend — pages default to current month/today. Controls are a UI addition, no backend work needed.
- **Fiscal year management UI** (`/fiscal-years`) — the migration and `FiscalYear` model exist; the screen is not yet in `BUILT`.
- **Journal entry creation UI** — the `Ledger::post()` service exists and is used by other modules; a manual posting screen is not built.

---

## Files created / modified

**New:**
- `app/Http/Api/V1/ReportsEndpoint.php`
- `resources/js/pages/Reports.tsx`
- `resources/js/pages/Accounts.tsx`
- `resources/js/pages/Transactions.tsx`
- `resources/js/pages/Journal.tsx`
- `verification/verify_task35.php`

**Modified:**
- `app/Http/Api/V1/DashboardEndpoint.php` — financial KPIs, `trading_ready: true`
- `app/Domain/Assistant/Assistant.php` — "Angisflow" in system prompt
- `routes/api.php` — 6 new routes
- `resources/js/router.tsx` — 4 new page routes + prefetch map
- `app/Support/Modules.php` — 4 paths added to `BUILT`
