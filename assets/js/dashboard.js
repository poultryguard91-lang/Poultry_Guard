/* =====================================================================
   PoultryGuard - dashboard controller.

   Fetches /api/dashboard/index.php on a timer and paints the result. Status
   values are polled often (they are what an operator watches); the heavier
   chart series are refreshed on a slower cadence and whenever the range
   selector changes.
   ===================================================================== */

(function () {
    'use strict';

    const deviceId = PG.deviceId;
    if (!deviceId) return; // no device registered yet - header shows a notice

    const STATUS_POLL = Math.max(10000, PG_CONFIG.pollInterval || 30000);
    const CHART_EVERY = 4; // refresh charts on every 4th status poll

    let charts = {};
    let tick = 0;
    let chartHours = 24;

    // -----------------------------------------------------------------
    // Chart setup
    // -----------------------------------------------------------------
    function initCharts() {
        charts.climate = PG.charts.levelChart('climateChart', {
            color: PG.charts.COLORS.feed,
            label: 'Temperature',
            unit: function (n) { return Number(n).toFixed(1) + ' °C'; },
            thresholdLabel: 'Heat warning'
        });

        charts.waterLevel = PG.charts.levelChart('waterLevelChart', {
            color: PG.charts.COLORS.water,
            label: 'Water depth',
            unit: PG.fmt.cm,
            thresholdLabel: 'Refill threshold'
        });

        charts.feedRuns = PG.charts.consumptionChart('feedRunsChart', {
            unit: function (n) { return Number(n).toFixed(0) + ' s'; },
            primaryLabel: 'Feeder run time',
            primaryColor: PG.charts.COLORS.feed
        });

        charts.waterConsumption = PG.charts.consumptionChart('waterConsumptionChart', {
            unit: PG.fmt.liters,
            primaryLabel: 'Consumed by birds',
            primaryColor: PG.charts.COLORS.water
        });
    }

    // -----------------------------------------------------------------
    // Painting
    // -----------------------------------------------------------------

    /** Apply a status word to one of the pill elements. */
    function paintPill(id, textId, status, text) {
        const el = document.getElementById(id);
        if (!el) return;
        const cls = String(status || 'unknown').toLowerCase();
        const known = ['normal', 'low', 'critical', 'info'].indexOf(cls) !== -1;
        el.className = 'pg-pill ' + (known ? cls : 'unknown');
        const label = document.getElementById(textId);
        if (label) label.textContent = text || status || '--';
    }

    /** Small status pill in the System status list. */
    function paintStat(id, value, okWords) {
        const el = document.getElementById(id);
        if (!el) return;
        const v = String(value || 'unknown').toLowerCase();
        let cls = 'unknown';
        if (okWords.indexOf(v) !== -1) cls = 'normal';
        else if (v === 'error' || v === 'offline' || v === 'disconnected') cls = 'critical';
        else if (v === 'running') cls = 'info';
        el.className = 'pg-pill ' + cls;
        el.textContent = PG.fmt.label(value);
    }

    function paintFeed(feed, today) {
        PG.setText('feedLastRun', feed.last_run ? feed.last_run_ago : 'Never');
        PG.setText('feedLastSeconds', feed.last_run_seconds === null ? '--' : feed.last_run_seconds + ' s');
        PG.setText('feedMaxDuration', feed.max_duration + ' s');
        PG.setText('feedAuto', feed.auto ? 'On' : 'Off');
        PG.setText('feedSchedule', feed.schedule.length
            ? feed.schedule.map(function (s) { return s.time + ' (' + s.seconds + ' s)'; }).join(' · ')
            : 'None set');
        PG.setText('feedToday', today.feed_runs + ' run(s), ' + today.feed_seconds + ' s');

        const pill = document.getElementById('feedStatusPill');
        if (pill) pill.className = 'pg-pill ' + (feed.running ? 'info' : 'normal');
        PG.setText('feedStatusText', feed.running ? 'Running' : 'Idle');
    }

    function paintWater(water, today) {
        const pct = water.percentage;

        PG.setText('tileWaterValue', water.level_display);
        PG.setText('tileWaterSub', pct === null
            ? 'No reading yet'
            : PG.fmt.percent(pct) + (water.liters !== null ? ' · ' + PG.fmt.liters(water.liters) : ''));

        PG.setText('waterLevel', water.level_display);
        PG.setText('waterPercent', pct === null ? '' : '(' + PG.fmt.percent(pct) + ')');
        PG.setText('waterCapacity', PG.fmt.cm(water.capacity));
        PG.setText('waterUpdated', water.reading_ago);
        PG.setText('waterThreshold', PG.fmt.cm(water.threshold) + ' (' + PG.fmt.percent(water.threshold_percent) + ')');
        PG.setText('waterTarget', PG.fmt.cm(water.target));
        PG.setText('waterAuto', water.auto ? 'Enabled' : 'Disabled');
        PG.setText('waterToday', PG.fmt.liters(today.water_consumed_l));
        PG.setText('waterLastRefill', water.last_refill ? water.last_refill_ago : 'Never');

        paintPill('waterStatusPill', 'waterStatusText', water.status, water.status);

        const bar = document.getElementById('waterBar');
        if (bar) {
            const w = pct === null ? 0 : Math.max(0, Math.min(100, pct));
            bar.style.width = w + '%';
            bar.className = 'pg-level-fill ' +
                (water.status === 'CRITICAL' ? 'critical' : water.status === 'LOW' ? 'low' : 'water');
            bar.textContent = w > 12 ? PG.fmt.percent(pct) : '';
        }
        const mark = document.getElementById('waterThresholdMark');
        if (mark) mark.style.left = Math.max(0, Math.min(100, water.threshold_percent)) + '%';

        PG.charts.setThreshold(charts.waterLevel, water.threshold);
    }

    function paintClimate(c) {
        if (!c) return;
        PG.setText('tileTempValue', c.temperature === null ? '-- °C' : c.temperature.toFixed(1) + ' °C');
        PG.setText('tileTempSub', c.humidity === null ? 'No reading yet' : Math.round(c.humidity) + ' % humidity · ' + c.heat_level.toLowerCase());
        PG.charts.setThreshold(charts.climate, c.warn_c);
        PG.setText('climateTemp', c.temperature === null ? '--' : c.temperature.toFixed(1) + ' °C');
        PG.setText('climateHumidity', c.humidity === null ? '--' : Math.round(c.humidity) + ' %');
        PG.setText('climateUpdated', c.reading_ago);
        PG.setText('climateLimits', 'Warning ' + c.warn_c + ' °C · Danger ' + c.danger_c + ' °C');
        const map = { NORMAL: 'normal', WARNING: 'low', DANGER: 'critical' };
        const pill = document.getElementById('climateStatusPill');
        if (pill) pill.className = 'pg-pill ' + (map[c.heat_level] || 'unknown');
        PG.setText('climateStatusText', c.heat_level === 'UNKNOWN' ? '--' : c.heat_level);
    }

    function paintDevice(device) {
        PG.setText('tileDeviceValue', device.online ? 'Online' : 'Offline');
        PG.setText('tileDeviceSub', 'Last contact ' + device.last_seen_ago);

        paintStat('statEsp32', device.esp32_status, ['online']);
        paintStat('statInternet', device.internet_status, ['connected']);
        paintStat('statWaterSensor', device.water_sensor, ['ok']);
        paintStat('statMotor', device.motor_status, ['idle']);
        paintStat('statSolenoid', device.solenoid_status, ['idle']);
        PG.setText('statLastSeen', PG.fmt.datetime(device.last_seen));

        const meta = document.getElementById('deviceMeta');
        if (meta) {
            const bits = [];
            if (device.location) bits.push(device.location);
            if (device.firmware_version) bits.push('Firmware v' + device.firmware_version);
            if (device.ip_address) bits.push(device.ip_address);
            if (device.wifi_rssi !== null) bits.push('Wi-Fi ' + device.wifi_rssi + ' dBm');
            meta.textContent = bits.join(' · ');
        }

        const notice = document.getElementById('offlineNotice');
        if (notice) notice.classList.toggle('d-none', device.online);

        // Keep the live pill in the top bar in step.
        const badge = document.getElementById('pgLiveBadge');
        const text = document.getElementById('pgLiveText');
        if (badge) badge.classList.toggle('is-offline', !device.online);
        if (text) text.textContent = device.online ? 'Device online' : 'Device offline';
    }

    function paintAlerts(alerts) {
        PG.setText('tileAlertValue', alerts.unread);
        PG.setText('tileAlertSub',
            alerts.critical > 0
                ? alerts.critical + ' critical need attention'
                : (alerts.warning > 0 ? alerts.warning + ' warnings open' : 'Nothing needs attention'));

        // Top-bar and sidebar badges
        const badge = document.getElementById('pgAlertBadge');
        if (badge) {
            badge.textContent = alerts.unread > 99 ? '99+' : alerts.unread;
            badge.classList.toggle('d-none', alerts.unread === 0);
        }
        const sideBadge = document.getElementById('pgSidebarAlertBadge');
        if (sideBadge) {
            sideBadge.textContent = alerts.unread > 99 ? '99+' : alerts.unread;
            sideBadge.hidden = alerts.unread === 0;
        }

        const list = document.getElementById('alertList');
        if (!list) return;

        if (!alerts.recent.length) {
            list.innerHTML = '<div class="p-4 text-center pg-muted small">' +
                'No alerts for this device. Everything is running normally.</div>';
            return;
        }

        list.innerHTML = alerts.recent.map(function (a) {
            return '<div class="pg-alert-item' + (a.status === 'unread' ? ' is-unread' : '') + '">' +
                '<span class="pg-alert-bar ' + PG.escapeHtml(a.severity) + '"></span>' +
                '<div class="flex-grow-1">' +
                  '<p class="pg-alert-msg">' + PG.escapeHtml(a.message) + '</p>' +
                  '<p class="pg-alert-meta">' +
                    PG.escapeHtml(a.severity) + ' · ' + PG.escapeHtml(PG.fmt.label(a.alert_type)) +
                    ' · ' + PG.escapeHtml(a.ago) +
                    (a.status === 'unread' ? ' · <strong>unread</strong>' : '') +
                  '</p>' +
                '</div>' +
              '</div>';
        }).join('');
    }

    function paintCharts(c) {
        // --- trends ---
        const climateLabels = c.climate.map(function (p) { return PG.fmt.time(p.time); });
        PG.charts.update(charts.climate, climateLabels, [c.climate.map(function (p) { return p.temperature; })]);
        PG.charts.emptyState('climateChart', c.climate.length ? '' : 'No temperature readings in this period yet.');

        const waterLabels = c.water_level.map(function (p) { return PG.fmt.time(p.time); });
        PG.charts.update(charts.waterLevel, waterLabels, [c.water_level.map(function (p) { return p.value; })]);
        PG.charts.emptyState('waterLevelChart', c.water_level.length ? '' : 'No water readings in this period yet.');

        // --- daily consumption ---
        const fLabels = c.feed_runs.map(function (p) { return PG.fmt.date(p.period); });
        PG.charts.update(charts.feedRuns, fLabels, [c.feed_runs.map(function (p) { return p.seconds; })]);
        PG.charts.emptyState('feedRunsChart', c.feed_runs.length ? '' : 'Not enough history yet.');

        const wLabels = c.water_consumption.map(function (p) { return PG.fmt.date(p.period); });
        PG.charts.update(charts.waterConsumption, wLabels, [
            c.water_consumption.map(function (p) { return p.consumed_liters; })
        ]);
        PG.charts.emptyState('waterConsumptionChart', c.water_consumption.length ? '' : 'Not enough history yet.');
    }

    // -----------------------------------------------------------------
    // Load
    // -----------------------------------------------------------------
    async function load(withCharts) {
        const data = await PG.api('api/dashboard/index.php', {
            params: {
                device_id: deviceId,
                charts: withCharts ? 1 : '',
                hours: withCharts ? chartHours : ''
            }
        });

        paintFeed(data.feed, data.today);
        paintWater(data.water, data.today);
        paintClimate(data.climate);
        paintDevice(data.device);
        paintAlerts(data.alerts);

        if (withCharts && data.charts) {
            paintCharts(data.charts);
        }
    }

    // -----------------------------------------------------------------
    // Wire up
    // -----------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        initCharts();

        const poller = PG.poll(function () {
            // First run draws the charts; after that they refresh less often
            // than the status values do.
            const withCharts = (tick % CHART_EVERY) === 0;
            tick++;
            return load(withCharts);
        }, STATUS_POLL);

        const range = document.getElementById('chartRange');
        if (range) {
            range.addEventListener('change', function () {
                chartHours = parseInt(this.value, 10) || 24;
                load(true).catch(function (e) { PG.toast(e.message, 'danger'); });
            });
        }

        const refresh = document.getElementById('pgRefresh');
        if (refresh) {
            refresh.addEventListener('click', async function () {
                const done = PG.busy(refresh, 'Refreshing');
                try {
                    await load(true);
                    PG.toast('Dashboard updated.', 'success');
                } catch (e) {
                    PG.toast(e.message, 'danger');
                } finally {
                    done();
                }
            });
        }

        const markRead = document.getElementById('markAllRead');
        if (markRead) {
            markRead.addEventListener('click', async function () {
                const ok = await PG.confirm({
                    title: 'Mark all alerts as read',
                    message: 'Mark every unread alert for this device as read?',
                    confirmText: 'Mark all read',
                    variant: 'success'
                });
                if (!ok) return;

                const done = PG.busy(markRead, 'Working');
                try {
                    const res = await PG.api('api/alerts/read.php', {
                        method: 'POST',
                        body: { action: 'read_all', device_id: deviceId }
                    });
                    PG.toast(res.affected + ' alert(s) marked as read.', 'success');
                    await poller.refresh();
                } catch (e) {
                    PG.toast(e.message, 'danger');
                } finally {
                    done();
                }
            });
        }
    });
})();
