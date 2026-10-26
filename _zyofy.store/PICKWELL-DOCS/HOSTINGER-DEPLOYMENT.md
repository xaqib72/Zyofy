# Pickwell — Hostinger Deployment Guide

Target: **https://pickwell.t86.site** on Hostinger shared hosting (PHP 8.x + MySQL,
Apache/LiteSpeed).

This guide assumes you have the archive `Pickwell-deploy.zip`. Nothing in it
contains production credentials — you will enter those yourself in step 4.

---

## 0. What is in the archive

```
public_html/                 ← upload the CONTENTS of this folder to Hostinger's public_html
  index.php, products.php, product.php, guides.php, guide.php, …
  admin/                     ← dashboard (protected by its own login)
  inc/                       ← shared PHP includes (templates + helpers)
  assets/                    ← CSS, JS, images, uploads, brand files
  src/, vendor/              ← Amazon Creators API SDK + Composer dependencies (required)
  migrations/, sql/          ← schema/migration scripts (do not run yet — step 5)
  config.php                 ← placeholder config; edit in step 4
  config.example.php         ← the same template, for reference
  favicon.ico, favicon.svg, apple-touch-icon.png, manifest.webmanifest
  .htaccess                  ← routing, security headers, caching
BRAND-ASSETS/                ← logo variants, icons, social image, SVG sources
PICKWELL-CHANGE-REPORT.md    ← what changed and what was tested
HOSTINGER-DEPLOYMENT.md      ← this file
```

Two directories from the original repository are deliberately **not** included:
`tests/` (development test suite) and the previous production `config.php`
(credentials), plus logs, SQL dumps and AppleDouble `._*` files.

---

## 1. Prerequisites in hPanel

1. **PHP version** — set 8.1 or newer (hPanel → Advanced → PHP Configuration).
   Required extensions (all enabled by default on Hostinger): `pdo_mysql`,
   `curl`, `json`, `mbstring`, `openssl`, `fileinfo`.
2. **HTTPS** — hPanel → Security → SSL: issue the free certificate for
   `pickwell.t86.site` and enable **Force HTTPS**.
3. **DNS** — point `pickwell.t86.site` at the hosting account (A record or the
   nameservers Hostinger gave you). Wait for propagation before step 6.

---

## 2. Upload the files

**Upload the contents of `public_html/`, not the folder itself.**

* hPanel → Files → File Manager → open **`public_html`** for the domain
  `pickwell.t86.site`.
* Delete Hostinger's `default.php` placeholder if present.
* Upload `Pickwell-deploy.zip` into `public_html`, then use **Extract**. The
  archive's `public_html/` folder will extract *inside* the current folder, so
  after extraction move its contents up one level (select all → Move →
  `/public_html`), then delete the empty `public_html/public_html` folder and the
  ZIP. Alternatively upload the files with FTP/SFTP and skip the move.
* Result: `public_html/index.php` exists — not
  `public_html/public_html/index.php`.

Expected size: ~27 MB, ~2,600 files.

### Folder permissions
```
public_html/                 755
public_html/storage/         755   (PHP error log is written here if it exists)
public_html/assets/uploads/  755   (manual product images uploaded from the dashboard)
```
No file in this package needs 777. If you see an upload error from the admin
"manual product" screen, set `assets/uploads/manual-products/` to 755 owned by
your account user — never 777.

---

## 3. Create the database

1. hPanel → Databases → **MySQL Databases** → create a database.
   Hostinger prefixes everything with your account id, e.g. database
   `u123456789_pickwell`, user `u123456789_pickwell`.
2. Copy the generated **password** somewhere safe.
3. Note the exact database name, user name and host (`localhost` on Hostinger).

> **Existing site migration?** Keep your current database and simply point
> `config.php` at it — the schema is unchanged by this redesign, so no data
> migration is required. Do **not** import the old database dump from the previous host on
> top of a live database.

> **Fresh install?** The repository does not contain a complete schema dump, only
> migration scripts (`sql/`, `migrations/`). On a brand-new empty database,
> export the structure from your existing installation first
> (phpMyAdmin → Export → **Structure only** → SQL), import it, then run the
> pending migration files listed in `sql/DEPLOYMENT-NOTE.txt`.

---

## 4. Configure `config.php`

Open `public_html/config.php` in File Manager → Edit, and set:

```php
'app' => [
    'name' => 'Pickwell',
    'url' => 'https://pickwell.t86.site',   // no trailing slash
    'environment' => 'production',
    'debug' => false,                        // must stay false in production
    'noindex' => false,
    'support_email' => 'your-real-inbox@example.com',
    'support_phone' => '+1...',
],
'db' => [
    'host' => 'localhost',
    'name' => 'u123456789_pickwell',         // step 3
    'user' => 'u123456789_pickwell',         // step 3
    'password' => 'the-password-from-step-3',
    'charset' => 'utf8mb4',
],
```

`app.url` drives every generated link, canonical tag, Open Graph URL and sitemap
entry — if it is wrong, the site will produce wrong absolute URLs everywhere.

`config.php` is blocked from web access by `.htaccess`. Do not rename or move it.

---

## 5. Run database migrations (only if your schema is behind)

Compare the tables in phpMyAdmin with the files in `sql/` and `migrations/`.
Apply only the ones whose columns are missing. Each file starts with a comment
describing its purpose. Typical order: `sql/009…`, `sql/010…`, `sql/011…`, then
`migrations/001…008` if the installation predates per-user/per-tag commission.

Do not run a migration twice. Take a database backup first
(hPanel → Files → Backups, or phpMyAdmin → Export).

---

## 6. Domain, HTTPS and canonical URLs

1. hPanel → Domains: confirm `pickwell.t86.site` is the domain whose document
   root is `public_html`.
2. Security → SSL → issue/activate, then **Force HTTPS**.
3. The application also sends HSTS on HTTPS requests itself; you do not need to
   add a redirect rule. There are no redirect rules in `.htaccess` that could
   loop with Hostinger's HTTPS forcing.
4. Verify:
   * `https://pickwell.t86.site/` loads with a padlock
   * `http://pickwell.t86.site/` redirects to `https://` (Hostinger setting)
   * `https://pickwell.t86.site/robots.txt` shows the new sitemap URL
   * `https://pickwell.t86.site/sitemap.xml` lists `pickwell.t86.site` URLs

Optional (recommended) after launch: add the domain to Google Search Console and
submit `https://pickwell.t86.site/sitemap.xml`.

---

## 7. Post-deploy verification checklist

| # | Check | Expected |
| --- | --- | --- |
| 1 | Homepage | Pickwell hero, seal logo in header, brass accents, no Zyofy text |
| 2 | Browser tab | Pickwell seal icon (hard-refresh to beat the icon cache) |
| 3 | View source | `og:image` = `/assets/brand/pickwell-og.png`, JSON-LD present, one favicon set |
| 4 | `/products/` | Catalog renders, search box filters, pagination works |
| 5 | Any product page | Image, price, "View on Amazon" affiliate button (with `rel="sponsored nofollow"`) |
| 6 | `/guides/` and one guide | Guide library + article layout, sidebar |
| 7 | `/contact/` | Form submits and shows the confirmation notice |
| 8 | `/login/` | Split auth screen with the Pickwell brand panel |
| 9 | `/admin/` | Pickwell sign-in; if it is the first run, the setup screen appears |
| 10 | Admin dashboard | Rail with brass active marker, metrics, tables, tabs |
| 11 | Admin imports | Report import and payout screens render with the new palette |
| 12 | Mobile | Shrink to 390 px: drawer navigation, single-column cards, no horizontal scroll |
| 13 | Social preview | Paste the homepage URL into a chat app → Pickwell card with the branded image |
| 14 | 404 | `/does-not-exist` → branded "This page took a different turn." page |

---

## 8. Rollback

**Before you upload:** download the current `public_html` (or use hPanel's
Files → Backups snapshot) so you can restore it byte-for-byte.

To roll back:
1. hPanel → Files → Backups → restore the most recent snapshot of the account, or
2. delete the uploaded files and re-upload your previous `public_html` copy, then
3. restore `config.php` from your backup (it is the only file with credentials).
4. If you changed the database password in step 3, revert `config.php` to the old
   password instead of restoring the database.
5. Clear the LiteSpeed cache (hPanel → Advanced → LiteSpeed Cache → Purge All) so
   the browser receives the restored CSS/HTML.

Nothing in this release alters the database schema, so a code rollback needs no
database rollback.

---

## 9. Security housekeeping (do this after launch)

1. **Rotate the old database password.** The previous `config.php` was committed
   to the repository's git history, so treat that password as compromised. The
   archive ships without it.
2. **Create the admin account immediately** at `/admin/setup-admin.php`, then
   confirm `/admin/login.php` works and the setup screen refuses to run twice.
3. **WhatsApp / Amazon integrations** are configured through the admin screens
   and (for WhatsApp) a private `pickwell-whatsapp-secrets.php` file one level
   **above** `public_html` in your home directory — never inside the web root.
   The secrets file is not part of this archive; create it on the server.
4. Keep `debug => false` and `noindex => false` in production.
5. Consider deleting `config.example.php` from the server once `config.php` is
   set (it contains no secrets, but it is not needed in production).

---

## 10. What cannot be configured from source code

* DNS records and the SSL certificate (hPanel / registrar).
* The PHP version and its extensions (hPanel).
* HTTPS forcing and the LiteSpeed cache (hPanel).
* Cron jobs for price refresh, if your installation uses them — the code is
  unchanged, so any existing cron entry keeps working after deployment; just
  confirm the path still points at the same script.
