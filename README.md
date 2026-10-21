# Tiny PHP Image Host

A small, self-contained PHP image hosting script. Pick SQLite, MySQL/MariaDB,
or LibSQL/Turso at install time. Ships with Solarized Light + Solarized Dark
themes, and lets the admin pick exactly which image formats are accepted.

## Features

- **Three registration modes** (admin chooses):
  - `invite` — only people with an invite link can sign up *(default)*
  - `closed` — no public sign-ups; admin creates accounts manually
  - `open` — anyone can register
- **Solarized Light + Solarized Dark** built in, plus per-user toggle
  in the top bar (preference saved to `localStorage`). Default theme
  configurable in the admin panel (light / dark / auto-from-OS).
- **Configurable allowed formats**, defaults to **WebP + AVIF**.
  Selectable: PNG, JPEG, GIF, JPEG 2000 (jp2), JPEG XL (jxl), BMP,
  TIFF, HEIC/HEIF, ICO.
- Drag-and-drop upload, gallery, per-user "My images", admin user
  management (including password reset), invite generation/revocation.
  Logged-in users can change their own password from Account.
- Random short slugs, ETag/cache headers, deny-all `.htaccess` on
  uploads (PHP execution disabled), CSRF tokens, prepared statements,
  bcrypt password hashing, magic-byte format verification.

## Requirements

- PHP 8.0+ (uses `match`, named typed properties).
- Database (one of):
  - SQLite: `pdo_sqlite` (default, bundled with PHP).
  - MySQL/MariaDB: `pdo_mysql`.
  - LibSQL/Turso: `curl` extension; uses the HTTP pipeline API.
- `fileinfo` extension (also default).
- Apache (the `.htaccess` files do the lockdown). On nginx, replicate
  the rules: deny request to `/uploads/*.php*`, `/database/`, and
  any `*.db` / `*.sqlite` file; disable PHP handler under `/uploads/`.

## Install

1. Upload the entire folder to your web server (e.g., `public_html/img/`).
2. Make sure the `uploads/` and `database/` directories are writable
   by the PHP process (`chmod 755` is usually enough; some shared
   hosts need `775`).
3. Visit `https://yourdomain.tld/img/install.php` in a browser.
4. Pick a site name, an admin username + password, your starting
   registration mode, default theme, and **database engine** (SQLite file,
   MySQL/MariaDB, or LibSQL/Turso). Click **Install**.
5. The installer writes `config.php`, creates tables, and deletes
   itself. You'll be redirected to the login page.

That's it. Log in as admin, hit **Admin** in the top bar to fine-tune
everything (including the format whitelist) at any time.

## File layout

```
.htaccess          Top-level access rules
install.php        One-shot installer (auto-deletes on success)
config.php         Generated at install time
db.php             PDO connection + schema
functions.php      Auth, settings, format detection helpers
header.php         Shared HTML head + nav
footer.php         Shared footer + theme-toggle JS
index.php          Upload form + gallery
upload.php         Upload handler
view.php           Single-image page
i.php              Direct image delivery (mime + cache headers)
login.php          Login
logout.php         Logout
register.php       Sign-up (open or invite-only)
account.php        Change own password
admin.php          Admin: settings, users, invites, password reset
assets/style.css   Solarized Light + Dark stylesheet
uploads/           User-uploaded images (PHP exec disabled here)
database/          SQLite database when that engine is chosen (deny-all)
```

## Notes

- Direct image URLs look like `https://you.tld/img/i.php/abc12345.webp`
  (wget-friendly: no query string, filename has an extension). Logged-in
  users also get an HTML `<img>` snippet on the image page. Pretty
  `/i/abc12345.webp` aliases are enabled in `.htaccess` when `mod_rewrite`
  is available. The old `i.php?s=abc12345` form still works.
- `i.php` sends `X-Content-Type-Options: nosniff` and the correct
  MIME for each format, so browsers won't try to interpret an image
  as HTML.
- Magic-byte verification covers the formats `finfo` can be flaky on
  (JXL, JP2, AVIF/HEIC brand boxes, modern WebP).
- **No image transcoding is performed.** Files are stored as
  uploaded. If a user uploads a 50 MB JXL, that's what gets served.
  Tweak `max_size_mb` in admin settings.
- For SVG support: deliberately omitted because SVG can carry script
  payloads. If you need it, add it to `all_formats()` and run uploads
  through a sanitizer like `enshrined/svg-sanitize` first.

## Reset / reinstall

Delete `config.php` (and `database/site.db` if you used SQLite), then visit
`install.php` again (re-upload it if you don't have it anymore — the installer
self-deletes after successful setup).
