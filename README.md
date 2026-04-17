# EbayFind

***First and foremost, clone the repository***
```bash
git clone https://github.com/KevinN2025/EbayFind.git
cd EbayFind
```

***Install the dependencies via the Makefile***
```bash
sudo make install
```

***EbayFind(ef)***
```bash
ef --help

 Usage: ef --query <keywords> [options]

Options:
  -q, --query    <keywords>  Search keywords (required)
  -e, --exclude  <words>     Exclude titles containing these words
  -l, --limit    <n>         Number of results (default: 10)
  -o, --offset   <n>         Result offset for paging (default: 0)
  -f, --env-file <path>      Path to .env credentials file (default: .env)
  -d, --db-dsn   <dsn>       MariaDB DSN, overrides EBAY_DB_DSN
  -h, --help                 Show this help message

Examples:
  ef --query "ThinkPad X1 Carbon"
  ef --query "ThinkPad T480" -l 25 -e "parts only"
  ef --query "ThinkPad T480" --limit 10 --offset 10
  ef --query "ThinkPad X13" --db-dsn "user:pass@tcp(localhost:3306)/ef?parseTime=true"
```

## Query Example to find instances of Thinkpad T480
```bash
ef --query "Thinkpad T480" --limit 10
```
***Output***
<img width="1398" height="764" alt="image" src="https://github.com/user-attachments/assets/785d458c-74cf-4aa2-8d63-67c6869c948b" />

## Lazysql Usage
Within my repository i have a nix flake that installs lazysql and the necessary configuration needed to start it. Of course for the URL section of your lazysql config.toml you will have to change it to your databse username and password.

Install lazysql with flake.nix
```bash
nix develop
```
After the packages are installed and the config has been written.
```bash
lazysql
```
**Give love to the lazysql author. His work is greatly appreciated:** 
https://github.com/jorgerojas26/lazysql.git

***Screenshot***
<img width="1860" height="966" alt="image" src="https://github.com/user-attachments/assets/d3b91def-4965-43b1-8bf1-807542db18e4" />

## Man Page
Once you run the Makefile you can view the man page with
```bash
man ef
```
<img width="1418" height="135" alt="image" src="https://github.com/user-attachments/assets/29d13653-6c29-44c0-849f-3e0158a11d0d" />

## Refresh DB with crontab
I have the script refresh.sh that automatically runs via a crontab every 15 minutes. Thus, it is continuously updated and a file named refresh.log 
will be subsequently produced. 
```bash
*/15 * * * * $HOME/Documents/EbayFind/db/refresh.sh
```
## Note!
My specific Database and program are catered towards finding Thinkpads I am looking for resell and flipping purposes. Feel free to change the program for your specific needs
