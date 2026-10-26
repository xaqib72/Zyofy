# Pickwell — Change Report

**Project:** Pickwell — affiliate product discovery platform
**Source:** `github.com/xaqib72/Zyofy` (rebrand merged as `bc0c786`)
**Working branch:** `arena/6129e035-zyofy`
**Canonical domain:** `https://pickwell.t86.site`
**Target host:** Hostinger (shared, PHP + MySQL, Apache/LiteSpeed)
**Date:** 26 October 2026 — round 2 adds the layout repair in §10

---

## 1. What was delivered

A complete rebrand and redesign of the application — public storefront and admin
console — delivered as source code plus a Hostinger-ready deployment archive.
No PHP/MySQL runtime and no browser are available in the build environment, so
section 9 lists exactly what was and was not verified.

| Deliverable | Location in the archive |
| --- | --- |
| Full application, ready to upload | `public_html/` |
| Brand kit (logo variants, icons, social image, SVG sources) | `BRAND-ASSETS/` |
| This report | `PICKWELL-CHANGE-REPORT.md` |
| Deployment instructions | `HOSTINGER-DEPLOYMENT.md` |
| Configuration template | `public_html/config.example.php` (+ placeholder `config.php`) |

---

## 2. Brand identity

### The mark
A **"verified pick" seal**: a ring of approval with a tick inside it. It reads as
*chosen and checked* — the promise a discovery brand makes — and it stays legible
at 16 px, where lettering would not. Everything is vector; there is no raster
artwork in the logo.

| File | Use |
| --- | --- |
| `pickwell-logo.svg` | Primary horizontal lockup (badge + outlined wordmark) |
| `pickwell-logo-light.svg` | Same lockup for dark backgrounds |
| `pickwell-logo-reverse.svg` | Lockup with an ivory badge for light photography |
| `pickwell-mark.svg` | Square app icon, evergreen plate |
| `pickwell-mark-light.svg` | Square icon for dark surfaces (rail, footer) |
| `pickwell-mark-mono.svg` | Single-colour variant for embossing / print |
| `pickwell-symbol.svg`, `pickwell-symbol-light.svg` | Ring + tick without the plate |
| `pickwell-maskable.svg` | Full-bleed icon that survives Android's mask |

The wordmark is **real outlined Inter Bold** (extracted from the font's glyph
outlines and converted to SVG paths), not live text — so it renders identically
everywhere and needs no webfont to display.

### Colour
| Token | Value | Role |
| --- | --- | --- |
| `--zf-ink` | `#0d3b32` | Evergreen primary — headers, buttons, dark surfaces |
| `--zf-ink-deep` | `#082a23` | Footer, rail, hero overlays |
| `--zf-accent` | `#dca94f` | Brass accent — used **on** ink, never as small text on white |
| `--zf-accent-deep` | `#c8963c` | Brass for borders, rules, underlines |
| `--zf-brass-ink` | `#8a6520` | Brass dark enough for text on ivory (≈4.9:1) |
| `--zf-paper` | `#f8f7f3` | Ivory canvas |
| `--zf-line` | `#e4e0d6` | Hairlines and card borders |
| `--zf-slate` | `#5d6a64` | Secondary text (≈5.6:1 on ivory) |

Semantic colours were retuned to sit inside the warm palette: success
`#1f7a4d`, warning `#8a5f14`, danger `#a8332a`, info `#1f5f8b`. Accent text is
never placed on white at small sizes — the darker `--zf-brass-ink` is used
wherever brass text is needed on a light surface.

### Typography
* **Plus Jakarta Sans** (600/700/800) — UI display: wordmark lockups, card
  titles, prices, admin headings.
* **Fraunces** (500, optical 9–144) — editorial voice: hero headline, section
  headings, guide titles, legal page titles. This is what makes the brand read
  as a publisher rather than a template.
* **Inter** — body copy and controls.
* **IBM Plex Mono** — labels, eyebrows, metrics, tabular data.

Rhythm was tightened: headline letter-spacing moved from the previous
grotesque's `-.045em` to `-.018em…-.026em`, eyebrow labels moved to `.18em`
tracking, and button corners moved from pills to a 12 px radius so the identity
reads as *retail*, not *SaaS*.

---

## 3. Browser, device and social identity

Implemented once in `inc/layout.php` (public pages) and
`admin/partials/brand_icons.php` (every admin screen), so declarations cannot
drift apart:

* `favicon.ico` — multi-size (16/32/48/64) at the web root
* `favicon.svg` — crisp in modern browsers
* PNG favicons at 16 and 32 px
* `apple-touch-icon.png` — 180×180, ivory-safe artwork, no transparency issues
* `icon-192.png` / `icon-512.png` + maskable variants for Android/WebAPK
* `manifest.webmanifest` — name, short name, theme `#0d3b32`, canvas
  `#f8f7f3`, standalone display, two shortcuts (catalog, guides)
* Open Graph / Twitter cards with a **1200×630** branded image
  (`assets/brand/pickwell-og.png`, generated from `pickwell-og.svg`)
* JSON-LD `Organization` + `WebSite` with `SearchAction` pointing at
  `/products/?q={search_term_string}`
* `theme-color`, `application-name`, `apple-mobile-web-app-title`,
  `msapplication-TileColor`
* `.htaccess` now declares `AddType application/manifest+json .webmanifest` so
  the manifest is served with the right MIME type on Apache

Old Zyofy logo files (`assets/images/zyofy-mark.svg`, `zyofy-hero.svg`) were
removed and every reference replaced. The retired illustration folder was
renamed `assets/images/editorial/`.

---

## 4. Pages and surfaces audited

### Public (14 routed pages + 2 endpoints)
`index.php` (home) · `products.php` (catalog, search, category filters,
pagination) · `product.php` (detail, 404 branch) · `guides.php` · `guide.php` ·
`about.php` · `contact.php` · `affiliate-disclaimer.php` · `privacy-policy.php` ·
`terms-conditions.php` · `404.php` · `login-page.php` · `register-page.php` ·
`forgot-password-page.php` · `reset-password.php` · `link-generator.php` ·
`my-earnings.php` · `whatsapp-link.php`
Plus `Home.php` (301 to `/`) and `load_more.php` (AJAX partial that renders the
shared product card).

### Admin (9 screens + shared chrome)
`admin/index.php` (dashboard with 6 tab panes: users & tags, tracking tags,
reports & earnings, API connections, product builder, settings) ·
`admin/login.php` · `admin/setup-admin.php` · `admin/upload-month.php` ·
`admin/user-commission.php` · `admin/exchange-rates.php` ·
`admin/contributor-report.php` · `admin/report_manager.php` (redirect shim) ·
`admin/partials/admin_head.php`, `admin/partials/countries_rates.php`.

### What changed on every page
Headers, footers, buttons, cards, tables, forms, modals, empty states, notice
blocks, pagination, load-more, mobile drawers, focus states, selection colour,
and the loading/progress treatments. The design systems are
`assets/css/pickwell.css` (2,731 declarations) and
`admin/assets/pickwell-admin.css` (1,446 declarations); each file now ends with
a brand layer (storefront §30, admin §20) that re-skins rather than rewrites, so
the legacy markup keeps its structure.

---

## 5. Backend, security and functional preservation

**No backend logic was modified.** Verified by diff against the baseline:

```
config.php · db.php · session.php · autoload.php · inc/app.php · inc/site.php
inc/amazon-products.php · inc/manual-products.php · inc/product-discovery.php
inc/product-inputs.php · inc/product-share.php · inc/storefront.php
inc/guides-data.php · inc/guides-content-*.php · inc/whatsapp.php
admin/actions/* (23 handlers) · admin/lib/* (3 files)
migrations/* (8 files) · sql/* (schema) · whatsapp/webhook.php
tests/* · src/com/amazon/creators/** · vendor/**
```

The only `inc/` file touched is `inc/layout.php`, which is the HTML template
partial (head/header/footer/product card) — every function signature is
unchanged, so every caller still works.

Preserved contracts, re-checked after the rebrand:
`data-site-header`, `data-menu-toggle`, `data-mobile-menu`, `data-tab`,
`data-copy`, `#loadMoreProducts`, `#linkGeneratorForm`, `#productDiscoveryForm`,
`#loginForm` / `#registerForm` / `#forgotForm` / `#authNotice` / `#monthForm`,
`#adminNavToggle` / `#adminSidebar` / `#adminNavBackdrop`, modal IDs and
`.close-modal`, `#initializeMarketplaceSettings` / `#marketSetupStatus`, CSRF
(`csrf_token`, `report_csrf_token`, `settings_csrf_token`,
`dd_csrf_token()`), the contact-form honeypot `website`, and every form action,
field name and hidden input.

Affiliate behaviour is untouched: the `href`/`rel` output, the Creators API
calls, share tokens, click tracking, payout maths, exchange-rate handling and
CSV import all remain byte-identical.

### Security-relevant changes (all deliberate, all minimal)
1. **Credentials removed from the package.** `public_html/config.php` in the
   archive is a template with `CHANGE_ME` placeholders; the live credentials are
   not included anywhere in the ZIP (verified by scanning for the DB user,
   password, `.sql` dumps and logs).
2. **Database password rotation recommended.** The original `config.php`
   containing a real password is still present in the repository's git history.
   Rotate that password in hPanel before launch (see the deployment guide).
3. **Admin heads now emit `noindex,nofollow,noarchive`** via the shared
   `brand_icons.php` partial, in addition to the existing session/auth guards.
   No authentication logic was altered, added or bypassed.
4. `.htaccess` `Require all denied` rules for `config.php`, `*.log`, `*.sql`,
   `*.md` and archive files were preserved.

---

## 6. Domain, SEO and copy

* `app.url` → `https://pickwell.t86.site`; every URL, canonical tag, OG URL and
  sitemap entry derives from it.
* `sitemap.xml` — all 22 URLs rewritten to the new domain, `lastmod` refreshed.
* `robots.txt` — old alias removed, sitemap pointer updated.
* `ads.txt` — `ownerdomain=pickwell.t86.site`.
* Page titles/metadata rewritten with the Pickwell voice ("Smart shopping,
  curated", "Discover products worth choosing").
* Affiliate disclosure preserved verbatim, including the Amazon Associate
  statement and the Amazon content notice — Pickwell is **not** presented as an
  Amazon-owned or officially affiliated site.

---

## 7. Files added

```
public_html/assets/css/pickwell.css            storefront design system
public_html/assets/js/pickwell.js              presentation-only behaviour
public_html/admin/assets/pickwell-admin.css    admin design system
public_html/admin/partials/brand_icons.php     shared icon/manifest/font head
public_html/assets/brand/*                     16 brand + icon files
public_html/favicon.ico · favicon.svg · apple-touch-icon.png
public_html/manifest.webmanifest
public_html/config.example.php
```
Renamed from the previous design layer: `zyofy-2026.css → pickwell.css`,
`zyofy-2026.js → pickwell.js`, `admin-2026.css → pickwell-admin.css`.
Removed: the two retired Zyofy logo files and the superseded `REDESIGN-2026`
report folder.

---

## 8. Responsive work

Breakpoints were authored at 1180 / 980 / 760 / 680 / 560 / 380 px
(storefront) and 1200 / 860 / 720 / 520 px (admin): stacked hero, two-column
product grid on phones, wrapping filter rail, full-width admin cards, collapsing
data tables, off-canvas admin rail, and reduced-motion fallbacks. Target widths
of 360, 390, 768, 1024 and 1440 px were the design reference. **These were
verified by reading the cascade, not by screenshotting** — see limitations.

---

## 9. Tests performed — and what they actually prove

| Check | Method | Result |
| --- | --- | --- |
| PHP syntax | Full parse of all 98 application PHP files with a real parser (`php-parser`) | 0 errors |
| CSS validity | `postcss` parse of all 7 stylesheets | 0 errors |
| JavaScript | `node --check` on `pickwell.js` and `site.js` | pass |
| Old branding | Case-insensitive grep for `zyofy` / `dealdigger` across the packaged tree | 0 matches in visible code (only the private secrets filename history note and a data dump that is **not** shipped) |
| Dead asset references | Crawled every `dd_url()`, `src=`, `href=` and CSS `url()` in the package | 0 missing files |
| Token integrity | Every `var(--zf-*)` / `var(--a-*)` resolved against its token block | 0 unresolved |
| Icon coverage | Confirmed each declared icon exists at the declared size | 9/9 |
| Archive integrity | `unzip -t` on the final ZIP | no errors |

### Preview
A static preview of the redesigned storefront and admin console is included in the
repository at `_zyofy.store/PICKWELL-PREVIEW/index.html` and `.../admin.html`.
They load the real production stylesheets and the real brand assets, so they show
what the design layer actually renders — but they are hand-built pages, not the
PHP templates executed.

### Not tested (honest list)
* **No PHP runtime** in the build environment: no page was executed, no query
  ran, no form was submitted, no admin action was triggered, PHPUnit was not run.
* **No browser**: layouts were not screenshot-verified at any breakpoint; the
  supplied previews are static HTML for visual review in your own browser.
* **No MySQL**: migrations, imports, payouts and report parsing are untested
  against a live database.
* **Amazon Creators API / WhatsApp Cloud API**: not called; credentials and
  network access were outside this environment.
* Google Fonts and Font Awesome load from CDNs; if Hostinger blocks them the
  stack falls back to system fonts and the layout still holds, but the
  typography will differ.

### Known issues / caveats
1. `my-earnings.php` and `whatsapp-link.php` carry large legacy inline
   `<style>` blocks. They were re-pointed to the new palette **and** the new
   stylesheet is loaded after them, but those two screens are the least
   thoroughly re-skinned parts of the product.
2. The retired legacy sheets (`assets/css/site.css`, `storefront.css`,
   `premium-refresh.css`, plus the admin equivalents) are still on disk. Only
   `site.css` (public base utilities), `admin/app.css` and
   `admin/premium-refresh.css` are still loaded. They are kept because
   `my-earnings.php` and parts of the admin still depend on them; deleting them
   is a follow-up cleanup, not a launch blocker.
3. `assets/images/editorial/*.svg` illustrations are unused by the current
   templates (guides use remote photography). They were kept and renamed rather
   than deleted.
4. The repository's production SQL dump and the previous real `config.php` stay
   in the repository (outside the shipped archive). See security note 2.

---

## 10. Layout repair pass (round 2)

After the first delivery the header and, in the client's words, "many places"
had elements sitting on top of each other or shifted up and down — on phones,
tablets and desktop. This pass traced that to specific competing rules rather
than restyling by eye. Each item below names the rule that caused it.

### 10.1 Two headers, two designs

`inc/layout.php` rendered **two different bars**: `dd_render_storefront_header()`
for the storefront and `dd_render_header()` for login, register, forgot-password
and reset-password. The second emitted `.site-header` markup, which
`assets/css/site.css` painted navy (`#1c2941` chips, `#a94f49` hovers, a 74px
inner height, a navy drawer) while every other page got the ivory Pickwell bar
from `pickwell.css`. Two headers, two metric systems, two palettes.

*Now:* `dd_render_header()` delegates to the storefront renderer, so there is one
header in the markup, one set of measurements (`--zf-header-h`) and one palette.
The legacy `.site-header` skin — both the original block and the later "polished
shared header" block — was removed from `site.css`, together with the
`background:#18233a!important` brand chip.

### 10.2 The page shell disagreed with itself

`site.css` defined `.shell` as `width:min(var(--shell), calc(100% - 44px))` and
re-declared it at 768px; `pickwell.css` defined it as `max-width` + padding. The
result depended on which sheet won per page.

*Now:* one definition in `pickwell.css` §31.1 —
`width:100%; max-width:var(--zf-shell); margin-inline:auto;
padding-inline:clamp(16px, 4vw, 32px)` — and `site.css` mirrors it.

### 10.3 Sticky header could stop sticking

`body { overflow-x: hidden }` turns `<body>` into a scroll container in Chrome
and Safari, which can silently disable `position: sticky` on the header and
produce content jumping under/over the bar. Body now uses `overflow-x: clip`
(with `hidden` kept as a fallback for older engines).

### 10.4 The mobile drawer ran off the screen

`site.css` positioned `.mobile-menu` absolutely with its own padding, and
`pickwell.css` added a second inner padding, with **no height cap**. A long menu
(five links + disclosure + account actions) ran past the bottom of an iPhone and
the last items were unreachable.

*Now:* single padding, `max-height: calc(100dvh - var(--zf-header-h))`,
`overflow-y:auto`, `overscroll-behavior:contain`, and bottom padding that
respects `env(safe-area-inset-bottom)`.

### 10.5 The affiliate disclosure was hidden site-wide

A "legacy markup" guard — `.affiliate-header-disclosure { display: none }` — and
`body.auth-page .zf-disclosure { display: none }` were hiding the required
Amazon disclosure bar on **every** page. Both now display. This is a compliance
fix as much as a layout one: the disclosure is visible above the fold again, and
the footer disclosure is untouched.

### 10.6 Tablet band overflowed instead of collapsing

Between roughly 980px and 1140px the brand, five nav links, the search field and
the account cluster no longer fit inside the 1280px shell, so the bar overflowed
sideways rather than collapsing.

*Now:* the nav and header search collapse at **1140px** (previously 980px) and
the menu toggle takes over, so iPad widths get a clean single-line bar.

### 10.7 Anchors landed under the sticky bar

`/#guides`, `/#edit` and similar links parked the heading behind the sticky
header. `html { scroll-padding-top }` plus per-section `scroll-margin-top` now
offset by the real header height.

### 10.8 100vh on mobile Safari

`calc(100vh - header)` includes Safari's collapsing chrome, which pushed the auth
panel past the fold. Now `100svh` with a `100vh` fallback.

### 10.9 Prose had no vertical rhythm

`site.css` zeroes `margin` and `padding` on every element. Components set their
own spacing, but plain content (policy pages, guide bodies, standalone notes)
collapsed into touching lines. §31.7 adds **zero-specificity** `:where()` spacing
between siblings only — never before a first child — so any component rule still
wins.

### 10.10 `/my-earnings/` was on a third palette

`body.contributor-page` was forced to `background:#f5f3ef!important;
color:#123f36`, navy `#1c2941` chips, a Georgia serif page title, coral
`rgba(219,119,99)` nav accents, an emerald hero gradient and a
`max-width:1440px!important` page — **wider than the 1280px header it sits
under**, which is exactly the kind of misalignment that reads as "elements are
off". All of it now uses Pickwell tokens, the page is 1300px like the header
shell, and the inline `<style>` block's hero gradient was re-pointed to
evergreen/brass. No markup, PHP or data was touched in that file.

### 10.11 Admin: header and rail disagreed, tablet got a scrollbar

`admin/assets/app.css` still carried the pre-Pickwell layout maths — a 248px page
offset and `width: calc(100% - 218px)` on the top bar between 701 and 1050px —
while `pickwell-admin.css` had moved the rail to 264px (236px below 1200px).
Between 861px and 1050px the bar was therefore ~18px wider than the space its own
`margin-left` left for it: a horizontal scrollbar on every admin screen at tablet
width, with the right edge of the bar cut off.

*Now:* one scale owns the geometry — rail 264 → 236 → 0, header `width:auto` so
it always fills the gap its margin leaves, page margin matching at every
breakpoint, rail at `100dvh`, tables scrolling inside their wrapper instead of
the document, `overflow-x: clip` on the page container.

### 10.12 Files changed in this pass

| File | Change |
| --- | --- |
| `inc/layout.php` | `dd_render_header()` now delegates to the storefront renderer; the duplicate header markup is gone (54 lines removed) |
| `assets/css/site.css` | legacy `.site-header` skin removed, single `.shell`, `overflow-x: clip`, Pickwell tokens for the contributor surface, navy `!important` chip removed |
| `assets/css/pickwell.css` | new §31 "Layout authority" (227 lines): shell, header normalisation, disclosure, drawer, anchor offsets, `svh`, rhythm, overflow guards, tablet collapse |
| `admin/assets/pickwell-admin.css` | new §21 "Layout reconciliation" (93 lines): header/rail/page scale at 1200/1050/860/520px, `dvh` rail, table scrolling |
| `my-earnings.php` | colour values only, inside the inline `<style>` block (hero gradient, cap, big-number suffix) |

No PHP logic, query, form, field name, element ID, data attribute, CSRF token,
route or asset path was changed in this pass. `data-site-header`,
`data-menu-toggle`, `data-mobile-menu` and the admin hooks
(`#adminNavToggle`, `#adminSidebar`, `#adminNavBackdrop`) are all intact.

### 10.13 What was verified this round

| Check | Method | Result |
| --- | --- | --- |
| PHP syntax | `php-parser` over all 206 application PHP files | 0 errors (9 failures are all `vendor/` files using PHP 8 syntax the parser predates) |
| CSS validity | `postcss` parse of every stylesheet in `public_html/` | 0 errors |
| JavaScript | `node --check` on `pickwell.js` and `site.js` | pass |
| Layout invariants | 23 assertions over the four sheets + `layout.php` (one `<header>`, single `.shell`, disclosure visible, drawer capped, tablet collapse, admin 1050px trio, preserved hooks) | 23/23 pass |
| Cascade order | Confirmed every §31 / §21 override is the last matching rule in load order (`site.css` → `pickwell.css`; `app.css` → `premium-refresh.css` → `pickwell-admin.css`) | pass |
| Colour sweep | Grep for the previous theme hexes (`#1c2941`, `#18233a`, `#a94f49`, `#a94345`, `#b07a2b`, `#ea7867`, `#bd4c4c`, `#123f36`) in the three active sheets | 0 remaining |
| Secret scan | Grep for the production DB credential and account id across the packaged tree | 0 matches |

### 10.14 Still not visually verified

There is still **no browser in the build environment** (Chromium cannot start —
`libnspr4`, `libnss3`, `libnssutil3` are missing and the package mirrors are
unreachable; WeasyPrint has no Pango). Every claim above is a **cascade and
geometry analysis**, not a screenshot. Please check these five views first when
you open the site:

1. iPhone 390px — header, open drawer (all items reachable), footer.
2. iPad 834px and 1024px — bar collapses cleanly, no sideways scroll.
3. Laptop 1440px — nav, search and account on one line.
4. `/my-earnings/` — page aligned with the header, evergreen hero.
5. `/admin/` at ~900px — no horizontal scrollbar, rail and top bar flush.
