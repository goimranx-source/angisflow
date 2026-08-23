# Places

Countries, sub-divisions and areas, keyed by the codes shops store them under.

Read through `App\Domain\Integrations\Support\Geography`, never by opening these
files directly — it handles what real orders carry (stray spaces, wrong case, a
district arriving without its country, a name where a code was expected) and
returns `null` rather than throwing for anything it does not know.

## Layout

```
countries.json                  shared by every platform
subdivisions/
  woocommerce/bd.json           one folder per code scheme
areas/
  bd.json                       one file per country
```

The three levels are split this way because they diverge differently.

**Countries are shared.** WooCommerce, Shopify and Webflow all use ISO alpha-2,
so `BD` is `BD` wherever it came from. Nothing to split.

**Sub-divisions are per platform**, under `subdivisions/<scheme>/`. Which scheme
a connection uses is decided by `AddressScheme`. Add a folder and a line in its
map to give a platform its own list; nothing else changes.

**Areas belong to no platform.** Wherever a third level exists — thana, upazila,
barangay, ward — a shop owner added a plugin that invented its own codes. Keyed
by country, so a second business with a different plugin gets a different file
rather than a different scheme.

## WooCommerce is not consistent with itself

Worth knowing before assuming any pattern holds:

| | Countries | Example |
|---|---|---|
| `CC-NN` prefixed | 23 | `BD-58` = Satkhira |
| bare | 46 | `CA` = California, `ON` = Ontario |

A prefixed code carries its own country, so it resolves alone. A bare one does
not, and `CA` is genuinely ambiguous — Canada as a country, California as a
state of the US. `Geography` reads a bare code as a sub-division when a country
is known and as a country otherwise, and refuses rather than guessing.

## Names, not only codes

Every lookup falls back to matching the written name with case and punctuation
set aside, so `coxs bazar` finds `Cox's Bazar`. This is what lets Webflow work
with no code list at all, and covers the Shopify codes these files happen not to
carry. `Geography::codeForName()` goes the other way, for writing a value back to
a shop that wants a code.

## What is here

| Path | Holds | Count |
|---|---|---|
| `countries.json` | code → name | 250 |
| `subdivisions/woocommerce/<cc>.json` | code → name | 69 files, 2,040 total |
| `areas/bd.json` | district → { area code → name } | 581 across 64 districts |

181 of the 250 countries have no sub-divisions. That is represented by the file
not existing, and a missing file reads as an empty list rather than a fault.

## Where each came from

**`countries.json` and `subdivisions/woocommerce/`** — read from a live
WooCommerce shop's `GET /wp-json/wc/v3/data/countries`. Taken from WooCommerce
rather than an ISO list on purpose: these have to match what actually arrives on
an order, and where the two differ, WooCommerce is the one that is right for
this job.

**`areas/bd.json`** — from `woocommerce-address-field-manager`, the address
plugin this business wrote. The plugin itself is deliberately not part of this
repository.

## Refreshing

Country and sub-division lists change when a country does — rarely, and worth
doing deliberately rather than on a timer. Re-read from a connected WooCommerce
shop and rewrite the files; the shape is `{"CODE": "Name"}` sorted by code, and
`Geography` sorts by name for display.

The area list follows its plugin: copy `data/thana.json`, sort it, write it as
`areas/<cc>.json`. The shape is already what is wanted.

## Adding a platform

1. Create `subdivisions/<platform>/` with one `<cc>.json` per country it covers.
2. Add `'<platform>' => '<platform>'` to `AddressScheme::SCHEMES`.

Only do this if the platform's codes genuinely differ. If it sends names, or ISO
codes, the name fallback already handles it and a second copy of the same data
is a second thing to keep current.
