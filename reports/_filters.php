<?php
/**
 * PoultryGuard - shared report filter bar.
 *
 * Included by the consumption and system report pages. Kept in one file so a
 * change to the filters applies everywhere at once.
 *
 * Expects $ALL_DEVICES and $CURRENT_DEVICE_ID from header.php.
 * Set $SHOW_PERIOD = false to hide the daily/weekly/monthly selector.
 */

declare(strict_types=1);

$SHOW_PERIOD = $SHOW_PERIOD ?? true;
?>
<div class="pg-card mb-3 pg-no-print">
    <div class="pg-card-body">
        <form id="filterForm" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold" for="fDevice">Device / poultry house</label>
                <select class="form-select form-select-sm" id="fDevice">
                    <?php foreach ($ALL_DEVICES as $d): ?>
                        <option value="<?= (int)$d['device_id'] ?>"
                            <?= (int)$d['device_id'] === $CURRENT_DEVICE_ID ? 'selected' : '' ?>>
                            <?= e($d['device_name']) ?>
                            <?= $d['location'] ? ' — ' . e($d['location']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold" for="fFrom">From</label>
                <input type="date" class="form-control form-control-sm" id="fFrom">
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold" for="fTo">To</label>
                <input type="date" class="form-control form-control-sm" id="fTo">
            </div>

            <?php if ($SHOW_PERIOD): ?>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold" for="fPeriod">Group by</label>
                    <select class="form-select form-select-sm" id="fPeriod">
                        <option value="daily" selected>Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </div>
            <?php endif; ?>

            <div class="col-12 col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary flex-grow-1">Generate</button>
                <button type="button" class="btn btn-sm btn-light" id="quick7">7 d</button>
                <button type="button" class="btn btn-sm btn-light" id="quick30">30 d</button>
            </div>
        </form>
    </div>
</div>
