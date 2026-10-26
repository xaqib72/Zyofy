# Pickwell — Change Report

**Project:** Pickwell — affiliate product discovery platform
**Source:** `github.com/xaqib72/Zyofy` (baseline commit `23fc400`)
**Working branch:** `arena/0be754c4-zyofy`
**Canonical domain:** `https://pickwell.t86.site`
**Target host:** Hostinger (shared, PHP + MySQL, Apache/LiteSpeed)
**Date:** 26 October 2026

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
