<?php
/**
 * PoultryGuard - water monitoring.
 *
 * Live HC-SR04 readings, the level trend and the refill log for the selected
 * device. Populated by assets/js/monitoring.js.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_page_auth('monitoring.view');

$PAGE_TITLE    = 'Water Monitoring';
$PAGE_SUBTITLE = 'Ultrasonic level readings from the water tank.';
$NAV_ACTIVE    = 'monitoring.water';
$PAGE_SCRIPTS  = [asset_url('assets/js/monitoring.js')];

require __DIR__ . '/../includes/header.php';
?>

<script>
window.PG_PAGE = { kind: 'water' };
</script>

<div class="row g-3 mb-3">
    <!-- Current reading -->
    <div class="col-lg-5">
        <div class="pg-card accent-water h-100">
            <div class="pg-card-head">
                <h2>Current water level</h2>
                <span class="pg-pill unknown" id="statusPill">
                    <span class="dot" aria-hidden="true"></span><span id="statusText">--</span>
                </span>
            </div>
            <div class="pg-card-body">
                <div class="text-center py-2">
                    <div class="pg-reading pg-skeleton d-inline-block" id="mainReading">--</div>
                    <div class="pg-muted small mt-1">
                        <span id="mainPercent">--</span> of tank depth
                        &middot; <span id="mainLiters">--</span>
                    </div>
                </div>

                <div class="pg-level mt-4 mb-2">
                    <div class="pg-level-fill water" id="levelBar" style="width:0%"></div>
                    <div class="pg-level-threshold" id="thresholdMark" data-label="Refill" style="left:0%"></div>
                </div>

                <table class="table table-sm pg-table mt-3 mb-0">
                    <tbody>
                        <tr><td class="pg-muted">Tank depth</td>
                            <td class="pg-num fw-semibold" id="infoCapacity">--</td></tr>
                        <tr><td class="pg-muted">Tank volume</td>
                            <td class="pg-num fw-semibold" id="infoVolume">--</td></tr>
                        <tr><td class="pg-muted">Refill threshold</td>
                            <td class="pg-num fw-semibold" id="infoThreshold">--</td></tr>
                        <tr><td class="pg-muted">Refill target</td>
                            <td class="pg-num fw-semibold" id="infoTarget">--</td></tr>
                        <tr><td class="pg-muted">Measured distance</td>
                            <td class="pg-num fw-semibold pg-mono" id="infoRaw">--</td></tr>
                        <tr><td class="pg-muted">Sensor mount offset</td>
                            <td class="pg-num fw-semibold" id="infoOffset">--</td></tr>
                        <tr><td class="pg-muted">Automatic watering</td>
                            <td class="pg-num fw-semibold" id="infoAuto">--</td></tr>
                        <tr><td class="pg-muted">Reading age</td>
                            <td class="pg-num fw-semibold" id="infoAge">--</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Trend -->
    <div class="col-lg-7">
        <div class="pg-card h-100">
            <div class="pg-card-head">
                <h2>Water level trend</h2>
                <select class="form-select form-select-sm w-auto" id="rangeSelect" aria-label="Time range">
                    <option value="6">Last 6 hours</option>
                    <option value="24" selected>Last 24 hours</option>
                    <option value="72">Last 3 days</option>
                    <option value="168">Last 7 days</option>
                    <option value="336">Last 14 days</option>
                </select>
            </div>
            <div class="pg-card-body">
                <div class="pg-chart lg"><canvas id="levelChart"></canvas></div>
                <p class="pg-muted small mb-0 mt-2">
                    Water depth is calculated from the ultrasonic distance reading:
                    <code>depth = tank depth &minus; (distance &minus; sensor offset)</code>.
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Hardware + accuracy -->
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="pg-card h-100">
            <div class="pg-card-body">
                <div class="pg-metric">
                    <div class="pg-metric-icon brand" aria-hidden="true">&#128225;</div>
                    <div>
                        <p class="pg-metric-label">Ultrasonic sensor</p>
                        <p class="pg-metric-value" id="sensorStatus">--</p>
                        <p class="pg-metric-sub" id="sensorSub">&nbsp;</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="pg-card h-100">
            <div class="pg-card-body">
                <div class="pg-metric">
                    <div class="pg-metric-icon water" aria-hidden="true">&#9881;</div>
                    <div>
                        <p class="pg-metric-label">Solenoid valve</p>
                        <p class="pg-metric-value" id="motorStatus">--</p>
                        <p class="pg-metric-sub" id="motorSub">&nbsp;</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="pg-card h-100">
            <div class="pg-card-body">
                <div class="pg-metric">
                    <div class="pg-metric-icon brand" aria-hidden="true">&#127919;</div>
                    <div>
                        <p class="pg-metric-label">Refill accuracy</p>
                        <p class="pg-metric-value" id="accuracyValue">--</p>
                        <p class="pg-metric-sub" id="accuracySub">&nbsp;</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Refill history -->
<div class="pg-card">
    <div class="pg-card-head">
        <h2>Recent refill events</h2>
        <?php if (user_can('reports.view')): ?>
            <a class="btn btn-sm btn-light" href="<?= e(url('reports/water.php')) ?>">Full report</a>
        <?php endif; ?>
    </div>
    <div class="pg-table-wrap">
        <table class="table pg-table mb-0">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Method</th>
                    <th class="pg-num">Before</th>
                    <th class="pg-num">Target</th>
                    <th class="pg-num">After</th>
                    <th class="pg-num">Added</th>
                    <th class="pg-num">Error</th>
                    <th class="pg-num">Duration</th>
                    <th>Status</th>
                    <th>By</th>
                </tr>
            </thead>
            <tbody id="historyBody">
                <tr><td colspan="10" class="text-center pg-muted py-4">Loading&hellip;</td></tr>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
