<?php
/**
 * PoultryGuard - main dashboard.
 *
 * The markup here is the shell; every value is filled in by dashboard.js from
 * /api/dashboard/index.php and refreshed on a timer, so the page never reloads.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_page_auth('dashboard.view');

$PAGE_TITLE    = 'Dashboard';
$PAGE_SUBTITLE = 'Live water, feeder and climate status for your poultry house.';
$NAV_ACTIVE    = 'dashboard';
$PAGE_ACTIONS  = '<button class="btn btn-sm btn-light" id="pgRefresh">Refresh now</button>';
$PAGE_SCRIPTS  = [asset_url('assets/js/dashboard.js')];

require __DIR__ . '/../includes/header.php';
?>

<!-- ======================= Summary tiles ======================= -->
<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3">
        <div class="pg-card h-100">
            <div class="pg-card-body">
                <div class="pg-metric">
                    <div class="pg-metric-icon feed" aria-hidden="true">&#127777;</div>
                    <div class="flex-grow-1 min-width-0">
                        <p class="pg-metric-label">Temperature</p>
                        <p class="pg-metric-value pg-skeleton" id="tileTempValue">-- °C</p>
                        <p class="pg-metric-sub" id="tileTempSub">&nbsp;</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="pg-card h-100">
            <div class="pg-card-body">
                <div class="pg-metric">
                    <div class="pg-metric-icon water" aria-hidden="true">&#128167;</div>
                    <div class="flex-grow-1 min-width-0">
                        <p class="pg-metric-label">Water level</p>
                        <p class="pg-metric-value pg-skeleton" id="tileWaterValue">0.0 cm</p>
                        <p class="pg-metric-sub" id="tileWaterSub">&nbsp;</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="pg-card h-100">
            <div class="pg-card-body">
                <div class="pg-metric">
                    <div class="pg-metric-icon brand" aria-hidden="true">&#128225;</div>
                    <div class="flex-grow-1 min-width-0">
                        <p class="pg-metric-label">Controller</p>
                        <p class="pg-metric-value pg-skeleton" id="tileDeviceValue">--</p>
                        <p class="pg-metric-sub" id="tileDeviceSub">&nbsp;</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="pg-card h-100">
            <div class="pg-card-body">
                <div class="pg-metric">
                    <div class="pg-metric-icon alert" aria-hidden="true">&#128276;</div>
                    <div class="flex-grow-1 min-width-0">
                        <p class="pg-metric-label">Unread alerts</p>
                        <p class="pg-metric-value pg-skeleton" id="tileAlertValue">0</p>
                        <p class="pg-metric-sub" id="tileAlertSub">&nbsp;</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ======================= Feed & water cards ======================= -->
<div class="row g-3 mb-3">
    <!-- Feeder -->
    <div class="col-lg-6">
        <div class="pg-card accent-feed h-100">
            <div class="pg-card-head">
                <h2>Feeder</h2>
                <span class="pg-pill unknown" id="feedStatusPill">
                    <span class="dot" aria-hidden="true"></span><span id="feedStatusText">--</span>
                </span>
            </div>
            <div class="pg-card-body">
                <div class="row g-2 small">
                    <div class="col-6">
                        <div class="pg-muted">Last run</div>
                        <div class="fw-semibold" id="feedLastRun">--</div>
                    </div>
                    <div class="col-6">
                        <div class="pg-muted">Last run length</div>
                        <div class="fw-semibold" id="feedLastSeconds">--</div>
                    </div>
                    <div class="col-6">
                        <div class="pg-muted">Safety limit per run</div>
                        <div class="fw-semibold" id="feedMaxDuration">--</div>
                    </div>
                    <div class="col-6">
                        <div class="pg-muted">Scheduled feeding</div>
                        <div class="fw-semibold" id="feedAuto">--</div>
                    </div>
                    <div class="col-12">
                        <div class="pg-muted">Feeding times</div>
                        <div class="fw-semibold" id="feedSchedule">--</div>
                    </div>
                </div>

                <div class="pg-divider"></div>
                <div class="d-flex justify-content-between small">
                    <span class="pg-muted">Runs today</span>
                    <span class="fw-semibold" id="feedToday">--</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Water monitoring -->
    <div class="col-lg-6">
        <div class="pg-card accent-water h-100">
            <div class="pg-card-head">
                <h2>Water monitoring</h2>
                <span class="pg-pill unknown" id="waterStatusPill">
                    <span class="dot" aria-hidden="true"></span><span id="waterStatusText">--</span>
                </span>
            </div>
            <div class="pg-card-body">
                <div class="d-flex align-items-end justify-content-between mb-1">
                    <div>
                        <span class="pg-reading" id="waterLevel">--</span>
                        <small id="waterPercent"></small>
                    </div>
                    <div class="text-end small pg-muted">
                        <div>Tank depth <span id="waterCapacity">--</span></div>
                        <div>Updated <span id="waterUpdated">--</span></div>
                    </div>
                </div>

                <div class="pg-level mt-3 mb-2">
                    <div class="pg-level-fill water" id="waterBar" style="width:0%"></div>
                    <div class="pg-level-threshold" id="waterThresholdMark" data-label="Refill" style="left:0%"></div>
                </div>

                <div class="row g-2 small mt-2">
                    <div class="col-6">
                        <div class="pg-muted">Refill threshold</div>
                        <div class="fw-semibold" id="waterThreshold">--</div>
                    </div>
                    <div class="col-6">
                        <div class="pg-muted">Refill target</div>
                        <div class="fw-semibold" id="waterTarget">--</div>
                    </div>
                    <div class="col-6">
                        <div class="pg-muted">Last refill</div>
                        <div class="fw-semibold" id="waterLastRefill">--</div>
                    </div>
                    <div class="col-6">
                        <div class="pg-muted">Automatic watering</div>
                        <div class="fw-semibold" id="waterAuto">--</div>
                    </div>
                </div>

                <div class="pg-divider"></div>
                <div class="d-flex justify-content-between small">
                    <span class="pg-muted">Consumed today</span>
                    <span class="fw-semibold" id="waterToday">--</span>
                </div>
            </div>
        </div>
    </div>

    <!-- House climate (DHT11) -->
    <div class="col-12">
        <div class="pg-card h-100">
            <div class="pg-card-head">
                <h2>House climate</h2>
                <span class="pg-pill unknown" id="climateStatusPill">
                    <span class="dot" aria-hidden="true"></span><span id="climateStatusText">--</span>
                </span>
            </div>
            <div class="pg-card-body">
                <div class="row g-3 small">
                    <div class="col-6 col-md-3">
                        <div class="pg-muted">Temperature</div>
                        <div class="fw-semibold fs-5" id="climateTemp">--</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="pg-muted">Humidity</div>
                        <div class="fw-semibold fs-5" id="climateHumidity">--</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="pg-muted">Heat limits</div>
                        <div class="fw-semibold" id="climateLimits">--</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="pg-muted">Updated</div>
                        <div class="fw-semibold" id="climateUpdated">--</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ======================= Trend charts ======================= -->
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="pg-card h-100">
            <div class="pg-card-head">
                <h2>Temperature over time</h2>
                <select class="form-select form-select-sm w-auto" id="chartRange" aria-label="Chart time range">
                    <option value="6">Last 6 hours</option>
                    <option value="24" selected>Last 24 hours</option>
                    <option value="72">Last 3 days</option>
                    <option value="168">Last 7 days</option>
                </select>
            </div>
            <div class="pg-card-body">
                <div class="pg-chart"><canvas id="climateChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="pg-card h-100">
            <div class="pg-card-head"><h2>Water level over time</h2></div>
            <div class="pg-card-body">
                <div class="pg-chart"><canvas id="waterLevelChart"></canvas></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="pg-card h-100">
            <div class="pg-card-head"><h2>Daily feeder runs</h2></div>
            <div class="pg-card-body">
                <div class="pg-chart sm"><canvas id="feedRunsChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="pg-card h-100">
            <div class="pg-card-head"><h2>Daily water consumption</h2></div>
            <div class="pg-card-body">
                <div class="pg-chart sm"><canvas id="waterConsumptionChart"></canvas></div>
            </div>
        </div>
    </div>
</div>

<!-- ======================= Alerts & device status ======================= -->
<div class="row g-3">
    <div class="col-lg-7">
        <div class="pg-card h-100">
            <div class="pg-card-head">
                <h2>Recent alerts</h2>
                <div class="d-flex gap-2">
                    <?php if (user_can('alerts.manage')): ?>
                        <button class="btn btn-sm btn-light" id="markAllRead">Mark all read</button>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-light" href="<?= e(url('alerts/')) ?>">View all</a>
                </div>
            </div>
            <div id="alertList">
                <div class="p-4 text-center pg-muted small">Loading alerts&hellip;</div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="pg-card h-100">
            <div class="pg-card-head"><h2>System status</h2></div>
            <div class="pg-card-body">
                <ul class="list-unstyled mb-0" id="systemStatusList">
                    <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <span>ESP32 controller</span>
                        <span class="pg-pill unknown" id="statEsp32">--</span>
                    </li>
                    <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <span>Internet connection</span>
                        <span class="pg-pill unknown" id="statInternet">--</span>
                    </li>
                    <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <span>Water level sensor</span>
                        <span class="pg-pill unknown" id="statWaterSensor">--</span>
                    </li>
                    <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <span>Feeder motor</span>
                        <span class="pg-pill unknown" id="statMotor">--</span>
                    </li>
                    <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <span>Water pump</span>
                        <span class="pg-pill unknown" id="statSolenoid">--</span>
                    </li>
                    <li class="d-flex justify-content-between align-items-center py-2">
                        <span>Last communication</span>
                        <span class="small fw-semibold" id="statLastSeen">--</span>
                    </li>
                </ul>

                <div class="pg-divider"></div>
                <div class="small pg-muted" id="deviceMeta">&nbsp;</div>

                <div class="alert alert-info py-2 px-3 small mt-3 mb-0 d-none" id="offlineNotice">
                    <strong>Controller offline.</strong>
                    Automatic feeding and watering continue running locally on the ESP32
                    using its stored settings. Buffered records upload when it reconnects.
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
