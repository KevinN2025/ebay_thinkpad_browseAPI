package main

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

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
	Title            string      `json:"title"`
	ItemID           string      `json:"itemId"`
	ItemWebURL       string      `json:"itemWebUrl"`
	Condition        string      `json:"condition"`
	Price            moneyValue  `json:"price"`
	Seller           sellerBrief `json:"seller"`
	BuyingOptions    []string    `json:"buyingOptions"`
	ItemEndDate      string      `json:"itemEndDate"`
	CurrentBidPrice  moneyValue  `json:"currentBidPrice"`
	BidCount         int         `json:"bidCount"`
	ItemCreationDate string      `json:"itemCreationDate"`
}

type sellerBrief struct {
	Username string `json:"username"`
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
