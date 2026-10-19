<?php
/**
 * PoultryGuard - productivity report (Feed Conversion Ratio).
 *
 * FCR = feed consumed (kg) / live weight gain (kg). The feed side comes from
 * load-cell data; the weight side comes from the flock weigh-ins recorded here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_page_auth('reports.view');

$CAN_RECORD = user_can('production.manage');

$PAGE_TITLE    = 'Productivity Report';
$PAGE_SUBTITLE = 'Feed Conversion Ratio, growth and mortality by flock.';
$NAV_ACTIVE    = 'reports.productivity';
$PAGE_SCRIPTS  = [asset_url('assets/js/reports.js')];
$PAGE_ACTIONS  = '<button class="btn btn-sm btn-light" data-pg-print>Print report</button>'
    . ($CAN_RECORD ? '<button class="btn btn-sm btn-primary" id="addRecordBtn">Record weigh-in</button>' : '');
$SHOW_DEVICE_PICKER = false;

require __DIR__ . '/../includes/header.php';

$HOUSES = db()->query('SELECT DISTINCT house_name FROM flocks ORDER BY house_name')
    ->fetchAll(PDO::FETCH_COLUMN);
$FLOCKS = db()->query(
    'SELECT f.flock_id, f.flock_name, f.house_name, f.status
     FROM flocks f ORDER BY f.status ASC, f.start_date DESC'
)->fetchAll();
?>

<div class="pg-card mb-3 pg-no-print">
    <div class="pg-card-body">
        <form id="filterForm" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold" for="fHouse">Poultry house</label>
                <select class="form-select form-select-sm" id="fHouse">
                    <option value="all">All houses</option>
                    <?php foreach ($HOUSES as $h): ?>
                        <option value="<?= e($h) ?>"><?= e($h) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold" for="fDevice">Device</label>
                <select class="form-select form-select-sm" id="fDevice">
                    <option value="all">All devices</option>
                    <?php foreach ($ALL_DEVICES as $d): ?>
                        <option value="<?= (int)$d['device_id'] ?>"><?= e($d['device_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold" for="fStatus">Flock status</label>
                <select class="form-select form-select-sm" id="fStatus">
                    <option value="all">All</option>
                    <option value="active" selected>Active</option>
                    <option value="closed">Closed</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold" for="fFrom">Started from</label>
                <input type="date" class="form-control form-control-sm" id="fFrom">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold" for="fTo">Started to</label>
                <input type="date" class="form-control form-control-sm" id="fTo">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-sm btn-primary">Generate</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="pg-card accent-brand"><div class="pg-card-body">
            <p class="pg-metric-label">Overall FCR</p>
            <p class="pg-metric-value" id="sumFcr">--</p>
            <p class="pg-metric-sub" id="sumFcrSub">&nbsp;</p>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="pg-card"><div class="pg-card-body">
            <p class="pg-metric-label">Total feed used</p>
            <p class="pg-metric-value" id="sumFeed">--</p>
            <p class="pg-metric-sub">measured by load cell</p>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="pg-card"><div class="pg-card-body">
            <p class="pg-metric-label">Total weight gain</p>
            <p class="pg-metric-value" id="sumGain">--</p>
            <p class="pg-metric-sub" id="sumBirds">&nbsp;</p>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="pg-card"><div class="pg-card-body">
            <p class="pg-metric-label">Average mortality</p>
            <p class="pg-metric-value" id="sumMortality">--</p>
            <p class="pg-metric-sub" id="sumFlocks">&nbsp;</p>
        </div></div>
    </div>
</div>

<div class="pg-card mb-3">
    <div class="pg-card-head">
        <h2>Feed Conversion Ratio by flock</h2>
        <span class="small pg-muted">Lower is better</span>
    </div>
    <div class="pg-card-body">
        <div class="pg-chart"><canvas id="mainChart"></canvas></div>
        <p class="small pg-muted mb-0 mt-2">
            FCR = feed consumed &divide; live weight gain. Weight gain uses the
            <em>surviving</em> bird count at both ends of the period, so birds lost to
            mortality do not inflate the figure. A broiler flock typically runs 1.5&ndash;1.8;
            growing pullets run higher because they gain weight more slowly.
        </p>
    </div>
</div>

<div class="pg-card mb-3">
    <div class="pg-card-head"><h2>Flock performance</h2></div>
    <div class="pg-table-wrap">
        <table class="table pg-table mb-0">
            <thead>
                <tr>
                    <th>Flock</th><th>House</th><th>Breed</th>
                    <th class="pg-num">Age</th><th class="pg-num">Birds</th>
                    <th class="pg-num">Mortality</th><th class="pg-num">Avg weight</th>
                    <th class="pg-num">ADG</th><th class="pg-num">Feed used</th>
                    <th class="pg-num">Gain</th><th class="pg-num">FCR</th>
                </tr>
            </thead>
            <tbody id="flockBody">
                <tr><td colspan="11" class="text-center pg-muted py-4">Loading&hellip;</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="pg-card">
    <div class="pg-card-head"><h2>Weigh-in records</h2></div>
    <div class="pg-table-wrap">
        <table class="table pg-table mb-0">
            <thead>
                <tr><th>Flock</th><th>Date</th><th class="pg-num">Birds</th>
                    <th class="pg-num">Avg weight</th><th class="pg-num">Mortality</th></tr>
            </thead>
            <tbody id="recordBody">
                <tr><td colspan="5" class="text-center pg-muted py-4">--</td></tr>
            </tbody>
        </table>
    </div>
</div>

<?php if ($CAN_RECORD): ?>
<!-- ===================== Weigh-in modal ===================== -->
<div class="modal fade" id="recordModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="recordForm" novalidate>
      <div class="modal-header">
        <h5 class="modal-title">Record a flock weigh-in</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="small pg-muted">
          The feed side of the FCR is measured automatically. This is the other half:
          how many birds there are and what they weigh.
        </p>

        <div class="mb-3">
          <label class="form-label small fw-semibold" for="rFlock">Flock</label>
          <select class="form-select" id="rFlock" required>
            <?php foreach ($FLOCKS as $f): ?>
              <option value="<?= (int)$f['flock_id'] ?>">
                <?= e($f['flock_name']) ?> &mdash; <?= e($f['house_name']) ?>
                <?= $f['status'] === 'closed' ? ' (closed)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="row g-2">
          <div class="col-6">
            <label class="form-label small fw-semibold" for="rDate">Date</label>
            <input type="date" class="form-control" id="rDate" required
                   max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>">
          </div>
          <div class="col-6">
            <label class="form-label small fw-semibold" for="rCount">Birds alive</label>
            <input type="number" class="form-control" id="rCount" min="0" step="1" required>
          </div>
          <div class="col-6">
            <label class="form-label small fw-semibold" for="rWeight">Average weight</label>
            <div class="input-group">
              <input type="number" class="form-control" id="rWeight" min="1" step="1" required>
              <span class="input-group-text">g</span>
            </div>
          </div>
          <div class="col-6">
            <label class="form-label small fw-semibold" for="rMortality">Deaths since last</label>
            <input type="number" class="form-control" id="rMortality" min="0" step="1" value="0">
          </div>
          <div class="col-12">
            <label class="form-label small fw-semibold" for="rNotes">Notes (optional)</label>
            <input type="text" class="form-control" id="rNotes" maxlength="255">
          </div>
        </div>

        <div class="alert alert-danger py-2 px-3 small d-none mt-3" id="recordError"></div>
        <div class="form-text mt-2">
          One record per flock per day. Saving again for the same date corrects that entry.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" id="recordSaveBtn">Save weigh-in</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
window.PG_PAGE = { report: 'productivity', canRecord: <?= $CAN_RECORD ? 'true' : 'false' ?> };
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
