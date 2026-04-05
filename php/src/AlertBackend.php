<?php

declare(strict_types=1);

final class AlertBackend
{
    private const DEFAULT_TRACKED_MODELS = [
        'X230 thinkpad',
        'T400 thinkpad',
        'X200 thinkpad',
        'T500 thinkpad',
        'W500 thinkpad',
        'T410 thinkpad',
        'T61 thinkpad',
        'W520 thinkpad',
        'X201 thinkpad',
    ];

    private const EXCLUDED_TITLE_TERMS = [
        'ultrabase',
        'charger',
        'adapter',
        'ac adapter',
        'power adapter',
        'power supply',
        'dock',
        'docking station',
        'port replicator',
        'keyboard',
        'palmrest',
        'touchpad',
        'bezel',
        'hinge',
        'motherboard',
        'logic board',
        'heatsink',
        'fan',
        'bottom case',
        'housing',
        'shell',
        'parts',
        'for parts',
        'parts only',
        'repair',
        'spares',
        'broken',
        'damaged',
        'replacement',
        'lcd assembly',
        'screen assembly',
        'hard drive',
        'stylus',
        'digitizer',
        'pen',
        'capacitive pen',
        'mouse',
        'trackpoint cap',
        'caddy',
        'cover',
        'back cover',
        'case',
        'speaker',
        'usb board',
        'dc jack',
        'cable',
        'wifi card',
        'wireless card',
        'antenna',
        'pcie card',
        'heat sink',
        'cpu fan',
        'cooling fan',
        'keycap',
        'bezel strip',
        'pwr board',
        'button board',
        'coin cell',
        'bios chip',
        'compatible',
        'camera set',
        'wwan',
        'wireless',
        'bluetooth',
        'dock model',
        'docking',
        'socket fit',
        'fit thinkpad',
        'for thinkpad',
        'for lenovo thinkpad',
        'dc cable',
        'power jack',
        'jack',
        'cover',
        'ram cover',
        'hard cover',
        'tablet pen',
    ];

    private string $rootPath;
    private array $config;
    private array $trackedModels;
    private \PDO $pdo;
    private ?string $accessToken = null;
    private ?\DateTimeImmutable $tokenExpiresAt = null;

    public function __construct(string $rootPath)
    {
        $this->rootPath = rtrim($rootPath, '/');
        $this->config = $this->loadConfig();
        $this->trackedModels = $this->trackedModelsFromConfig($this->config);
        $this->pdo = $this->connectDatabase();
        $this->ensureSchema();
    }

    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        if ($path === '/refresh') {
            if ($method !== 'POST') {
                $this->respondText('method not allowed', 405);
                return;
            }

            $this->maybePoll(true);
            header('Location: /', true, 303);
            return;
        }

        if ($path !== '/') {
            $this->respondText('not found', 404);
            return;
        }

        $this->maybePoll(false);
        [$selectedModel, $selectedFormat] = $this->selectedFilters();
        $this->renderDashboard($selectedModel, $selectedFormat);
    }

    public function pollNow(bool $force = true): array
    {
        $this->maybePoll($force);
        return $this->status();
    }

    public function status(): array
    {
        return [
            'last_poll_at' => $this->getMeta('last_poll_at') ?? '',
            'last_message' => $this->getMeta('last_message') ?? '',
            'last_error' => $this->getMeta('last_error') ?? '',
        ];
    }

    private function respondText(string $body, int $statusCode): void
    {
        http_response_code($statusCode);
        header('Content-Type: text/plain; charset=utf-8');
        echo $body;
    }

    private function loadConfig(): array
    {
        $fileValues = $this->loadEnvFile($this->rootPath . '/.env');

        $config = [
            'ebay_access_token' => $this->envValue('EBAY_ACCESS_TOKEN', $fileValues['EBAY_ACCESS_TOKEN'] ?? ''),
            'ebay_client_id' => $this->envValue('EBAY_CLIENT_ID', $fileValues['EBAY_CLIENT_ID'] ?? ($fileValues['legacy_client_id'] ?? '')),
            'ebay_client_secret' => $this->envValue('EBAY_CLIENT_SECRET', $fileValues['EBAY_CLIENT_SECRET'] ?? ($fileValues['legacy_client_secret'] ?? '')),
            'ebay_env' => strtolower($this->envValue('EBAY_ENV', $fileValues['EBAY_ENV'] ?? 'production')),
            'ebay_marketplace_id' => $this->envValue('EBAY_MARKETPLACE_ID', $fileValues['EBAY_MARKETPLACE_ID'] ?? 'EBAY_US'),
            'db_host' => $this->envValue('DB_HOST', $fileValues['DB_HOST'] ?? '127.0.0.1'),
            'db_port' => $this->envValue('DB_PORT', $fileValues['DB_PORT'] ?? '3306'),
            'db_socket' => $this->envValue('DB_SOCKET', $fileValues['DB_SOCKET'] ?? ''),
            'db_name' => $this->envValue('DB_NAME', $fileValues['DB_NAME'] ?? 'ebay_find'),
            'db_user' => $this->envValue('DB_USER', $fileValues['DB_USER'] ?? ''),
            'db_password' => $this->envValue('DB_PASSWORD', $fileValues['DB_PASSWORD'] ?? ''),
            'poll_interval_seconds' => max(30, (int) $this->envValue('ALERT_POLL_INTERVAL_SECONDS', $fileValues['ALERT_POLL_INTERVAL_SECONDS'] ?? '300')),
            'alert_limit' => max(1, (int) $this->envValue('ALERT_LIMIT', $fileValues['ALERT_LIMIT'] ?? '25')),
            'alert_existing' => $this->toBool($this->envValue('ALERT_EXISTING', $fileValues['ALERT_EXISTING'] ?? 'false')),
            'dashboard_limit' => max(1, (int) $this->envValue('DASHBOARD_ALERT_LIMIT', $fileValues['DASHBOARD_ALERT_LIMIT'] ?? '150')),
            'tracked_models' => $this->envValue('TRACKED_MODELS', $fileValues['TRACKED_MODELS'] ?? ''),
            'max_search_results_per_model' => max(1, min(10000, (int) $this->envValue('ALERT_MAX_RESULTS_PER_MODEL', $fileValues['ALERT_MAX_RESULTS_PER_MODEL'] ?? '250'))),
        ];

        if ($config['db_user'] === '') {
            throw new \RuntimeException('missing DB_USER');
        }

        if ($config['ebay_access_token'] === '' && ($config['ebay_client_id'] === '' || $config['ebay_client_secret'] === '')) {
            throw new \RuntimeException('missing EBAY_ACCESS_TOKEN or EBAY_CLIENT_ID/EBAY_CLIENT_SECRET');
        }

        if (!in_array($config['ebay_env'], ['sandbox', 'production'], true)) {
            throw new \RuntimeException(sprintf('EBAY_ENV must be sandbox or production, got %s', $config['ebay_env']));
        }

        return $config;
    }

    private function envValue(string $name, string $fallback): string
    {
        $value = getenv($name);
        if ($value === false) {
            return trim($fallback);
        }

        return trim((string) $value);
    }

    private function toBool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function trackedModelsFromConfig(array $config): array
    {
        $raw = trim((string) ($config['tracked_models'] ?? ''));
        if ($raw === '') {
            return self::DEFAULT_TRACKED_MODELS;
        }

        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
        $models = [];
        foreach ($parts as $part) {
            $model = trim($part);
            if ($model === '') {
                continue;
            }

            $models[strtolower($model)] = $model;
        }

        return array_values($models) !== [] ? array_values($models) : self::DEFAULT_TRACKED_MODELS;
    }

    private function loadEnvFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException(sprintf('unable to read %s', $path));
        }

        $values = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $parts = explode('=', $trimmed, 2);
            if (count($parts) === 2) {
                $values[trim($parts[0])] = trim($parts[1], " \t\n\r\0\x0B'\"");
            }
        }

        if (!isset($values['EBAY_CLIENT_ID']) && !isset($values['EBAY_ACCESS_TOKEN'])) {
            $legacyLookup = [
                'App ID (Client ID)' => 'legacy_client_id',
                'Cert ID (Client Secret)' => 'legacy_client_secret',
            ];

            for ($index = 0, $count = count($lines); $index < $count; $index++) {
                $label = trim($lines[$index]);
                if (!isset($legacyLookup[$label])) {
                    continue;
                }

                $next = $lines[$index + 1] ?? '';
                $value = trim($next);
                if ($value !== '') {
                    $values[$legacyLookup[$label]] = $value;
                }
                $index++;
            }
        }

        return $values;
    }

    private function connectDatabase(): \PDO
    {
        if ($this->config['db_socket'] !== '') {
            $dsn = sprintf(
                'mysql:unix_socket=%s;dbname=%s;charset=utf8mb4',
                $this->config['db_socket'],
                $this->config['db_name']
            );
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $this->config['db_host'],
                $this->config['db_port'],
                $this->config['db_name']
            );
        }

        return new \PDO($dsn, $this->config['db_user'], $this->config['db_password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function ensureSchema(): void
    {
        $schema = [
            <<<SQL
            CREATE TABLE IF NOT EXISTS app_meta (
                meta_key VARCHAR(100) PRIMARY KEY,
                meta_value TEXT NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS seen_listings (
                listing_key VARCHAR(512) PRIMARY KEY,
                item_id VARCHAR(255) DEFAULT NULL,
                item_url TEXT DEFAULT NULL,
                title TEXT DEFAULT NULL,
                first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS alerts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                listing_key VARCHAR(512) NOT NULL,
                title TEXT NOT NULL,
                matched_model VARCHAR(255) NOT NULL,
                price_value VARCHAR(64) DEFAULT NULL,
                price_currency VARCHAR(16) DEFAULT NULL,
                condition_label VARCHAR(255) DEFAULT NULL,
                item_url TEXT DEFAULT NULL,
                item_id VARCHAR(255) DEFAULT NULL,
                listed_at DATETIME DEFAULT NULL,
                origin_listed_at DATETIME DEFAULT NULL,
                discovered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_alert_listing (listing_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        ];

        foreach ($schema as $statement) {
            $this->pdo->exec($statement);
        }

        $this->pdo->exec(
            'ALTER TABLE seen_listings
             ADD COLUMN IF NOT EXISTS matched_model VARCHAR(255) DEFAULT NULL,
             ADD COLUMN IF NOT EXISTS listed_at DATETIME DEFAULT NULL,
             ADD COLUMN IF NOT EXISTS item_end_date DATETIME DEFAULT NULL,
             ADD COLUMN IF NOT EXISTS origin_listed_at DATETIME DEFAULT NULL,
             ADD COLUMN IF NOT EXISTS price_value VARCHAR(64) DEFAULT NULL,
             ADD COLUMN IF NOT EXISTS price_currency VARCHAR(16) DEFAULT NULL,
             ADD COLUMN IF NOT EXISTS condition_label VARCHAR(255) DEFAULT NULL,
             ADD COLUMN IF NOT EXISTS buying_options VARCHAR(255) DEFAULT NULL,
             ADD COLUMN IF NOT EXISTS current_bid_value VARCHAR(64) DEFAULT NULL,
             ADD COLUMN IF NOT EXISTS current_bid_currency VARCHAR(16) DEFAULT NULL,
             ADD COLUMN IF NOT EXISTS bid_count INT DEFAULT NULL'
        );

        $this->pdo->exec(
            'ALTER TABLE alerts
             ADD COLUMN IF NOT EXISTS item_end_date DATETIME DEFAULT NULL,
             ADD COLUMN IF NOT EXISTS buying_options VARCHAR(255) DEFAULT NULL,
             ADD COLUMN IF NOT EXISTS current_bid_value VARCHAR(64) DEFAULT NULL,
             ADD COLUMN IF NOT EXISTS current_bid_currency VARCHAR(16) DEFAULT NULL,
             ADD COLUMN IF NOT EXISTS bid_count INT DEFAULT NULL'
        );
    }

    private function maybePoll(bool $force): void
    {
        if (!$force && !$this->pollIsDue()) {
            return;
        }

        if (!$this->acquirePollLock()) {
            return;
        }

        try {
            if (!$force && !$this->pollIsDue()) {
                return;
            }

            $this->setMeta('last_message', 'Polling eBay for tracked models');
            $this->setMeta('last_error', '');

            $seedCompleted = $this->getMeta('seed_completed') === '1';
            $newMatches = $this->pollMarketplace();

            if (!$seedCompleted && !$this->config['alert_existing']) {
                foreach ($newMatches as $item) {
                    $this->insertSeenListing($item);
                }
                $this->setMeta('seed_completed', '1');
                $this->setMeta('last_message', sprintf('Seeded %d existing listings without alerting', count($newMatches)));
                $this->setMeta('last_poll_at', gmdate('Y-m-d H:i:s'));
                return;
            }

            foreach ($newMatches as $item) {
                $this->insertSeenListing($item);
                $this->insertAlert($item);
            }

            $this->setMeta('seed_completed', '1');
            $this->setMeta('last_message', count($newMatches) === 0
                ? 'No new matching laptop listings'
                : sprintf('Found %d new listing(s)', count($newMatches))
            );
            $this->setMeta('last_error', '');
            $this->setMeta('last_poll_at', gmdate('Y-m-d H:i:s'));
        } catch (\Throwable $exception) {
            $this->setMeta('last_error', $exception->getMessage());
            $this->setMeta('last_message', 'Last poll failed');
            $this->setMeta('last_poll_at', gmdate('Y-m-d H:i:s'));
        } finally {
            $this->releasePollLock();
        }
    }

    private function pollIsDue(): bool
    {
        $lastPollAt = $this->getMeta('last_poll_at');
        if ($lastPollAt === null || trim($lastPollAt) === '') {
            return true;
        }

        $last = strtotime($lastPollAt . ' UTC');
        if ($last === false) {
            return true;
        }

        return (time() - $last) >= $this->config['poll_interval_seconds'];
    }

    private function acquirePollLock(): bool
    {
        $statement = $this->pdo->query("SELECT GET_LOCK('ebay_find_poll', 0) AS locked");
        $value = $statement->fetchColumn();
        return (string) $value === '1';
    }

    private function releasePollLock(): void
    {
        $this->pdo->query("SELECT RELEASE_LOCK('ebay_find_poll')");
    }

    private function pollMarketplace(): array
    {
        $seenKeys = $this->loadSeenKeys();
        $results = [];

        foreach ($this->trackedModels as $model) {
            $items = $this->searchEbay($model);
            foreach ($this->filterLaptopListings($items, $model) as $item) {
                $item['matched_model'] = $model;
                $listingKey = $this->listingKey($item);
                if ($listingKey === '') {
                    continue;
                }

                $item['listing_key'] = $listingKey;
                $this->insertSeenListing($item);

                if (isset($seenKeys[$listingKey])) {
                    continue;
                }

                $seenKeys[$listingKey] = true;
                $results[] = $item;
            }
        }

        return $results;
    }

    private function loadSeenKeys(): array
    {
        $statement = $this->pdo->query('SELECT listing_key FROM seen_listings');
        $seen = [];
        foreach ($statement->fetchAll() as $row) {
            $seen[(string) $row['listing_key']] = true;
        }

        return $seen;
    }

    private function insertSeenListing(array $item): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO seen_listings (
                listing_key,
                item_id,
                item_url,
                title,
                matched_model,
                listed_at,
                item_end_date,
                origin_listed_at,
                price_value,
                price_currency,
                condition_label,
                buying_options,
                current_bid_value,
                current_bid_currency,
                bid_count
            ) VALUES (
                :listing_key,
                :item_id,
                :item_url,
                :title,
                :matched_model,
                :listed_at,
                :item_end_date,
                :origin_listed_at,
                :price_value,
                :price_currency,
                :condition_label,
                :buying_options,
                :current_bid_value,
                :current_bid_currency,
                :bid_count
            )
            ON DUPLICATE KEY UPDATE
                item_id = VALUES(item_id),
                item_url = VALUES(item_url),
                title = VALUES(title),
                matched_model = VALUES(matched_model),
                listed_at = COALESCE(VALUES(listed_at), listed_at),
                item_end_date = COALESCE(VALUES(item_end_date), item_end_date),
                origin_listed_at = COALESCE(VALUES(origin_listed_at), origin_listed_at),
                price_value = COALESCE(VALUES(price_value), price_value),
                price_currency = COALESCE(VALUES(price_currency), price_currency),
                condition_label = COALESCE(VALUES(condition_label), condition_label),
                buying_options = COALESCE(VALUES(buying_options), buying_options),
                current_bid_value = COALESCE(VALUES(current_bid_value), current_bid_value),
                current_bid_currency = COALESCE(VALUES(current_bid_currency), current_bid_currency),
                bid_count = COALESCE(VALUES(bid_count), bid_count)'
        );

        $statement->execute([
            'listing_key' => $item['listing_key'],
            'item_id' => $item['itemId'] ?: null,
            'item_url' => $item['itemWebUrl'] ?: null,
            'title' => $item['title'] ?: null,
            'matched_model' => $item['matched_model'] ?? null,
            'listed_at' => $this->mysqlDateTime($item['itemCreationDate'] ?? ''),
            'item_end_date' => $this->mysqlDateTime($item['itemEndDate'] ?? ''),
            'origin_listed_at' => $this->mysqlDateTime($item['itemOriginDate'] ?? ''),
            'price_value' => $item['price']['value'] ?? null,
            'price_currency' => $item['price']['currency'] ?? null,
            'condition_label' => $item['condition'] ?: null,
            'buying_options' => $this->buyingOptionsString($item),
            'current_bid_value' => $item['currentBidPrice']['value'] ?? null,
            'current_bid_currency' => $item['currentBidPrice']['currency'] ?? null,
            'bid_count' => isset($item['bidCount']) ? (int) $item['bidCount'] : null,
        ]);
    }

    private function insertAlert(array $item): void
    {
        $statement = $this->pdo->prepare(
            'INSERT IGNORE INTO alerts (
                listing_key,
                title,
                matched_model,
                price_value,
                price_currency,
                condition_label,
                item_url,
                item_id,
                listed_at,
                item_end_date,
                origin_listed_at,
                buying_options,
                current_bid_value,
                current_bid_currency,
                bid_count
            ) VALUES (
                :listing_key,
                :title,
                :matched_model,
                :price_value,
                :price_currency,
                :condition_label,
                :item_url,
                :item_id,
                :listed_at,
                :item_end_date,
                :origin_listed_at,
                :buying_options,
                :current_bid_value,
                :current_bid_currency,
                :bid_count
            )'
        );

        $statement->execute([
            'listing_key' => $item['listing_key'],
            'title' => $item['title'],
            'matched_model' => $item['matched_model'],
            'price_value' => $item['price']['value'] ?? null,
            'price_currency' => $item['price']['currency'] ?? null,
            'condition_label' => $item['condition'] ?: null,
            'item_url' => $item['itemWebUrl'] ?: null,
            'item_id' => $item['itemId'] ?: null,
            'listed_at' => $this->mysqlDateTime($item['itemCreationDate'] ?? ''),
            'item_end_date' => $this->mysqlDateTime($item['itemEndDate'] ?? ''),
            'origin_listed_at' => $this->mysqlDateTime($item['itemOriginDate'] ?? ''),
            'buying_options' => $this->buyingOptionsString($item),
            'current_bid_value' => $item['currentBidPrice']['value'] ?? null,
            'current_bid_currency' => $item['currentBidPrice']['currency'] ?? null,
            'bid_count' => isset($item['bidCount']) ? (int) $item['bidCount'] : null,
        ]);
    }

    private function mysqlDateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function getMeta(string $key): ?string
    {
        $statement = $this->pdo->prepare('SELECT meta_value FROM app_meta WHERE meta_key = :meta_key');
        $statement->execute(['meta_key' => $key]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    private function setMeta(string $key, string $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO app_meta (meta_key, meta_value) VALUES (:meta_key, :meta_value)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)'
        );
        $statement->execute([
            'meta_key' => $key,
            'meta_value' => $value,
        ]);
    }

    private function searchEbay(string $query): array
    {
        $baseUrl = $this->config['ebay_env'] === 'sandbox'
            ? 'https://api.sandbox.ebay.com'
            : 'https://api.ebay.com';

        $token = $this->accessToken();
        $pageSize = max(1, min(200, (int) $this->config['alert_limit']));
        $maxResults = max($pageSize, (int) $this->config['max_search_results_per_model']);
        $offset = 0;
        $items = [];

        while ($offset < $maxResults) {
            $url = $baseUrl . '/buy/browse/v1/item_summary/search?' . http_build_query([
                'q' => $query,
                'limit' => min($pageSize, $maxResults - $offset),
                'offset' => $offset,
                'sort' => 'newlyListed',
                'filter' => 'buyingOptions:{AUCTION|FIXED_PRICE}',
            ]);

            $response = $this->requestJson('GET', $url, [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'X-EBAY-C-MARKETPLACE-ID: ' . $this->config['ebay_marketplace_id'],
            ]);

            $pageItems = $response['itemSummaries'] ?? [];
            if (!is_array($pageItems) || $pageItems === []) {
                break;
            }

            array_push($items, ...$pageItems);

            $offset += count($pageItems);
            $total = isset($response['total']) ? (int) $response['total'] : 0;
            if (($total > 0 && $offset >= $total) || count($pageItems) < $pageSize) {
                break;
            }
        }

        return $items;
    }

    private function accessToken(): string
    {
        if ($this->canRefreshToken()
            && $this->accessToken !== null
            && $this->tokenExpiresAt !== null
            && $this->tokenExpiresAt > new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        ) {
            return $this->accessToken;
        }

        if (!$this->canRefreshToken()) {
            if ($this->config['ebay_access_token'] === '') {
                throw new \RuntimeException('missing EBAY_ACCESS_TOKEN');
            }

            return $this->config['ebay_access_token'];
        }

        $baseUrl = $this->config['ebay_env'] === 'sandbox'
            ? 'https://api.sandbox.ebay.com'
            : 'https://api.ebay.com';

        $body = http_build_query([
            'grant_type' => 'client_credentials',
            'scope' => 'https://api.ebay.com/oauth/api_scope',
        ]);

        try {
            $response = $this->requestJson('POST', $baseUrl . '/identity/v1/oauth2/token', [
                'Authorization: Basic ' . base64_encode($this->config['ebay_client_id'] . ':' . $this->config['ebay_client_secret']),
                'Content-Type: application/x-www-form-urlencoded',
            ], $body);
        } catch (\Throwable $exception) {
            if ($this->config['ebay_access_token'] !== '') {
                return $this->config['ebay_access_token'];
            }

            throw $exception;
        }

        $token = trim((string) ($response['access_token'] ?? ''));
        if ($token === '') {
            throw new \RuntimeException('empty access token in response');
        }

        $expiresIn = (int) ($response['expires_in'] ?? 0);
        $refreshAt = max(0, $expiresIn - 60);
        $this->accessToken = $token;
        $this->tokenExpiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify(sprintf('+%d seconds', $refreshAt));

        return $token;
    }

    private function canRefreshToken(): bool
    {
        return $this->config['ebay_client_id'] !== '' && $this->config['ebay_client_secret'] !== '';
    }

    private function requestJson(string $method, string $url, array $headers, ?string $body = null): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('failed to initialize curl');
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $rawResponse = curl_exec($handle);
        if ($rawResponse === false) {
            $message = curl_error($handle);
            curl_close($handle);
            throw new \RuntimeException($message === '' ? 'request failed' : $message);
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf('status %d: %s', $statusCode, trim($rawResponse)));
        }

        return $decoded;
    }

    private function filterLaptopListings(array $items, string $model): array
    {
        $terms = $this->splitTerms($model);
        $filtered = [];

        foreach ($items as $item) {
            $title = strtolower((string) ($item['title'] ?? ''));
            if (!$this->containsAllTerms($title, $terms)) {
                continue;
            }
            if ($this->containsAnyTerm($title, self::EXCLUDED_TITLE_TERMS)) {
                continue;
            }
            if (!$this->looksLikeLaptopTitle($title, $model)) {
                continue;
            }

            $filtered[] = $item;
        }

        return $filtered;
    }

    private function splitTerms(string $text): array
    {
        $parts = preg_split('/\s+/', strtolower(trim($text))) ?: [];
        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private function containsAllTerms(string $title, array $terms): bool
    {
        foreach ($terms as $term) {
            if (!str_contains($title, $term)) {
                return false;
            }
        }

        return true;
    }

    private function containsAnyTerm(string $title, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($title, $term)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeLaptopTitle(string $title, string $model): bool
    {
        if (str_contains($title, 'for thinkpad') || str_contains($title, 'compatible')) {
            return false;
        }

        return trim($model) !== '';
    }

    private function listingKey(array $item): string
    {
        $url = $this->normalizedListingUrl((string) ($item['itemWebUrl'] ?? ''));
        if ($url !== '') {
            return 'url:' . $url;
        }

        $itemId = trim((string) ($item['itemId'] ?? ''));
        if ($itemId !== '') {
            return 'id:' . $itemId;
        }

        $title = strtolower(trim(preg_replace('/\s+/', ' ', (string) ($item['title'] ?? '')) ?? ''));
        if ($title === '') {
            return '';
        }

        return 'fallback:' . $title . '|' . ($item['price']['value'] ?? '') . '|' . ($item['itemCreationDate'] ?? $item['itemOriginDate'] ?? '');
    }

    private function normalizedListingUrl(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $parts = parse_url($raw);
        if (!is_array($parts)) {
            return $raw;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';
        if ($host === '') {
            return $raw;
        }

        return sprintf('%s://%s%s', $scheme, $host, $path);
    }

    private function renderDashboard(?string $selectedModel, string $selectedFormat): void
    {
        $alerts = $this->loadRecentAlerts($selectedModel, $selectedFormat);
        $trackedListings = $this->loadTrackedListings($selectedModel, $selectedFormat);
        $lastPoll = $this->getMeta('last_poll_at');
        $intervalSeconds = (int) $this->config['poll_interval_seconds'];

        $view = [
            'now' => $this->formatDisplayTime(gmdate('Y-m-d H:i:s')),
            'status' => $this->getMeta('last_message') ?? 'Ready',
            'error' => $this->getMeta('last_error') ?? '',
            'lastPoll' => $this->formatDisplayTime($lastPoll),
            'nextPoll' => $lastPoll === null ? 'Waiting for first poll' : $this->formatDisplayTime(gmdate('Y-m-d H:i:s', strtotime($lastPoll . ' UTC') + $intervalSeconds)),
            'interval' => $this->formatInterval($intervalSeconds),
            'alerts' => $alerts,
            'alertCount' => count($alerts),
            'trackedListings' => $trackedListings,
            'trackedCount' => count($trackedListings),
            'models' => $this->trackedModels,
            'selectedModel' => $selectedModel ?? '',
            'selectedFormat' => $selectedFormat,
        ];

        header('Content-Type: text/html; charset=utf-8');
        echo $this->dashboardHtml($view);
    }

    private function loadRecentAlerts(?string $selectedModel, string $selectedFormat): array
    {
        $sql = 'SELECT title, matched_model, price_value, price_currency, condition_label, item_url, item_id, listed_at, item_end_date, origin_listed_at, discovered_at, buying_options, current_bid_value, current_bid_currency, bid_count
                FROM alerts';
        $clauses = [];
        if ($selectedModel !== null) {
            $clauses[] = 'matched_model = :matched_model';
        }
        $formatClause = $this->sqlFormatClause($selectedFormat);
        if ($formatClause !== '') {
            $clauses[] = $formatClause;
        }
        if ($clauses !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }
        $sql .= ' ORDER BY discovered_at DESC, id DESC LIMIT :limit';

        $statement = $this->pdo->prepare($sql);
        if ($selectedModel !== null) {
            $statement->bindValue(':matched_model', $selectedModel);
        }
        $statement->bindValue(':limit', $this->config['dashboard_limit'], \PDO::PARAM_INT);
        $statement->execute();

        $alerts = [];
        foreach ($statement->fetchAll() as $row) {
            if (!$this->storedRowLooksLikeLaptop($row)) {
                continue;
            }

            $listedAt = $this->formatDisplayTime($row['listed_at']);
            $firstListedAt = $this->formatDisplayTime($row['origin_listed_at']);
            if ($firstListedAt === $listedAt) {
                $firstListedAt = '';
            }

            $alerts[] = [
                'title' => (string) $row['title'],
                'model' => strtoupper((string) $row['matched_model']),
                'price' => trim((string) ($row['price_value'] ?? '') . ' ' . (string) ($row['price_currency'] ?? '')),
                'condition' => (string) ($row['condition_label'] ?? ''),
                'url' => (string) ($row['item_url'] ?? ''),
                'itemId' => (string) ($row['item_id'] ?? ''),
                'listedAt' => $listedAt,
                'endingAt' => $this->formatDisplayTime($row['item_end_date']),
                'firstListedAt' => $firstListedAt,
                'discoveredAt' => $this->formatDisplayTime((string) $row['discovered_at']),
                'buyingOptions' => (string) ($row['buying_options'] ?? ''),
                'currentBid' => trim((string) ($row['current_bid_value'] ?? '') . ' ' . (string) ($row['current_bid_currency'] ?? '')),
                'bidCount' => $row['bid_count'] === null ? '' : (string) $row['bid_count'],
            ];
        }

        return $alerts;
    }

    private function loadTrackedListings(?string $selectedModel, string $selectedFormat): array
    {
        $sql = 'SELECT title, matched_model, price_value, price_currency, condition_label, item_url, item_id, listed_at, item_end_date, origin_listed_at, first_seen_at, buying_options, current_bid_value, current_bid_currency, bid_count
                FROM seen_listings';
        $clauses = [];
        if ($selectedModel !== null) {
            $clauses[] = 'matched_model = :matched_model';
        }
        $formatClause = $this->sqlFormatClause($selectedFormat);
        if ($formatClause !== '') {
            $clauses[] = $formatClause;
        }
        if ($clauses !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }
        $sql .= ' ORDER BY first_seen_at DESC LIMIT :limit';

        $statement = $this->pdo->prepare($sql);
        if ($selectedModel !== null) {
            $statement->bindValue(':matched_model', $selectedModel);
        }
        $statement->bindValue(':limit', $this->config['dashboard_limit'], \PDO::PARAM_INT);
        $statement->execute();

        $listings = [];
        foreach ($statement->fetchAll() as $row) {
            if (!$this->storedRowLooksLikeLaptop($row)) {
                continue;
            }

            $listedAt = $this->formatDisplayTime($row['listed_at']);
            $firstListedAt = $this->formatDisplayTime($row['origin_listed_at']);
            if ($firstListedAt === $listedAt) {
                $firstListedAt = '';
            }

            $listings[] = [
                'title' => (string) ($row['title'] ?? ''),
                'model' => strtoupper((string) ($row['matched_model'] ?? '')),
                'price' => trim((string) ($row['price_value'] ?? '') . ' ' . (string) ($row['price_currency'] ?? '')),
                'condition' => (string) ($row['condition_label'] ?? ''),
                'url' => (string) ($row['item_url'] ?? ''),
                'itemId' => (string) ($row['item_id'] ?? ''),
                'listedAt' => $listedAt,
                'endingAt' => $this->formatDisplayTime($row['item_end_date']),
                'firstListedAt' => $firstListedAt,
                'discoveredAt' => $this->formatDisplayTime((string) ($row['first_seen_at'] ?? '')),
                'buyingOptions' => (string) ($row['buying_options'] ?? ''),
                'currentBid' => trim((string) ($row['current_bid_value'] ?? '') . ' ' . (string) ($row['current_bid_currency'] ?? '')),
                'bidCount' => $row['bid_count'] === null ? '' : (string) $row['bid_count'],
            ];
        }

        return $listings;
    }

    private function formatDisplayTime(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 'Not yet';
        }

        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s T');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function formatInterval(int $seconds): string
    {
        if ($seconds % 60 === 0) {
            return (int) ($seconds / 60) . 'm';
        }

        return $seconds . 's';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function storedRowLooksLikeLaptop(array $row): bool
    {
        $title = strtolower((string) ($row['title'] ?? ''));
        $model = strtolower((string) ($row['matched_model'] ?? ''));

        if ($title === '' || $model === '') {
            return false;
        }

        if (!$this->containsAllTerms($title, $this->splitTerms($model))) {
            return false;
        }

        if ($this->containsAnyTerm($title, self::EXCLUDED_TITLE_TERMS)) {
            return false;
        }

        return $this->looksLikeLaptopTitle($title, $model);
    }

    private function buyingOptionsString(array $item): string
    {
        $options = $item['buyingOptions'] ?? [];
        if (!is_array($options) || $options === []) {
            return '';
        }

        return implode(', ', array_map(static fn ($value): string => (string) $value, $options));
    }

    private function selectedFilters(): array
    {
        $selected = trim((string) ($_GET['model'] ?? ''));
        $selectedModel = null;
        if ($selected !== '' && in_array($selected, $this->trackedModels, true)) {
            $selectedModel = $selected;
        }

        $selectedFormat = strtolower(trim((string) ($_GET['format'] ?? 'buy-now')));
        if (!in_array($selectedFormat, ['buy-now', 'auction', 'auction-buy-now'], true)) {
            $selectedFormat = 'buy-now';
        }

        return [$selectedModel, $selectedFormat];
    }

    private function filterUrl(?string $model, string $selectedFormat): string
    {
        $params = ['format' => $selectedFormat];
        if ($model !== null && $model !== '') {
            $params['model'] = $model;
        }

        return '/?' . http_build_query($params);
    }

    private function sqlFormatClause(string $selectedFormat): string
    {
        return match ($selectedFormat) {
            'buy-now' => "(buying_options LIKE '%FIXED_PRICE%' AND buying_options NOT LIKE '%AUCTION%')",
            'auction' => "(buying_options = 'AUCTION')",
            'auction-buy-now' => "(buying_options LIKE '%FIXED_PRICE%' AND buying_options LIKE '%AUCTION%')",
            default => '',
        };
    }

    private function dashboardHtml(array $view): string
    {
        $formatTabs = '';
        $formatLabels = [
            'buy-now' => 'buy now',
            'auction' => 'auction',
            'auction-buy-now' => 'auction + BIN',
        ];
        foreach ($formatLabels as $formatKey => $label) {
            $tabClass = $view['selectedFormat'] === $formatKey ? 'format-tab active' : 'format-tab';
            $formatTabs .= '<a class="' . $tabClass . '" href="' . $this->e($this->filterUrl($view['selectedModel'] !== '' ? $view['selectedModel'] : null, $formatKey)) . '">' . $this->e($label) . '</a>';
        }

        $modelChips = '';
        $allClass = $view['selectedModel'] === '' ? 'chip active' : 'chip';
        $modelChips .= '<a class="' . $allClass . '" href="' . $this->e($this->filterUrl(null, $view['selectedFormat'])) . '">all models</a>';
        foreach ($view['models'] as $model) {
            $chipClass = $view['selectedModel'] === $model ? 'chip active' : 'chip';
            $modelChips .= '<a class="' . $chipClass . '" href="' . $this->e($this->filterUrl($model, $view['selectedFormat'])) . '">' . $this->e($model) . '</a>';
        }

        $alertCards = '';
        foreach ($view['alerts'] as $alert) {
            $cardClass = str_contains($alert['buyingOptions'], 'AUCTION') ? 'alert auction' : 'alert';
            $badgeLabel = $alert['model'];
            if (str_contains($alert['buyingOptions'], 'AUCTION')) {
                $badgeLabel .= ' · AUCTION';
            }
            $details = [];
            if ($alert['price'] !== '') {
                $details[] = '<div><strong>Price:</strong> ' . $this->e($alert['price']) . '</div>';
            }
            if ($alert['condition'] !== '') {
                $details[] = '<div><strong>Condition:</strong> ' . $this->e($alert['condition']) . '</div>';
            }
            if ($alert['listedAt'] !== 'Not yet') {
                $details[] = '<div><strong>Listed:</strong> ' . $this->e($alert['listedAt']) . '</div>';
            }
            if ($alert['buyingOptions'] !== '') {
                $details[] = '<div><strong>Format:</strong> ' . $this->e($alert['buyingOptions']) . '</div>';
            }
            if ($alert['currentBid'] !== '') {
                $details[] = '<div><strong>Current bid:</strong> ' . $this->e($alert['currentBid']) . '</div>';
            }
            if ($alert['bidCount'] !== '') {
                $details[] = '<div><strong>Bids:</strong> ' . $this->e($alert['bidCount']) . '</div>';
            }
            if ($alert['endingAt'] !== 'Not yet') {
                $details[] = '<div><strong>Ends:</strong> ' . $this->e($alert['endingAt']) . '</div>';
            }
            if ($alert['firstListedAt'] !== '' && $alert['firstListedAt'] !== 'Not yet') {
                $details[] = '<div><strong>First listed:</strong> ' . $this->e($alert['firstListedAt']) . '</div>';
            }
            if ($alert['discoveredAt'] !== '') {
                $details[] = '<div><strong>Seen by app:</strong> ' . $this->e($alert['discoveredAt']) . '</div>';
            }
            if ($alert['itemId'] !== '') {
                $details[] = '<div><strong>Item ID:</strong> ' . $this->e($alert['itemId']) . '</div>';
            }
            if ($alert['url'] !== '') {
                $details[] = '<div><strong>URL:</strong> <a class="link" href="' . $this->e($alert['url']) . '" target="_blank" rel="noreferrer">' . $this->e($alert['url']) . '</a></div>';
            }

            $alertCards .= '
                <article class="' . $cardClass . '">
                    <div class="alert-head">
                        <h3>' . $this->e($alert['title']) . '</h3>
                        <span class="badge">' . $this->e($badgeLabel) . '</span>
                    </div>
                    <div class="grid">' . implode('', $details) . '</div>
                </article>';
        }

        if ($alertCards === '') {
            $alertCards = '<div class="empty">No alert history yet. The first pass seeds existing listings without showing them unless <code>ALERT_EXISTING=true</code>.</div>';
        }

        $trackedCards = '';
        foreach ($view['trackedListings'] as $listing) {
            $cardClass = str_contains($listing['buyingOptions'], 'AUCTION') ? 'alert auction' : 'alert';
            $badgeLabel = $listing['model'] !== '' ? $listing['model'] : 'TRACKED';
            if (str_contains($listing['buyingOptions'], 'AUCTION')) {
                $badgeLabel .= ' · AUCTION';
            }
            $details = [];
            if ($listing['price'] !== '') {
                $details[] = '<div><strong>Price:</strong> ' . $this->e($listing['price']) . '</div>';
            }
            if ($listing['condition'] !== '') {
                $details[] = '<div><strong>Condition:</strong> ' . $this->e($listing['condition']) . '</div>';
            }
            if ($listing['listedAt'] !== 'Not yet') {
                $details[] = '<div><strong>Listed:</strong> ' . $this->e($listing['listedAt']) . '</div>';
            }
            if ($listing['buyingOptions'] !== '') {
                $details[] = '<div><strong>Format:</strong> ' . $this->e($listing['buyingOptions']) . '</div>';
            }
            if ($listing['currentBid'] !== '') {
                $details[] = '<div><strong>Current bid:</strong> ' . $this->e($listing['currentBid']) . '</div>';
            }
            if ($listing['bidCount'] !== '') {
                $details[] = '<div><strong>Bids:</strong> ' . $this->e($listing['bidCount']) . '</div>';
            }
            if ($listing['endingAt'] !== 'Not yet') {
                $details[] = '<div><strong>Ends:</strong> ' . $this->e($listing['endingAt']) . '</div>';
            }
            if ($listing['firstListedAt'] !== '' && $listing['firstListedAt'] !== 'Not yet') {
                $details[] = '<div><strong>First listed:</strong> ' . $this->e($listing['firstListedAt']) . '</div>';
            }
            if ($listing['discoveredAt'] !== '') {
                $details[] = '<div><strong>Stored:</strong> ' . $this->e($listing['discoveredAt']) . '</div>';
            }
            if ($listing['itemId'] !== '') {
                $details[] = '<div><strong>Item ID:</strong> ' . $this->e($listing['itemId']) . '</div>';
            }
            if ($listing['url'] !== '') {
                $details[] = '<div><strong>URL:</strong> <a class="link" href="' . $this->e($listing['url']) . '" target="_blank" rel="noreferrer">' . $this->e($listing['url']) . '</a></div>';
            }

            $trackedCards .= '
                <article class="' . $cardClass . '">
                    <div class="alert-head">
                        <h3>' . $this->e($listing['title'] !== '' ? $listing['title'] : 'Tracked listing') . '</h3>
                        <span class="badge">' . $this->e($badgeLabel) . '</span>
                    </div>
                    <div class="grid">' . implode('', $details) . '</div>
                </article>';
        }

        if ($trackedCards === '') {
            $trackedCards = '<div class="empty">No tracked listings are stored yet.</div>';
        }

        $errorPanel = '';
        if ($view['error'] !== '') {
            $errorPanel = '
                <div class="status status-error">
                    <strong>Error</strong>
                    <div>' . $this->e($view['error']) . '</div>
                </div>';
        }

        return $this->renderPhpTemplate($this->dashboardTemplatePath(), [
            'alertCards' => $alertCards,
            'dashboardCss' => $this->loadDashboardCss(),
            'errorPanel' => $errorPanel,
            'formatTabs' => $formatTabs,
            'modelChips' => $modelChips,
            'trackedCards' => $trackedCards,
            'view' => $view,
        ]);
    }

    private function dashboardTemplatePath(): string
    {
        return $this->rootPath . '/AlertBackend.dashboard.html.php';
    }

    private function dashboardCssPath(): string
    {
        return $this->rootPath . '/AlertBackend.dashboard.css';
    }

    private function loadDashboardCss(): string
    {
        return $this->readFile($this->dashboardCssPath());
    }

    private function renderPhpTemplate(string $templatePath, array $variables): string
    {
        if (!is_file($templatePath) || !is_readable($templatePath)) {
            throw new \RuntimeException(sprintf('unable to read template %s', $templatePath));
        }

        extract($variables, EXTR_SKIP);

        ob_start();
        include $templatePath;

        return (string) ob_get_clean();
    }

    private function readFile(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf('unable to read file %s', $path));
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('unable to read file %s', $path));
        }

        return $contents;
    }
}
