#!/usr/bin/env bash
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BIN="$DIR/ef"
LOG="$DIR/refresh.log"

queries=(
  "x1 carbon thinkpad"
  "X1 Carbon Thinkpad"
  "t14 thinkpad"
  "T14 Thinkpad"
  "t14s thinkpad"
  "T14s Thinkpad"
  "t480 thinkpad"
  "T480 Thinkpad"
  "t490 thinkpad"
  "T490 Thinkpad"
  "t490s thinkpad"
  "T490s Thinkpad"
  "x13 thinkpad"
  "X13 Thinkpad"
  "p14s thinkpad"
  "P14s Thinkpad"
  "w520 thinkpad"
  "W520 Thinkpad"
  "x201 thinkpad"
  "X201 Thinkpad"
)

cd "$DIR"

echo "[$(TZ='America/New_York' date '+%Y-%m-%d %H:%M:%S %Z')] === Poll started ===" >> "$LOG"

for q in "${queries[@]}"; do
  echo "[$(TZ='America/New_York' date '+%Y-%m-%d %H:%M:%S %Z')] Polling: $q" >> "$LOG"
  "$BIN" --query "$q" --limit 50 >> "$LOG" 2>&1 || true
done

echo "[$(TZ='America/New_York' date '+%Y-%m-%d %H:%M:%S %Z')] === Poll complete ===" >> "$LOG"
