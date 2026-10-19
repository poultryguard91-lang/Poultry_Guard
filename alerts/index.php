<?php
/**
 * PoultryGuard - alert history.
 *
 * Filterable list of every alert the system has raised, with bulk and
 * per-alert status changes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_page_auth('alerts.view');

$PAGE_TITLE    = 'Alerts';
$PAGE_SUBTITLE = 'Everything the system has flagged, newest first.';
$NAV_ACTIVE    = 'alerts';
$PAGE_SCRIPTS  = [asset_url('assets/js/alerts.js')];
$PAGE_ACTIONS  = '<button class="btn btn-sm btn-light" data-pg-print>Print</button>';

require __DIR__ . '/../includes/header.php';

$ALERT_TYPES = [
    'low_feed' => 'Low feed', 'critical_feed' => 'Critical feed',
    'low_water' => 'Low water', 'critical_water' => 'Critical water',
    'sensor_failure' => 'Sensor failure', 'device_offline' => 'Device offline',
    'internet_disconnected' => 'Internet disconnected', 'motor_error' => 'Motor error',
    'solenoid_error' => 'Solenoid error', 'abnormal_reading' => 'Abnormal reading',
    'dispensing_timeout' => 'Dispensing timeout', 'system_error' => 'System error',
    'info' => 'Information',
];
?>

<!-- Summary tiles -->
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="pg-card"><div class="pg-card-body">
            <p class="pg-metric-label">Matching alerts</p>
            <p class="pg-metric-value" id="sumTotal">--</p>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="pg-card"><div class="pg-card-body">
            <p class="pg-metric-label">Unread</p>
            <p class="pg-metric-value text-warning" id="sumUnread">--</p>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="pg-card"><div class="pg-card-body">
            <p class="pg-metric-label">Critical</p>
            <p class="pg-metric-value text-danger" id="sumCritical">--</p>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="pg-card"><div class="pg-card-body">
            <p class="pg-metric-label">Resolved</p>
            <p class="pg-metric-value text-success" id="sumResolved">--</p>
        </div></div>
    </div>
</div>

<!-- Filters -->
<div class="pg-card mb-3 pg-no-print">
    <div class="pg-card-body">
        <form id="filterForm" class="row g-2 align-items-end">
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label small fw-semibold" for="fDevice">Device</label>
                <select class="form-select form-select-sm" id="fDevice">
                    <option value="all">All devices</option>
                    <?php foreach ($ALL_DEVICES as $d): ?>
                        <option value="<?= (int)$d['device_id'] ?>"
                            <?= (int)$d['device_id'] === $CURRENT_DEVICE_ID ? 'selected' : '' ?>>
                            <?= e($d['device_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label small fw-semibold" for="fSeverity">Severity</label>
                <select class="form-select form-select-sm" id="fSeverity">
                    <option value="all">All</option>
                    <option value="CRITICAL">Critical</option>
                    <option value="WARNING">Warning</option>
                    <option value="INFO">Info</option>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label small fw-semibold" for="fStatus">Status</label>
                <select class="form-select form-select-sm" id="fStatus">
                    <option value="all">All</option>
                    <option value="unread">Unread</option>
                    <option value="read">Read</option>
                    <option value="resolved">Resolved</option>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label small fw-semibold" for="fType">Type</label>
                <select class="form-select form-select-sm" id="fType">
                    <option value="all">All types</option>
                    <?php foreach ($ALERT_TYPES as $k => $label): ?>
                        <option value="<?= e($k) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label small fw-semibold" for="fFrom">From</label>
                <input type="date" class="form-control form-control-sm" id="fFrom">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label small fw-semibold" for="fTo">To</label>
                <input type="date" class="form-control form-control-sm" id="fTo">
            </div>

            <div class="col-12 d-flex gap-2 flex-wrap mt-2">
                <button type="submit" class="btn btn-sm btn-primary">Apply filters</button>
                <button type="button" class="btn btn-sm btn-light" id="resetFilters">Reset</button>
                <?php if (user_can('alerts.manage')): ?>
                    <div class="ms-auto d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="readAllBtn">
                            Mark all read
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="resolveAllBtn">
                            Resolve all
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Alert table -->
<div class="pg-card">
    <div class="pg-card-head">
        <h2>Alert history</h2>
        <span class="small pg-muted" id="pageInfo">&nbsp;</span>
    </div>
    <div class="pg-table-wrap">
        <table class="table pg-table mb-0">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Severity</th>
                    <th>Type</th>
                    <th>Message</th>
                    <th>Device</th>
                    <th>Status</th>
                    <?php if (user_can('alerts.manage')): ?><th class="pg-no-print">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody id="alertBody">
                <tr><td colspan="7" class="text-center pg-muted py-4">Loading&hellip;</td></tr>
            </tbody>
        </table>
    </div>
    <div class="pg-card-head border-top border-bottom-0 pg-no-print">
        <nav aria-label="Alert pages">
            <ul class="pagination pagination-sm mb-0" id="pager"></ul>
        </nav>
    </div>
</div>

<script>
window.PG_PAGE = { canManage: <?= user_can('alerts.manage') ? 'true' : 'false' ?> };
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
