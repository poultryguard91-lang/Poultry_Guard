/* =====================================================================
   PoultryGuard - water monitoring page.

   Drives the water level sensor view (the feeder is timed, so it has no
   level of its own to monitor).
   ===================================================================== */

(function () {
    'use strict';

    const kind = 'water';
    const deviceId = PG.deviceId;
    if (!deviceId) return;

    const spec = {
        endpoint: 'api/water/read.php',
        color: PG.charts.COLORS.water,
        chartLabel: 'Water depth',
        unit: PG.fmt.cm,
        barClass: 'water',
        accuracyUnit: 'cm',
        hardwareLabel: 'pump'
    };

    let chart = null;
    let hours = 24;

    // -----------------------------------------------------------------
    // Painting
    // -----------------------------------------------------------------
    function paintCurrent(c, device) {
        const pct = c.percentage;

        PG.setText('mainReading', c.level_display);
        PG.setText('mainPercent', PG.fmt.percent(pct));
        PG.setText('infoThreshold', spec.unit(c.threshold) + ' (' + PG.fmt.percent(c.threshold_percent) + ')');
        PG.setText('infoTarget', spec.unit(c.target));
        PG.setText('infoCapacity', spec.unit(c.capacity));
        PG.setText('infoAuto', c.auto ? 'Enabled' : 'Disabled');
        PG.setText('infoAge', c.reading_ago);

        PG.setText('mainLiters', PG.fmt.liters(c.liters));
        PG.setText('infoVolume', PG.fmt.liters(c.capacity_liters));

        // Status pill
        const pill = document.getElementById('statusPill');
        if (pill) {
            const cls = String(c.status || 'unknown').toLowerCase();
            pill.className = 'pg-pill ' + (['normal', 'low', 'critical'].indexOf(cls) !== -1 ? cls : 'unknown');
        }
        PG.setText('statusText', c.status);

        // Level bar, coloured by status
        const bar = document.getElementById('levelBar');
        if (bar) {
            const w = pct === null ? 0 : Math.max(0, Math.min(100, pct));
            bar.style.width = w + '%';
            bar.className = 'pg-level-fill ' +
                (c.status === 'CRITICAL' ? 'critical' : c.status === 'LOW' ? 'low' : spec.barClass);
            bar.textContent = w > 12 ? PG.fmt.percent(pct) : '';
        }
        const mark = document.getElementById('thresholdMark');
        if (mark) mark.style.left = Math.max(0, Math.min(100, c.threshold_percent)) + '%';

        // Hardware tiles
        const sensorStatus = device.sensor_status;
        PG.setText('sensorStatus', device.online ? PG.fmt.label(sensorStatus) : 'Unknown');
        PG.setText('sensorSub', device.online
            ? (sensorStatus === 'ok' ? 'Reporting normally' : 'Check the sensor wiring')
            : 'Controller is offline');

        const actuator = device.solenoid_status;
        PG.setText('motorStatus', device.online ? PG.fmt.label(actuator) : 'Unknown');
        PG.setText('motorSub', device.online
            ? (actuator === 'running' ? 'Currently filling'
                                      : 'Idle and ready')
            : 'Controller is offline');
    }

    function paintAccuracy(a) {
        if (a.runs === 0 || a.avg_error === null) {
            PG.setText('accuracyValue', 'No data');
            PG.setText('accuracySub', 'No completed runs in the last 30 days');
            return;
        }
        PG.setText('accuracyValue', '±' + a.avg_error + ' ' + spec.accuracyUnit);
        const withinTarget = a.avg_error <= a.target;
        PG.setText('accuracySub',
            'Average over ' + a.runs + ' run' + (a.runs === 1 ? '' : 's') +
            ' · target ±' + a.target + ' ' + spec.accuracyUnit +
            (withinTarget ? ' — within spec' : ' — above target'));
    }

    function paintHistory(rows) {
        const body = document.getElementById('historyBody');
        if (!body) return;

        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="10" class="text-center pg-muted py-4">' +
                'No refill events recorded yet.</td></tr>';
            return;
        }

        body.innerHTML = rows.map(function (r) {
            const before = PG.fmt.cm(r.starting_level);
            const target = PG.fmt.cm(r.target_level);
            const after  = r.ending_level === null ? '--' : PG.fmt.cm(r.ending_level);
            const added  = r.added_cm === null ? '--' : PG.fmt.cm(r.added_cm);

            const err = r.accuracy_cm;
            const errText = (err === null || err === undefined)
                ? '--'
                : (err > 0 ? '+' : '') + err + ' ' + spec.accuracyUnit;

            // Highlight a run that missed the accuracy target.
            const errClass = (err === null || err === undefined) ? ''
                : (Math.abs(err) > 1 ? ' text-danger fw-semibold' : ' text-success');

            const methodBadge = r.dispensing_method === 'manual' ? 'bg-secondary'
                : (r.dispensing_method === 'offline' ? 'bg-dark' : 'bg-success');

            return '<tr>' +
                '<td class="text-nowrap">' + PG.escapeHtml(PG.fmt.datetime(r.timestamp)) +
                    '<div class="pg-muted" style="font-size:.72rem">' + PG.escapeHtml(r.ago) + '</div></td>' +
                '<td><span class="badge ' + methodBadge + '">' +
                    PG.escapeHtml(PG.fmt.label(r.dispensing_method)) + '</span></td>' +
                '<td class="pg-num">' + PG.escapeHtml(before) + '</td>' +
                '<td class="pg-num">' + PG.escapeHtml(target) + '</td>' +
                '<td class="pg-num">' + PG.escapeHtml(after) + '</td>' +
                '<td class="pg-num fw-semibold">' + PG.escapeHtml(added) + '</td>' +
                '<td class="pg-num' + errClass + '">' + PG.escapeHtml(errText) + '</td>' +
                '<td class="pg-num">' + PG.escapeHtml(PG.fmt.seconds(r.duration_ms)) + '</td>' +
                '<td><span class="badge bg-' + PG.statusColor(r.status) + '">' +
                    PG.escapeHtml(PG.fmt.label(r.status)) + '</span></td>' +
                '<td class="pg-muted">' + PG.escapeHtml(r.user_name || 'System') + '</td>' +
            '</tr>';
        }).join('');
    }

    // -----------------------------------------------------------------
    // Load
    // -----------------------------------------------------------------
    async function load() {
        const data = await PG.api(spec.endpoint, {
            params: { device_id: deviceId, hours: hours, limit: 25 }
        });

        paintCurrent(data.current, data.device);
        paintAccuracy(data.accuracy);
        paintHistory(data.dispensing);

        const labels = data.history.map(function (p) {
            return hours > 48 ? PG.fmt.date(p.time) + ' ' + PG.fmt.time(p.time) : PG.fmt.time(p.time);
        });
        PG.charts.update(chart, labels, [data.history.map(function (p) { return p.value; })]);
        PG.charts.setThreshold(chart, data.current.threshold);
        PG.charts.emptyState('levelChart', data.history.length ? '' : 'No readings in this period yet.');
    }

    document.addEventListener('DOMContentLoaded', function () {
        chart = PG.charts.levelChart('levelChart', {
            color: spec.color,
            label: spec.chartLabel,
            unit: spec.unit,
            thresholdLabel: 'Refill threshold'
        });

        PG.poll(load, Math.max(10000, PG_CONFIG.pollInterval || 30000));

        const range = document.getElementById('rangeSelect');
        if (range) {
            range.addEventListener('change', function () {
                hours = parseInt(this.value, 10) || 24;
                load().catch(function (e) { PG.toast(e.message, 'danger'); });
            });
        }
    });
})();
