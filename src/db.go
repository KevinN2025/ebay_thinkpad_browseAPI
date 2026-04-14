package main

import (
	"context"
	"database/sql"
	"fmt"
	"strings"
	"time"

	_ "github.com/go-sql-driver/mysql"
)

type dbStore struct {
	db *sql.DB
}

func openDB(dsn string) (*dbStore, error) {
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		return nil, err
	}
	if err := db.Ping(); err != nil {
		db.Close()
		return nil, fmt.Errorf("db ping: %w", err)
	}
	return &dbStore{db: db}, nil
}

// hasSeen returns true if listing_key is already in seen_listings.
func (s *dbStore) hasSeen(ctx context.Context, listingKey string) (bool, error) {
	var count int
	err := s.db.QueryRowContext(ctx,
		"SELECT COUNT(*) FROM seen_listings WHERE listing_key = ?", listingKey,
	).Scan(&count)
	return count > 0, err
}

// recordSeen inserts the item into seen_listings (no-op if already present).
func (s *dbStore) recordSeen(ctx context.Context, item itemSummary, matchedModel string) error {
	listingKey := "url:" + item.ItemWebURL
	_, err := s.db.ExecContext(ctx, `
		INSERT IGNORE INTO seen_listings
			(listing_key, item_id, item_url, title, matched_model,
			 listed_at, origin_listed_at,
			 price_value, price_currency, condition_label,
			 item_end_date, buying_options,
			 current_bid_value, current_bid_currency, bid_count)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
		listingKey,
		nullStr(item.ItemID),
		item.ItemWebURL,
		item.Title,
		matchedModel,
		nullTime(parseEbayTime(item.ItemCreationDate)),
		nullTime(parseEbayTime(item.ItemCreationDate)),
		nullStr(item.Price.Value),
		nullStr(item.Price.Currency),
		nullStr(item.Condition),
		nullTime(parseEbayTime(item.ItemEndDate)),
		nullStr(strings.Join(item.BuyingOptions, ",")),
		nullStr(item.CurrentBidPrice.Value),
		nullStr(item.CurrentBidPrice.Currency),
		item.BidCount,
	)
	return err
}

// upsertBuyNow inserts the item into buy_now (no-op if already present).
func (s *dbStore) upsertBuyNow(ctx context.Context, item itemSummary, matchedModel string) error {
	listingKey := "url:" + item.ItemWebURL
	_, err := s.db.ExecContext(ctx, `
		INSERT IGNORE INTO buy_now
			(listing_key, title, matched_model,
			 price_value, price_currency, condition_label,
			 item_url, item_id,
			 listed_at, origin_listed_at, buying_options)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
		listingKey,
		item.Title,
		matchedModel,
		nullStr(item.Price.Value),
		nullStr(item.Price.Currency),
		nullStr(item.Condition),
		item.ItemWebURL,
		nullStr(item.ItemID),
		nullTime(parseEbayTime(item.ItemCreationDate)),
		nullTime(parseEbayTime(item.ItemCreationDate)),
		nullStr(strings.Join(item.BuyingOptions, ",")),
	)
	return err
}

// upsertAuction inserts the item into auctions (no-op if already present).
func (s *dbStore) upsertAuction(ctx context.Context, item itemSummary, matchedModel string) error {
	listingKey := "url:" + item.ItemWebURL
	_, err := s.db.ExecContext(ctx, `
		INSERT IGNORE INTO auctions
			(listing_key, item_id, item_url, title, matched_model,
			 listed_at, origin_listed_at,
			 price_value, price_currency, condition_label,
			 item_end_date, buying_options,
			 current_bid_value, current_bid_currency, bid_count)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
		listingKey,
		nullStr(item.ItemID),
		item.ItemWebURL,
		item.Title,
		matchedModel,
		nullTime(parseEbayTime(item.ItemCreationDate)),
		nullTime(parseEbayTime(item.ItemCreationDate)),
		nullStr(item.Price.Value),
		nullStr(item.Price.Currency),
		nullStr(item.Condition),
		nullTime(parseEbayTime(item.ItemEndDate)),
		nullStr(strings.Join(item.BuyingOptions, ",")),
		nullStr(item.CurrentBidPrice.Value),
		nullStr(item.CurrentBidPrice.Currency),
		item.BidCount,
	)
	return err
}

// setMeta upserts a key/value pair in app_meta.
func (s *dbStore) setMeta(ctx context.Context, key, value string) error {
	_, err := s.db.ExecContext(ctx, `
		INSERT INTO app_meta (meta_key, meta_value) VALUES (?, ?)
		ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)`,
		key, value,
	)
	return err
}

// saveItem checks seen_listings, then persists to seen_listings and either
// buy_now or auctions based on BuyingOptions. Returns true if the item was new.
func (s *dbStore) saveItem(ctx context.Context, item itemSummary, matchedModel string) (bool, error) {
	listingKey := "url:" + item.ItemWebURL
	seen, err := s.hasSeen(ctx, listingKey)
	if err != nil {
		return false, err
	}
	if seen {
		return false, nil
	}

	if err := s.recordSeen(ctx, item, matchedModel); err != nil {
		return false, err
	}

	isAuction := false
	for _, opt := range item.BuyingOptions {
		if opt == "AUCTION" {
			isAuction = true
			break
		}
	}

	if isAuction {
		if err := s.upsertAuction(ctx, item, matchedModel); err != nil {
			return false, err
		}
	} else {
		if err := s.upsertBuyNow(ctx, item, matchedModel); err != nil {
			return false, err
		}
	}

	return true, nil
}

// parseEbayTime parses an RFC3339 timestamp from the eBay API.
// Returns the zero time if the string is empty or unparseable.
func parseEbayTime(s string) time.Time {
	if s == "" {
		return time.Time{}
	}
	t, err := time.Parse(time.RFC3339, s)
	if err != nil {
		return time.Time{}
	}
	return t
}

// nullStr returns nil for an empty string, otherwise the string itself.
// This lets database/sql bind NULL for optional VARCHAR columns.
func nullStr(s string) interface{} {
	if s == "" {
		return nil
	}
	return s
}

// nullTime returns nil for a zero time, otherwise a MySQL-formatted string.
func nullTime(t time.Time) interface{} {
	if t.IsZero() {
		return nil
	}
	return t.UTC().Format("2006-01-02 15:04:05")
}
