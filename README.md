# Thinkpad Tracker

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

## Query Example to find instances of Thinkpad T480
```bash
ef --query "Thinkpad T480" --limit 10
```
***Output***
<img width="1398" height="764" alt="image" src="https://github.com/user-attachments/assets/785d458c-74cf-4aa2-8d63-67c6869c948b" />

## Lazysql Usage
Within my repository i have a flake that installs lazysql and the necessary configuration needed to start it. Of course for the URL section of your lazysql config.toml you 
will hav to change it to your databse username and password. Here is how your lazysql database should look like:

Install the flake 
```bash
nidx develop
```
After the packages are installed and the config has been written.
```bash
lazysql
```
Give love to the lazysql author: 
https://github.com/jorgerojas26/lazysql.git

***Screenshot***
<img width="1860" height="966" alt="image" src="https://github.com/user-attachments/assets/d3b91def-4965-43b1-8bf1-807542db18e4" />

## Man Page
I also added a man page. However, there is an error within that man page that I still have to resolve. 

In the example section flags actually need to be within quotation marks.

<img width="1699" height="588" alt="image" src="https://github.com/user-attachments/assets/3448faee-c664-4cb2-b786-678c3e95eb5a" />


