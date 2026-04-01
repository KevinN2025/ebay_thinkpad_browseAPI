package main

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"html/template"
	"io"
	"log"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"time"
)

type config struct {
	AccessToken   string
	ClientID      string
	ClientSecret  string
	Environment   string
	MarketplaceID string
}

type tokenResponse struct {
	AccessToken string `json:"access_token"`
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
	ItemCreationDate string     `json:"itemCreationDate"`
	ItemOriginDate   string     `json:"itemOriginDate"`
	MatchedModel     string     `json:"-"`
}

type moneyValue struct {
	Value    string `json:"value"`
	Currency string `json:"currency"`
}

type ebayClient struct {
	httpClient *http.Client
	baseURL    string
	tokenURL   string
	cfg        config
}

type alertState struct {
	SeenItemIDs     []string `json:"seen_item_ids"`
	SeenListingKeys []string `json:"seen_listing_keys"`
}

type tokenFetcher func(context.Context) (tokenResponse, error)

type authSession struct {
	cfg         config
	fetchToken  tokenFetcher
	accessToken string
	expiresAt   time.Time
}

type app struct {
	client        ebayClient
	session       authSession
	interval      time.Duration
	limit         int
	statePath     string
	alertExisting bool

	mu          sync.RWMutex
	seen        map[string]struct{}
	stateLoaded bool
	alerts      []uiAlert
	lastPoll    time.Time
	lastMessage string
	lastError   string
	polling     bool
}

type uiAlert struct {
	Title         string
	Model         string
	Price         string
	Condition     string
	URL           string
	ItemID        string
	ListedAt      string
	FirstListedAt string
	DiscoveredAt  string
}

type dashboardView struct {
	Now        string
	LastPoll   string
	NextPoll   string
	Status     string
	Error      string
	Polling    bool
	AlertCount int
	Interval   string
	Alerts     []uiAlert
	Models     []string
}

var trackedModels = []string{
	"x200 thinkpad",
	"t400 thinkpad",
	"w520 thinkpad",
	"t430 thinkpad",
	"x220 thinkpad",
	"x230 thinkpad",
	"t480 thinkpad",
}

var excludedTitleTerms = []string{
	"ultrabase",
	"charger",
	"adapter",
	"ac adapter",
	"power adapter",
	"power supply",
	"dock",
	"docking station",
	"port replicator",
	"keyboard",
	"palmrest",
	"touchpad",
	"bezel",
	"hinge",
	"motherboard",
	"logic board",
	"heatsink",
	"fan",
	"bottom case",
	"housing",
	"shell",
	"parts",
	"for parts",
	"parts only",
	"repair",
	"spares",
	"broken",
	"damaged",
	"replacement",
	"lcd assembly",
	"screen assembly",
}

func main() {
	envPath := flag.String("env-file", ".env", "Path to the env file")
	interval := flag.Duration("interval", 5*time.Minute, "Polling interval")
	limit := flag.Int("limit", 25, "Max search results to inspect per model per poll")
	statePath := flag.String("state-file", ".alert-state.json", "Path to the persisted alert state file")
	alertExisting := flag.Bool("alert-existing", false, "Alert on matches found during the first pass instead of seeding them as already seen")
	addr := flag.String("addr", "127.0.0.1:8081", "HTTP listen address")
	flag.Parse()

	cfg, err := loadConfig(*envPath)
	if err != nil {
		exitf("config error: %v", err)
	}

	client := newEbayClient(cfg)
	session := newAuthSession(cfg, client.fetchAccessToken)

	seen, stateLoaded, err := loadSeenState(*statePath)
	if err != nil {
		exitf("state error: %v", err)
	}

	application := &app{
		client:        client,
		session:       session,
		interval:      *interval,
		limit:         *limit,
		statePath:     *statePath,
		alertExisting: *alertExisting,
		seen:          seen,
		stateLoaded:   stateLoaded,
		lastMessage:   "Starting first poll",
	}

	go application.pollLoop()

	server := &http.Server{
		Addr:              *addr,
		Handler:           application.routes(),
		ReadHeaderTimeout: 5 * time.Second,
	}

	log.Printf("alert UI listening on http://%s", *addr)
	if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		exitf("server error: %v", err)
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

func pollOnce(client ebayClient, session *authSession, limit int, seen map[string]struct{}) ([]itemSummary, error) {
	results := make([]itemSummary, 0)

	for _, model := range trackedModels {
		ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
		token, err := session.token(ctx)
		if err != nil {
			cancel()
			return nil, fmt.Errorf("%s: %w", model, err)
		}
		resp, err := client.search(ctx, token, model, limit, 0)
		cancel()
		if err != nil {
			return nil, fmt.Errorf("%s: %w", model, err)
		}

		for _, item := range filterLaptopListings(resp.ItemSummaries, model) {
			item.MatchedModel = model
			key := listingKey(item)
			if key == "" {
				continue
			}
			if _, ok := seen[key]; ok {
				continue
			}
			seen[key] = struct{}{}
			results = append(results, item)
		}
	}

	return results, nil
}

func (a *app) routes() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/", a.handleIndex)
	mux.HandleFunc("/refresh", a.handleRefresh)
	return mux
}

func (a *app) pollLoop() {
	a.runPoll()

	ticker := time.NewTicker(a.interval)
	defer ticker.Stop()

	for range ticker.C {
		a.runPoll()
	}
}

func (a *app) runPoll() {
	a.mu.Lock()
	if a.polling {
		a.mu.Unlock()
		return
	}
	a.polling = true
	a.lastError = ""
	a.lastMessage = "Polling eBay for tracked models"
	a.mu.Unlock()

	newMatches, err := pollOnce(a.client, &a.session, a.limit, a.seenSnapshot())
	if err != nil {
		a.mu.Lock()
		a.polling = false
		a.lastError = err.Error()
		a.lastMessage = "Last poll failed"
		a.lastPoll = time.Now()
		a.mu.Unlock()
		return
	}

	now := time.Now()

	a.mu.Lock()
	defer a.mu.Unlock()

	if !a.stateLoaded && !a.alertExisting {
		a.lastMessage = fmt.Sprintf("Seeded %d existing listings without alerting", len(newMatches))
	} else if len(newMatches) == 0 {
		a.lastMessage = "No new matching laptop listings"
	} else {
		a.lastMessage = fmt.Sprintf("Found %d new listing(s)", len(newMatches))
		a.prependAlerts(newMatches, now)
	}

	for _, item := range newMatches {
		if key := listingKey(item); key != "" {
			a.seen[key] = struct{}{}
		}
	}

	if err := saveSeenState(a.statePath, a.seen); err != nil {
		a.lastError = err.Error()
		a.lastMessage = "Poll completed, but saving state failed"
	} else {
		a.stateLoaded = true
	}

	a.polling = false
	a.lastPoll = now
}

func (a *app) seenSnapshot() map[string]struct{} {
	a.mu.RLock()
	defer a.mu.RUnlock()

	clone := make(map[string]struct{}, len(a.seen))
	for key := range a.seen {
		clone[key] = struct{}{}
	}
	return clone
}

func (a *app) prependAlerts(items []itemSummary, now time.Time) {
	fresh := make([]uiAlert, 0, len(items))
	for _, item := range items {
		listedAt := formatListingTime(item.ItemCreationDate)
		firstListedAt := formatListingTime(item.ItemOriginDate)
		if firstListedAt == listedAt {
			firstListedAt = ""
		}

		fresh = append(fresh, uiAlert{
			Title:         item.Title,
			Model:         strings.ToUpper(item.MatchedModel),
			Price:         formatPrice(item.Price),
			Condition:     item.Condition,
			URL:           item.ItemWebURL,
			ItemID:        item.ItemID,
			ListedAt:      listedAt,
			FirstListedAt: firstListedAt,
			DiscoveredAt:  now.In(time.Local).Format("2006-01-02 15:04:05 MST"),
		})
	}

	a.alerts = append(fresh, a.alerts...)
	if len(a.alerts) > 150 {
		a.alerts = a.alerts[:150]
	}
}

func (a *app) handleIndex(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}

	a.mu.RLock()
	view := dashboardView{
		Now:        time.Now().In(time.Local).Format("2006-01-02 15:04:05 MST"),
		LastPoll:   formatMaybeTime(a.lastPoll),
		NextPoll:   formatMaybeTime(a.lastPoll.Add(a.interval)),
		Status:     a.lastMessage,
		Error:      a.lastError,
		Polling:    a.polling,
		AlertCount: len(a.alerts),
		Interval:   a.interval.String(),
		Alerts:     append([]uiAlert(nil), a.alerts...),
		Models:     append([]string(nil), trackedModels...),
	}
	if a.lastPoll.IsZero() {
		view.NextPoll = "Waiting for first poll"
	}
	a.mu.RUnlock()

	if err := dashboardTemplate.Execute(w, view); err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
	}
}

func (a *app) handleRefresh(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}

	go a.runPoll()
	http.Redirect(w, r, "/", http.StatusSeeOther)
}

func filterLaptopListings(items []itemSummary, model string) []itemSummary {
	modelTerms := splitTerms(model)
	filtered := make([]itemSummary, 0, len(items))

	for _, item := range items {
		title := strings.ToLower(item.Title)
		if !containsAllTerms(title, modelTerms) {
			continue
		}
		if containsAnyTerm(title, excludedTitleTerms) {
			continue
		}
		filtered = append(filtered, item)
	}

	return filtered
}

func emitAlerts(items []itemSummary) {
	if len(items) == 0 {
		fmt.Printf("[%s] No new matching laptop listings\n", time.Now().Format(time.RFC3339))
		return
	}

	for _, item := range items {
		fmt.Printf("\a[%s] New listing: %s\n", time.Now().Format(time.RFC3339), item.Title)
		if item.Price.Value != "" {
			fmt.Printf("Price: %s %s\n", item.Price.Value, item.Price.Currency)
		}
		if listedAt := formatListingTime(item.ItemCreationDate); listedAt != "" {
			fmt.Printf("Listed: %s\n", listedAt)
		}
		if firstListedAt := formatListingTime(item.ItemOriginDate); firstListedAt != "" && firstListedAt != formatListingTime(item.ItemCreationDate) {
			fmt.Printf("First listed: %s\n", firstListedAt)
		}
		if item.Condition != "" {
			fmt.Printf("Condition: %s\n", item.Condition)
		}
		if item.ItemWebURL != "" {
			fmt.Printf("URL: %s\n", item.ItemWebURL)
		}
		if item.ItemID != "" {
			fmt.Printf("Item ID: %s\n", item.ItemID)
		}
		fmt.Println()
	}
}

func formatPrice(price moneyValue) string {
	if price.Value == "" {
		return ""
	}
	return strings.TrimSpace(price.Value + " " + price.Currency)
}

func loadSeenState(path string) (map[string]struct{}, bool, error) {
	data, err := os.ReadFile(filepath.Clean(path))
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return map[string]struct{}{}, false, nil
		}
		return nil, false, err
	}

	var state alertState
	if err := json.Unmarshal(data, &state); err != nil {
		return nil, false, err
	}

	seen := make(map[string]struct{}, len(state.SeenItemIDs))
	for _, id := range state.SeenItemIDs {
		if strings.TrimSpace(id) == "" {
			continue
		}
		seen["id:"+id] = struct{}{}
	}
	for _, key := range state.SeenListingKeys {
		key = strings.TrimSpace(key)
		if key == "" {
			continue
		}
		seen[key] = struct{}{}
	}

	return seen, true, nil
}

func saveSeenState(path string, seen map[string]struct{}) error {
	ids := make([]string, 0, len(seen))
	keys := make([]string, 0, len(seen))
	for key := range seen {
		keys = append(keys, key)
		if rawID, ok := strings.CutPrefix(key, "id:"); ok && rawID != "" {
			ids = append(ids, rawID)
		}
	}
	slices.Sort(ids)
	slices.Sort(keys)

	data, err := json.MarshalIndent(alertState{
		SeenItemIDs:     ids,
		SeenListingKeys: keys,
	}, "", "  ")
	if err != nil {
		return err
	}
	data = append(data, '\n')

	return os.WriteFile(filepath.Clean(path), data, 0o644)
}

func loadConfig(envPath string) (config, error) {
	cfg := config{
		AccessToken:   strings.TrimSpace(os.Getenv("EBAY_ACCESS_TOKEN")),
		ClientID:      strings.TrimSpace(os.Getenv("EBAY_CLIENT_ID")),
		ClientSecret:  strings.TrimSpace(os.Getenv("EBAY_CLIENT_SECRET")),
		Environment:   strings.TrimSpace(os.Getenv("EBAY_ENV")),
		MarketplaceID: strings.TrimSpace(os.Getenv("EBAY_MARKETPLACE_ID")),
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
		if !ok || i+1 >= len(lines) {
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
	params.Set("sort", "newlyListed")
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

func listingKey(item itemSummary) string {
	if normalizedURL := normalizedListingURL(item.ItemWebURL); normalizedURL != "" {
		return "url:" + normalizedURL
	}
	if item.ItemID != "" {
		return "id:" + item.ItemID
	}

	title := strings.Join(strings.Fields(strings.ToLower(item.Title)), " ")
	if title == "" {
		return ""
	}

	return fmt.Sprintf("fallback:%s|%s|%s", title, item.Price.Value, firstNonEmpty(item.ItemCreationDate, item.ItemOriginDate))
}

func normalizedListingURL(raw string) string {
	if strings.TrimSpace(raw) == "" {
		return ""
	}

	u, err := url.Parse(raw)
	if err != nil {
		return strings.TrimSpace(raw)
	}

	u.RawQuery = ""
	u.Fragment = ""
	return u.String()
}

func formatListingTime(raw string) string {
	if strings.TrimSpace(raw) == "" {
		return ""
	}

	t, err := time.Parse(time.RFC3339, raw)
	if err != nil {
		return raw
	}

	return t.In(time.Local).Format("2006-01-02 15:04:05 MST")
}

func firstNonEmpty(values ...string) string {
	for _, value := range values {
		if strings.TrimSpace(value) != "" {
			return value
		}
	}
	return ""
}

func formatMaybeTime(t time.Time) string {
	if t.IsZero() {
		return "Not yet"
	}
	return t.In(time.Local).Format("2006-01-02 15:04:05 MST")
}

var dashboardTemplate = template.Must(template.New("dashboard").Parse(`<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ThinkPad Alert Monitor</title>
  <style>
    :root {
      --bg: #f2efe8;
      --panel: rgba(255,255,255,0.88);
      --panel-strong: #fffaf2;
      --line: rgba(49, 37, 26, 0.14);
      --text: #23170f;
      --muted: #6b5a4d;
      --accent: #b54829;
      --accent-dark: #7f2f1b;
      --ok: #1f6a42;
      --warn: #a03d23;
      --shadow: 0 18px 45px rgba(69, 38, 20, 0.12);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Georgia, "Times New Roman", serif;
      color: var(--text);
      background:
        radial-gradient(circle at top left, rgba(181,72,41,0.18), transparent 28rem),
        linear-gradient(160deg, #f7f1e8 0%, #ece3d4 50%, #f4eee6 100%);
      min-height: 100vh;
    }
    .shell {
      width: min(1120px, calc(100vw - 2rem));
      margin: 0 auto;
      padding: 2rem 0 3rem;
    }
    .hero {
      display: grid;
      gap: 1rem;
      grid-template-columns: 2fr 1fr;
      align-items: end;
      margin-bottom: 1.5rem;
    }
    .hero-card, .panel, .alert {
      background: var(--panel);
      border: 1px solid var(--line);
      box-shadow: var(--shadow);
      border-radius: 24px;
      backdrop-filter: blur(10px);
    }
    .hero-card {
      padding: 1.6rem;
      position: relative;
      overflow: hidden;
    }
    .hero-card::after {
      content: "";
      position: absolute;
      inset: auto -4rem -5rem auto;
      width: 14rem;
      height: 14rem;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(181,72,41,0.23), rgba(181,72,41,0));
    }
    h1 {
      margin: 0 0 0.4rem;
      font-size: clamp(2rem, 4vw, 3.3rem);
      line-height: 0.95;
      letter-spacing: -0.05em;
    }
    .subtitle {
      margin: 0;
      color: var(--muted);
      max-width: 48rem;
      font-size: 1rem;
    }
    .meta {
      display: grid;
      gap: 1rem;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      margin-top: 1.2rem;
    }
    .stat {
      padding-top: 0.9rem;
      border-top: 1px solid var(--line);
    }
    .stat-label {
      display: block;
      color: var(--muted);
      font-size: 0.83rem;
      text-transform: uppercase;
      letter-spacing: 0.09em;
    }
    .stat-value {
      display: block;
      margin-top: 0.25rem;
      font-size: 1.15rem;
    }
    .side {
      padding: 1.3rem;
      display: grid;
      gap: 0.9rem;
    }
    .status {
      padding: 0.8rem 0.95rem;
      border-radius: 16px;
      background: var(--panel-strong);
      border: 1px solid var(--line);
    }
    .status strong {
      display: block;
      margin-bottom: 0.25rem;
      font-size: 0.82rem;
      text-transform: uppercase;
      letter-spacing: 0.09em;
      color: var(--muted);
    }
    .status-error {
      border-color: rgba(160,61,35,0.3);
      color: var(--warn);
    }
    .button {
      appearance: none;
      border: 0;
      border-radius: 999px;
      padding: 0.95rem 1.2rem;
      background: linear-gradient(135deg, var(--accent), var(--accent-dark));
      color: white;
      font-size: 0.95rem;
      font-weight: 700;
      cursor: pointer;
      text-align: center;
    }
    .button:disabled {
      opacity: 0.65;
      cursor: wait;
    }
    .layout {
      display: grid;
      gap: 1.25rem;
      grid-template-columns: 1.1fr 2fr;
    }
    .panel {
      padding: 1.2rem;
    }
    .panel h2 {
      margin: 0 0 0.85rem;
      font-size: 1.1rem;
      letter-spacing: -0.02em;
    }
    .models {
      display: flex;
      flex-wrap: wrap;
      gap: 0.55rem;
    }
    .chip {
      padding: 0.45rem 0.75rem;
      border-radius: 999px;
      border: 1px solid var(--line);
      background: rgba(255,255,255,0.65);
      font-size: 0.9rem;
    }
    .alerts {
      display: grid;
      gap: 1rem;
    }
    .alert {
      padding: 1.15rem 1.15rem 1rem;
    }
    .alert-head {
      display: flex;
      justify-content: space-between;
      gap: 1rem;
      align-items: start;
      margin-bottom: 0.8rem;
    }
    .alert-head h3 {
      margin: 0;
      font-size: 1.12rem;
    }
    .badge {
      white-space: nowrap;
      padding: 0.35rem 0.65rem;
      border-radius: 999px;
      background: rgba(181,72,41,0.12);
      border: 1px solid rgba(181,72,41,0.18);
      color: var(--accent-dark);
      font-size: 0.82rem;
      letter-spacing: 0.05em;
      text-transform: uppercase;
    }
    .grid {
      display: grid;
      gap: 0.65rem 1rem;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      color: var(--muted);
      font-size: 0.94rem;
    }
    .grid strong {
      color: var(--text);
    }
    .link {
      color: var(--accent-dark);
      text-decoration-thickness: 0.08em;
      text-underline-offset: 0.15em;
      word-break: break-all;
    }
    .empty {
      padding: 1.2rem;
      border-radius: 18px;
      border: 1px dashed var(--line);
      color: var(--muted);
      background: rgba(255,255,255,0.45);
    }
    @media (max-width: 860px) {
      .hero, .layout {
        grid-template-columns: 1fr;
      }
      .meta, .grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <main class="shell">
    <section class="hero">
      <article class="hero-card">
        <h1>ThinkPad Alert Monitor</h1>
        <p class="subtitle">Local watchlist UI for the tracked eBay models. It suppresses repeat alerts for the same listing, shows the actual eBay listing date, and keeps recent discoveries in view.</p>
        <div class="meta">
          <div class="stat">
            <span class="stat-label">Last Poll</span>
            <span class="stat-value">{{.LastPoll}}</span>
          </div>
          <div class="stat">
            <span class="stat-label">Next Poll</span>
            <span class="stat-value">{{.NextPoll}}</span>
          </div>
          <div class="stat">
            <span class="stat-label">Stored Alerts</span>
            <span class="stat-value">{{.AlertCount}}</span>
          </div>
        </div>
      </article>
      <aside class="hero-card side">
        <div class="status">
          <strong>Status</strong>
          <div>{{.Status}}</div>
        </div>
        {{if .Error}}
        <div class="status status-error">
          <strong>Error</strong>
          <div>{{.Error}}</div>
        </div>
        {{end}}
        <div class="status">
          <strong>Now</strong>
          <div>{{.Now}}</div>
        </div>
        <form method="post" action="/refresh">
          <button class="button" type="submit" {{if .Polling}}disabled{{end}}>{{if .Polling}}Polling…{{else}}Refresh Now{{end}}</button>
        </form>
      </aside>
    </section>

    <section class="layout">
      <aside class="panel">
        <h2>Tracking</h2>
        <p class="subtitle">Automatic poll interval: <strong>{{.Interval}}</strong></p>
        <div class="models">
          {{range .Models}}<span class="chip">{{.}}</span>{{end}}
        </div>
      </aside>
      <section class="panel">
        <h2>Recent Listings</h2>
        {{if .Alerts}}
        <div class="alerts">
          {{range .Alerts}}
          <article class="alert">
            <div class="alert-head">
              <h3>{{.Title}}</h3>
              {{if .Model}}<span class="badge">{{.Model}}</span>{{end}}
            </div>
            <div class="grid">
              {{if .Price}}<div><strong>Price:</strong> {{.Price}}</div>{{end}}
              {{if .Condition}}<div><strong>Condition:</strong> {{.Condition}}</div>{{end}}
              {{if .ListedAt}}<div><strong>Listed:</strong> {{.ListedAt}}</div>{{end}}
              {{if .FirstListedAt}}<div><strong>First listed:</strong> {{.FirstListedAt}}</div>{{end}}
              {{if .DiscoveredAt}}<div><strong>Seen by app:</strong> {{.DiscoveredAt}}</div>{{end}}
              {{if .ItemID}}<div><strong>Item ID:</strong> {{.ItemID}}</div>{{end}}
              {{if .URL}}<div><strong>URL:</strong> <a class="link" href="{{.URL}}" target="_blank" rel="noreferrer">{{.URL}}</a></div>{{end}}
            </div>
          </article>
          {{end}}
        </div>
        {{else}}
        <div class="empty">No alert history yet. The first pass may seed existing listings without displaying them unless you start with <code>-alert-existing</code>.</div>
        {{end}}
      </section>
    </section>
  </main>
</body>
</html>`))

func splitTerms(text string) []string {
	return strings.Fields(strings.ToLower(strings.TrimSpace(text)))
}

func containsAllTerms(title string, terms []string) bool {
	for _, term := range terms {
		if !strings.Contains(title, term) {
			return false
		}
	}
	return true
}

func containsAnyTerm(title string, terms []string) bool {
	for _, term := range terms {
		if strings.Contains(title, term) {
			return true
		}
	}
	return false
}

func exitf(format string, args ...any) {
	fmt.Fprintf(os.Stderr, format+"\n", args...)
	os.Exit(1)
}
