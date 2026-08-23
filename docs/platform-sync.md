# Two-way sync: how it works, and what each platform needs

This is the reference for connecting a shop to Angisflow and keeping both sides
in step. Read part 1 and 2 once; parts 4 and 5 are per-platform and meant to be
looked up when you build one.

**A note on trust.** Three platforms have been built and used against their real
API. Everything else in part 4 comes from the platform's own published
documentation, not from a connection made here. Where that distinction matters
it is marked, because a guess presented as a certainty is how somebody wastes a
day.

---

## 1. How sync works here

Two directions, and they are not symmetrical.

**Bringing in (pull).** We call the shop, ask what changed since last time, and
turn each record into ours. Runs on a schedule, and again whenever the shop
tells us something changed.

**Sending out (push).** Something changes here, so we tell the shop. Queued, not
done inside the request, because a shop can take seconds to answer and nobody
should wait.

### The link

Every synced record has a row in `integration_links` tying *our* id to *their*
id, per connection. It is the single most important row in the system:

- Without it, a push has nothing to update and **creates a duplicate instead**
- It carries `push_fingerprint`, used to recognise our own change coming back
- It carries `push_pending_at`, our record that the shop has not accepted a
  change yet

A product listed in three shops has three links. An order has one, because it
was placed in exactly one place.

### Echo suppression

When we push a change, the shop usually fires a webhook back describing that
same change. Applied naively, that re-imports our own edit as though the shop
made it, and two connected systems talk in a circle forever.

We fingerprint what we sent. An inbound payload matching the fingerprint is
recognised as our own echo and dropped. Compared by *content*, not by a time
window — a window wide enough to cover the echo is also wide enough to swallow a
genuine edit made seconds later, and that edit is somebody's real work.

### Unsent changes

When a record changes here, its link is marked `push_pending_at` **in the same
transaction as the change**. Only a push that actually succeeds clears it.

This exists because the failures that hurt are the ones that never happen: a
queue with no worker, a job lost to a restart, a process killed mid-request.
None of those produce an error to record. Marking the debt first turns silence
into a visible backlog — the order row shows a cloud icon, or a red warning with
the shop's own reason.

`integrations:retry-pushes` sweeps up anything still owed after five minutes,
but only where no error was recorded. A shop that refused is left alone: asking
again on a timer is only being refused on a timer.

---

## 2. Field mapping and direction

Nothing is hard-coded to a platform. A connection has a list of mappings, each
saying: *their field* ↔ *our field*, with a direction and a transform.

### The three directions

| In the UI | Meaning |
|---|---|
| **Bring only** | Read from the shop. Never sent back. |
| **Push only** | Sent to the shop. Their value is ignored. |
| **Both ways** | Read and sent. |

Choose deliberately:

- **Bring only** for anything the shop computes — totals, tax, its own order
  number. Sending those back is at best ignored and at worst rejected.
- **Push only** for something we are authoritative about that the shop should
  display but never overwrite.
- **Both ways** for anything a human might edit on either side: status, address,
  customer details, notes.

### Fields the platform owns

Some fields are refused regardless of direction, listed in
`EntityFields::platformOwned()`:

```
number, ordered_on, channel, external_ref,
subtotal_minor, discount_minor, shipping_minor,
tax_minor, total_minor, paid_minor
```

The shop issued the number and derives money from its own lines. **To change
what an order costs, change what is on it** — push the line items and let the
shop recompute. A total sent directly is discarded.

`currency` is deliberately *not* on this list: WooCommerce accepts it, and being
stricter than the platform helps nobody.

### Transforms

Each mapping carries a type, used in both directions — applied on the way in,
reversed on the way out. `money_minor` respects each currency's precision (yen
has no minor unit, dinars have three). `image`, `video`, `file` and `rich_text`
survive rather than being flattened.

### Nulls are never sent

A null here means *we hold nothing for this*, but a shop reads it as *set this
to nothing*. An order imported without a postcode would push back an
instruction to erase the postcode the shop itself has. Unknown values are
omitted.

This is not theoretical: WooCommerce answers a null inside the shipping object
with `Invalid parameter(s): shipping` and **rejects the whole request** — so one
unknown city meant the status change travelling with it never landed either.

---

## 3. Where we actually stand

| Entity | Bring in | Send back |
|---|---|---|
| **Orders** | ✅ working | ✅ working |
| **Products** | ✅ working | ❌ **never fires** |
| **Customers** | ✅ working | ❌ never fires |

### The product gap

`PushIntegrationRecord` already knows how to push a product — it loads the
product with its variants and maps it. But **nothing ever dispatches that job**.
`PushDispatcher` has only `order()` and `jobsFor(Order)`, and no observer
watches products.

So today: edit a product here and the shop never hears. It is a wiring gap, not
a missing feature.

**To close it:**

1. `PushDispatcher::product(Product $p)` and `jobsFor(Product $p)`, mirroring the
   order pair, marking `push_pending_at` on each product link
2. Dispatch after a product or variant is saved — an observer, or an explicit
   call from the endpoints that edit them
3. Confirm `LineItems`-equivalent handling for variants: a variant removed here
   must be removed there, not silently left behind
4. Decide what is platform-owned for products. At minimum a shop's own
   `slug`/`handle` is its public URL — rewriting it breaks every link to that
   page that exists anywhere

Same for customers, though the case for pushing those is weaker.

---

## 4. Platform reference

Grouped by what is required to make two-way work, because that is what decides
the order of the work.

### 4.1 Built and used against the real API

#### WooCommerce — *verified*
- **Auth**: consumer key + secret, HTTP Basic over HTTPS
- **Base**: `https://shop.tld/wp-json/wc/v3`
- **Orders**: `GET /orders`, `PUT /orders/{id}` — two-way
- **Products**: `GET /products`, `PUT /products/{id}` — two-way *once wired*
- **Webhooks**: yes, HMAC-SHA256 in `X-WC-Webhook-Signature`
- **Line items**: send `line_items[]`; **omitting a line does not delete it** —
  send `{id, quantity: 0}` to remove. Read the remote order first to know what
  is there.
- **Quirk**: rejects `null` inside address objects, failing the whole request.

#### Shopify — *verified*
- **Auth**: Admin API access token in `X-Shopify-Access-Token`
- **Base**: `https://{shop}.myshopify.com/admin/api/{version}`
- **Orders**: `GET/PUT /orders.json` — two-way, but many fields are read-only
  once an order exists; line-item edits require the newer `orderEditBegin`
  GraphQL flow
- **Webhooks**: HMAC-SHA256, base64, in `X-Shopify-Hmac-Sha256`
- **Quirk**: version pinned in the URL; versions retire yearly.

#### Webflow — *verified*
- **Auth**: bearer token
- **Orders**: readable and updatable; catalogue is limited
- **Quirk**: one-way in practice for most fields.

### 4.2 Should work on the current client — *documented, untested*

Static credential, REST/JSON, page-number paging. Connect one and check the
mapping.

| Platform | Auth | Notes |
|---|---|---|
| **BigCommerce** | `X-Auth-Token` header | Base includes the store hash: `/stores/{hash}/v3`. Orders `v2`, products `v3`. |
| **Ecwid** | Bearer | `/api/v3/{storeId}`. Clean REST. |
| **Squarespace** | Bearer | Orders readable; **writing back is very limited** — treat as Bring only. |
| **Wix** | API key **+ site ID header** | Needs *two* headers; the client offers one. Small fix. |
| **Medusa** | Bearer / API key | Admin API is plain REST. Good two-way candidate. |
| **Swell** | Basic (store id + key) | Clean REST. |
| **ShopBase** | Bearer | Shopify-shaped, so Shopify field maps mostly apply. |
| **Big Cartel** | Basic | Small API; orders read-mostly. |
| **Shift4Shop** | Token headers | Private-app token. |
| **Trendyol** | Basic | Supplier-scoped; orders in, status out. |
| **Clover** | Bearer | Merchant-scoped `/v3/merchants/{mId}`. |

### 4.3 One fix each — *do these first*

| Platform | Problem | Fix |
|---|---|---|
| **PrestaShop** | Returns **XML** by default | Force `output_format=JSON` on every request. Webservice key goes in the username, password blank. |
| **Magento** | List sits under `items`; filters use `searchCriteria[...]`; `page`/`per_page` do nothing | Configurable list key and paging parameter names. |
| **Square** | Orders are **not** a `GET` list — `POST /v2/orders/search` | Allow a POST body for pulling. |

All three are cured by the same change: **make the reader configurable** rather
than assuming one shape. That also makes 4.2 far more likely to work on real
installs.

### 4.4 Need refreshing credentials

These issue a token that **expires**, typically in an hour, with a refresh token
to get another. Pasting one today means it stops working before lunch.

**eBay · Etsy · Shopware · Salla · Zid · Lightspeed Retail · commercetools · nopCommerce**

Needed: an OAuth 2 flow — send the user to the platform to approve, exchange the
code for tokens, store both, refresh *before* expiry, and re-authorise cleanly
when a refresh token is revoked. One build serves all eight.

Notes: Shopware and commercetools use client-credentials (no user consent step,
simpler). Salla and Zid are authorisation-code with good webhook support, which
makes them strong two-way candidates once this exists.

### 4.5 Need a signature on every request

Not one login — a computed stamp on *each* call.

| Platform | Scheme |
|---|---|
| **Amazon SP-API** | LWA token **plus** AWS SigV4; restricted-data tokens for buyer info |
| **Daraz** | App key/secret, HMAC-SHA256 over sorted parameters |
| **Lazada** | Same family as Daraz |
| **Shopee** | HMAC-SHA256 over path + timestamp + token |
| **Jumia** | Vendor API, signed requests |

The plumbing is shared; the recipe differs per platform. For a Bangladesh-first
product, **Daraz is the one that matters** — and it is the least work of the
five.

### 4.6 Not REST

**Saleor · Vendure** — GraphQL only. Needs a query transport and per-entity
documents. Build only on demand.

### 4.7 Nothing we build will help

- **OpenCart** — no API in core; the shop owner must install an extension
- **Drupal Commerce** — entirely site-dependent
- **noon** — gated partner API; they must approve you

---

## 5. Order of work

1. **Configurable reader** — list key, paging parameter names, POST-body pulls.
   Fixes 4.3 outright and de-risks all of 4.2.
2. **Wire product push** — the job exists; give it a dispatcher and an observer.
   Closes the biggest honesty gap in the product today.
3. **OAuth 2 with refresh** — unlocks eight platforms in one build.
4. **Request signing** — start with Daraz.
5. **GraphQL** — only when asked.

---

## 6. Checklist for adding a platform

1. Read the platform's **own** documentation for auth, order read/write, product
   read/write, and webhook signing. Do not infer from a similar platform.
2. Add it to `PlatformPresets` with an honest `support` level.
3. Default field maps: start from the nearest family, then correct against real
   payloads.
4. Mark platform-computed fields **Bring only** — totals, tax, their order
   number.
5. Test the round trip: change it there → appears here; change it here →
   appears there; and confirm the echo does **not** bounce back a third time.
6. Test a removal, not just an edit. Removals are where line-item sync fails.
7. Only then raise `support` to `built`.
