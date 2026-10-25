# Zyofy 2026 redesign — delivery folder

Everything here sits **outside** `public_html`, so nothing in this folder is
served to visitors and nothing needs to be uploaded with the application.

| Item | What it is |
| --- | --- |
| `CHANGE-REPORT.md` | Files inspected, pages redesigned, design decisions, tests run, limitations. |
| `BACKEND-PRESERVATION.md` | Proof that no backend logic, schema, auth, CSRF, form contract or integration was touched. |
| `preview/index.html` | Static storefront design preview (sample content, production stylesheet). |
| `preview/admin.html` | Static admin console design preview. |

## Where the work lives

* Branch: `arena/0be754c4-zyofy` (branched from `main` @ `23fc400`)
* Pull request: https://github.com/xaqib72/Zyofy/pull/1

## Files that make up the redesign

```
public_html/assets/css/zyofy-2026.css        storefront design system
public_html/assets/js/zyofy-2026.js          presentation-only behaviour
public_html/admin/assets/admin-2026.css      admin design system
public_html/inc/layout.php                   head / header / footer / product card
public_html/*.php                            22 public templates re-skinned
public_html/admin/*.php                      dashboard, sign-in, setup, sub-pages
```

## Opening the previews

Open `preview/index.html` or `preview/admin.html` directly from this folder (or
serve the repository root over any static server). The stylesheet and image paths
are relative, so no build step is needed. Google Fonts and Font Awesome load from
their CDNs when the machine has network access; both degrade to system fonts.

## Deploying

The change set is CSS, one JS file and template markup. To deploy, copy
`public_html` exactly as it is here — the `.htaccess` rules, upload folders and
`storage/` directory are unchanged. Then smoke-test sign-in, a product import and
a report import on a staging copy (see the limitations section of the change
report).
