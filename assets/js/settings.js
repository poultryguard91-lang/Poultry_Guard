/* =====================================================================
   PoultryGuard - system settings page.

   Each of the three forms saves only its own fields, so a Farm Staff account
   saving feed settings never sends alert fields it is not allowed to change.
   ===================================================================== */

(function () {
    'use strict';

    const deviceId = PG.deviceId;
    if (!deviceId) return;

    const perms = window.PG_PAGE || {};

    // Which input ids belong to which form - mirrors SETTING_GROUPS on the server.
    const FIELDS = {
        feed: ['feed_capacity', 'feed_threshold', 'target_feed', 'feed_max_duration', 'feed_tolerance'],
        water: ['water_capacity', 'water_capacity_liters', 'sensor_offset_cm',
                'water_threshold', 'water_target', 'water_max_duration'],
        alerts: ['feed_low_percent', 'feed_critical_percent', 'water_low_percent',
                 'water_critical_percent', 'offline_timeout', 'reading_interval']
    };
    const SWITCHES = { feed: ['auto_feed'], water: ['auto_water'], alerts: ['notify_in_app', 'notify_email'] };

    function set(id, value) {
        const el = document.getElementById(id);
        if (el) el.value = value;
    }
    function setSwitch(id, value) {
        const el = document.getElementById(id);
        if (el) el.checked = !!value;
    }
    function num(id) {
        const el = document.getElementById(id);
        return el ? parseFloat(el.value) : NaN;
    }
    function bool(id) {
        const el = document.getElementById(id);
        return el ? el.checked : false;
    }
    function showError(id, message) {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = message || '';
        el.classList.toggle('d-none', !message);
    }

    // -----------------------------------------------------------------
    // Load
    // -----------------------------------------------------------------
    async function load() {
        const data = await PG.api('api/settings/get.php', { params: { device_id: deviceId } });

        Object.keys(data.feed).forEach(function (k) {
            if (typeof data.feed[k] === 'boolean') setSwitch(k, data.feed[k]);
            else set(k, data.feed[k]);
        });
        Object.keys(data.water).forEach(function (k) {
            if (typeof data.water[k] === 'boolean') setSwitch(k, data.water[k]);
            else set(k, data.water[k]);
        });
        Object.keys(data.alerts).forEach(function (k) {
            if (typeof data.alerts[k] === 'boolean') setSwitch(k, data.alerts[k]);
            else set(k, data.alerts[k]);
        });

        PG.setText('settingsMeta',
            'Last updated ' + PG.fmt.datetime(data.updated_at) +
            (data.updated_by_name ? ' by ' + data.updated_by_name : '') +
            ' · device: ' + data.device.device_name +
            ' · ' + (data.device.online ? 'online' : 'offline'));
    }

    // -----------------------------------------------------------------
    // Client-side cross-field checks
    //
    // These mirror the server rules so the operator gets an immediate,
    // specific explanation. The server enforces them regardless.
    // -----------------------------------------------------------------
    function validateFeed() {
        if (num('feed_threshold') >= num('target_feed')) {
            return 'The feed threshold must be lower than the target feed quantity, ' +
                   'otherwise dispensing would never stop.';
        }
        if (num('target_feed') > num('feed_capacity')) {
            return 'The target feed quantity cannot exceed the hopper capacity.';
        }
        if (num('feed_capacity') <= 0) return 'Enter a hopper capacity greater than zero.';
        return null;
    }

    function validateWater() {
        if (num('water_threshold') >= num('water_target')) {
            return 'The water threshold must be lower than the target water level, ' +
                   'otherwise the valve would never close.';
        }
        if (num('water_target') > num('water_capacity')) {
            return 'The target water level cannot exceed the tank depth.';
        }
        if (num('water_capacity') + num('sensor_offset_cm') > 400) {
            return 'Tank depth plus sensor offset exceeds the 400 cm range of the HC-SR04 sensor.';
        }
        return null;
    }

    function validateAlerts() {
        if (num('feed_critical_percent') >= num('feed_low_percent')) {
            return 'The critical feed threshold must be lower than the low feed threshold.';
        }
        if (num('water_critical_percent') >= num('water_low_percent')) {
            return 'The critical water threshold must be lower than the low water threshold.';
        }
        if (num('offline_timeout') < num('reading_interval') * 2) {
            return 'The offline timeout must be at least twice the reading interval, ' +
                   'or the device would be flagged offline between normal reports.';
        }
        return null;
    }

    // -----------------------------------------------------------------
    // Save
    // -----------------------------------------------------------------
    async function save(group, errorId, btnId, validate, confirmText) {
        showError(errorId, '');

        const problem = validate();
        if (problem) {
            showError(errorId, problem);
            PG.toast(problem, 'danger');
            return;
        }

        const body = { device_id: deviceId };
        FIELDS[group].forEach(function (f) { body[f] = num(f); });
        SWITCHES[group].forEach(function (f) { body[f] = bool(f); });

        const ok = await PG.confirm({
            title: 'Save settings',
            message: confirmText,
            detail: 'The new values are pushed to the ESP32, which stores them locally and ' +
                'will keep using them even if the network goes down.',
            confirmText: 'Save settings',
            variant: 'success'
        });
        if (!ok) return;

        const btn = document.getElementById(btnId);
        const done = PG.busy(btn, 'Saving');
        try {
            await PG.api('api/settings/update.php', { method: 'POST', body: body });
            PG.toast('Settings saved and sent to the device.', 'success');
            await load();
        } catch (e) {
            showError(errorId, e.message);
            PG.toast(e.message, 'danger');
        } finally {
            done();
        }
    }

    // -----------------------------------------------------------------
    // Wire up
    // -----------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        load().catch(function (e) { PG.toast(e.message, 'danger'); });

        if (perms.canThresholds) {
            document.getElementById('feedForm').addEventListener('submit', function (e) {
                e.preventDefault();
                save('feed', 'feedError', 'feedSaveBtn', validateFeed,
                    'Apply these feed automation settings to the device?');
            });
            document.getElementById('waterForm').addEventListener('submit', function (e) {
                e.preventDefault();
                save('water', 'waterError', 'waterSaveBtn', validateWater,
                    'Apply these water automation settings to the device?');
            });
        }

        if (perms.canAlerts) {
            document.getElementById('alertForm').addEventListener('submit', function (e) {
                e.preventDefault();
                save('alerts', 'alertError', 'alertSaveBtn', validateAlerts,
                    'Apply these alert and notification settings?');
            });
        }
    });
})();
