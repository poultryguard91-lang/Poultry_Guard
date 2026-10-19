<?php
/**
 * PoultryGuard - device management.
 *
 * Everyone with devices.view sees the status board; only devices.manage
 * (Farm Owner) can add, edit, disable a device or rotate its API key.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_page_auth('devices.view');

$CAN_MANAGE = user_can('devices.manage');

$PAGE_TITLE    = 'Devices';
$PAGE_SUBTITLE = 'ESP32 controllers registered to this farm.';
$NAV_ACTIVE    = 'devices';
$PAGE_SCRIPTS  = [asset_url('assets/js/devices.js')];
if ($CAN_MANAGE) {
    $PAGE_ACTIONS = '<button class="btn btn-sm btn-primary" id="addDeviceBtn">Add device</button>';
}

require __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-3">
    <div class="col-4">
        <div class="pg-card"><div class="pg-card-body">
            <p class="pg-metric-label">Registered</p>
            <p class="pg-metric-value" id="sumTotal">--</p>
        </div></div>
    </div>
    <div class="col-4">
        <div class="pg-card"><div class="pg-card-body">
            <p class="pg-metric-label">Online</p>
            <p class="pg-metric-value text-success" id="sumOnline">--</p>
        </div></div>
    </div>
    <div class="col-4">
        <div class="pg-card"><div class="pg-card-body">
            <p class="pg-metric-label">Offline</p>
            <p class="pg-metric-value text-danger" id="sumOffline">--</p>
        </div></div>
    </div>
</div>

<div id="deviceCards" class="row g-3">
    <div class="col-12 text-center pg-muted py-5">Loading devices&hellip;</div>
</div>

<?php if ($CAN_MANAGE): ?>
<!-- ===================== Add / edit device modal ===================== -->
<div class="modal fade" id="deviceModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="deviceForm" novalidate>
      <div class="modal-header">
        <h5 class="modal-title" id="deviceModalTitle">Add device</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="dDeviceId">

        <div class="mb-3">
          <label class="form-label small fw-semibold" for="dName">Device name</label>
          <input type="text" class="form-control" id="dName" maxlength="100" required
                 placeholder="e.g. Broiler House 1 Controller">
        </div>

        <div class="mb-3" id="uidGroup">
          <label class="form-label small fw-semibold" for="dUid">ESP32 UID</label>
          <input type="text" class="form-control pg-mono" id="dUid" maxlength="64"
                 placeholder="e.g. ESP32-A1B2C3D4E5F6">
          <div class="form-text">
            The identifier the firmware reports. It is printed to the serial monitor
            on boot &mdash; copy it exactly. Cannot be changed after registration.
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold" for="dLocation">Location</label>
          <input type="text" class="form-control" id="dLocation" maxlength="150"
                 placeholder="e.g. Broiler House 1 - North Wing">
        </div>

        <div class="alert alert-danger py-2 px-3 small d-none" id="deviceError"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" id="deviceSaveBtn">Save device</button>
      </div>
    </form>
  </div>
</div>

<!-- ===================== API key reveal modal ===================== -->
<div class="modal fade" id="keyModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Device API key</h5>
      </div>
      <div class="modal-body">
        <p class="small">
          Paste this into <code>API_KEY</code> in the ESP32 firmware
          (<code>firmware/PoultryGuard_ESP32/config.h</code>).
        </p>
        <div class="input-group mb-2">
          <input type="text" class="form-control pg-mono" id="keyValue" readonly>
          <button class="btn btn-outline-secondary" type="button" id="copyKeyBtn">Copy</button>
        </div>
        <div class="alert alert-warning py-2 px-3 small mb-0">
          <strong>This key is shown once.</strong>
          It is stored for comparison only and cannot be displayed again.
          If you lose it, generate a new one &mdash; the device will not be able to
          report until the firmware is updated to match.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">I have copied it</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
window.PG_PAGE = { canManage: <?= $CAN_MANAGE ? 'true' : 'false' ?> };
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
