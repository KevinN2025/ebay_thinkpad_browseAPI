<?php

declare(strict_types=1);

final class AlertBackend
{
    private const TRACKED_MODELS = [
        'p14s thinkpad',
        't490 thinkpad',
        't490s thinkpad',
        'x200 thinkpad',
        'x13 thinkpad',
        'x1 carbon thinkpad',
        't400 thinkpad',
        't14 thinkpad',
        't14s thinkpad',
        'w520 thinkpad',
        't430 thinkpad',
        't480s thinkpad',
        'x220 thinkpad',
        'x230 thinkpad',
        't480 thinkpad',
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
        'battery',
        'memory',
        'ram',
        'ddr',
        'ssd',
        'hdd',
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
        'camera',
        'wwan',
        'wireless',
        'wifi',
        'bluetooth',
        'module',
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
    private \PDO $pdo;
    private ?string $accessToken = null;
    private ?\DateTimeImmutable $tokenExpiresAt = null;

    public function __construct(string $rootPath)
    {
        $this->rootPath = rtrim($rootPath, '/');
        $this->config = $this->loadConfig();
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

        foreach (self::TRACKED_MODELS as $model) {
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

        $url = $baseUrl . '/buy/browse/v1/item_summary/search?' . http_build_query([
            'q' => $query,
            'limit' => $this->config['alert_limit'],
            'offset' => 0,
            'sort' => 'newlyListed',
            'filter' => 'buyingOptions:{AUCTION|FIXED_PRICE}',
        ]);

        $response = $this->requestJson('GET', $url, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'X-EBAY-C-MARKETPLACE-ID: ' . $this->config['ebay_marketplace_id'],
        ]);

        $items = $response['itemSummaries'] ?? [];
        return is_array($items) ? $items : [];
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

        if ($this->containsAnyTerm($title, [
            'camera',
            'card',
            'module',
            'cable',
            'battery',
            'cover',
            'pen',
            'digitizer',
            'socket',
            'jack',
            'speaker',
            'bios',
            'dock',
            'docking',
            'wwan',
            'wireless',
            'wifi',
            'bluetooth',
        ])) {
            return false;
        }

        if (str_contains($title, 'laptop') || str_contains($title, 'notebook')) {
            return $this->containsAnyTerm($title, [
                'intel',
                'core',
                'windows',
                'ssd',
                'hdd',
                'nvme',
                'ghz',
                '8gb',
                '16gb',
                '32gb',
                '4gb',
                'i3',
                'i5',
                'i7',
                'i9',
                'celeron',
                'ryzen',
                'touchscreen',
                '12.5',
                '13"',
                '13.3',
                '14"',
                '14.0',
                '15.6',
                'tablet',
            ]);
        }

        $normalizedModel = strtolower(trim($model));
        if ($normalizedModel === '') {
            return false;
        }

        return $this->containsAnyTerm($title, [
            'intel',
            'core',
            'windows',
            'ssd',
            'hdd',
            'nvme',
            'ghz',
            '8gb',
            '16gb',
            '32gb',
            '4gb',
            'i3',
            'i5',
            'i7',
            'i9',
            'celeron',
            'ryzen',
            'touchscreen',
            '12.5',
            '13"',
            '13.3',
            '14"',
            '14.0',
            '15.6',
            'tablet',
            'win 10',
            'win 11',
            'win11',
            'win10',
            'pro laptop',
        ]);
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
            'models' => self::TRACKED_MODELS,
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
        if ($selected !== '' && in_array($selected, self::TRACKED_MODELS, true)) {
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

        return '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="refresh" content="60">
  <title>ThinkPad Alert Monitor</title>
  <style>
    :root {
      --bg: #040404;
      --panel: #0b0b0b;
      --panel-strong: #110707;
      --line: rgba(255, 64, 64, 0.16);
      --text: #f4d8d8;
      --muted: #d18d8d;
      --red: #ff3b3b;
      --red-dark: #9e0f0f;
      --red-soft: #ff8585;
      --shadow: 0 20px 50px rgba(0, 0, 0, 0.55);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      background:
        radial-gradient(circle at top left, rgba(255, 59, 59, 0.22), transparent 25rem),
        radial-gradient(circle at bottom right, rgba(158, 15, 15, 0.25), transparent 28rem),
        linear-gradient(180deg, #020202 0%, #070707 100%);
      color: var(--text);
      font-family: "Trebuchet MS", "Segoe UI", sans-serif;
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
      margin-bottom: 1.3rem;
    }
    .card, .alert {
      background: linear-gradient(180deg, rgba(17, 7, 7, 0.92), rgba(8, 8, 8, 0.96));
      border: 1px solid var(--line);
      border-radius: 22px;
      box-shadow: var(--shadow);
    }
    .card {
      padding: 1.35rem;
    }
    h1 {
      margin: 0 0 0.4rem;
      color: var(--red);
      font-size: clamp(2.1rem, 4vw, 3.4rem);
      line-height: 0.95;
      letter-spacing: -0.05em;
      text-transform: uppercase;
    }
    .subtitle {
      margin: 0;
      color: var(--muted);
      max-width: 46rem;
    }
    .meta {
      display: grid;
      gap: 1rem;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      margin-top: 1.2rem;
    }
    .stat {
      padding-top: 0.85rem;
      border-top: 1px solid var(--line);
    }
    .stat-label {
      display: block;
      color: var(--red-soft);
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 0.12em;
    }
    .stat-value {
      display: block;
      margin-top: 0.25rem;
      font-size: 1.12rem;
    }
    .side {
      display: grid;
      gap: 0.85rem;
      align-content: start;
    }
    .status {
      padding: 0.85rem 0.95rem;
      border-radius: 16px;
      border: 1px solid var(--line);
      background: rgba(255, 59, 59, 0.05);
    }
    .status strong {
      display: block;
      margin-bottom: 0.25rem;
      color: var(--red-soft);
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 0.11em;
    }
    .status-error {
      border-color: rgba(255, 133, 133, 0.35);
    }
    .button {
      width: 100%;
      border: 0;
      border-radius: 999px;
      padding: 0.95rem 1rem;
      background: linear-gradient(135deg, #ff2525, #7a0707);
      color: #fff3f3;
      font-size: 0.95rem;
      font-weight: 700;
      letter-spacing: 0.05em;
      text-transform: uppercase;
      cursor: pointer;
    }
    .layout {
      display: grid;
      gap: 1.2rem;
      grid-template-columns: 1fr 2fr;
    }
    .panel h2 {
      margin: 0 0 0.85rem;
      color: var(--red);
      text-transform: uppercase;
      letter-spacing: 0.08em;
      font-size: 1rem;
    }
    .models {
      display: flex;
      flex-wrap: wrap;
      gap: 0.55rem;
    }
    .format-tabs {
      display: flex;
      flex-wrap: wrap;
      gap: 0.6rem;
      margin-bottom: 0.95rem;
    }
    .format-tab {
      display: inline-block;
      padding: 0.55rem 0.9rem;
      border-radius: 999px;
      border: 1px solid rgba(255, 120, 120, 0.28);
      color: #ffd9d9;
      background: rgba(255, 59, 59, 0.08);
      text-decoration: none;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      font-size: 0.84rem;
      font-weight: 700;
    }
    .format-tab.active {
      background: linear-gradient(135deg, rgba(255, 37, 37, 0.96), rgba(122, 7, 7, 0.94));
      color: #fff6f6;
      border-color: rgba(255, 140, 140, 0.55);
      box-shadow: 0 0 0 1px rgba(255, 80, 80, 0.18);
    }
    .chip {
      display: inline-block;
      padding: 0.42rem 0.75rem;
      border-radius: 999px;
      border: 1px solid var(--line);
      color: var(--red-soft);
      background: rgba(255, 59, 59, 0.07);
      font-size: 0.88rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      text-decoration: none;
    }
    .chip.active {
      color: #fff2f2;
      background: linear-gradient(135deg, rgba(255, 37, 37, 0.9), rgba(122, 7, 7, 0.9));
      border-color: rgba(255, 120, 120, 0.5);
    }
    .alerts {
      display: grid;
      gap: 1rem;
    }
    .alert {
      padding: 1.05rem 1.1rem;
    }
    .alert.auction {
      border-color: rgba(255, 166, 64, 0.38);
      background: linear-gradient(180deg, rgba(28, 11, 0, 0.96), rgba(11, 7, 4, 0.98));
      box-shadow: 0 0 0 1px rgba(255, 166, 64, 0.08), var(--shadow);
    }
    .alert-head {
      display: flex;
      justify-content: space-between;
      gap: 1rem;
      align-items: start;
      margin-bottom: 0.85rem;
    }
    .alert-head h3 {
      margin: 0;
      color: #ffe7e7;
      font-size: 1.08rem;
    }
    .badge {
      white-space: nowrap;
      padding: 0.35rem 0.65rem;
      border-radius: 999px;
      background: rgba(255, 59, 59, 0.12);
      border: 1px solid var(--line);
      color: var(--red);
      font-size: 0.8rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }
    .grid {
      display: grid;
      gap: 0.65rem 1rem;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      color: var(--muted);
      font-size: 0.93rem;
    }
    .grid strong {
      color: var(--red-soft);
    }
    .link {
      color: #ff6666;
      word-break: break-all;
    }
    .empty {
      padding: 1.15rem;
      border-radius: 16px;
      border: 1px dashed var(--line);
      color: var(--muted);
      background: rgba(255, 59, 59, 0.04);
    }
    code {
      color: var(--red-soft);
    }
    @media (max-width: 860px) {
      .hero, .layout, .meta, .grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <main class="shell">
    <section class="hero">
      <article class="card">
        <h1>ThinkPad Alert Monitor</h1>
        <p class="subtitle">PHP backend with MariaDB persistence for the eBay watcher. Repeat listings are suppressed, recent discoveries are stored in the database, and the dashboard shows the actual listing timestamps from eBay.</p>
        <div class="meta">
          <div class="stat">
            <span class="stat-label">Last Poll</span>
            <span class="stat-value">' . $this->e($view['lastPoll']) . '</span>
          </div>
          <div class="stat">
            <span class="stat-label">Next Poll</span>
            <span class="stat-value">' . $this->e($view['nextPoll']) . '</span>
          </div>
          <div class="stat">
            <span class="stat-label">Stored Alerts</span>
            <span class="stat-value">' . $this->e((string) $view['alertCount']) . '</span>
          </div>
          <div class="stat">
            <span class="stat-label">Tracked Listings</span>
            <span class="stat-value">' . $this->e((string) $view['trackedCount']) . '</span>
          </div>
        </div>
      </article>
      <aside class="side">
        <div class="card status">
          <strong>Status</strong>
          <div>' . $this->e($view['status']) . '</div>
        </div>
        ' . $errorPanel . '
        <div class="card status">
          <strong>Now</strong>
          <div>' . $this->e($view['now']) . '</div>
        </div>
        <form method="post" action="/refresh">
          <button class="button" type="submit">Refresh Now</button>
        </form>
      </aside>
    </section>
    <section class="layout">
      <aside class="card panel">
        <h2>Tracking</h2>
        <p class="subtitle">Automatic poll interval: <strong>' . $this->e($view['interval']) . '</strong></p>
        <div class="format-tabs">' . $formatTabs . '</div>
        <div class="models">' . $modelChips . '</div>
      </aside>
      <section class="card panel">
        <h2>Recent Listings</h2>
        <div class="alerts">' . $alertCards . '</div>
      </section>
    </section>
    <section class="card panel" style="margin-top: 1.2rem;">
      <h2>Tracked Listings</h2>
      <div class="alerts">' . $trackedCards . '</div>
    </section>
  </main>
</body>
</html>';
    }
}
