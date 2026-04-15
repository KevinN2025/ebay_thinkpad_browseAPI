#!/usr/bin/env bash
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BIN="$DIR/ebay_find"
LOG="$DIR/refresh.log"

queries=(
  "x1 carbon thinkpad"
  "t14 thinkpad"
  "t14s thinkpad"
  "t480 thinkpad"
  "t490 thinkpad"
  "t490s thinkpad"
  "x13 thinkpad"
  "p14s thinkpad"
  "W520 thinkpad"
  "X201 thinkpad"
)

cd "$DIR"

echo "[$(date -u '+%Y-%m-%d %H:%M:%S UTC')] === Poll started ===" >> "$LOG"

for q in "${queries[@]}"; do
  echo "[$(date -u '+%Y-%m-%d %H:%M:%S UTC')] Polling: $q" >> "$LOG"
  "$BIN" -query "$q" -limit 50 >> "$LOG" 2>&1 || true
done

echo "[$(date -u '+%Y-%m-%d %H:%M:%S UTC')] === Poll complete ===" >> "$LOG"
