# Sheets Sync (Google Sheets to MySQL)

> **Live Demo:** https://bulls-media.uniticmedia.com  
> **Login:** admin / Kevi1234?

This tool automatically reads data from multiple Google Sheets files and stores it in a MySQL database once a day. Everything is driven by configuration: which files to read, which tabs, which columns, and where each column goes in the database.

It's built with **Laravel** and **MySQL**, and comes with a simple admin UI to manage the sync settings.

## How it works

The system is split into two main parts:

1. **Configuration:** Stored in 3 database tables (`sync_sources`, `sync_mappings`, `sync_mapping_columns`) and managed through the admin UI.
2. **Execution:** A command (`php artisan sheets:sync`) runs daily. For every active mapping, it:
   - Reads the tab via the Google Sheets API.
   - Matches columns by their **header name** (not by position).
   - Converts values based on the configured type (date, number, text, etc.).
   - Creates the table or adds any missing columns.
   - Deletes the old rows for that mapping and inserts the new ones, all inside a single transaction.
   - Records the outcome in `sync_runs` (visible in the UI).

**Key features:**
* **Schema follows configuration.** If you add a new column in the mapping, the system automatically runs `ALTER TABLE ... ADD COLUMN` and fills it with data (even for existing rows).
* **Safe to re-run.** Every stored row remembers which mapping wrote it (`sync_mapping_id`). A run replaces exactly that mapping's rows in a transaction, so nothing gets duplicated and multiple files can write to the same table safely.

## Quick start (Ubuntu)

On a fresh Ubuntu 22.04 / 24.04 server, from the project root:

```bash
sudo ./scripts/setup-ubuntu.sh --with-demo --with-nginx
```

The script is idempotent. It installs PHP 8.3, MySQL and Composer, creates the database and user, writes `.env`,
installs dependencies, runs the migrations, and adds the cron entry that triggers the Laravel scheduler.
At the end it prints the admin password. Options:

| Option | Effect |
| --- | --- |
| `--with-demo` | Seeds an example source/mapping (`sales` table, as in the task description) |
| `--with-nginx` | Also installs nginx + PHP-FPM and serves the UI on port 80 (keep the project outside `/home`, e.g. `/var/www/sheets-sync`) |
| `--production` | `APP_ENV=production`, no dev dependencies, caches config/routes/views |

Without `--with-nginx` you can use `php artisan serve --host=0.0.0.0 --port=8000`.
Afterwards continue with [Google API credentials](#google-api-credentials).

## Manual setup

Requirements: PHP 8.2+ (extensions: mbstring, xml, curl, zip, bcmath, openssl, pdo_mysql), Composer, MySQL 8 / MariaDB 10.6+.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sheets_sync
DB_USERNAME=root
DB_PASSWORD=

GOOGLE_SHEETS_CREDENTIALS=storage/app/google/service-account.json   # absolute path recommended
SHEETS_SYNC_TIME=02:00                                              # daily run, server time
SHEETS_ADMIN_USER=admin
SHEETS_ADMIN_PASSWORD=choose-a-long-password
```

```bash
mysql -u root -e "CREATE DATABASE sheets_sync CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php artisan migrate
php artisan db:seed        # optional: demo mapping
php artisan serve          # http://127.0.0.1:8000
```

The UI is protected with HTTP Basic authentication (`SHEETS_ADMIN_USER` / `SHEETS_ADMIN_PASSWORD`).
Outside `local`/`testing` environments it stays closed until a password is set.

For the daily run the server needs one cron entry (the Ubuntu script installs it):

```cron
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

During local development you can use `php artisan schedule:work` instead.

## Google API credentials

The app reads sheets with a **service account**: a robot Google identity with its own key. It needs no interactive
login, and the key does not expire, which suits an unattended daily job. Access is read-only.

1. Open the [Google Cloud Console](https://console.cloud.google.com/) and create a project (or pick an existing one).
2. **APIs & Services -> Library**, search for **Google Sheets API** and click **Enable**.
3. **IAM & Admin -> Service Accounts -> Create service account.** Give it a name such as `sheets-sync`.
   No project roles are needed; click through to **Done**.
4. Open the new service account, go to the **Keys** tab, choose **Add key -> Create new key -> JSON**.
   A `.json` file is downloaded. Treat it like a password.
5. Put the file on the server and point the app at it:
   ```bash
   mkdir -p storage/app/google
   mv ~/Downloads/your-key-file.json storage/app/google/service-account.json
   chmod 600 storage/app/google/service-account.json
   ```
   (or set `GOOGLE_SHEETS_CREDENTIALS` to any other absolute path in `.env`).
   The folder is git-ignored so the key is never committed. The file must be readable by the user that runs the
   sync: the cron user and, for the UI's **Run** buttons, the PHP-FPM user (`www-data` when installed with
   `--with-nginx`; the setup script prints the exact `chown`/`chmod` commands).
6. Open the JSON file and copy the `client_email` value (`sheets-sync@your-project.iam.gserviceaccount.com`).
7. **Share every spreadsheet** you want to import with that e-mail address, as **Viewer**
   (Google Sheets -> Share). Without this step the API answers 403.
8. Copy the spreadsheet ID from its URL, `https://docs.google.com/spreadsheets/d/`**`<ID>`**`/edit`,
   and add it in the UI under **Sources -> Add file** (pasting the full URL also works).

If your Google Workspace organisation blocks service account keys (policy *Disable service account key creation*),
ask an administrator to allow it for this project.

| Message in the run history | Cause |
| --- | --- |
| `Google service account file not found ...` | Wrong `GOOGLE_SHEETS_CREDENTIALS` path. After editing `.env` on a server that caches config, run `php artisan config:clear`. |
| `Access denied. Share the spreadsheet with ...` | The file is not shared with the `client_email`, or the Sheets API is not enabled in the project. |
| `Spreadsheet not found` | Wrong spreadsheet ID. |
| `Tab '...' was not found` | The tab name in the mapping does not match the tab in the file. |
| `Google rejected the service account credentials` | The key was deleted/disabled in the Cloud Console. Create a new key. |

## Configuring a sync

In the UI: **Sources -> Add file**, then **Add mapping** on the file's page.

A mapping consists of:

* **Tab name** - which tab of the file to read.
* **Database table** - lowercase letters, digits, underscores; created automatically.
* **Header row** - the row holding the column titles (default 1; rows above it are ignored).
* **Columns** - for each: the sheet's header, the database column name, and a data type
  (`string`, `text`, `integer`, `decimal`, `boolean`, `date`, `datetime`).

The example from the task description - a file with columns `[date, product_name, price, amount]` stored in table
`sales` as `[date, name, product_price, amount]`:

| Sheet column | Database column | Type |
| --- | --- | --- |
| `date` | `date` | date |
| `product_name` | `name` | string |
| `price` | `product_price` | decimal |
| `amount` | `amount` | integer |

To send the same tab to a second table with a different structure, add another mapping with the same tab and a
different table. To add a field later, add a column to the mapping: the table is altered on the next run.

`php artisan db:seed` creates exactly this example (with a placeholder spreadsheet ID to replace).

The configuration lives in four tables, so it can also be edited with plain SQL if needed:
`sync_sources`, `sync_mappings`, `sync_mapping_columns`, and the run log `sync_runs`.

## Running and scheduling

```bash
php artisan sheets:sync                 # all active sources
php artisan sheets:sync --source=3      # one file
php artisan sheets:sync --mapping=7     # one mapping (also if paused)
php artisan schedule:list               # shows the daily entry
```

The command prints a result table and exits with a non-zero status if any mapping failed.
The UI has the same actions (**Run all now**, **Run this file now**, **Run** per mapping) and a run history with
row counts, newly added columns and warnings. Time of the daily run: `SHEETS_SYNC_TIME` (default `02:00`).

## Matching and conversion rules

**Headers.** Compared case-insensitively, ignoring punctuation and extra spaces: `Product Name`, `product_name` and
` PRODUCT-NAME ` are the same header. If a header occurs twice, the first one wins.

**Missing columns.** If a configured column is not in a file, its values are stored as `NULL` and the run is marked
`warning`. If *none* of the configured columns is found, the run fails (usually a wrong tab or header row).

**Empty rows** (all mapped cells blank) are skipped.

**Values.** Sheets are requested with unformatted values, so the result does not depend on the spreadsheet's locale:

| Type | Rules |
| --- | --- |
| `date`, `datetime` | Real date cells arrive as serial numbers and are converted exactly. Text is parsed as a fallback (`2026-03-04`, `03/04/2026` = US month/day). Impossible dates such as `2026-02-30` are rejected. |
| `integer` | Whole numbers only (`3`, `3.0`, `"1,000"`); `3.5` is rejected rather than rounded. |
| `decimal` | `1234.5`, `"1,234.50"`, `"1.234,56"`, `"12,5"`, `"$ 99"`. Stored as `DECIMAL(18,4)`. A lone `1,234` is read as one thousand two hundred thirty-four. |
| `boolean` | `TRUE/FALSE`, `yes/no`, `y/n`, `1/0`, `on/off`. |
| `string`, `text` | Trimmed; numbers are written without trailing zeros. |

A value that cannot be converted is stored as `NULL`; the run becomes `warning` and lists the first few offending
cells (row and column) in its message.

## Design decisions and limitations

* **Replace-per-mapping instead of append/upsert.** Sheets rarely have a stable primary key, so "replace this
  mapping's rows" is the only strategy that is always correct and idempotent: edits and deleted rows in the sheet are
  reflected, added columns are back-filled, and a failed run leaves the old data in place (the swap is one transaction).
  If you need a history of daily snapshots, add a `synced_on` column and change the delete condition in
  `SyncService::replaceRows`.
* **Schema changes only ever add.** Columns are never dropped, renamed or retyped, so nothing is lost when a mapping
  changes. If you rename a database column in a mapping, the old column stays (with its old data) and a new one is added.
  Every target column is nullable because a cell can always be empty.
* **Safe DDL.** Table and column names must match `^[a-z][a-z0-9_]*$`. The sync refuses to touch the application's own
  tables (`users`, `migrations`, `sessions`, ... - see `config/sheets-sync.php`) or any `sync_*` table, and refuses the
  names `id`, `sync_mapping_id`, `synced_at`, which it manages itself. Schema changes run outside the data transaction
  because MySQL commits DDL implicitly.
* **Every target table gets two bookkeeping columns:** `sync_mapping_id` (which mapping wrote the row) and
  `synced_at`. If you point a mapping at a table that already exists, they are added to it.
* **Deleting a source or mapping keeps the data** already stored in the target tables.
* **No overlapping runs.** A per-mapping lock stops two runs of the same mapping (for example cron and a click in the
  UI) from interfering; the scheduler entry uses `withoutOverlapping()` too.
* **Runs from the UI are synchronous.** That is fine for normal sheets; for very large files use the CLI/scheduler.
* **Tabs are read in full.** Rows are processed in memory and inserted in chunks. Very large tabs (hundreds of
  thousands of rows) would need streaming/pagination of the API response.
* **Authentication is HTTP Basic with one shared admin account.** Put the UI behind HTTPS (and a VPN/IP allow-list if
  possible); swap in Laravel's full auth if you need individual users.
* **Sheets API quotas** (about 60 read requests per minute per user) are far above what a daily job uses; 429/5xx
  responses are retried with back-off.

## Tests

```bash
php artisan test
```

The suite uses an in-memory SQLite database and a fake Sheets client, so it needs no network and no Google account:

* `tests/Unit` - header matching, value conversion, row extraction, name validation, JWT signing for the service account.
* `tests/Feature/SyncServiceTest.php` - files with different layouts into one table, one tab into several tables,
  automatic `ADD COLUMN` with back-fill, idempotent re-runs, protected tables, missing columns/tabs, the Artisan command
  and the schedule entry.
* `tests/Feature/AdminUiTest.php` - authentication, creating/editing/running sources and mappings through the UI,
  validation of unsafe input.

## Project layout

```
app/
  Console/Commands/SheetsSyncCommand.php     php artisan sheets:sync
  Http/Controllers/                          Dashboard, Source, Mapping, Run
  Http/Middleware/AdminBasicAuth.php
  Http/Requests/                             Source / Mapping validation
  Models/                                    SyncSource, SyncMapping, SyncMappingColumn, SyncRun
  Services/Sheets/                           SheetsClient (interface), GoogleSheetsClient, ServiceAccountToken
  Services/Sync/                             HeaderMatcher, ValueCaster, RowExtractor, SchemaGuard, TableManager, SyncService
config/sheets-sync.php                       credentials path, schedule time, protected tables, admin login
database/migrations/                         configuration + run-log tables
database/seeders/DatabaseSeeder.php         demo mapping from the task description
resources/views/                             Blade + Bootstrap 5 admin UI
routes/                                      web.php (UI), console.php (daily schedule)
scripts/setup-ubuntu.sh                      one-shot Ubuntu installer
tests/                                       unit + feature tests
```
