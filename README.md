### Thinkpad Tracker

***Install the dependencies via the Makefile***
```bash
sudo make install
```

***ef --help***
```bash
Usage of ef:
  -db-dsn string
        MariaDB DSN (overrides EBAY_DB_DSN env var), e.g. user:pass@tcp(localhost:3306)/ebay_find?parseTime=true
  -env-file string
        Path to the env file (default ".env")
  -exclude string
        Exclude listings whose titles contain these words in addition to the built-in accessory filters, for example: -exclude "battery cracked"
  -limit int
        Number of items to return (default 10)
  -offset int
        Result offset
  -query string
        Search keywords, for example: -query "thinkpad t14"
```

