<?php
/**
 * PoultryGuard - system settings.
 *
 * Two groups of settings guarded by different permissions:
 *   feed/water automation -> settings.thresholds (Farm Staff)
 *   alerts & notifications -> settings.alerts     (Farm Owner)
 *
 * Both groups are visible to anyone with settings.view; the group a user
 * cannot change renders read-only.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_page_auth('settings.view');

$CAN_THRESHOLDS = user_can('settings.thresholds');
$CAN_ALERTS     = user_can('settings.alerts');

$PAGE_TITLE    = 'System Settings';
$PAGE_SUBTITLE = 'Automation rules for the selected device. These are also stored on the ESP32 so they keep working offline.';
$NAV_ACTIVE    = 'settings';
$PAGE_SCRIPTS  = [asset_url('assets/js/settings.js')];

require __DIR__ . '/../includes/header.php';

/** Render a labelled numeric field with its unit and help text. */
function setting_field(
    string $id,
    string $label,
    string $unit,
    string $help,
    bool $editable,
    string $step = 'any',
    string $min = '',
    string $max = ''
): void {
    ?>
    <div class="col-md-6">
        <label class="form-label small fw-semibold" for="<?= e($id) ?>"><?= e($label) ?></label>
        <div class="input-group input-group-sm">
            <input type="number" class="form-control" id="<?= e($id) ?>"
                   step="<?= e($step) ?>"
                   <?= $min !== '' ? 'min="' . e($min) . '"' : '' ?>
                   <?= $max !== '' ? 'max="' . e($max) . '"' : '' ?>
                   <?= $editable ? '' : 'readonly' ?>>
            <span class="input-group-text"><?= e($unit) ?></span>
        </div>
        <div class="form-text"><?= e($help) ?></div>
    </div>
    <?php
}
?>

<?php if (!$CAN_THRESHOLDS && !$CAN_ALERTS): ?>
    <div class="alert alert-info">
        You can view these settings but not change them. Feed and water automation is
        managed by Farm Staff; alert and notification policy is managed by the Farm Owner.
    </div>
<?php endif; ?>

<div class="row g-3">

    <!-- ======================== Feed settings ======================== -->
    <div class="col-xl-6">
        <form class="pg-card accent-feed h-100" id="feedForm" novalidate>
            <div class="pg-card-head">
                <h2>Feed settings</h2>
                <?php if (!$CAN_THRESHOLDS): ?>
                    <span class="badge bg-secondary">Read only</span>
                <?php endif; ?>
            </div>
            <div class="pg-card-body">
                <div class="row g-3">
                    <?php
                    setting_field('feed_capacity', 'Maximum feed capacity', 'g',
                        'Weight of a completely full hopper.', $CAN_THRESHOLDS, '1', '100', '500000');
                    setting_field('feed_threshold', 'Minimum feed threshold', 'g',
                        'Dispensing starts when the weight falls below this.', $CAN_THRESHOLDS, '1', '0');
                    setting_field('target_feed', 'Target feed quantity', 'g',
                        'The auger runs until the hopper reaches this weight.', $CAN_THRESHOLDS, '1', '0');
                    setting_field('feed_max_duration', 'Maximum motor run time', 'seconds',
                        'Safety cut-off: the auger never runs longer than this in one cycle.', $CAN_THRESHOLDS, '1', '1', '3600');
                    setting_field('feed_tolerance', 'Dispensing tolerance', 'g',
                        'How close to the target counts as done. The design target is ±5 g.', $CAN_THRESHOLDS, '0.1', '0.1');
                    ?>
                    <div class="col-md-6 d-flex align-items-end">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="auto_feed"
                                   <?= $CAN_THRESHOLDS ? '' : 'disabled' ?>>
                            <label class="form-check-label fw-semibold" for="auto_feed">
                                Automatic feeding
                            </label>
                            <div class="form-text">
                                When off, the ESP32 stops dispensing on its own — including offline.
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($CAN_THRESHOLDS): ?>
                    <div class="alert alert-danger py-2 px-3 small d-none mt-3" id="feedError"></div>
                    <button type="submit" class="btn btn-warning fw-semibold mt-3" id="feedSaveBtn">
                        Save feed settings
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- ======================== Water settings ======================== -->
    <div class="col-xl-6">
        <form class="pg-card accent-water h-100" id="waterForm" novalidate>
            <div class="pg-card-head">
                <h2>Water settings</h2>
                <?php if (!$CAN_THRESHOLDS): ?>
                    <span class="badge bg-secondary">Read only</span>
                <?php endif; ?>
            </div>
            <div class="pg-card-body">
                <div class="row g-3">
                    <?php
                    setting_field('water_capacity', 'Maximum water capacity (depth)', 'cm',
                        'Usable water depth from the full line to the bottom of the tank.', $CAN_THRESHOLDS, '0.1', '1', '400');
                    setting_field('water_capacity_liters', 'Tank volume at full', 'litres',
                        'Used to report consumption in litres rather than centimetres.', $CAN_THRESHOLDS, '0.1', '1');
                    setting_field('sensor_offset_cm', 'Ultrasonic sensor offset', 'cm',
                        'Gap between the sensor face and the full-water line.', $CAN_THRESHOLDS, '0.1', '0', '400');
                    setting_field('water_threshold', 'Minimum water threshold', 'cm',
                        'The valve opens when the depth falls below this.', $CAN_THRESHOLDS, '0.1', '0', '400');
                    setting_field('water_target', 'Target water level', 'cm',
                        'The valve closes once the tank reaches this depth.', $CAN_THRESHOLDS, '0.1', '0', '400');
                    setting_field('water_max_duration', 'Maximum valve open time', 'seconds',
                        'Safety cut-off: the valve never stays open longer than this.', $CAN_THRESHOLDS, '1', '1', '7200');
                    ?>
                    <div class="col-md-6 d-flex align-items-end">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="auto_water"
                                   <?= $CAN_THRESHOLDS ? '' : 'disabled' ?>>
                            <label class="form-check-label fw-semibold" for="auto_water">
                                Automatic watering
                            </label>
                            <div class="form-text">
                                When off, the ESP32 stops refilling on its own — including offline.
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($CAN_THRESHOLDS): ?>
                    <div class="alert alert-danger py-2 px-3 small d-none mt-3" id="waterError"></div>
                    <button type="submit" class="btn btn-primary fw-semibold mt-3" id="waterSaveBtn">
                        Save water settings
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- ======================== Alert settings ======================== -->
    <div class="col-12">
        <form class="pg-card accent-brand" id="alertForm" novalidate>
            <div class="pg-card-head">
                <h2>Alert &amp; notification settings</h2>
                <?php if (!$CAN_ALERTS): ?>
                    <span class="badge bg-secondary">Read only &mdash; Farm Owner manages these</span>
                <?php endif; ?>
            </div>
            <div class="pg-card-body">
                <div class="row g-3">
                    <?php
                    setting_field('feed_low_percent', 'Low feed threshold', '%',
                        'A warning alert is raised below this percentage of capacity.', $CAN_ALERTS, '0.1', '0', '100');
                    setting_field('feed_critical_percent', 'Critical feed threshold', '%',
                        'A critical alert is raised below this. Must be lower than the low threshold.', $CAN_ALERTS, '0.1', '0', '100');
                    setting_field('water_low_percent', 'Low water threshold', '%',
                        'A warning alert is raised below this percentage of tank depth.', $CAN_ALERTS, '0.1', '0', '100');
                    setting_field('water_critical_percent', 'Critical water threshold', '%',
                        'A critical alert is raised below this. Must be lower than the low threshold.', $CAN_ALERTS, '0.1', '0', '100');
                    setting_field('offline_timeout', 'Device offline timeout', 'seconds',
                        'How long the controller may stay silent before it counts as offline.', $CAN_ALERTS, '1', '30', '86400');
                    setting_field('reading_interval', 'Sensor reading interval', 'seconds',
                        'How often the ESP32 uploads a reading. The dashboard polls at the same pace.', $CAN_ALERTS, '1', '5', '3600');
                    ?>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="notify_in_app"
                                   <?= $CAN_ALERTS ? '' : 'disabled' ?>>
                            <label class="form-check-label fw-semibold" for="notify_in_app">
                                In-app notifications
                            </label>
                            <div class="form-text">Show alerts in the dashboard and the alert badge.</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="notify_email"
                                   <?= $CAN_ALERTS ? '' : 'disabled' ?>>
                            <label class="form-check-label fw-semibold" for="notify_email">
                                Email notifications
                            </label>
                            <div class="form-text">
                                Records the preference. Delivery needs an SMTP server configured
                                in <code>php.ini</code>; without one, alerts remain in-app only.
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($CAN_ALERTS): ?>
                    <div class="alert alert-danger py-2 px-3 small d-none mt-3" id="alertError"></div>
                    <button type="submit" class="btn btn-primary fw-semibold mt-3" id="alertSaveBtn">
                        Save alert settings
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="pg-muted small mt-3" id="settingsMeta">&nbsp;</div>

<script>
window.PG_PAGE = {
    canThresholds: <?= $CAN_THRESHOLDS ? 'true' : 'false' ?>,
    canAlerts:     <?= $CAN_ALERTS ? 'true' : 'false' ?>
};
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
