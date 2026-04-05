<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="refresh" content="60">
  <title>ThinkPad Alert Monitor</title>
  <style>
<?= $dashboardCss ?>
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
            <span class="stat-value"><?= $this->e($view['lastPoll']) ?></span>
          </div>
          <div class="stat">
            <span class="stat-label">Next Poll</span>
            <span class="stat-value"><?= $this->e($view['nextPoll']) ?></span>
          </div>
          <div class="stat">
            <span class="stat-label">Stored Alerts</span>
            <span class="stat-value"><?= $this->e((string) $view['alertCount']) ?></span>
          </div>
          <div class="stat">
            <span class="stat-label">Tracked Listings</span>
            <span class="stat-value"><?= $this->e((string) $view['trackedCount']) ?></span>
          </div>
        </div>
      </article>
      <aside class="side">
        <div class="card status">
          <strong>Status</strong>
          <div><?= $this->e($view['status']) ?></div>
        </div>
<?= $errorPanel ?>
        <div class="card status">
          <strong>Now</strong>
          <div><?= $this->e($view['now']) ?></div>
        </div>
        <form method="post" action="/refresh">
          <button class="button" type="submit">Refresh Now</button>
        </form>
      </aside>
    </section>
    <section class="layout">
      <aside class="card panel">
        <h2>Tracking</h2>
        <p class="subtitle">Automatic poll interval: <strong><?= $this->e($view['interval']) ?></strong></p>
        <div class="format-tabs"><?= $formatTabs ?></div>
        <div class="models"><?= $modelChips ?></div>
      </aside>
      <section class="card panel">
        <h2>Recent Listings</h2>
        <div class="alerts"><?= $alertCards ?></div>
      </section>
    </section>
    <section class="card panel" style="margin-top: 1.2rem;">
      <h2>Tracked Listings</h2>
      <div class="alerts"><?= $trackedCards ?></div>
    </section>
  </main>
</body>
</html>
