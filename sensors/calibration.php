<?php
/**
 * PoultryGuard - sensor calibration.
 *
 * Load cell (HX711) and ultrasonic (HC-SR04) calibration. Restricted to
 * accounts holding sensors.calibrate (Farm Staff and Farm Owner).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_page_auth('sensors.calibrate');

$PAGE_TITLE    = 'Sensor Calibration';
$PAGE_SUBTITLE = 'Tune the load cell and ultrasonic sensor so readings match reality.';
$NAV_ACTIVE    = 'sensors.calibration';
$PAGE_SCRIPTS  = [asset_url('assets/js/calibration.js')];

require __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-3">

    <!-- ============================ Load cell ============================ -->
    <div class="col-lg-6">
        <div class="pg-card accent-feed h-100">
            <div class="pg-card-head">
                <h2>Load cell &amp; HX711</h2>
                <span class="pg-pill unknown" id="lcStatus">--</span>
            </div>
            <div class="pg-card-body">

                <div class="row g-2 text-center mb-3">
                    <div class="col-6">
                        <div class="border rounded p-2 h-100">
                            <div class="pg-metric-label">Current weight</div>
                            <div class="fw-bold fs-5 pg-mono" id="lcWeight">--</div>
                            <div class="pg-muted" style="font-size:.72rem" id="lcAge">--</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="border rounded p-2 h-100">
                            <div class="pg-metric-label">Raw HX711 value</div>
                            <div class="fw-bold fs-5 pg-mono" id="lcRaw">--</div>
                            <div class="pg-muted" style="font-size:.72rem">uncalibrated count</div>
                        </div>
                    </div>
                </div>

                <div class="row g-2 small mb-3">
                    <div class="col-6">
                        <span class="pg-muted">Active factor</span>
                        <div class="fw-semibold pg-mono" id="lcFactor">--</div>
                    </div>
                    <div class="col-6">
                        <span class="pg-muted">Active tare offset</span>
                        <div class="fw-semibold pg-mono" id="lcOffset">--</div>
                    </div>
                    <div class="col-12">
                        <span class="pg-muted">Last calibrated</span>
                        <div class="fw-semibold" id="lcDate">--</div>
                    </div>
                </div>

                <div class="alert alert-secondary small py-2 px-3">
                    <strong>Procedure</strong>
                    <ol class="mb-0 ps-3 mt-1">
                        <li>Empty the hopper completely, then press <em>Tare scale</em>.
                            Copy the resulting raw value into <em>Tare offset</em>.</li>
                        <li>Place a known mass (for example a 1&nbsp;kg weight) on the hopper.</li>
                        <li>Read the raw value above, enter it with the reference weight, and save.</li>
                    </ol>
                    <div class="mt-2 pg-muted">
                        factor = (raw at reference &minus; tare offset) &divide; reference weight
                    </div>
                </div>

                <button class="btn btn-outline-secondary btn-sm w-100 mb-3" id="tareBtn">
                    Tare scale (zero with an empty hopper)
                </button>

                <form id="loadCellForm" novalidate>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="lcTareInput">Tare offset (raw counts)</label>
                        <input type="number" step="any" class="form-control form-control-sm"
                               id="lcTareInput" required>
                    </div>

                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="lcTwoPoint" checked>
                        <label class="form-check-label small" for="lcTwoPoint">
                            Derive the factor from a reference weight
                        </label>
                    </div>

                    <div id="lcTwoPointFields">
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small fw-semibold" for="lcReference">Reference weight (g)</label>
                                <input type="number" step="any" min="1" class="form-control form-control-sm"
                                       id="lcReference" value="1000">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold" for="lcRawInput">Raw value at that weight</label>
                                <input type="number" step="any" class="form-control form-control-sm" id="lcRawInput">
                            </div>
                        </div>
                        <div class="small pg-muted mb-2">
                            Computed factor: <strong class="pg-mono" id="lcComputed">--</strong>
                        </div>
                    </div>

                    <div class="mb-2 d-none" id="lcDirectField">
                        <label class="form-label small fw-semibold" for="lcFactorInput">Calibration factor (counts per gram)</label>
                        <input type="number" step="any" class="form-control form-control-sm" id="lcFactorInput">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="lcNotes">Notes (optional)</label>
                        <input type="text" maxlength="255" class="form-control form-control-sm" id="lcNotes"
                               placeholder="e.g. Calibrated with a 1 kg reference mass">
                    </div>

                    <div class="alert alert-danger py-2 px-3 small d-none" id="lcError"></div>

                    <button type="submit" class="btn btn-warning w-100 fw-semibold" id="lcSaveBtn">
                        Save load cell calibration
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ============================ Ultrasonic ============================ -->
    <div class="col-lg-6">
        <div class="pg-card accent-water h-100">
            <div class="pg-card-head">
                <h2>Ultrasonic HC-SR04</h2>
                <span class="pg-pill unknown" id="usStatus">--</span>
            </div>
            <div class="pg-card-body">

                <div class="row g-2 text-center mb-3">
                    <div class="col-4">
                        <div class="border rounded p-2 h-100">
                            <div class="pg-metric-label">Distance</div>
                            <div class="fw-bold fs-5 pg-mono" id="usDistance">--</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="border rounded p-2 h-100">
                            <div class="pg-metric-label">Water depth</div>
                            <div class="fw-bold fs-5 pg-mono" id="usLevel">--</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="border rounded p-2 h-100">
                            <div class="pg-metric-label">Percentage</div>
                            <div class="fw-bold fs-5 pg-mono" id="usPercent">--</div>
                        </div>
                    </div>
                </div>

                <div class="small pg-muted mb-3" id="usAge">--</div>

                <div class="alert alert-secondary small py-2 px-3">
                    <strong>How the conversion works</strong>
                    <div class="mt-1">
                        The sensor points down from above the tank.
                        <code>depth = tank depth &minus; (distance &minus; sensor offset)</code>
                    </div>
                    <ul class="mb-0 ps-3 mt-2">
                        <li><strong>Tank depth</strong> &mdash; usable water depth from the full line to the bottom.</li>
                        <li><strong>Sensor offset</strong> &mdash; gap between the sensor face and the full-water line.</li>
                    </ul>
                    <div class="mt-2">
                        Measure the offset with the tank full: it is the distance the sensor
                        reports at that moment.
                    </div>
                </div>

                <form id="ultrasonicForm" novalidate>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold" for="usDepthInput">Tank depth (cm)</label>
                            <input type="number" step="any" min="1" max="400"
                                   class="form-control form-control-sm" id="usDepthInput" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold" for="usOffsetInput">Sensor offset (cm)</label>
                            <input type="number" step="any" min="0" max="400"
                                   class="form-control form-control-sm" id="usOffsetInput" required>
                        </div>
                    </div>

                    <div class="alert alert-info py-2 px-3 small">
                        With these values, the current distance of
                        <strong class="pg-mono" id="usPreviewDistance">--</strong>
                        would read as <strong class="pg-mono" id="usPreviewLevel">--</strong>
                        (<span id="usPreviewPercent">--</span>).
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="usNotes">Notes (optional)</label>
                        <input type="text" maxlength="255" class="form-control form-control-sm" id="usNotes"
                               placeholder="e.g. 200 L drum, sensor on the lid bracket">
                    </div>

                    <div class="alert alert-danger py-2 px-3 small d-none" id="usError"></div>

                    <button type="submit" class="btn btn-primary w-100 fw-semibold" id="usSaveBtn">
                        Save ultrasonic calibration
                    </button>
                    <div class="form-text mt-2">
                        Saving also updates the tank depth and sensor offset used in Settings,
                        so the server and the device always agree.
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Calibration history -->
<div class="pg-card">
    <div class="pg-card-head"><h2>Calibration history</h2></div>
    <div class="pg-table-wrap">
        <table class="table pg-table mb-0">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Sensor</th>
                    <th class="pg-num">Factor</th>
                    <th class="pg-num">Offset</th>
                    <th class="pg-num">Reference</th>
                    <th>Active</th>
                    <th>By</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody id="historyBody">
                <tr><td colspan="8" class="text-center pg-muted py-4">Loading&hellip;</td></tr>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
