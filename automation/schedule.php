<?php
/**
 * PoultryGuard - scheduled feeding.
 *
 * Daily feeding times for the selected device. The schedule is saved on the
 * server and copied to the ESP32, which runs it from its own clock. Anyone
 * with settings.view can see it; settings.thresholds (Farm Staff) can edit it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_page_auth('settings.view');

$CAN_EDIT = user_can('settings.thresholds');

$PAGE_TITLE    = 'Scheduled Feeding';
$PAGE_SUBTITLE = 'Set the times the feeder runs by itself each day.';
$NAV_ACTIVE    = 'automation.schedule';
$PAGE_SCRIPTS  = [asset_url('assets/js/schedule.js')];

require __DIR__ . '/../includes/header.php';
?>

<script>
window.PG_PAGE = { canEdit: <?= $CAN_EDIT ? 'true' : 'false' ?> };
</script>

<div class="alert alert-secondary small d-flex gap-2 align-items-start">
    <span aria-hidden="true">&#9432;</span>
    <div>
        The controller saves this schedule in its own memory and feeds from its own clock,
        so feeding continues if the network drops. If its clock has not been set (it needs
        internet after power-up) it <strong>skips</strong> scheduled feeds and raises an alert.
        To feed right now, use <a href="<?= e(url('automation/feed.php')) ?>">Feed Control</a>.
    </div>
</div>

<div class="row g-3">
    <!-- Status -->
    <div class="col-lg-4">
        <div class="pg-card accent-feed h-100">
            <div class="pg-card-head">
                <h2>Schedule status</h2>
                <span class="pg-pill unknown" id="schedPill">
                    <span class="dot" aria-hidden="true"></span><span id="schedPillText">--</span>
                </span>
            </div>
            <div class="pg-card-body">
                <div class="small pg-muted">Next feeding</div>
                <div class="pg-reading pg-skeleton d-inline-block" id="nextFeed">--</div>
                <div class="pg-muted small mt-1" id="nextFeedIn">&nbsp;</div>

                <div class="pg-divider"></div>
                <div class="row g-2 small">
                    <div class="col-6"><span class="pg-muted">Feedings per day</span>
                        <div class="fw-semibold" id="slotTotal">--</div></div>
                    <div class="col-6"><span class="pg-muted">Feed time per day</span>
                        <div class="fw-semibold" id="secTotal">--</div></div>
                    <div class="col-6"><span class="pg-muted">Longest single run</span>
                        <div class="fw-semibold" id="maxRun">--</div></div>
                    <div class="col-6"><span class="pg-muted">Controller</span>
                        <div class="fw-semibold" id="deviceState">--</div></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Editor -->
    <div class="col-lg-8">
        <form class="pg-card h-100" id="scheduleForm" novalidate>
            <div class="pg-card-head">
                <h2>Feeding times</h2>
                <?php if (!$CAN_EDIT): ?>
                    <span class="badge bg-secondary">Read only &mdash; Farm Staff manage this</span>
                <?php endif; ?>
            </div>
            <div class="pg-card-body">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="auto_feed"
                           <?= $CAN_EDIT ? '' : 'disabled' ?>>
                    <label class="form-check-label fw-semibold" for="auto_feed">Scheduled feeding</label>
                    <div class="form-text">
                        When off, the feeder only runs when you start it manually.
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label small fw-semibold mb-0">Times (house clock, <?= e(date_default_timezone_get()) ?>)</label>
                    <?php if ($CAN_EDIT): ?>
                        <button type="button" class="btn btn-sm btn-light" id="addSlotBtn">+ Add time</button>
                    <?php endif; ?>
                </div>
                <div id="scheduleRows"></div>
                <div class="form-text">
                    Up to 8 feedings a day, each for 1 to <span id="maxHint">30</span> seconds
                    (the maximum motor run time in
                    <a href="<?= e(url('settings/')) ?>">Settings</a>).
                </div>

                <?php if ($CAN_EDIT): ?>
                    <div class="alert alert-danger py-2 px-3 small d-none mt-3" id="scheduleError"></div>
                    <button type="submit" class="btn btn-warning fw-semibold mt-3" id="scheduleSaveBtn">
                        Save schedule
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="pg-muted small mt-3" id="scheduleMeta">&nbsp;</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
