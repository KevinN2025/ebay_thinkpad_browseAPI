package main

import (
	"context"
	"database/sql"
	"encoding/base64"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"time"

	_ "github.com/go-sql-driver/mysql"
)

type config struct {
	AccessToken   string
	ClientID      string
	ClientSecret  string
	Environment   string
	MarketplaceID string
	DBDSN         string
}

type tokenResponse struct {
	AccessToken string `json:"access_token"`
	TokenType   string `json:"token_type"`
	ExpiresIn   int    `json:"expires_in"`
}

type searchResponse struct {
	Total         int           `json:"total"`
	ItemSummaries []itemSummary `json:"itemSummaries"`
}

type itemSummary struct {
	Title            string     `json:"title"`
	ItemID           string     `json:"itemId"`
	ItemWebURL       string     `json:"itemWebUrl"`
	Condition        string     `json:"condition"`
	Price            moneyValue `json:"price"`
	Seller           sellerBrief `json:"seller"`
	BuyingOptions    []string   `json:"buyingOptions"`
	ItemEndDate      string     `json:"itemEndDate"`
	CurrentBidPrice  moneyValue `json:"currentBidPrice"`
	BidCount         int        `json:"bidCount"`
	ItemCreationDate string     `json:"itemCreationDate"`
}

type sellerBrief struct {
	Username string `json:"username"`
}

type moneyValue struct {
	Value    string `json:"value"`
	Currency string `json:"currency"`
}

type tokenFetcher func(context.Context) (tokenResponse, error)

type authSession struct {
	cfg         config
	fetchToken  tokenFetcher
	accessToken string
	expiresAt   time.Time
}

var defaultExcludedTitleTerms = []string{
	"ultrabase",
	"charger",
	"adapter",
	"ac adapter",
	"power adapter",
	"power supply",
	"dock",
	"docking station",
	"port replicator",
}

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

func loadConfig(envPath string) (config, error) {
	cfg := config{
		AccessToken:   strings.TrimSpace(os.Getenv("EBAY_ACCESS_TOKEN")),
		ClientID:      strings.TrimSpace(os.Getenv("EBAY_CLIENT_ID")),
		ClientSecret:  strings.TrimSpace(os.Getenv("EBAY_CLIENT_SECRET")),
		Environment:   strings.TrimSpace(os.Getenv("EBAY_ENV")),
		MarketplaceID: strings.TrimSpace(os.Getenv("EBAY_MARKETPLACE_ID")),
		DBDSN:         strings.TrimSpace(os.Getenv("EBAY_DB_DSN")),
	}

	if cfg.AccessToken == "" || cfg.ClientID == "" || cfg.ClientSecret == "" || cfg.Environment == "" || cfg.MarketplaceID == "" {
		fileCfg, err := loadDotEnvFile(envPath)
		if err != nil && !errors.Is(err, os.ErrNotExist) {
			return config{}, err
		}
		if cfg.AccessToken == "" {
			cfg.AccessToken = fileCfg.AccessToken
		}
		if cfg.ClientID == "" {
			cfg.ClientID = fileCfg.ClientID
		}
		if cfg.ClientSecret == "" {
			cfg.ClientSecret = fileCfg.ClientSecret
		}
		if cfg.Environment == "" {
			cfg.Environment = fileCfg.Environment
		}
		if cfg.MarketplaceID == "" {
			cfg.MarketplaceID = fileCfg.MarketplaceID
		}
		if cfg.DBDSN == "" {
			cfg.DBDSN = fileCfg.DBDSN
		}
	}

	if cfg.Environment == "" {
		cfg.Environment = "sandbox"
	}
	if cfg.MarketplaceID == "" {
		cfg.MarketplaceID = "EBAY_US"
	}

	if cfg.AccessToken == "" {
		if cfg.ClientID == "" {
			return config{}, errors.New("missing EBAY_CLIENT_ID")
		}
		if cfg.ClientSecret == "" {
			return config{}, errors.New("missing EBAY_CLIENT_SECRET")
		}
	}

	cfg.Environment = strings.ToLower(cfg.Environment)
	if cfg.Environment != "sandbox" && cfg.Environment != "production" {
		return config{}, fmt.Errorf("EBAY_ENV must be sandbox or production, got %q", cfg.Environment)
	}

	return cfg, nil
}

func loadDotEnvFile(path string) (config, error) {
	data, err := os.ReadFile(filepath.Clean(path))
	if err != nil {
		return config{}, err
	}

	lines := strings.Split(string(data), "\n")
	values := map[string]string{}
	for _, raw := range lines {
		line := strings.TrimSpace(raw)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		if key, value, ok := strings.Cut(line, "="); ok {
			values[strings.TrimSpace(key)] = strings.Trim(strings.TrimSpace(value), `"'`)
		}
	}

	cfg := config{
		AccessToken:   values["EBAY_ACCESS_TOKEN"],
		ClientID:      values["EBAY_CLIENT_ID"],
		ClientSecret:  values["EBAY_CLIENT_SECRET"],
		Environment:   values["EBAY_ENV"],
		MarketplaceID: values["EBAY_MARKETPLACE_ID"],
		DBDSN:         values["EBAY_DB_DSN"],
	}
	if cfg.AccessToken != "" || cfg.ClientID != "" || cfg.ClientSecret != "" {
		return cfg, nil
	}

	lookup := map[string]string{
		"App ID (Client ID)":      "client_id",
		"Cert ID (Client Secret)": "client_secret",
		"Dev ID":                  "dev_id",
	}

	legacy := map[string]string{}
	for i := 0; i < len(lines); i++ {
		label := strings.TrimSpace(lines[i])
		key, ok := lookup[label]
		if !ok {
			continue
		}
		if i+1 >= len(lines) {
			continue
		}
		value := strings.TrimSpace(lines[i+1])
		if value == "" {
			continue
		}
		legacy[key] = value
		i++
	}

	return config{
		ClientID:      legacy["client_id"],
		ClientSecret:  legacy["client_secret"],
		Environment:   "sandbox",
		MarketplaceID: "EBAY_US",
	}, nil
}

// ---- eBay HTTP client ----

type ebayClient struct {
	httpClient *http.Client
	baseURL    string
	tokenURL   string
	cfg        config
}

func newEbayClient(cfg config) ebayClient {
	host := "https://api.ebay.com"
	if cfg.Environment == "sandbox" {
		host = "https://api.sandbox.ebay.com"
	}

	return ebayClient{
		httpClient: &http.Client{Timeout: 20 * time.Second},
		baseURL:    host + "/buy/browse/v1",
		tokenURL:   host + "/identity/v1/oauth2/token",
		cfg:        cfg,
	}
}

func newAuthSession(cfg config, fetch tokenFetcher) authSession {
	return authSession{
		cfg:         cfg,
		fetchToken:  fetch,
		accessToken: cfg.AccessToken,
	}
}

func (a *authSession) token(ctx context.Context) (string, error) {
	if !a.canRefresh() {
		if a.accessToken == "" {
			return "", errors.New("missing EBAY_ACCESS_TOKEN")
		}
		return a.accessToken, nil
	}

	if a.accessToken != "" && time.Now().Before(a.expiresAt) {
		return a.accessToken, nil
	}

	resp, err := a.fetchToken(ctx)
	if err != nil {
		if a.accessToken != "" {
			return a.accessToken, nil
		}
		return "", err
	}

	a.accessToken = resp.AccessToken
	a.expiresAt = tokenExpiryDeadline(resp.ExpiresIn)
	return a.accessToken, nil
}

func (a authSession) canRefresh() bool {
	return a.cfg.ClientID != "" && a.cfg.ClientSecret != ""
}

func tokenExpiryDeadline(expiresIn int) time.Time {
	if expiresIn <= 120 {
		return time.Now()
	}
	return time.Now().Add(time.Duration(expiresIn-60) * time.Second)
}

func (c ebayClient) fetchAccessToken(ctx context.Context) (tokenResponse, error) {
	form := url.Values{}
	form.Set("grant_type", "client_credentials")
	form.Set("scope", "https://api.ebay.com/oauth/api_scope")

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.tokenURL, strings.NewReader(form.Encode()))
	if err != nil {
		return tokenResponse{}, err
	}

	creds := c.cfg.ClientID + ":" + c.cfg.ClientSecret
	req.Header.Set("Authorization", "Basic "+base64.StdEncoding.EncodeToString([]byte(creds)))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return tokenResponse{}, err
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return tokenResponse{}, err
	}

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return tokenResponse{}, fmt.Errorf("status %s: %s", resp.Status, strings.TrimSpace(string(body)))
	}

	var token tokenResponse
	if err := json.Unmarshal(body, &token); err != nil {
		return tokenResponse{}, err
	}
	if token.AccessToken == "" {
		return tokenResponse{}, errors.New("empty access token in response")
	}
	return token, nil
}

func (c ebayClient) search(ctx context.Context, token, query string, limit, offset int) (searchResponse, error) {
	var result searchResponse

	u, err := url.Parse(c.baseURL + "/item_summary/search")
	if err != nil {
		return result, err
	}

	params := u.Query()
	params.Set("q", query)
	params.Set("limit", fmt.Sprintf("%d", limit))
	params.Set("offset", fmt.Sprintf("%d", offset))
	u.RawQuery = params.Encode()

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u.String(), nil)
	if err != nil {
		return result, err
	}

	req.Header.Set("Authorization", "Bearer "+token)
	req.Header.Set("Accept", "application/json")
	req.Header.Set("X-EBAY-C-MARKETPLACE-ID", c.cfg.MarketplaceID)

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return result, err
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return result, err
	}

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return result, fmt.Errorf("status %s: %s", resp.Status, strings.TrimSpace(string(body)))
	}

	if err := json.Unmarshal(body, &result); err != nil {
		return result, err
	}
	return result, nil
}

func (c ebayClient) searchTitleContains(ctx context.Context, token, query, exclude string, limit, offset int) (searchResponse, error) {
	terms := splitQueryTerms(query)
	excludedTerms := combineExcludedTerms(exclude)
	if len(terms) == 0 {
		return searchResponse{}, nil
	}

	apiLimit := limit
	if apiLimit < 50 {
		apiLimit = 50
	}
	if apiLimit > 200 {
		apiLimit = 200
	}

	apiOffset := 0
	matches := make([]itemSummary, 0, limit+offset)
	lastTotal := 0

	for {
		resp, err := c.search(ctx, token, buildBroadQuery(terms), apiLimit, apiOffset)
		if err != nil {
			return searchResponse{}, err
		}
		lastTotal = resp.Total
		if len(resp.ItemSummaries) == 0 {
			break
		}

		matches = append(matches, filterItems(resp.ItemSummaries, terms, excludedTerms)...)
		if len(matches) >= offset+limit {
			break
		}

		apiOffset += len(resp.ItemSummaries)
		if apiOffset >= resp.Total || apiOffset >= 10000 {
			break
		}
	}

	start := offset
	if start > len(matches) {
		start = len(matches)
	}

	end := start + limit
	if end > len(matches) {
		end = len(matches)
	}

	return searchResponse{
		Total:         min(lastTotal, len(matches)),
		ItemSummaries: matches[start:end],
	}, nil
}

func splitQueryTerms(query string) []string {
	return strings.Fields(strings.ToLower(strings.TrimSpace(query)))
}

func combineExcludedTerms(exclude string) []string {
	terms := make([]string, 0, len(defaultExcludedTitleTerms)+len(strings.Fields(exclude)))
	terms = append(terms, defaultExcludedTitleTerms...)
	terms = append(terms, splitQueryTerms(exclude)...)
	return terms
}

func buildBroadQuery(terms []string) string {
	if len(terms) <= 1 {
		return strings.Join(terms, " ")
	}
	return "(" + strings.Join(terms, ", ") + ")"
}

func filterItems(items []itemSummary, requiredTerms, excludedTerms []string) []itemSummary {
	filtered := make([]itemSummary, 0, len(items))
	for _, item := range items {
		if !titleContainsAllTerms(item.Title, requiredTerms) {
			continue
		}
		if titleContainsAnyTerm(item.Title, excludedTerms) {
			continue
		}
		filtered = append(filtered, item)
	}
	return filtered
}

func titleContainsAllTerms(title string, terms []string) bool {
	normalizedTitle := strings.ToLower(title)
	for _, term := range terms {
		if !strings.Contains(normalizedTitle, term) {
			return false
		}
	}
	return true
}

func titleContainsAnyTerm(title string, terms []string) bool {
	normalizedTitle := strings.ToLower(title)
	for _, term := range terms {
		if strings.Contains(normalizedTitle, term) {
			return true
		}
	}
	return false
}

func min(a, b int) int {
	if a < b {
		return a
	}
	return b
}

func exitf(format string, args ...any) {
	fmt.Fprintf(os.Stderr, format+"\n", args...)
	os.Exit(1)
}

// ---- Database layer ----

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
