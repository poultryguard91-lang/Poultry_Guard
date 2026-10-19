<?php
/**
 * PoultryGuard - water consumption report.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_page_auth('reports.view');

$PAGE_TITLE    = 'Water Report';
$PAGE_SUBTITLE = 'Consumption and refill history over a chosen period.';
$NAV_ACTIVE    = 'reports.water';
$PAGE_SCRIPTS  = [asset_url('assets/js/reports.js')];
$PAGE_ACTIONS  = '<button class="btn btn-sm btn-light" data-pg-print>Print report</button>';

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/_filters.php';
?>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="pg-card accent-water"><div class="pg-card-body">
            <p class="pg-metric-label">Total consumed</p>
            <p class="pg-metric-value" id="sumConsumed">--</p>
            <p class="pg-metric-sub" id="sumConsumedSub">&nbsp;</p>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="pg-card"><div class="pg-card-body">
            <p class="pg-metric-label">Total refilled</p>
            <p class="pg-metric-value" id="sumDispensed">--</p>
            <p class="pg-metric-sub" id="sumDispensedSub">&nbsp;</p>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="pg-card"><div class="pg-card-body">
            <p class="pg-metric-label">Average per period</p>
            <p class="pg-metric-value" id="sumAverage">--</p>
            <p class="pg-metric-sub" id="sumAverageSub">&nbsp;</p>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="pg-card"><div class="pg-card-body">
            <p class="pg-metric-label">Peak period</p>
            <p class="pg-metric-value" id="sumPeak">--</p>
            <p class="pg-metric-sub" id="sumPeakSub">&nbsp;</p>
        </div></div>
    </div>
</div>

<div class="pg-card mb-3">
    <div class="pg-card-head">
        <h2>Water consumption</h2>
        <span class="small pg-muted" id="rangeLabel">&nbsp;</span>
    </div>
    <div class="pg-card-body">
        <div class="pg-chart"><canvas id="mainChart"></canvas></div>
        <p class="small pg-muted mb-0 mt-2">
            Litres are derived from the level change and the configured tank volume:
            a drop of one tank depth equals the full volume.
        </p>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="pg-card h-100">
            <div class="pg-card-head"><h2>How refilling was triggered</h2></div>
            <div class="pg-table-wrap">
                <table class="table pg-table mb-0">
                    <thead><tr><th>Method</th><th>Status</th><th class="pg-num">Events</th><th class="pg-num">Added</th></tr></thead>
                    <tbody id="methodBody">
                        <tr><td colspan="4" class="text-center pg-muted py-3">--</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="pg-card h-100">
            <div class="pg-card-head"><h2>Consumption by period</h2></div>
            <div class="pg-table-wrap">
                <table class="table pg-table mb-0">
                    <thead>
                        <tr><th>Period</th><th class="pg-num">Consumed</th>
                            <th class="pg-num">Level drop</th><th class="pg-num">Refills</th></tr>
                    </thead>
                    <tbody id="periodBody">
                        <tr><td colspan="4" class="text-center pg-muted py-3">--</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="pg-card">
    <div class="pg-card-head">
        <h2>Refill history</h2>
        <span class="small pg-muted" id="logCount">&nbsp;</span>
    </div>
    <div class="pg-table-wrap">
        <table class="table pg-table mb-0">
            <thead>
                <tr>
                    <th>When</th><th>Method</th><th class="pg-num">Before</th><th class="pg-num">Target</th>
                    <th class="pg-num">After</th><th class="pg-num">Added</th><th class="pg-num">Error</th>
                    <th class="pg-num">Duration</th><th>Status</th><th>By</th>
                </tr>
            </thead>
            <tbody id="logBody">
                <tr><td colspan="10" class="text-center pg-muted py-4">Choose a range and press Generate.</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
window.PG_PAGE = { report: 'water' };
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
