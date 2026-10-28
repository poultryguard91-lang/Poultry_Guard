<?php
/**
 * PoultryGuard - manual water control.
 *
 * Queues refill/stop commands for the ESP32. Restricted to accounts holding
 * automation.control (Farm Staff).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_page_auth('automation.control');

$PAGE_TITLE    = 'Water Control';
$PAGE_SUBTITLE = 'Manually start or stop a water refill.';
$NAV_ACTIVE    = 'automation.water';
$PAGE_SCRIPTS  = [asset_url('assets/js/automation.js')];

require __DIR__ . '/../includes/header.php';
?>

<script>
window.PG_PAGE = { kind: 'water' };
</script>

<div class="alert alert-secondary small d-flex gap-2 align-items-start">
    <span aria-hidden="true">&#9432;</span>
    <div>
        Commands are queued and picked up by the controller on its next check-in
        (usually within 5 seconds).
        The ESP32 stops the pump itself once the target level is reached,
        or after the
        <strong><?= (int)($DEVICE_SETTINGS['water_max_duration'] ?? WATER_MAX_SEC_LIMIT) ?>&nbsp;second</strong>
        safety timeout, whichever comes first.
    </div>
</div>

<div class="row g-3 mb-3">
    <!-- Live state -->
    <div class="col-lg-4">
        <div class="pg-card accent-water h-100">
            <div class="pg-card-head">
                <h2>Tank right now</h2>
                <span class="pg-pill unknown" id="statusPill">
                    <span class="dot" aria-hidden="true"></span><span id="statusText">--</span>
                </span>
            </div>
            <div class="pg-card-body text-center">
                <div class="pg-reading pg-skeleton d-inline-block" id="mainReading">--</div>
                <div class="pg-muted small mt-1">
                    <span id="mainPercent">--</span> &middot; <span id="mainLiters">--</span>
                </div>

                <div class="pg-level mt-3">
                    <div class="pg-level-fill water" id="levelBar" style="width:0%"></div>
                </div>

                <div class="row g-2 small mt-3 text-start">
                    <div class="col-6"><span class="pg-muted">Tank depth</span>
                        <div class="fw-semibold" id="infoCapacity">--</div></div>
                    <div class="col-6"><span class="pg-muted">Refill starts below</span>
                        <div class="fw-semibold" id="infoTarget">--</div></div>
                    <div class="col-6"><span class="pg-muted">Pump</span>
                        <div class="fw-semibold" id="motorStatus">--</div></div>
                    <div class="col-6"><span class="pg-muted">Controller</span>
                        <div class="fw-semibold" id="deviceStatus">--</div></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Controls -->
    <div class="col-lg-8">
        <div class="pg-card h-100">
            <div class="pg-card-head"><h2>Manual refill</h2></div>
            <div class="pg-card-body">

                <div class="alert alert-warning py-2 px-3 small d-none" id="offlineWarning">
                    <strong>Controller offline.</strong>
                    Manual refilling needs a connected device. Automatic watering is still
                    running locally on the ESP32 using its stored thresholds.
                </div>

                <form id="dispenseForm" novalidate>
                    <p class="small mb-3">
                        The pump runs until the water level sensor reads <strong>full</strong>,
                        or until the safety time limit, whichever comes first.
                    </p>
                    <div class="invalid-feedback d-block text-danger small mb-2 d-none" id="targetError"></div>

                    <div class="row g-2">
                        <div class="col-sm-7">
                            <button type="submit" class="btn btn-primary pg-control-btn" id="dispenseBtn">
                                <span aria-hidden="true">&#128167;</span> Start refill
                            </button>
                        </div>
                        <div class="col-sm-5">
                            <button type="button" class="btn btn-outline-danger pg-control-btn" id="stopBtn">
                                <span aria-hidden="true">&#9632;</span> Stop refill
                            </button>
                        </div>
                    </div>
                </form>

                <div class="pg-divider"></div>

                <div class="pg-danger-zone">
                    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
                        <div>
                            <div class="fw-bold text-danger">Emergency stop</div>
                            <div class="small pg-muted">
                                Halts the pump and the feeder immediately and switches off both
                                automations until you turn them back on in Settings.
                            </div>
                        </div>
                        <button type="button" class="btn btn-danger fw-semibold" id="emergencyBtn">
                            Emergency stop
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Command queue -->
<div class="pg-card">
    <div class="pg-card-head">
        <h2>Command history</h2>
        <span class="small pg-muted" id="queueSummary">&nbsp;</span>
    </div>
    <div class="pg-table-wrap">
        <table class="table pg-table mb-0">
            <thead>
                <tr>
                    <th>Issued</th>
                    <th>Command</th>
                    <th>Details</th>
                    <th>State</th>
                    <th>Result</th>
                    <th>By</th>
                </tr>
            </thead>
            <tbody id="commandBody">
                <tr><td colspan="6" class="text-center pg-muted py-4">Loading&hellip;</td></tr>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
