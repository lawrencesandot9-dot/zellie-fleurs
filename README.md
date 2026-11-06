# Zellie Fleurs

A premium floral boutique built with plain PHP 8 and SQLite, designed to run comfortably in Termux on Android. The storefront, cart, checkout, order tracking and administrator studio are all functional end to end.

## Quick start

```bash
cd ~/zellie-fleurs
./serve.sh
```

Then open:

- Storefront: <http://127.0.0.1:8000/>
- Studio: <http://127.0.0.1:8000/admin/login.php>

To expose the shop to another device on the same network:

```bash
HOST=0.0.0.0 PORT=8000 ./serve.sh
```

The PHP document root is `public/`. The `config/`, `data/` and `src/` directories are outside the web root.

## Deployment

For a production VPS deployment with Nginx or Apache, HTTPS, permissions, backups, and verification steps, see [`deploy/DEPLOYMENT.md`](deploy/DEPLOYMENT.md).

## Administrator setup

On the first database initialisation, a random administrator password is generated and written to:

```
data/initial_admin_credentials.txt
```

That file is outside the public web root and is created with private permissions. Sign in, open **Settings → Security**, change the password, then delete the credentials file.

You can also configure credentials before initialising the database:

```bash
ZF_ADMIN_USER=admin ZF_ADMIN_PASSWORD='choose-a-strong-password' php bin/init_db.php
```

Or create/reset an administrator later:

```bash
php bin/create_admin.php admin 'a-new-strong-password'
```

The login form is protected by CSRF tokens, password hashing (`password_hash` / `password_verify`), session regeneration after sign-in, secure cookie settings where supported, and a basic 5-attempt / 15-minute lockout.

## Database

- SQLite database: `data/shop.db`
- Bootstrap/schema: `config/database.php` + `src/database.php`
- Initialise or inspect without resetting: `php bin/init_db.php`
- The schema is created idempotently. Sample products and settings are inserted only into a brand-new database.
- To intentionally start over, move the existing `data/shop.db` aside (it is already covered by the backup) and run `php bin/init_db.php`.

Tables:

- `products` — catalogue, stock, active flag, timestamps
- `orders` — customer/delivery data, server-calculated totals, item snapshot JSON, status, timestamps
- `admin_users` — hashed administrator credentials
- `login_attempts` — basic sign-in throttling
- `settings` — editable shop information

## Features

- Dynamic storefront with announcement bar, hero, featured collections, searchable/filterable catalogue, about/contact sections and footer.
- Product cards load from SQLite, show local photography, availability and an add-to-cart action.
- Persistent localStorage cart with quantity controls, removal, subtotal, badge, validation and empty state.
- Secure checkout with server-side price/total calculation, stock verification, GCash or COD payment, CSRF protection and duplicate-submission protection.
- Order confirmation and public tracking with a visual progress indicator for all six statuses.
- Administrator dashboard with status counts, revenue (excluding cancellations), recent orders, order search/filter/sort, full order details and status updates.
- Product management with add/edit/delete, image upload validation (JPEG/PNG/WebP, 3 MB limit, random filenames), activation toggles, and soft deactivation for products referenced by orders.
- Editable shop settings for contact details, GCash details, address, hours, announcement and delivery information.
- Responsive storefront and studio UI with no framework or build step.

## Security notes

- PDO prepared statements throughout.
- Server never trusts client prices, product names, totals or availability.
- Orders are created inside a transaction with conditional stock updates.
- Unique order references and an idempotency token prevent accidental duplicate orders.
- All forms use CSRF tokens; all dynamic output is escaped with `htmlspecialchars()`.
- Uploaded files are MIME/type/size validated, renamed with random bytes and stored in `public/uploads/`.
- Admin pages require an authenticated session; logout is a POST request with CSRF protection.
- Database errors are logged privately, not displayed to customers.

## Tests

```bash
php tests/run.php
./tests/http_test.sh
```

`tests/run.php` covers schema/seeding, catalogue queries, checkout validation, price manipulation, stock updates, duplicate prevention, status changes, tracking, admin authentication, product CRUD, image validation and settings. `tests/http_test.sh` starts a temporary PHP server with a temporary database and verifies the real storefront, admin login, product creation/upload/deactivation, checkout, tracking, status updates, logout and CSRF behaviour over HTTP.

Other checks:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
node --check public/assets/js/app.js
sqlite3 data/shop.db 'PRAGMA integrity_check;'
```

## Project layout

```
zellie-fleurs/
├── bin/
│   ├── create_admin.php
│   └── init_db.php
├── config/
│   └── database.php
├── data/                  # SQLite database + private setup files (not web-accessible)
├── public/                # web document root
│   ├── admin/
│   │   ├── index.php
│   │   ├── login.php
│   │   ├── logout.php
│   │   ├── orders.php
│   │   ├── products.php
│   │   ├── settings.php
│   │   └── view_order.php
│   ├── api/products.php
│   ├── assets/
│   ├── uploads/
│   ├── checkout.php
│   ├── index.php
│   └── track.php
├── src/
│   ├── auth.php
│   ├── database.php
│   ├── helpers.php
│   ├── orders.php
│   ├── products.php
│   ├── settings.php
│   └── views/
├── tests/
│   ├── http_test.sh
│   └── run.php
├── README.md
└── serve.sh
```

## Backup

The original project and databases were archived before the rebuild at:

```
~/zellie-fleurs-backups/zellie-fleurs-20261104-111709.tar.gz
```

A verified extracted copy is also available at `~/zellie-fleurs-backups/zellie-fleurs-20261104-111709-copy/`. The `LATEST` file in that directory records the active backup timestamp.
