Screenshot: 

<img width="1871" height="949" alt="image" src="https://github.com/user-attachments/assets/31d4baf9-a007-4c80-9ab0-e86584b173c5" />




Starting the eBay tracker for certain items.

Run a Browse API search from the repo root:

```bash
go run ./src -query "thinkpad" -limit 10
```

Multi-word queries are matched like a title `Ctrl+F`:

```bash
go run ./src -query "Thinkpad x200" -limit 10
```

That search now pulls broader eBay candidates and only keeps listings whose titles contain every query word, case-insensitively.

Searches automatically exclude common accessory-only matches such as `ultrabase`, `charger`, `adapter`, and `dock`.

You can also add your own unwanted title terms:

```bash
go run ./src -query "Thinkpad x200" -exclude "battery cracked" -limit 10
```

Config is loaded from environment variables or `.env`.

If you already have a Browse API bearer token, use that directly:

```bash
EBAY_ACCESS_TOKEN=...
EBAY_ENV=production
EBAY_MARKETPLACE_ID=EBAY_US
```

Supported env vars:

```bash
EBAY_ACCESS_TOKEN=...
EBAY_CLIENT_ID=...
EBAY_CLIENT_SECRET=...
EBAY_ENV=sandbox
EBAY_MARKETPLACE_ID=EBAY_US
```

When `EBAY_ACCESS_TOKEN` is set, the program uses it directly and skips the OAuth client-credentials exchange.

This repo also supports the raw credential format currently in `.env`, so it will work without rewriting that file first.

To run the PHP + MariaDB alert backend for newly listed ThinkPad laptops for the specific models `X200`, `T400`, `W520`, `T430`, `X220`, `X230`, and `T480`, first create a MariaDB database:

```sql
CREATE DATABASE ebay_find CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Add database settings to `.env` or your shell:

```bash
DB_HOST=127.0.0.1
DB_PORT=3306
DB_SOCKET=
DB_NAME=ebay_find
DB_USER=your_user
DB_PASSWORD=your_password
ALERT_POLL_INTERVAL_SECONDS=300
ALERT_LIMIT=25
ALERT_EXISTING=false
```

Use `DB_HOST=localhost` if you want MariaDB to resolve through the local socket automatically. If your MariaDB socket lives somewhere non-standard, set `DB_SOCKET` explicitly.

If you want the database to keep collecting without the web UI, use one of these:

One-shot poll:

```bash
php php/bin/poll.php
```

Long-running worker:

```bash
php php/bin/worker.php --interval=300
```

If you prefer the PHP app to stay in the background after reboot, run the one-shot poll from `cron` every 5 minutes:

```cron
*/5 * * * * cd /home/dispater/Documents/ebay_find && /usr/bin/php php/bin/poll.php >> /home/dispater/Documents/ebay_find/php/poll.log 2>&1
```

You can still start the local server on port `8081` if you want the dashboard:

```bash
php -S 127.0.0.1:8081 -t php/public php/router.php
```

Then open `http://127.0.0.1:8081`.

The alert UI:

- polls eBay repeatedly for those models
- filters out accessory and parts listings so it stays focused on full laptops
- saves seen listing keys and alert history in MariaDB so it only alerts once per listing
- shows the actual eBay listing date in the browser
- uses a red-on-black theme

The PHP backend auto-creates its tables inside the configured database on first run.

 Go alert UI:

```bash
go run ./src/alert -interval 5m
```
