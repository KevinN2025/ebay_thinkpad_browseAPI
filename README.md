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
