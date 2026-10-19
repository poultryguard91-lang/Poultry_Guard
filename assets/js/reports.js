/* =====================================================================
   PoultryGuard - reports.

   One controller for all four report pages; window.PG_PAGE.report selects
   which. Reports are generated on demand rather than polled - they cover a
   fixed historical range, so there is nothing to refresh.
   ===================================================================== */

(function () {
    'use strict';

    const report = (window.PG_PAGE || {}).report || 'water';
    let chart = null;
    let typeChart = null;

    // -----------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------
    function val(id) {
        const el = document.getElementById(id);
        return el ? el.value : '';
    }

    function periodLabel(period, groupBy) {
        if (groupBy === 'monthly') {
            const d = new Date(String(period).replace(' ', 'T'));
            return isNaN(d) ? period : d.toLocaleDateString(undefined, { month: 'short', year: 'numeric' });
        }
        if (groupBy === 'weekly') return 'w/c ' + PG.fmt.date(period);
        return PG.fmt.date(period);
    }

    function methodBadge(method) {
        const cls = method === 'manual' ? 'bg-secondary'
            : method === 'offline' ? 'bg-dark' : 'bg-success';
        return '<span class="badge ' + cls + '">' + PG.escapeHtml(PG.fmt.label(method)) + '</span>';
    }

    function empty(bodyId, cols, message) {
        document.getElementById(bodyId).innerHTML =
            '<tr><td colspan="' + cols + '" class="text-center pg-muted py-4">' +
            PG.escapeHtml(message) + '</td></tr>';
    }

    // =================================================================
    // Water consumption report
    // =================================================================
    function paintConsumption(data) {
        const s = data.summary;
        const groupBy = data.range.period;

        PG.setText('rangeLabel', data.range.label + ' · ' + PG.fmt.label(groupBy) +
            ' · ' + data.device.device_name);

        PG.setText('sumConsumed', PG.fmt.liters(s.total_consumed_liters));
        PG.setText('sumConsumedSub', s.total_consumed_cm + ' cm of level drop');
        PG.setText('sumDispensed', PG.fmt.cm(s.total_refilled_cm));
        PG.setText('sumDispensedSub', s.total_events + ' refill event(s)');
        PG.setText('sumAverage', PG.fmt.liters(s.average_per_period));
        PG.setText('sumPeak', PG.fmt.liters(s.peak_period));
        PG.setText('sumAverageSub', 'over ' + s.periods + ' ' + groupBy.replace('ly', '') + ' period(s)');
        PG.setText('sumPeakSub', 'busiest single period');

        // --- chart ---
        const labels = data.series.map(function (r) { return periodLabel(r.period, groupBy); });
        PG.charts.update(chart, labels, [data.series.map(function (r) { return r.consumed_liters; })]);
        PG.charts.emptyState('mainChart', data.series.length ? '' : 'No data in this range.');

        // --- method breakdown ---
        if (!data.methods.length) {
            empty('methodBody', 4, 'No dispensing events in this range.');
        } else {
            document.getElementById('methodBody').innerHTML = data.methods.map(function (m) {
                return '<tr>' +
                    '<td>' + methodBadge(m.method) + '</td>' +
                    '<td><span class="badge bg-' + PG.statusColor(m.status) + '">' +
                        PG.escapeHtml(PG.fmt.label(m.status)) + '</span></td>' +
                    '<td class="pg-num">' + m.events + '</td>' +
                    '<td class="pg-num">' + PG.escapeHtml(PG.fmt.cm(m.amount_cm)) + '</td>' +
                '</tr>';
            }).join('');
        }

        // --- per-period table ---
        if (!data.series.length) {
            empty('periodBody', 4, 'No data in this range.');
        } else {
            document.getElementById('periodBody').innerHTML = data.series.map(function (r) {
                return '<tr>' +
                    '<td>' + PG.escapeHtml(periodLabel(r.period, groupBy)) + '</td>' +
                    '<td class="pg-num fw-semibold">' + PG.escapeHtml(PG.fmt.liters(r.consumed_liters)) + '</td>' +
                    '<td class="pg-num">' + PG.escapeHtml(PG.fmt.cm(r.consumed_cm)) + '</td>' +
                    '<td class="pg-num">' + r.events + '</td>' +
                '</tr>';
            }).join('');
        }

        // --- event log ---
        PG.setText('logCount', data.log.length + ' event(s)');
        if (!data.log.length) {
            empty('logBody', 10, 'No events recorded in this range.');
            return;
        }

        document.getElementById('logBody').innerHTML = data.log.map(function (r) {
            const err = r.accuracy_cm;
            const errText = (err === null || err === undefined) ? '--' : (err > 0 ? '+' : '') + err + ' cm';
            const errClass = (err === null || err === undefined) ? ''
                : (Math.abs(err) > 1 ? ' text-danger fw-semibold' : ' text-success');

            return '<tr>' +
                '<td class="text-nowrap">' + PG.escapeHtml(PG.fmt.datetime(r.timestamp)) + '</td>' +
                '<td>' + methodBadge(r.dispensing_method) + '</td>' +
                '<td class="pg-num">' + PG.escapeHtml(PG.fmt.cm(r.starting_level)) + '</td>' +
                '<td class="pg-num">' + PG.escapeHtml(PG.fmt.cm(r.target_level)) + '</td>' +
                '<td class="pg-num">' + PG.escapeHtml(r.ending_level === null ? '--' : PG.fmt.cm(r.ending_level)) + '</td>' +
                '<td class="pg-num fw-semibold">' + PG.escapeHtml(r.added_cm === null ? '--' : PG.fmt.cm(r.added_cm)) + '</td>' +
                '<td class="pg-num' + errClass + '">' + PG.escapeHtml(errText) + '</td>' +
                '<td class="pg-num">' + PG.escapeHtml(PG.fmt.seconds(r.duration_ms)) + '</td>' +
                '<td><span class="badge bg-' + PG.statusColor(r.status) + '">' +
                    PG.escapeHtml(PG.fmt.label(r.status)) + '</span></td>' +
                '<td class="pg-muted">' + PG.escapeHtml(r.user_name || 'System') + '</td>' +
            '</tr>';
        }).join('');
    }

    // =================================================================
    // System report
    // =================================================================
    function paintSystem(data) {
        const groupBy = data.range.period;
        PG.setText('rangeLabel', data.range.label + ' · ' + data.device.device_name);

        PG.setText('sumTotal', PG.fmt.number(data.summary.total));
        PG.setText('sumTotalSub', data.summary.resolved + ' resolved');
        PG.setText('sumCritical', PG.fmt.number(data.summary.critical));
        PG.setText('sumCriticalSub', data.summary.warning + ' warning, ' + data.summary.info + ' info');

        PG.setText('sumUptime', PG.fmt.percent(data.uptime.estimated_percent));
        PG.setText('sumUptimeSub', data.uptime.currently_online ? 'Online now' : 'Offline now');

        const totalReadings = data.sensor_activity.climate_readings + data.sensor_activity.water_readings;
        PG.setText('sumReadings', PG.fmt.number(totalReadings));
        const offline = data.sensor_activity.climate_offline_sync + data.sensor_activity.water_offline_sync;
        PG.setText('sumReadingsSub', offline > 0
            ? PG.fmt.number(offline) + ' arrived via the offline buffer'
            : 'all received live');

        // --- frequency chart (stacked by severity) ---
        const labels = data.frequency.map(function (r) { return periodLabel(r.period, groupBy); });
        PG.charts.update(chart, labels, [
            data.frequency.map(function (r) { return r.critical; }),
            data.frequency.map(function (r) { return r.warning; })
        ]);
        PG.charts.emptyState('mainChart', data.frequency.length ? '' : 'No alerts in this range.');

        // --- by type ---
        const typeLabels = data.by_type.map(function (r) { return PG.fmt.label(r.alert_type); });
        const typeValues = data.by_type.map(function (r) { return r.total; });
        const typeColors = data.by_type.map(function (r) {
            return r.severity === 'CRITICAL' ? '#dc2626'
                 : r.severity === 'WARNING' ? '#f8a71d' : '#0284c7';
        });
        PG.charts.update(typeChart, typeLabels, [typeValues], [typeColors]);
        PG.charts.emptyState('typeChart', data.by_type.length ? '' : 'No alerts in this range.');

        // --- uptime detail ---
        const upNow = document.getElementById('upNow');
        upNow.className = 'pg-pill ' + (data.uptime.currently_online ? 'normal' : 'critical');
        upNow.textContent = data.uptime.currently_online ? 'Online' : 'Offline';
        PG.setText('upLastSeen', PG.fmt.datetime(data.uptime.last_seen));
        PG.setText('upInterval', data.uptime.reading_interval + ' s');
        PG.setText('upExpected', PG.fmt.number(data.uptime.expected_readings));
        PG.setText('upActual', PG.fmt.number(data.uptime.actual_readings));

        PG.setText('actFeed', PG.fmt.number(data.sensor_activity.climate_readings));
        PG.setText('actWater', PG.fmt.number(data.sensor_activity.water_readings));
        PG.setText('actFeedOffline', PG.fmt.number(data.sensor_activity.climate_offline_sync));
        PG.setText('actWaterOffline', PG.fmt.number(data.sensor_activity.water_offline_sync));

        // --- manual vs automated ---
        if (!data.actions.length) {
            empty('actionBody', 4, 'No dispensing events in this range.');
        } else {
            document.getElementById('actionBody').innerHTML = data.actions.map(function (a) {
                return '<tr><td>' + methodBadge(a.method) + '</td>' +
                    '<td class="pg-num">' + a.feed_events + '</td>' +
                    '<td class="pg-num">' + a.water_events + '</td>' +
                    '<td class="pg-num fw-semibold">' + a.total + '</td></tr>';
            }).join('');
        }

        // --- activity log ---
        PG.setText('logCount', data.activity_log.length + ' entries');
        if (!data.activity_log.length) {
            empty('logBody', 5, 'No operator activity in this range.');
            return;
        }
        document.getElementById('logBody').innerHTML = data.activity_log.map(function (l) {
            return '<tr>' +
                '<td class="text-nowrap small">' + PG.escapeHtml(PG.fmt.datetime(l.created_at)) + '</td>' +
                '<td>' + PG.escapeHtml(l.user_name || 'System / device') + '</td>' +
                '<td><span class="badge bg-light text-dark">' + PG.escapeHtml(l.action) + '</span></td>' +
                '<td class="small">' + PG.escapeHtml(l.description) + '</td>' +
                '<td class="pg-muted pg-mono small">' + PG.escapeHtml(l.ip_address || '--') + '</td>' +
            '</tr>';
        }).join('');
    }

    // =================================================================
    // Load
    // =================================================================
    async function load() {
        const params = {
            device_id: val('fDevice'), from: val('fFrom'), to: val('fTo'), period: val('fPeriod')
        };

        if (report === 'alerts') {
            paintSystem(await PG.api('api/reports/alerts.php', { params: params }));
        } else {
            paintConsumption(await PG.api('api/reports/water.php', { params: params }));
        }
    }

    function generate() {
        load().catch(function (e) { PG.toast(e.message, 'danger'); });
    }

    // =================================================================
    // Wire up
    // =================================================================
    document.addEventListener('DOMContentLoaded', function () {
        // Build the chart this page needs.
        if (report === 'water') {
            chart = PG.charts.consumptionChart('mainChart', {
                unit: PG.fmt.liters,
                primaryLabel: 'Consumed',
                primaryColor: PG.charts.COLORS.water
            });
        } else if (report === 'alerts') {
            chart = PG.charts.consumptionChart('mainChart', {
                unit: function (n) { return PG.fmt.number(n); },
                primaryLabel: 'Critical',
                secondaryLabel: 'Warning',
                primaryColor: '#dc2626'
            });
            typeChart = PG.charts.categoryChart('typeChart', { label: 'Alerts' });
        }

        // Default range: the last 14 days.
        const from = document.getElementById('fFrom');
        const to = document.getElementById('fTo');
        if (from && to) {
            const now = new Date();
            const past = new Date(now.getTime() - 13 * 86400000);
            to.value = now.toISOString().slice(0, 10);
            from.value = past.toISOString().slice(0, 10);
        }

        generate();

        document.getElementById('filterForm').addEventListener('submit', function (e) {
            e.preventDefault();
            generate();
        });

        // Quick range buttons
        function quickRange(days) {
            const now = new Date();
            to.value = now.toISOString().slice(0, 10);
            from.value = new Date(now.getTime() - (days - 1) * 86400000).toISOString().slice(0, 10);
            generate();
        }
        const q7 = document.getElementById('quick7');
        const q30 = document.getElementById('quick30');
        if (q7) q7.addEventListener('click', function () { quickRange(7); });
        if (q30) q30.addEventListener('click', function () { quickRange(30); });
    });
})();
