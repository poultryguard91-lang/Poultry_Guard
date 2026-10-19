<?php
/**
 * PoultryGuard - system report.
 *
 * Alert frequency, estimated device uptime, sensor activity and the split
 * between manual and automated actions.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_page_auth('reports.view');

$PAGE_TITLE    = 'System Report';
$PAGE_SUBTITLE = 'Alert frequency, device uptime and operator activity.';
$NAV_ACTIVE    = 'reports.alerts';
$PAGE_SCRIPTS  = [asset_url('assets/js/reports.js')];
$PAGE_ACTIONS  = '<button class="btn btn-sm btn-light" data-pg-print>Print report</button>';

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/_filters.php';
?>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="pg-card"><div class="pg-card-body">
            <p class="pg-metric-label">Total alerts</p>
            <p class="pg-metric-value" id="sumTotal">--</p>
            <p class="pg-metric-sub" id="sumTotalSub">&nbsp;</p>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="pg-card"><div class="pg-card-body">
            <p class="pg-metric-label">Critical</p>
            <p class="pg-metric-value text-danger" id="sumCritical">--</p>
            <p class="pg-metric-sub" id="sumCriticalSub">&nbsp;</p>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="pg-card"><div class="pg-card-body">
            <p class="pg-metric-label">Estimated uptime</p>
            <p class="pg-metric-value" id="sumUptime">--</p>
            <p class="pg-metric-sub" id="sumUptimeSub">&nbsp;</p>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="pg-card"><div class="pg-card-body">
            <p class="pg-metric-label">Sensor readings</p>
            <p class="pg-metric-value" id="sumReadings">--</p>
            <p class="pg-metric-sub" id="sumReadingsSub">&nbsp;</p>
        </div></div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <div class="pg-card h-100">
            <div class="pg-card-head">
                <h2>Alert frequency</h2>
                <span class="small pg-muted" id="rangeLabel">&nbsp;</span>
            </div>
            <div class="pg-card-body">
                <div class="pg-chart"><canvas id="mainChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="pg-card h-100">
            <div class="pg-card-head"><h2>Alerts by type</h2></div>
            <div class="pg-card-body">
                <div class="pg-chart"><canvas id="typeChart"></canvas></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <div class="pg-card h-100">
            <div class="pg-card-head"><h2>Device uptime</h2></div>
            <div class="pg-card-body">
                <table class="table table-sm pg-table mb-0">
                    <tbody>
                        <tr><td class="pg-muted">Currently</td>
                            <td class="text-end"><span class="pg-pill unknown" id="upNow">--</span></td></tr>
                        <tr><td class="pg-muted">Last seen</td>
                            <td class="text-end fw-semibold" id="upLastSeen">--</td></tr>
                        <tr><td class="pg-muted">Reading interval</td>
                            <td class="text-end fw-semibold" id="upInterval">--</td></tr>
                        <tr><td class="pg-muted">Expected readings</td>
                            <td class="text-end fw-semibold pg-mono" id="upExpected">--</td></tr>
                        <tr><td class="pg-muted">Actual readings</td>
                            <td class="text-end fw-semibold pg-mono" id="upActual">--</td></tr>
                    </tbody>
                </table>
                <p class="small pg-muted mb-0 mt-2">
                    Uptime is estimated by comparing the readings that arrived against the
                    number the configured interval should have produced.
                </p>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="pg-card h-100">
            <div class="pg-card-head"><h2>Sensor activity</h2></div>
            <div class="pg-card-body">
                <table class="table table-sm pg-table mb-0">
                    <tbody>
                        <tr><td class="pg-muted">Feed readings</td>
                            <td class="text-end fw-semibold pg-mono" id="actFeed">--</td></tr>
                        <tr><td class="pg-muted">Water readings</td>
                            <td class="text-end fw-semibold pg-mono" id="actWater">--</td></tr>
                        <tr><td class="pg-muted">Feed via offline buffer</td>
                            <td class="text-end fw-semibold pg-mono" id="actFeedOffline">--</td></tr>
                        <tr><td class="pg-muted">Water via offline buffer</td>
                            <td class="text-end fw-semibold pg-mono" id="actWaterOffline">--</td></tr>
                    </tbody>
                </table>
                <p class="small pg-muted mb-0 mt-2">
                    Rows uploaded through the offline buffer show how much of the period the
                    controller ran without a network connection.
                </p>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="pg-card h-100">
            <div class="pg-card-head"><h2>Manual vs automated</h2></div>
            <div class="pg-table-wrap">
                <table class="table table-sm pg-table mb-0">
                    <thead><tr><th>Trigger</th><th class="pg-num">Feed</th><th class="pg-num">Water</th><th class="pg-num">Total</th></tr></thead>
                    <tbody id="actionBody">
                        <tr><td colspan="4" class="text-center pg-muted py-3">--</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="pg-card">
    <div class="pg-card-head">
        <h2>Activity log</h2>
        <span class="small pg-muted" id="logCount">&nbsp;</span>
    </div>
    <div class="pg-table-wrap">
        <table class="table pg-table mb-0">
            <thead><tr><th>When</th><th>User</th><th>Action</th><th>Description</th><th>IP</th></tr></thead>
            <tbody id="logBody">
                <tr><td colspan="5" class="text-center pg-muted py-4">Choose a range and press Generate.</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
window.PG_PAGE = { report: 'alerts' };
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
