/* =====================================================================
   PoultryGuard - reports.

   One controller for all four report pages; window.PG_PAGE.report selects
   which. Reports are generated on demand rather than polled - they cover a
   fixed historical range, so there is nothing to refresh.
   ===================================================================== */

(function () {
    'use strict';

    const report = (window.PG_PAGE || {}).report || 'feed';
    let chart = null;
    let typeChart = null;
    let recordModal = null;

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
    // Feed & water consumption reports
    // =================================================================
    function paintConsumption(data, isFeed) {
        const s = data.summary;
        const groupBy = data.range.period;

        PG.setText('rangeLabel', data.range.label + ' · ' + PG.fmt.label(groupBy) +
            ' · ' + data.device.device_name);

        if (isFeed) {
            PG.setText('sumConsumed', PG.fmt.weight(s.total_consumed_g));
            PG.setText('sumConsumedSub', s.total_consumed_kg + ' kg eaten by the birds');
            PG.setText('sumDispensed', PG.fmt.weight(s.total_dispensed_g));
            PG.setText('sumDispensedSub', s.total_events + ' dispensing event(s)');
            PG.setText('sumAverage', s.average_display);
            PG.setText('sumPeak', s.peak_display);
        } else {
            PG.setText('sumConsumed', PG.fmt.liters(s.total_consumed_liters));
            PG.setText('sumConsumedSub', s.total_consumed_cm + ' cm of level drop');
            PG.setText('sumDispensed', PG.fmt.cm(s.total_refilled_cm));
            PG.setText('sumDispensedSub', s.total_events + ' refill event(s)');
            PG.setText('sumAverage', PG.fmt.liters(s.average_per_period));
            PG.setText('sumPeak', PG.fmt.liters(s.peak_period));
        }
        PG.setText('sumAverageSub', 'over ' + s.periods + ' ' + groupBy.replace('ly', '') + ' period(s)');
        PG.setText('sumPeakSub', 'busiest single period');

        // --- chart ---
        const labels = data.series.map(function (r) { return periodLabel(r.period, groupBy); });
        const series = isFeed
            ? [data.series.map(function (r) { return r.consumed; }),
               data.series.map(function (r) { return r.dispensed; })]
            : [data.series.map(function (r) { return r.consumed_liters; })];

        PG.charts.update(chart, labels, series);
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
                    '<td class="pg-num">' +
                        PG.escapeHtml(isFeed ? PG.fmt.weight(m.amount) : PG.fmt.cm(m.amount_cm)) + '</td>' +
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
                    '<td class="pg-num fw-semibold">' +
                        PG.escapeHtml(isFeed ? PG.fmt.weight(r.consumed) : PG.fmt.liters(r.consumed_liters)) + '</td>' +
                    '<td class="pg-num">' +
                        PG.escapeHtml(isFeed ? PG.fmt.weight(r.dispensed) : PG.fmt.cm(r.consumed_cm)) + '</td>' +
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
            const before = isFeed ? PG.fmt.weight(r.start_weight) : PG.fmt.cm(r.starting_level);
            const target = isFeed ? PG.fmt.weight(r.target_weight) : PG.fmt.cm(r.target_level);
            const after = isFeed
                ? (r.actual_weight === null ? '--' : PG.fmt.weight(r.actual_weight))
                : (r.ending_level === null ? '--' : PG.fmt.cm(r.ending_level));
            const added = isFeed
                ? (r.dispensed_display || '--')
                : (r.added_cm === null ? '--' : PG.fmt.cm(r.added_cm));

            const err = isFeed ? r.accuracy_g : r.accuracy_cm;
            const errText = (err === null || err === undefined)
                ? '--' : (err > 0 ? '+' : '') + err + (isFeed ? ' g' : ' cm');
            const errClass = (err === null || err === undefined) ? ''
                : (Math.abs(err) > (isFeed ? 5 : 1) ? ' text-danger fw-semibold' : ' text-success');

            return '<tr>' +
                '<td class="text-nowrap">' + PG.escapeHtml(PG.fmt.datetime(r.timestamp)) + '</td>' +
                '<td>' + methodBadge(r.dispensing_method) + '</td>' +
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

        const totalReadings = data.sensor_activity.feed_readings + data.sensor_activity.water_readings;
        PG.setText('sumReadings', PG.fmt.number(totalReadings));
        const offline = data.sensor_activity.feed_offline_sync + data.sensor_activity.water_offline_sync;
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

        PG.setText('actFeed', PG.fmt.number(data.sensor_activity.feed_readings));
        PG.setText('actWater', PG.fmt.number(data.sensor_activity.water_readings));
        PG.setText('actFeedOffline', PG.fmt.number(data.sensor_activity.feed_offline_sync));
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
    // Productivity report
    // =================================================================
    function paintProductivity(data) {
        const s = data.summary;

        PG.setText('sumFcr', s.overall_fcr === null ? 'n/a' : s.overall_fcr.toFixed(3));
        PG.setText('sumFcrSub', s.flocks_with_fcr + ' of ' + s.flocks_reported + ' flock(s) calculable');
        PG.setText('sumFeed', s.total_feed_kg.toFixed(1) + ' kg');
        PG.setText('sumGain', s.total_gain_kg.toFixed(1) + ' kg');
        PG.setText('sumBirds', PG.fmt.number(s.total_birds) + ' birds');
        PG.setText('sumMortality', PG.fmt.percent(s.average_mortality));
        PG.setText('sumFlocks', s.flocks_reported + ' flock(s) in range');

        // --- FCR chart: only flocks that have one ---
        const withFcr = data.flocks.filter(function (f) { return f.fcr !== null && f.fcr !== undefined; });
        PG.charts.update(chart,
            withFcr.map(function (f) { return f.flock_name; }),
            [withFcr.map(function (f) { return f.fcr; })]);
        PG.charts.emptyState('mainChart', withFcr.length
            ? '' : 'No flock in this range has enough weigh-ins to calculate an FCR yet.');

        // --- flock table ---
        if (!data.flocks.length) {
            empty('flockBody', 11, 'No flocks match these filters.');
            empty('recordBody', 5, 'No weigh-in records.');
            return;
        }

        document.getElementById('flockBody').innerHTML = data.flocks.map(function (f) {
            if (f.fcr === null || f.fcr === undefined) {
                return '<tr class="opacity-75">' +
                    '<td class="fw-semibold">' + PG.escapeHtml(f.flock_name) + '</td>' +
                    '<td>' + PG.escapeHtml(f.house_name) + '</td>' +
                    '<td>' + PG.escapeHtml(f.breed || '--') + '</td>' +
                    '<td colspan="8" class="pg-muted small">' +
                        PG.escapeHtml(f.note || 'Not enough data to calculate.') + '</td>' +
                '</tr>';
            }

            // Broilers under ~1.8 are performing well; colour only as a hint.
            const fcrClass = f.fcr <= 1.8 ? 'text-success' : (f.fcr <= 2.5 ? '' : 'text-warning');

            return '<tr>' +
                '<td class="fw-semibold">' + PG.escapeHtml(f.flock_name) + '</td>' +
                '<td>' + PG.escapeHtml(f.house_name) + '</td>' +
                '<td>' + PG.escapeHtml(f.breed || '--') + '</td>' +
                '<td class="pg-num">' + f.age_days + ' d</td>' +
                '<td class="pg-num">' + f.current_count + '</td>' +
                '<td class="pg-num">' + f.mortality + ' (' + PG.fmt.percent(f.mortality_rate) + ')</td>' +
                '<td class="pg-num">' + PG.fmt.weight(f.current_avg_weight) + '</td>' +
                '<td class="pg-num">' + f.adg_grams + ' g/d</td>' +
                '<td class="pg-num">' + f.feed_consumed_kg.toFixed(1) + ' kg</td>' +
                '<td class="pg-num">' + f.weight_gain_kg.toFixed(1) + ' kg</td>' +
                '<td class="pg-num fw-bold ' + fcrClass + '">' + f.fcr.toFixed(3) + '</td>' +
            '</tr>';
        }).join('');

        // --- weigh-in records ---
        const records = [];
        data.flocks.forEach(function (f) {
            (f.records || []).forEach(function (r) {
                records.push({ flock: f.flock_name, r: r });
            });
        });

        if (!records.length) {
            empty('recordBody', 5, 'No weigh-in records for these flocks.');
            return;
        }
        document.getElementById('recordBody').innerHTML = records.map(function (x) {
            return '<tr>' +
                '<td>' + PG.escapeHtml(x.flock) + '</td>' +
                '<td>' + PG.escapeHtml(PG.fmt.datetime(x.r.record_date).split(',')[0]) + '</td>' +
                '<td class="pg-num">' + x.r.bird_count + '</td>' +
                '<td class="pg-num">' + PG.fmt.weight(x.r.avg_weight) + '</td>' +
                '<td class="pg-num">' + x.r.mortality + '</td>' +
            '</tr>';
        }).join('');
    }

    // =================================================================
    // Load
    // =================================================================
    async function load() {
        if (report === 'productivity') {
            const data = await PG.api('api/reports/productivity.php', {
                params: {
                    house: val('fHouse'), device_id: val('fDevice'), status: val('fStatus'),
                    from: val('fFrom'), to: val('fTo')
                }
            });
            paintProductivity(data);
            return;
        }

        const params = {
            device_id: val('fDevice'), from: val('fFrom'), to: val('fTo'), period: val('fPeriod')
        };

        if (report === 'alerts') {
            paintSystem(await PG.api('api/reports/alerts.php', { params: params }));
        } else {
            const data = await PG.api('api/reports/' + report + '.php', { params: params });
            paintConsumption(data, report === 'feed');
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
        if (report === 'feed') {
            chart = PG.charts.consumptionChart('mainChart', {
                unit: PG.fmt.weight,
                primaryLabel: 'Consumed by birds',
                secondaryLabel: 'Dispensed by auger',
                primaryColor: PG.charts.COLORS.feed
            });
        } else if (report === 'water') {
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
        } else {
            chart = PG.charts.consumptionChart('mainChart', {
                unit: function (n) { return Number(n).toFixed(3); },
                primaryLabel: 'FCR',
                primaryColor: PG.charts.COLORS.feed,
                // A farm has few flocks, so give each bar more presence.
                maxBarThickness: 72
            });
        }

        // Default range: the last 14 days.
        const from = document.getElementById('fFrom');
        const to = document.getElementById('fTo');
        if (from && to && report !== 'productivity') {
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

        // ---------------- weigh-in recording ----------------
        const addBtn = document.getElementById('addRecordBtn');
        if (addBtn) {
            recordModal = new bootstrap.Modal(document.getElementById('recordModal'));
            addBtn.addEventListener('click', function () {
                document.getElementById('recordError').classList.add('d-none');
                recordModal.show();
            });

            document.getElementById('recordForm').addEventListener('submit', async function (e) {
                e.preventDefault();
                const errBox = document.getElementById('recordError');
                errBox.classList.add('d-none');

                const count = parseInt(val('rCount'), 10);
                const weight = parseFloat(val('rWeight'));

                if (isNaN(count) || count < 0) {
                    errBox.textContent = 'Enter how many birds are alive.';
                    errBox.classList.remove('d-none');
                    return;
                }
                if (isNaN(weight) || weight <= 0) {
                    errBox.textContent = 'Enter the average bird weight in grams.';
                    errBox.classList.remove('d-none');
                    return;
                }

                const done = PG.busy(document.getElementById('recordSaveBtn'), 'Saving');
                try {
                    await PG.api('api/reports/productivity.php', {
                        method: 'POST',
                        body: {
                            flock_id: parseInt(val('rFlock'), 10),
                            record_date: val('rDate'),
                            bird_count: count,
                            avg_weight: weight,
                            mortality: parseInt(val('rMortality'), 10) || 0,
                            notes: val('rNotes')
                        }
                    });
                    recordModal.hide();
                    PG.toast('Weigh-in recorded. FCR updated.', 'success');
                    generate();
                } catch (err) {
                    errBox.textContent = err.message;
                    errBox.classList.remove('d-none');
                } finally {
                    done();
                }
            });
        }
    });
})();
