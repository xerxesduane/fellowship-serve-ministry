# Fellowship Dubai S.H.A.P.E. — PHP + JavaScript edition

This is a framework-free port of the Next.js/React application. It preserves the 19-stage journey, the complete workbook content, device-private autosave, required-answer validation, profile calculation, copy/email/print actions, and the Ministry–Gift Table.

## Requirements

- PHP 8.1 or newer
- Any modern browser with JavaScript enabled
- Apache, Nginx, Caddy, or PHP's built-in web server

## Run locally

```bash
php -S 127.0.0.1:8080
```

Open `http://127.0.0.1:8080`.

## Deploy

Point the web root at this directory. Apache can use the included `.htaccess`. For Nginx, route `/` to `index.php` and serve `assets/` directly.

The app has no database, API keys, environment variables, build step, or Node.js runtime. Answers remain in the browser under the original `fellowship-dubai-shape-v2` localStorage key, so users moving from the Next.js version on the same origin retain their progress.

The repository-level `vercel.json` publishes this directory as a static preview on Vercel using `index.html`. On a PHP-capable host, use `index.php` so the equivalent security headers are emitted by PHP.

## Main files

- `index.php` — PHP entry point, metadata, and security headers
- `assets/app.js` — journey UI, events, persistence, and exports
- `assets/profile.js` — profile calculations and plain-text export
- `assets/shapeContent.js` — canonical workbook content compiled to browser JavaScript
- `assets/ministryGiftTable.js` — serving-ministry guide
- `assets/styles.css` — responsive and print styling
