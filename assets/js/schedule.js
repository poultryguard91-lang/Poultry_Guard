/* =====================================================================
   PoultryGuard - scheduled feeding page.

   Edits the list of daily feeding times for the selected device and shows
   when the next one is due. Saving goes through the same settings endpoint as
   the rest of the automation rules; the server validates it again.
   ===================================================================== */

(function () {
    'use strict';

    const deviceId = PG.deviceId;
    if (!deviceId) return;

    const canEdit = !!(window.PG_PAGE || {}).canEdit;
    const MAX_SLOTS = 8;

    let maxRun = 30;        // feeder maximum run time (s), from the server
    let saved = [];         // the schedule as last saved, for the status panel
    let savedOn = false;
    let lastOnline = false; // controller state from the last load
    const pageLoadedAt = Date.now();

    function showError(message) {
        const el = document.getElementById('scheduleError');
        if (!el) return;
        el.textContent = message || '';
        el.classList.toggle('d-none', !message);
    }

    // -----------------------------------------------------------------
    // Editor rows
    // -----------------------------------------------------------------
    function slotRow(time, seconds) {
        const row = document.createElement('div');
        row.className = 'row g-2 align-items-center mb-2 pg-slot';
        row.innerHTML =
            '<div class="col-5 col-md-4"><input type="time" class="form-control form-control-sm slot-time" ' +
                'required ' + (canEdit ? '' : 'readonly') + ' aria-label="Feeding time"></div>' +
            '<div class="col-5 col-md-4"><div class="input-group input-group-sm">' +
                '<input type="number" class="form-control slot-sec" min="1" step="1" required ' +
                (canEdit ? '' : 'readonly') + ' aria-label="Run time in seconds">' +
                '<span class="input-group-text">seconds</span></div></div>' +
            (canEdit ? '<div class="col-2"><button type="button" class="btn btn-sm btn-outline-danger slot-del" ' +
                'aria-label="Remove this feeding time">&times;</button></div>' : '');
        row.querySelector('.slot-time').value = time || '';
        row.querySelector('.slot-sec').value = seconds || '';
        const del = row.querySelector('.slot-del');
        if (del) del.addEventListener('click', function () { row.remove(); refreshEditor(); });
        return row;
    }

    function refreshEditor() {
        const box = document.getElementById('scheduleRows');
        const count = box.querySelectorAll('.pg-slot').length;
        const none = document.getElementById('noSlots');
        if (count === 0 && !none) {
            const p = document.createElement('div');
            p.id = 'noSlots';
            p.className = 'small pg-muted mb-2';
            p.textContent = 'No feeding times set.';
            box.appendChild(p);
        } else if (count > 0 && none) {
            none.remove();
        }
        const add = document.getElementById('addSlotBtn');
        if (add) add.disabled = count >= MAX_SLOTS;
    }

    function renderRows(slots) {
        const box = document.getElementById('scheduleRows');
        box.innerHTML = '';
        slots.forEach(function (s) { box.appendChild(slotRow(s.time, s.seconds)); });
        refreshEditor();
    }

    function readRows() {
        return Array.prototype.map.call(document.querySelectorAll('.pg-slot'), function (row) {
            return {
                time: row.querySelector('.slot-time').value,
                seconds: parseInt(row.querySelector('.slot-sec').value, 10)
            };
        });
    }

    function validate(slots) {
        const seen = {};
        for (let i = 0; i < slots.length; i++) {
            const s = slots[i];
            if (!s.time) return 'Feeding time #' + (i + 1) + ' needs a time.';
            if (!(s.seconds >= 1)) return 'Feeding time ' + s.time + ' needs a run time of at least 1 second.';
            if (s.seconds > maxRun) {
                return 'Feeding time ' + s.time + ' runs ' + s.seconds + ' s, longer than the ' +
                       maxRun + ' s maximum run time.';
            }
            if (seen[s.time]) return 'The time ' + s.time + ' is listed twice.';
            seen[s.time] = true;
        }
        return null;
    }

    // -----------------------------------------------------------------
    // Status panel: next feeding, using the server's clock and timezone
    // -----------------------------------------------------------------
    function farmNowMinutes() {
        const cfg = window.PG_CONFIG || {};
        const now = new Date(Date.now() + ((cfg.serverTimeMs || Date.now()) - pageLoadedAt));
        try {
            const parts = new Intl.DateTimeFormat('en-GB', {
                timeZone: cfg.timezone, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
            }).formatToParts(now);
            const get = function (t) {
                return parseInt(parts.filter(function (p) { return p.type === t; })[0].value, 10) % 24;
            };
            return get('hour') * 60 + get('minute') + get('second') / 60;
        } catch (e) {
            return now.getHours() * 60 + now.getMinutes() + now.getSeconds() / 60;
        }
    }

    function clock12(hhmm) {
        const p = hhmm.split(':');
        const h = parseInt(p[0], 10);
        return ((h + 11) % 12 + 1) + ':' + p[1] + ' ' + (h < 12 ? 'AM' : 'PM');
    }

    function paintStatus(deviceOnline) {
        lastOnline = deviceOnline;
        const slots = saved.slice().sort(function (a, b) { return a.time < b.time ? -1 : 1; });
        const total = slots.reduce(function (n, s) { return n + s.seconds; }, 0);

        PG.setText('slotTotal', String(slots.length));
        PG.setText('secTotal', total + ' s');
        PG.setText('maxRun', slots.length ? Math.max.apply(null, slots.map(function (s) { return s.seconds; })) + ' s' : '--');
        PG.setText('deviceState', deviceOnline ? 'Online' : 'Offline');

        const pill = document.getElementById('schedPill');
        let label = 'Off';
        let cls = 'unknown';
        if (savedOn && slots.length) { label = 'On'; cls = 'normal'; }
        else if (savedOn) { label = 'On, no times'; cls = 'low'; }
        pill.className = 'pg-pill ' + cls;
        PG.setText('schedPillText', label);

        const el = document.getElementById('nextFeed');
        el.classList.remove('pg-skeleton');
        if (!savedOn || !slots.length) {
            PG.setText('nextFeed', '--');
            PG.setText('nextFeedIn', savedOn ? 'Add at least one feeding time.' : 'Scheduled feeding is off.');
            return;
        }
        const nowMin = farmNowMinutes();
        let next = null;
        let delta = 0;
        for (let i = 0; i < slots.length; i++) {
            const p = slots[i].time.split(':');
            const m = parseInt(p[0], 10) * 60 + parseInt(p[1], 10);
            if (m > nowMin) { next = slots[i]; delta = m - nowMin; break; }
        }
        if (!next) {            // none left today: the first one tomorrow
            next = slots[0];
            const p = next.time.split(':');
            delta = 1440 - nowMin + parseInt(p[0], 10) * 60 + parseInt(p[1], 10);
        }
        const mins = Math.max(1, Math.round(delta));
        const inText = mins >= 60 ? Math.floor(mins / 60) + ' h ' + (mins % 60) + ' min' : mins + ' min';
        PG.setText('nextFeed', clock12(next.time));
        PG.setText('nextFeedIn', 'in ' + inText + ' · runs ' + next.seconds + ' s');
    }

    // -----------------------------------------------------------------
    // Load / save
    // -----------------------------------------------------------------
    async function load() {
        const data = await PG.api('api/settings/get.php', { params: { device_id: deviceId } });

        maxRun = data.feed.feed_max_duration;
        PG.setText('maxHint', String(maxRun));
        saved = data.feed.feed_schedule || [];
        savedOn = !!data.feed.auto_feed;

        document.getElementById('auto_feed').checked = savedOn;
        renderRows(saved);
        paintStatus(data.device.online);

        PG.setText('scheduleMeta',
            'Last updated ' + PG.fmt.datetime(data.updated_at) +
            (data.updated_by_name ? ' by ' + data.updated_by_name : '') +
            ' · device: ' + data.device.device_name);
    }

    async function save() {
        showError('');
        const slots = readRows();
        const problem = validate(slots);
        if (problem) {
            showError(problem);
            PG.toast(problem, 'danger');
            return;
        }
        const on = document.getElementById('auto_feed').checked;

        const ok = await PG.confirm({
            title: 'Save feeding schedule',
            message: on
                ? 'Feed ' + slots.length + ' time(s) a day on this schedule?'
                : 'Save the schedule and keep scheduled feeding switched off?',
            detail: 'The schedule is sent to the ESP32, which keeps it in its own memory and ' +
                'feeds from its own clock.',
            confirmText: 'Save schedule',
            variant: 'success'
        });
        if (!ok) return;

        const done = PG.busy(document.getElementById('scheduleSaveBtn'), 'Saving');
        try {
            await PG.api('api/settings/update.php', {
                method: 'POST',
                body: { device_id: deviceId, auto_feed: on, feed_schedule: slots }
            });
            PG.toast('Schedule saved and sent to the device.', 'success');
            await load();
        } catch (e) {
            showError(e.message);
            PG.toast(e.message, 'danger');
        } finally {
            done();
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        load().catch(function (e) { PG.toast(e.message, 'danger'); });
        // Keep "in 12 min" honest without re-fetching.
        setInterval(function () { paintStatus(lastOnline); }, 30000);

        const add = document.getElementById('addSlotBtn');
        if (add) {
            add.addEventListener('click', function () {
                document.getElementById('scheduleRows').appendChild(slotRow('08:00', Math.min(3, maxRun)));
                refreshEditor();
            });
        }
        const form = document.getElementById('scheduleForm');
        if (form && canEdit) {
            form.addEventListener('submit', function (e) { e.preventDefault(); save(); });
        }
    });

})();
