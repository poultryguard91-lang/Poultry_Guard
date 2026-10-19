<?php
/**
 * PoultryGuard - page shell (closing half).
 *
 * Closes the content area and loads the front-end runtime. PG_CONFIG carries
 * the few server-side values the JavaScript needs - the URL prefix, the CSRF
 * token, the selected device and its polling interval.
 *
 * Note what is NOT here: no database host, user or password ever reaches the
 * browser. The front end talks only to the PHP API.
 *
 * A page may set $PAGE_SCRIPTS (array of extra script URLs) before including.
 */

declare(strict_types=1);

$PAGE_SCRIPTS = $PAGE_SCRIPTS ?? [];
?>
    <footer class="mt-4 pt-3 border-top pg-muted small d-flex flex-wrap justify-content-between gap-2">
        <div>
            <strong><?= e(APP_NAME) ?></strong> v<?= e(APP_VERSION) ?>
            &mdash; <?= e(APP_TAGLINE) ?>
        </div>
        <div>
            <?php if (!empty($CURRENT_DEVICE)): ?>
                Device: <?= e($CURRENT_DEVICE['device_name']) ?> &middot;
                last contact <?= e(time_ago($CURRENT_DEVICE['last_seen'])) ?>
            <?php endif; ?>
        </div>
    </footer>
</main>

<script>
// Values the front end needs. Deliberately limited to non-sensitive data.
window.PG_CONFIG = {
    baseUrl:   <?= json_encode(BASE_URL, JSON_UNESCAPED_SLASHES) ?>,
    csrfToken: <?= json_encode(csrf_token()) ?>,
    deviceId:  <?= $CURRENT_DEVICE_ID !== null ? (int)$CURRENT_DEVICE_ID : 'null' ?>,
    role:      <?= json_encode($CURRENT_USER['role']) ?>,
    // Farm clock: server time in the house timezone, so every viewer sees the
    // same time the feeding schedule runs on, whatever their own device says.
    serverTimeMs: <?= (int)(microtime(true) * 1000) ?>,
    timezone:     <?= json_encode(date_default_timezone_get()) ?>,
    // Poll a little slower than the device reports, so the dashboard is never
    // asking for data that cannot have changed yet.
    pollInterval: <?= (int)max(5, (int)($DEVICE_SETTINGS['reading_interval'] ?? 30)) * 1000 ?>
};
</script>
<script src="<?= e(url('assets/vendor/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('assets/vendor/chart.umd.min.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/app.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/charts.js')) ?>"></script>
<?php foreach ($PAGE_SCRIPTS as $src): ?>
<script src="<?= e($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
