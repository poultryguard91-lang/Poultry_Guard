<?php
/**
 * PoultryGuard - manual feed control.
 *
 * Queues commands for the ESP32; the firmware remains the only thing that
 * actually switches the relay. Restricted to accounts holding
 * automation.control (Farm Staff).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_page_auth('automation.control');

$PAGE_TITLE    = 'Feed Control';
$PAGE_SUBTITLE = 'Manually dispense feed or stop the auger.';
$NAV_ACTIVE    = 'automation.feed';
$PAGE_SCRIPTS  = [asset_url('assets/js/automation.js')];

require __DIR__ . '/../includes/header.php';
?>

<script>
window.PG_PAGE = { kind: 'feed' };
</script>

<div class="alert alert-secondary small d-flex gap-2 align-items-start">
    <span aria-hidden="true">&#9432;</span>
    <div>
        Commands are queued and picked up by the controller on its next check-in
        (usually within <?= (int)($DEVICE_SETTINGS['reading_interval'] ?? 30) ?> seconds).
        The ESP32 performs the weighing and stops the auger itself, so a manual
        dispense is subject to the same
        <strong><?= (int)($DEVICE_SETTINGS['feed_max_duration'] ?? 60) ?>&nbsp;second</strong>
        safety timeout as an automatic one.
    </div>
</div>

<div class="row g-3 mb-3">
    <!-- Live state -->
    <div class="col-lg-4">
        <div class="pg-card accent-feed h-100">
            <div class="pg-card-head">
                <h2>Hopper right now</h2>
                <span class="pg-pill unknown" id="statusPill">
                    <span class="dot" aria-hidden="true"></span><span id="statusText">--</span>
                </span>
            </div>
            <div class="pg-card-body text-center">
                <div class="pg-reading pg-skeleton d-inline-block" id="mainReading">--</div>
                <div class="pg-muted small mt-1"><span id="mainPercent">--</span> of capacity</div>

                <div class="pg-level mt-3">
                    <div class="pg-level-fill feed" id="levelBar" style="width:0%"></div>
                </div>

                <div class="row g-2 small mt-3 text-start">
                    <div class="col-6"><span class="pg-muted">Capacity</span>
                        <div class="fw-semibold" id="infoCapacity">--</div></div>
                    <div class="col-6"><span class="pg-muted">Default target</span>
                        <div class="fw-semibold" id="infoTarget">--</div></div>
                    <div class="col-6"><span class="pg-muted">Auger</span>
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
            <div class="pg-card-head"><h2>Manual dispensing</h2></div>
            <div class="pg-card-body">

                <div class="alert alert-warning py-2 px-3 small d-none" id="offlineWarning">
                    <strong>Controller offline.</strong>
                    Manual dispensing needs a connected device. Automatic feeding is still
                    running locally on the ESP32 using its stored thresholds.
                </div>

                <form id="dispenseForm" novalidate>
                    <label for="targetWeight" class="form-label fw-semibold small">
                        Fill the hopper up to
                    </label>
                    <div class="input-group mb-1" style="max-width: 320px;">
                        <input type="number" class="form-control" id="targetWeight"
                               min="0" step="10" required
                               aria-describedby="targetHelp">
                        <span class="input-group-text">grams</span>
                    </div>
                    <div class="form-text mb-3" id="targetHelp">
                        The auger runs until the load cell reads this weight, then stops.
                        It cannot remove feed, so the target must be above the current level.
                    </div>
                    <div class="invalid-feedback d-block text-danger small mb-2 d-none" id="targetError"></div>

                    <div class="row g-2 mb-3" style="max-width: 520px;">
                        <div class="col-6">
                            <button type="button" class="btn btn-light w-100 btn-sm" data-preset="target">
                                Refill target
                            </button>
                        </div>
                        <div class="col-6">
                            <button type="button" class="btn btn-light w-100 btn-sm" data-preset="full">
                                Fill to capacity
                            </button>
                        </div>
                    </div>

                    <div class="row g-2">
                        <div class="col-sm-7">
                            <button type="submit" class="btn btn-warning pg-control-btn" id="dispenseBtn">
                                <span aria-hidden="true">&#127806;</span> Dispense feed
                            </button>
                        </div>
                        <div class="col-sm-5">
                            <button type="button" class="btn btn-outline-danger pg-control-btn" id="stopBtn">
                                <span aria-hidden="true">&#9632;</span> Stop auger
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
                                Halts the auger and the valve immediately and switches off both
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
