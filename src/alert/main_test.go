package main

import (
	"context"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestFilterLaptopListingsKeepsLaptopMatches(t *testing.T) {
	items := []itemSummary{
		{Title: "Lenovo ThinkPad X220 laptop", ItemID: "1"},
		{Title: "Lenovo ThinkPad X220 charger", ItemID: "2"},
		{Title: "Lenovo ThinkPad X220 motherboard", ItemID: "3"},
		{Title: "Lenovo ThinkPad T480", ItemID: "4"},
	}

	got := filterLaptopListings(items, "x220 thinkpad")
	if len(got) != 1 {
		t.Fatalf("unexpected number of matches: %d", len(got))
	}
	if got[0].ItemID != "1" {
		t.Fatalf("unexpected match: %#v", got[0])
	}
}

func TestFilterLaptopListingsRequiresRequestedModel(t *testing.T) {
	items := []itemSummary{
		{Title: "Lenovo ThinkPad X230", ItemID: "1"},
		{Title: "Lenovo ThinkPad X220", ItemID: "2"},
	}

	got := filterLaptopListings(items, "x220 thinkpad")
	if len(got) != 1 {
		t.Fatalf("unexpected number of matches: %d", len(got))
	}
	if got[0].ItemID != "2" {
		t.Fatalf("unexpected match: %#v", got[0])
	}
}

func TestLoadSeenStateMissingFile(t *testing.T) {
	seen, loaded, err := loadSeenState("does-not-exist.json")
	if err != nil {
		t.Fatal(err)
	}
	if loaded {
		t.Fatal("expected missing state file to report not loaded")
	}
	if len(seen) != 0 {
		t.Fatalf("expected empty seen set, got %d", len(seen))
	}
}

func TestLoadSeenStateSupportsLegacyItemIDs(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "state.json")

	if err := os.WriteFile(path, []byte("{\n  \"seen_item_ids\": [\"123\"]\n}\n"), 0o644); err != nil {
		t.Fatal(err)
	}

	seen, loaded, err := loadSeenState(path)
	if err != nil {
		t.Fatal(err)
	}
	if !loaded {
		t.Fatal("expected existing state file to report loaded")
	}
	if _, ok := seen["id:123"]; !ok {
		t.Fatalf("expected legacy item id to be migrated into seen keys, got %v", seen)
	}
}

func TestSaveSeenStatePersistsListingKeys(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "state.json")

	err := saveSeenState(path, map[string]struct{}{
		"id:123":                              {},
		"url:https://www.ebay.com/itm/123456": {},
	})
	if err != nil {
		t.Fatal(err)
	}

	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}

	content := string(data)
	if !strings.Contains(content, "\"seen_item_ids\"") {
		t.Fatalf("expected seen_item_ids in saved state, got %s", content)
	}
	if !strings.Contains(content, "\"123\"") {
		t.Fatalf("expected item id in saved state, got %s", content)
	}
	if !strings.Contains(content, "\"seen_listing_keys\"") {
		t.Fatalf("expected seen_listing_keys in saved state, got %s", content)
	}
	if !strings.Contains(content, "\"url:https://www.ebay.com/itm/123456\"") {
		t.Fatalf("expected listing key in saved state, got %s", content)
	}
}

func TestListingKeyPrefersNormalizedURL(t *testing.T) {
	item := itemSummary{
		ItemID:     "123",
		ItemWebURL: "https://www.ebay.com/itm/123456?hash=itemabc#details",
	}

	if got := listingKey(item); got != "url:https://www.ebay.com/itm/123456" {
		t.Fatalf("unexpected listing key: %q", got)
	}
}

func TestFormatListingTimeUsesTimestamp(t *testing.T) {
	loc := time.Local
	time.Local = time.FixedZone("EDT", -4*60*60)
	defer func() {
		time.Local = loc
	}()

	got := formatListingTime("2026-03-30T12:34:56Z")
	if got != "2026-03-30 08:34:56 EDT" {
		t.Fatalf("unexpected formatted time: %q", got)
	}
}

func TestHandleIndexRendersDashboard(t *testing.T) {
	app := &app{
		interval:    5 * time.Minute,
		lastPoll:    time.Date(2026, time.March, 31, 12, 0, 0, 0, time.UTC),
		lastMessage: "Found 1 new listing(s)",
		alerts: []uiAlert{{
			Title:     "Lenovo ThinkPad X220",
			Model:     "X220 THINKPAD",
			ListedAt:  "2026-03-30 08:34:56 EDT",
			ItemID:    "123",
			URL:       "https://www.ebay.com/itm/123",
			Condition: "Used",
		}},
	}

	req := httptest.NewRequest(http.MethodGet, "/", nil)
	rec := httptest.NewRecorder()

	app.handleIndex(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("unexpected status code: %d", rec.Code)
	}
	body := rec.Body.String()
	for _, want := range []string{
		"ThinkPad Alert Monitor",
		"Found 1 new listing(s)",
		"Lenovo ThinkPad X220",
		"https://www.ebay.com/itm/123",
	} {
		if !strings.Contains(body, want) {
			t.Fatalf("expected body to contain %q, got %s", want, body)
		}
	}
}

func TestHandleRefreshRequiresPost(t *testing.T) {
	app := &app{}

	req := httptest.NewRequest(http.MethodGet, "/refresh", nil)
	rec := httptest.NewRecorder()

	app.handleRefresh(rec, req)

	if rec.Code != http.StatusMethodNotAllowed {
		t.Fatalf("unexpected status code: %d", rec.Code)
	}
}

func TestAuthSessionReturnsStaticTokenWithoutCredentials(t *testing.T) {
	session := newAuthSession(config{AccessToken: "static-token"}, func(context.Context) (tokenResponse, error) {
		t.Fatal("fetcher should not be called without client credentials")
		return tokenResponse{}, nil
	})

	got, err := session.token(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if got != "static-token" {
		t.Fatalf("unexpected token: %q", got)
	}
}

func TestAuthSessionRefreshesAndCachesToken(t *testing.T) {
	calls := 0
	session := newAuthSession(config{
		ClientID:     "client-id",
		ClientSecret: "client-secret",
	}, func(context.Context) (tokenResponse, error) {
		calls++
		return tokenResponse{AccessToken: "fresh-token", ExpiresIn: 7200}, nil
	})

	first, err := session.token(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	second, err := session.token(context.Background())
	if err != nil {
		t.Fatal(err)
	}

	if first != "fresh-token" || second != "fresh-token" {
		t.Fatalf("unexpected tokens: %q %q", first, second)
	}
	if calls != 1 {
		t.Fatalf("expected one refresh call, got %d", calls)
	}
}

func TestTokenExpiryDeadlineImmediateForShortExpiry(t *testing.T) {
	deadline := tokenExpiryDeadline(120)
	if time.Until(deadline) > time.Second {
		t.Fatalf("expected immediate refresh deadline, got %s", time.Until(deadline))
	}
}
