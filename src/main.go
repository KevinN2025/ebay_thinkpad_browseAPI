package main

import (
	"context"
	"flag"
	"fmt"
	"os"
	"strings"
	"time"
)

func main() {
	query := flag.String("query", "", "Search keywords, for example: -query \"thinkpad t14\"")
	exclude := flag.String("exclude", "", "Exclude listings whose titles contain these words in addition to the built-in accessory filters, for example: -exclude \"battery cracked\"")
	limit := flag.Int("limit", 10, "Number of items to return")
	offset := flag.Int("offset", 0, "Result offset")
	envPath := flag.String("env-file", ".env", "Path to the env file")
	dbDSN := flag.String("db-dsn", "", "MariaDB DSN (overrides EBAY_DB_DSN env var), e.g. user:pass@tcp(localhost:3306)/ebay_find?parseTime=true")
	flag.Parse()

	if strings.TrimSpace(*query) == "" {
		exitf("missing required -query argument")
	}

	cfg, err := loadConfig(*envPath)
	if err != nil {
		exitf("config error: %v", err)
	}
	if *dbDSN != "" {
		cfg.DBDSN = *dbDSN
	}

	client := newEbayClient(cfg)
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()

	session := newAuthSession(cfg, client.fetchAccessToken)
	token, err := session.token(ctx)
	if err != nil {
		exitf("token error: %v", err)
	}

	resp, err := client.searchTitleContains(ctx, token, *query, *exclude, *limit, *offset)
	if err != nil {
		exitf("search error: %v", err)
	}

	fmt.Printf("Found %d items\n\n", resp.Total)
	for i, item := range resp.ItemSummaries {
		fmt.Printf("%d. %s\n", i+1+*offset, item.Title)
		if item.Price.Value != "" {
			fmt.Printf("   Price: %s %s\n", item.Price.Value, item.Price.Currency)
		}
		if item.Condition != "" {
			fmt.Printf("   Condition: %s\n", item.Condition)
		}
		if len(item.BuyingOptions) > 0 {
			fmt.Printf("   Buying options: %s\n", strings.Join(item.BuyingOptions, ", "))
		}
		if item.CurrentBidPrice.Value != "" {
			fmt.Printf("   Current bid: %s %s (%d bids)\n", item.CurrentBidPrice.Value, item.CurrentBidPrice.Currency, item.BidCount)
		}
		if item.ItemEndDate != "" {
			fmt.Printf("   Ends: %s\n", item.ItemEndDate)
		}
		if item.Seller.Username != "" {
			fmt.Printf("   Seller: %s\n", item.Seller.Username)
		}
		if item.ItemWebURL != "" {
			fmt.Printf("   URL: %s\n", item.ItemWebURL)
		}
		if item.ItemID != "" {
			fmt.Printf("   Item ID: %s\n", item.ItemID)
		}
		fmt.Println()
	}

	if cfg.DBDSN == "" {
		return
	}

	store, err := openDB(cfg.DBDSN)
	if err != nil {
		fmt.Fprintf(os.Stderr, "db connect error: %v\n", err)
		return
	}
	defer store.db.Close()

	newCount := 0
	for _, item := range resp.ItemSummaries {
		isNew, saveErr := store.saveItem(ctx, item, *query)
		if saveErr != nil {
			fmt.Fprintf(os.Stderr, "db save error for %q: %v\n", item.ItemID, saveErr)
			continue
		}
		if isNew {
			newCount++
		}
	}

	msg := fmt.Sprintf("No new matching listings for %q", *query)
	if newCount > 0 {
		msg = fmt.Sprintf("%d new listing(s) found for %q", newCount, *query)
	}
	now := time.Now().UTC().Format("2006-01-02 15:04:05")
	_ = store.setMeta(ctx, "last_poll_at", now)
	_ = store.setMeta(ctx, "last_message", msg)
	_ = store.setMeta(ctx, "last_error", "")
	fmt.Printf("\nDB: %s\n", msg)
}

func exitf(format string, args ...any) {
	fmt.Fprintf(os.Stderr, format+"\n", args...)
	os.Exit(1)
}
