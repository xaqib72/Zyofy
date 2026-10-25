# Zyofy — 2026 Frontend Redesign · Change Report

**Repository:** `xaqib72/Zyofy`
**Application root:** `_zyofy.store/public_html`
**Branch with the work:** `arena/0be754c4-zyofy` (branched from `main` @ `23fc400`)
**Scope:** frontend only — markup, stylesheets, one presentation-only JS file.

---

## 1. Inventory inspected before any change

The repository was read recursively. 2,600 tracked files, of which ~100 are
application PHP (the rest is `vendor/` and the Amazon Creators SDK in `src/`).

### Application structure found

| Area | Files |
| --- | --- |
| Bootstrap / config | `config.php`, `db.php`, `session.php`, `autoload.php`, `inc/app.php`, `inc/site.php` |
| Shared layout | `inc/layout.php` (`dd_render_head`, `dd_render_header`, `dd_render_storefront_header`, `dd_render_footer`, `dd_product_card`) |
| Public data helpers | `inc/storefront.php`, `inc/guides-data.php`, `inc/guides-content-1..3.php`, `inc/amazon-products.php`, `inc/manual-products.php`, `inc/product-discovery.php`, `inc/product-inputs.php`, `inc/product-share.php`, `inc/whatsapp.php` |
| Public pages | `index.php`, `products.php`, `product.php`, `guides.php`, `guide.php`, `about.php`, `contact.php`, `affiliate-disclaimer.php`, `privacy-policy.php`, `terms-conditions.php`, `404.php` |
| Account / auth pages | `login-page.php`, `register-page.php`, `forgot-password-page.php`, `reset-password.php`, `link-generator.php`, `my-earnings.php`, `whatsapp-link.php` |
| JSON / form endpoints (untouched) | `login.php`, `register.php`, `forgot-password.php`, `generate.php`, `save-api.php`, `get-apis.php`, `get-products.php`, `load_more.php`, `logout.php`, `whatsapp/webhook.php` |
| Admin workspace | `admin/index.php` (6 tab panes), `admin/upload-month.php`, `admin/user-commission.php`, `admin/exchange-rates.php`, `admin/contributor-report.php`, `admin/login.php`, `admin/setup-admin.php`, `admin/report_manager.php`, `admin/logout.php` |
| Admin chrome | `admin/partials/admin_head.php`, `admin/partials/countries_rates.php` |
| Admin actions (untouched) | 23 handlers in `admin/actions/` |
| Business logic (untouched) | `admin/lib/payout.php`, `admin/lib/report_reader.php`, `admin/lib/countries.php` |
| Schema / data (untouched) | `migrations/` (8 files), `sql/` (5 files) |
| Existing styles | `assets/css/site.css`, `assets/css/storefront.css`, `assets/css/premium-refresh.css`, `admin/assets/app.css`, `admin/assets/premium-refresh.css` |
| Existing scripts | `assets/js/site.js` (mobile drawer, clipboard copy), inline scripts inside admin and auth templates |
| Tests present | `tests/product_discovery_test.php`, `tests/product_inputs_test.php` (PHPUnit, `vendor/` present) |

The `/public_html/.htaccess` rewrite map defines every public URL
(`/products/`, `/product/{ASIN}/`, `/guides/{slug}/`, `/about/`, `/contact/`,
`/login/`, `/register/`, `/forgot-password/`, `/affiliate-disclaimer/`,
`/privacy-policy/`, `/terms-conditions/`, `/link-generator/`, `/my-earnings/`,
`/whatsapp-link/`). All of them were inspected and re-skinned.

---

## 2. The new design direction

### Storefront — "Signal"
* **Palette:** obsidian ink `#0a101c`, porcelain canvas `#f4f6f9`, signal lime
  `#c9f45b`, slate neutrals. No cream, no coral, no green wash from the old theme.
* **Type:** Bricolage Grotesque (display), Inter (text), JetBrains Mono (labels,
  counters, metadata).
* **Geometry:** 1px hairline borders, 14–26px radii, pill buttons/controls,
  asymmetric editorial grids, numbered collection cards, dark full-bleed footer.
* **Header:** slim affiliate strip above a sticky translucent bar — wordmark,
  underline-accented nav, live catalog search, account actions, full-height
  mobile drawer.
* **Homepage:** split hero with an in-hero catalog search + stat row, trust
  ribbon, product edit grid, five-collection mosaic, featured-guide layout with
  an editorial list, FAQ accordion, dark transparency CTA.
* **Catalog:** sticky control panel (search / marketplace / category + chip rail),
  count stamp, product cards with contained imagery, empty/pagination/load-more
  states, disclosure block.
* **Product detail:** two-column gallery + information panel with the affiliate
  CTA, "paid link" marker and disclosure grouped in the purchase block, fact
  bullets and Amazon content notice.
* **Guides:** library hero, three-up article cards, article page with hero grid,
  editorial note, sticky sidebar and a reading-progress rule.
* **Auth:** full-height split screen — dark editorial panel beside a white form card.
* **Legal / info:** breadcrumbed document intro, sticky side note, prose card.

### Admin — "Ops"
* Same ink + lime identity, deliberately denser: fixed obsidian rail (264px,
  grouped navigation, lime active marker, workspace footer), white sticky topbar,
  porcelain canvas.
* Data-first components: mono micro-labels, tabular figures, sticky table heads,
  zebra hover, cards with hairline borders, pill status badges, focused modals
  with blurred backdrop, consistent form controls, off-canvas mobile navigation.
* Admin sign-in and first-run setup were rebuilt on the same identity.

### New files
| File | Purpose |
| --- | --- |
| `assets/css/zyofy-2026.css` | Storefront design system (2,536 declarations) |
| `admin/assets/admin-2026.css` | Admin design system (1,111 declarations) |
| `assets/js/zyofy-2026.js` | Presentation only: scroll reveal, header state, reading progress |

---

## 3. Files modified (25)

### Public templates
| File | What changed |
| --- | --- |
| `inc/layout.php` | New `<head>` asset order + fonts; affiliate strip; storefront header, legacy header and footer rebuilt; `dd_product_card()` markup; presentation JS include. Function names, signatures, links and `data-*` hooks preserved. |
| `index.php` | Homepage sections rebuilt (hero, search, ribbon, product edit, collections, guides, FAQ, CTA). Same PHP queries/loops. |
| `products.php` | Catalog intro, control panel, collection chips, result grid, empty/pagination/load-more blocks. Load-more JS untouched. |
| `product.php` | Product detail layout rebuilt; 404 branch retained. Affiliate `href`/`rel` output unchanged. |
| `guides.php` | Library hero, card grid, transparency FAQ. |
| `guide.php` | Article hero, editorial note, body, sticky aside; not-found branch retained. |
| `about.php` | Breadcrumb + intro + side note; prose text byte-identical. |
| `contact.php` | Breadcrumb, intro, dark contact card, form card. All field names, CSRF token, honeypot and `POST` handler untouched. |
| `affiliate-disclaimer.php`, `privacy-policy.php`, `terms-conditions.php` | Breadcrumb + intro wrapper; policy text unchanged. |
| `login-page.php`, `register-page.php`, `forgot-password-page.php`, `reset-password.php` | Eyebrow treatment; forms, IDs, CSRF and inline fetch scripts untouched. |
| `link-generator.php`, `whatsapp-link.php` | Stylesheet link only (they inherit the new header/footer and CSS). |
| `my-earnings.php` | Stylesheet + font links only (palette re-pointed by CSS overrides). |

### Admin
| File | What changed |
| --- | --- |
| `admin/partials/admin_head.php` | Fonts + `admin-2026.css`; existing rail/topbar markup kept. |
| `admin/index.php` | Fonts + `admin-2026.css` added after the legacy inline `<style>`. No PHP or JS touched. |
| `admin/login.php` | Legacy inline style block replaced with the shared admin stylesheet (form action, field names, `POST` logic untouched). |
| `admin/setup-admin.php` | Same treatment + `zyofy-admin-setup` body class and `setup-card` class (creation logic untouched). |
| `admin/user-commission.php` | `admin-2026.css` added after its page-level `<style>` (payout logic untouched). |

### Not edited, covered by the shared chrome
`admin/upload-month.php`, `admin/exchange-rates.php`,
`admin/contributor-report.php`, `admin/partials/countries_rates.php` include
`admin/partials/admin_head.php`, so they render in the new console through the
shared layout and stylesheet.

### Stylesheet loading note
`inc/layout.php` no longer links `assets/css/storefront.css` or
`assets/css/premium-refresh.css` — both were earlier visual refreshes whose
`!important` rules would fight the new system. The files remain in the repo
(and `my-earnings.php` still links `storefront.css` for its own legacy
inline-styled markup). `assets/css/site.css` is still loaded as the base layer.

---

## 4. Tests performed

| Check | Method | Result |
| --- | --- | --- |
| PHP syntax, all application templates | Parsed all 96 non-vendor PHP files with a real PHP parser (`php-parser`, glayzzle) | 0 errors |
| CSS syntax | `postcss` parse of both new stylesheets | 0 errors (2,536 / 1,111 declarations) |
| JS syntax | `node --check` on `zyofy-2026.js` and `site.js` | Pass |
| Markup contract | Diffed `name=""`, `id=""`, `data-*` and `action=""` attribute sets before/after for every changed template | **No attribute removed** |
| Backend diff | `git diff HEAD~1 HEAD` limited to config, DB, session, actions, lib, migrations, SQL, endpoints, webhook, tests | **Empty — no change** |
| Static assets | Served the tree over HTTP and requested both stylesheets, the new JS, mark, category, guide and uploaded product images | 200 for every asset |
| Class coverage | Cross-checked every class used by rewritten templates against the loaded stylesheets | No unstyled functional component |
| Responsive rules authored | Breakpoints 1180 / 980 / 680 / 380 px (storefront) and 1200 / 860 / 520 px (admin); `prefers-reduced-motion` and print rules | Authored, **not browser-verified** |
| PHPUnit suites | `tests/` require a PHP runtime + database | **Not run** |

### Static design previews
Because this sandbox has no PHP/MySQL runtime, two static preview pages
(`/home/user/preview/index.html` and `/home/user/preview/admin.html`) were built
from the real templates' markup using the production stylesheets, so the new
visual language can be reviewed in a browser. They contain sample content and are
**not** part of the application.

---

## 5. Limitations (read before deploying)

1. **No application runtime here.** PHP and MySQL are not installed in this
   environment, so no page was executed, no form submitted, no admin action run.
   Behavioural verification must happen on your staging host.
2. **No browser-based visual test.** Layouts were reviewed by reading the
   compiled CSS/markup; they were not screenshotted at each breakpoint.
3. **PHPUnit not run** — `tests/product_discovery_test.php` and
   `tests/product_inputs_test.php` need PHP + the DB.
4. **Google Fonts** are loaded from `fonts.googleapis.com` (Bricolage Grotesque,
   Inter, JetBrains Mono). If the host blocks external fonts, the stack falls back
   to system sans/mono — the layout still holds.
5. **Inline-styled legacy pages:** `my-earnings.php` and `whatsapp-link.php`
   carry large legacy `<style>` blocks. They were re-pointed to the new palette
   through scoped override rules rather than rewritten, to avoid risking their
   payout/WhatsApp logic.
6. The old `storefront.css` / `premium-refresh.css` files remain on disk. Deleting
   them is safe only after confirming no other template references them.
