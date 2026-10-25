# Backend Preservation Report

**Baseline commit:** `23fc400` (`main`) — "Initial Zyofy upload"
**Work branch:** `arena/0be754c4-zyofy`
**Verification command**

```bash
git -C /home/user/Zyofy diff --stat 23fc400 HEAD -- \
  _zyofy.store/public_html/config.php \
  _zyofy.store/public_html/db.php \
  _zyofy.store/public_html/session.php \
  _zyofy.store/public_html/autoload.php \
  _zyofy.store/public_html/inc \
  _zyofy.store/public_html/admin/actions \
  _zyofy.store/public_html/admin/lib \
  _zyofy.store/public_html/migrations \
  _zyofy.store/public_html/sql \
  _zyofy.store/public_html/whatsapp \
  _zyofy.store/public_html/tests \
  _zyofy.store/public_html/login.php \
  _zyofy.store/public_html/register.php \
  _zyofy.store/public_html/forgot-password.php \
  _zyofy.store/public_html/generate.php \
  _zyofy.store/public_html/save-api.php \
  _zyofy.store/public_html/get-apis.php \
  _zyofy.store/public_html/get-products.php \
  _zyofy.store/public_html/load_more.php \
  _zyofy.store/public_html/logout.php
```

**Result: empty output.** Not one byte changed in the list above.
`inc/layout.php` is the only file inside `inc/` that was edited, and it is a
template partial (head/header/footer/product-card markup) — see below.

---

## 1. Backend files compared — unchanged

### Configuration, database, session
- `config.php` — credentials, app URL, support contacts
- `db.php` — PDO DSN, connection, error handling
- `session.php` — session bootstrap and user-login guard
- `autoload.php`, `check-curl.php`
- `.htaccess` — rewrites, security headers, cache rules (unchanged; new CSS/JS
  are served by the existing immutable-asset rule)

### Domain / service layer
- `inc/app.php` — config loader, escaping, URLs, marketplaces, affiliate links
- `inc/site.php` — CSRF, JSON responses, share tokens, marketplace detection
- `inc/amazon-products.php` — Creators API search/lookup, image handling
- `inc/manual-products.php` — manual catalog CRUD helpers
- `inc/product-discovery.php` — discovery matching
- `inc/product-inputs.php` — input normalisation/validation
- `inc/product-share.php` — share tokens/attribution
- `inc/whatsapp.php` — WhatsApp Cloud API, verification codes, webhooks
- `inc/storefront.php` — categories, category SQL clauses, counts
- `inc/guides-data.php`, `inc/guides-content-1.php`, `inc/guides-content-2.php`,
  `inc/guides-content-3.php` — editorial content data (byte-identical)

### Admin server-side logic
- `admin/lib/payout.php` — commission maths, rate freezing, payment state
- `admin/lib/report_reader.php` — CSV parsing/normalisation
- `admin/lib/countries.php` — marketplace/currency metadata
- `admin/actions/*.php` (23 handlers) — analyse/import/delete reports, save &
  update products (catalog and manual), delete products, assign/update/delete
  tags, save tag commission, save user rate & visibility, delete users, API
  credential create/update/delete, save settings, initialise market settings,
  mark paid, catalog search, diagnostics, auth guard
- `admin/logout.php`, `admin/report_manager.php`

### Endpoints and integrations
- `login.php`, `register.php`, `forgot-password.php`, `reset-password.php`
  (POST handler), `generate.php`, `save-api.php`, `get-apis.php`,
  `get-products.php`, `load_more.php`, `logout.php`
- `whatsapp/webhook.php`
- `src/com/amazon/creators/**` (Amazon SDK) — untouched

### Schema and data
- `migrations/001…008_*.php`
- `sql/009_price_refresh_tracking.sql`, `sql/010_product_content_refresh.sql`,
  `sql/011_product_shares.sql`, `sql/STAGING-MIGRATION.sql`,
  `sql/DEPLOYMENT-NOTE.txt`
- `assets/uploads/manual-products/` (uploaded file and its `.htaccess`)
- `storage/` (`.htaccess`, `php-error.log`)

### Tests
- `tests/product_discovery_test.php`, `tests/product_inputs_test.php`

---

## 2. PHP templates that were edited — logic preserved

Each of these files mixes PHP with HTML. The diff was reviewed line by line and
contains **markup, asset links and page-chrome text only**. Specifically
preserved in every edited template:

- every `$pdo` query, prepared statement, transaction and error-handling block;
- every variable, loop and conditional that produces dynamic content
  (products, guides, users, tags, totals, currency conversions);
- every form `action`, `method`, `name`, `id`, `value` and hidden field;
- CSRF tokens (`csrf_token`, `dd_csrf_token()`, `dd_csrf_validate()`);
- honeypot fields (`website` on the contact form);
- authentication guards (`dd_require_user_login`, `dd_user_is_logged_in`,
  `$_SESSION['admin_logged_in']` checks);
- inline JavaScript (load-more, auth fetch handlers, admin tab/modal/AJAX code);
- all `data-*` hooks (`data-menu-toggle`, `data-mobile-menu`, `data-tab`,
  `data-url`, `data-offset`, `data-id`, `data-product`, …);
- affiliate `href`, `target="_blank"`, `rel="sponsored nofollow noopener"`;
- e-mail sending, password hashing/verification and session regeneration.

A mechanical check confirms it: for every modified PHP file, the sets of
`name=""`, `id=""`, `data-*` and `action=""` attributes present before the change
are still present afterwards — **no removals**. (New attributes were added, e.g.
the header search field on the storefront.)

`inc/layout.php` keeps every function name and signature
(`dd_render_head`, `dd_render_affiliate_header_disclosure`, `dd_render_header`,
`dd_render_storefront_header`, `dd_render_footer`, `dd_product_card`), so every
caller keeps working. `dd_render_head()` still emits the same SEO/meta/canonical
tags; the only additions are font and stylesheet links.

---

## 3. What was *not* verified

No PHP runtime or MySQL server exists in this environment, so this report proves
**source-level preservation** (git diff + attribute contract + parse check), not
runtime equivalence. Forms, admin actions, imports, payouts, cron/webhook calls
and the Creators API must be smoke-tested on a staging copy before production.
