# Prism

A subscription ERP for cash-on-delivery commerce. Laravel 12 API + React 19 SPA.

Rebuilt from the first version of Prism with one governing constraint: it has to
stay fast at a million subscribers and billions of operations, and it has to
*feel* like it never navigates.

---

## Running it

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
npm run build && php artisan serve
```

Demo login: `owner@prism.local` / `prism-dev-password`

For development with hot reload, run `npm run dev` alongside `php artisan serve`.

---

## The shape of it

The server renders **one** HTML document. Everything else is JSON.

```
GET  /                     the shell, with the session inlined
GET  /verify-email/{…}     a signed link out of an inbox
GET  /api/v1/*             everything else
```

`routes/web.php` is fourteen lines. `routes/api.php` is the product.

### Why it feels instant

Four separate decisions, and all four are needed:

| | |
|---|---|
| **The session arrives with the document** | The usual SPA fetches HTML, then the bundle, then asks *"who am I"* — and that third request cannot start until the JavaScript is already running. Prism inlines the answer into `<script id="prism-boot">`, so the first React render already has the user, the business, the capabilities and the whole menu. |
| **The shell never unmounts** | `AppLayout` is a layout *route*. Only `<Outlet/>` changes on navigation, so the sidebar keeps its scroll position and open sections, and the browser never re-lays-out the frame. |
| **Pages are prefetched on hover** | Hovering a sidebar link fetches that page's chunk *and* warms its query. The gap between hover and click is longer than either takes, so the click paints on the next frame with the network silent. |
| **The rail width is set before CSS parses** | An inline script in `<head>` reads an unencrypted cookie and stamps `rail-collapsed` on `<html>`. Without it, a collapsed sidebar paints open and snaps shut on every cold load. |

Measured on the dev server, cold: `domInteractive` 296 ms, first paint 360 ms,
11 kB of HTML. Navigation after that makes **no page request at all** — only the
page's chunk (once) and its data.

---

## Folder structure

```
app/
├── Domain/                    business logic, framework-light
│   ├── Identity/              who signs in
│   │   ├── Actions/           RegisterAccount — the whole signup, one transaction
│   │   ├── Models/            User, Role
│   │   └── Support/           TwoFactor, AuthEventRecorder, DefaultRoles
│   ├── Tenancy/               the subscriber boundary
│   │   ├── Concerns/          BelongsToAccount — the trait that enforces it
│   │   ├── Models/            Account, Business
│   │   ├── Scopes/            AccountScope
│   │   └── TenantContext      who this request may touch
│   ├── Billing/               Plan, Subscription
│   └── Shared/
│       ├── Concerns/          HasPublicId
│       ├── Jobs/              TenantJob — the base every queued job extends
│       └── ValueObjects/      Money
│
├── Http/
│   ├── Api/                   ← the entire client-facing surface
│   │   ├── Endpoint.php       base class
│   │   ├── ApiResponse.php    envelopes and cache headers
│   │   └── V1/
│   │       ├── Auth/          Login, Register, Password, TwoFactor, Passkey, EmailVerification
│   │       ├── Bootstrap      session + menu
│   │       ├── Dashboard, Profile, Business, Billing, Health
│   ├── Controllers/           SpaController only — the one HTML route
│   ├── Middleware/            ResolveTenant, RequireTwoFactor, Idempotency, …
│   └── Requests/
│
├── Providers/                 App, Tenancy, Authorization, RateLimit
└── Support/                   Capabilities, Modules, Navigation, BootPayload, Landing

resources/js/
├── app.tsx                    mount
├── router.tsx                 route table, lazy pages, guards
├── layouts/                   AppLayout (the shell), GuestLayout
├── pages/                     one file per screen, one chunk each
├── components/
│   ├── shell/                 Sidebar, Topbar, BusinessSwitcher, ProfileMenu, Toasts
│   └── ui/                    Button, Field, Icon, PageHeader
├── hooks/                     useApiForm, useRail, useModules
├── lib/                       api, boot, query, toast, webauthn, utils
├── providers/                 SessionProvider
└── types/                     the API contract, typed once
```

`app/Http/Api` is the answer to "what can the front end call" — list the folder.

---

## Database

Schema runs unmodified on SQLite (dev) and MySQL (production). The only
MySQL-specific migration checks the driver and skips itself elsewhere.

**Conventions**

- `id` — `BIGINT` auto-increment. Sequential, so InnoDB appends instead of
  splitting pages. Never leaves the server.
- `public_id` — `CHAR(26)` ULID, unique. What URLs and the API carry. Does not
  tell a competitor how many orders you took last month.
- `*_minor` — money as a whole number of the smallest unit. Never `DECIMAL`,
  never `FLOAT`. See `Domain/Shared/ValueObjects/Money`.
- Every tenant table carries `account_id`, and **every index leads with it**.

**Tables**

`accounts` · `plans` · `subscriptions` · `roles` · `users` · `businesses` ·
`settings` · `media_items` · `exchange_rates` · `activity_events` · `auth_events` ·
`sessions` · `password_reset_tokens` · `personal_access_tokens` ·
`webauthn_credentials`

`activity_events` and `auth_events` are append-only and RANGE-partitioned by
month on MySQL, so dropping old history is `DROP PARTITION` (instant) rather than
a `DELETE` that runs for a day.

---

## Multi-tenancy

One schema, one `account_id`, indexes led by it. A database per subscriber is a
million schemas to migrate; a shared schema keyed on the tenant means the
database only ever reads the slice of an index belonging to one subscriber — so
a billion-row table answers as fast as a thousand-row one.

Isolation is enforced in **one** place. `BelongsToAccount` adds a global scope on
read and stamps `account_id` on write. Forgetting a `where` clause is not
possible; you would have to call `acrossAllAccounts()`, which is named to be
conspicuous in a code review.

Permissions are a JSON array on the role, cached by role id. The textbook
`roles → role_permissions → permissions` join runs on every guarded element of
every page — billions of times a day to answer a question that changes twice a
year.

---

## Built for load

What is in the code:

- **Queue-first writes** — `TenantJob` carries the tenant, re-establishes it in
  the worker, and tears it down after. A request writes a row and returns; the
  work happens on machines that are not serving anybody's screen.
- **Per-entity serialisation** — `serialisationKey()` puts work on the *same*
  order behind a lock while a million different orders still run in parallel.
- **Idempotency** — `Idempotency-Key` on a POST makes a retry harmless. A load
  balancer retrying a timed-out request cannot turn one payment into two.
- **Per-account rate limits** — one subscriber's runaway script cannot consume
  everybody else's capacity. IP limits do not solve this; the traffic is
  legitimate and authenticated.
- **Read/write split** — declared in `config/database.php` with `sticky`, so
  reads go to a replica and read-after-write still works.
- **Readiness probe** — `/api/v1/health` returns 503 when the database or cache
  is unreachable, so a balancer drains a sick node instead of feeding it.
- **Stateless servers** — no local state, so any node can serve any request.

What you must provision (see the deployment notes below):

- a load balancer in front of two or more app servers
- Redis for sessions, cache and queue
- queue workers as their own scalable pool
- a CDN for `/build/*`
- a MySQL read replica

---

## Deployment

```env
SESSION_DRIVER=redis      # a DB session table is written on every request
CACHE_STORE=redis
QUEUE_CONNECTION=redis
DB_READ_HOST=replica.internal
ASSET_URL=https://cdn.example.com
```

```bash
php artisan migrate --force
npm run build
php artisan config:cache route:cache view:cache
php artisan queue:work --queue=default --tries=3
```

Point the balancer's health check at `/api/v1/health`, not `/up` — the latter
only proves PHP is running, and will happily keep sending traffic to a node whose
database connection has died.

---

## What is built

Authentication, the tenancy and subscription core, and the shell.

- Sign in / sign up (creates account + trial + owner + first business + roles)
- Password reset, confirmation, change
- TOTP two-factor with hashed one-shot recovery codes
- Passkeys (WebAuthn) for both sign-in and account management
- Email verification — a prompt, never a wall
- Sidebar, header, business switcher, profile menu, toasts
- Dashboard reading live account figures
- **Settings** — appearance (name, tagline, logo, collapsed mark, favicon), currency
  (base, manual or fetched rates, providers), the media library, and an honest
  signpost where Integrations will go

The trading modules — Orders, Ledger, Catalogue, People, Partners — are mapped
out in `app/Support/Modules.php` and appear in the menu with a **Soon** badge,
each with its own page explaining what it will do. A screen goes live by adding
one path to `Modules::BUILT`.
