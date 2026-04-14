package main

import (
	"context"
	"errors"
	"time"
)

type tokenFetcher func(context.Context) (tokenResponse, error)

type authSession struct {
	cfg         config
	fetchToken  tokenFetcher
	accessToken string
	expiresAt   time.Time
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
