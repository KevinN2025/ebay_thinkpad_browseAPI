package main

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

type config struct {
	AccessToken   string
	ClientID      string
	ClientSecret  string
	Environment   string
	MarketplaceID string
	DBDSN         string
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
