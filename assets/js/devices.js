/* =====================================================================
   PoultryGuard - device management page.
   ===================================================================== */

(function () {
    'use strict';

    const canManage = (window.PG_PAGE || {}).canManage === true;
    let deviceModal = null;
    let keyModal = null;

    // -----------------------------------------------------------------
    // Painting
    // -----------------------------------------------------------------
    function statusRow(label, value, okWords) {
        const v = String(value || 'unknown').toLowerCase();
        let cls = 'unknown';
        if (okWords.indexOf(v) !== -1) cls = 'normal';
        else if (v === 'error' || v === 'offline') cls = 'critical';
        else if (v === 'running') cls = 'info';

        return '<li class="d-flex justify-content-between align-items-center py-1">' +
            '<span class="pg-muted small">' + PG.escapeHtml(label) + '</span>' +
            '<span class="pg-pill ' + cls + '">' + PG.escapeHtml(PG.fmt.label(value)) + '</span></li>';
    }

    function card(d) {
        const disabled = d.status !== 'active';

        const manageButtons = canManage
            ? '<div class="d-flex gap-2 flex-wrap mt-3">' +
                '<button class="btn btn-sm btn-light" data-act="edit" data-id="' + d.device_id + '">Edit</button>' +
                '<button class="btn btn-sm btn-light" data-act="toggle" data-id="' + d.device_id + '">' +
                    (disabled ? 'Enable' : 'Disable') + '</button>' +
                '<button class="btn btn-sm btn-outline-warning" data-act="key" data-id="' + d.device_id + '">' +
                    'New API key</button>' +
              '</div>'
            : '';

        const wifi = d.wifi_rssi === null ? '--'
            : d.wifi_rssi + ' dBm (' + d.wifi_quality + ')';

        return '<div class="col-lg-6">' +
          '<div class="pg-card h-100' + (disabled ? ' opacity-75' : '') + '">' +
            '<div class="pg-card-head">' +
              '<div>' +
                '<h2 class="mb-0">' + PG.escapeHtml(d.device_name) + '</h2>' +
                '<div class="pg-muted small">' + PG.escapeHtml(d.location || 'No location set') + '</div>' +
              '</div>' +
              '<div class="text-end">' +
                '<span class="pg-pill ' + (d.online ? 'normal' : 'critical') + '">' +
                  PG.escapeHtml(d.connection) + '</span>' +
                (disabled ? '<div><span class="badge bg-secondary mt-1">Disabled</span></div>' : '') +
              '</div>' +
            '</div>' +
            '<div class="pg-card-body">' +

              '<div class="row g-2 mb-3">' +
                '<div class="col-6">' +
                  '<div class="border rounded p-2 text-center">' +
                    '<div class="pg-metric-label">Climate</div>' +
                    '<div class="fw-bold">' + (d.temperature === null ? '--' : d.temperature.toFixed(1) + ' °C') + '</div>' +
                    '<div class="pg-muted" style="font-size:.72rem">' +
                      (d.humidity === null ? '--' : Math.round(d.humidity) + ' % humidity') + '</div>' +
                  '</div>' +
                '</div>' +
                '<div class="col-6">' +
                  '<div class="border rounded p-2 text-center">' +
                    '<div class="pg-metric-label">Water</div>' +
                    '<div class="fw-bold">' + PG.escapeHtml(PG.fmt.cm(d.water_level)) + '</div>' +
                    '<div class="pg-muted" style="font-size:.72rem">' +
                      PG.escapeHtml(PG.fmt.percent(d.water_percentage)) + '</div>' +
                  '</div>' +
                '</div>' +
              '</div>' +

              '<ul class="list-unstyled mb-3">' +
                statusRow('Water level sensor', d.water_sensor, ['ok']) +
                statusRow('Feeder motor', d.motor_status, ['idle']) +
                statusRow('Water pump', d.solenoid_status, ['idle']) +
              '</ul>' +

              '<table class="table table-sm pg-table mb-0"><tbody>' +
                row('ESP32 UID', '<span class="pg-mono">' + PG.escapeHtml(d.esp32_uid) + '</span>') +
                row('Last seen', PG.escapeHtml(PG.fmt.datetime(d.last_seen)) +
                    ' <span class="pg-muted">(' + PG.escapeHtml(d.last_seen_ago) + ')</span>') +
                row('Firmware', PG.escapeHtml(d.firmware_version || 'Not reported')) +
                row('IP address', '<span class="pg-mono">' + PG.escapeHtml(d.ip_address || '--') + '</span>') +
                row('Wi-Fi signal', PG.escapeHtml(wifi)) +
                row('Automatic watering', d.auto_water ? 'On' : 'Off') +
                row('Offline after', d.offline_timeout + ' s without contact') +
                row('Last 24 h', d.activity.climate_readings + ' climate / ' +
                    d.activity.water_readings + ' water readings, ' +
                    (d.activity.feed_events + d.activity.water_events) + ' dispensing events') +
                row('Queued commands', String(d.activity.queued_commands)) +
                row('Registered', PG.escapeHtml(PG.fmt.datetime(d.created_at))) +
              '</tbody></table>' +

              manageButtons +
            '</div>' +
          '</div>' +
        '</div>';
    }

    function row(label, valueHtml) {
        return '<tr><td class="pg-muted">' + PG.escapeHtml(label) + '</td>' +
               '<td class="text-end">' + valueHtml + '</td></tr>';
    }

    function paint(data) {
        PG.setText('sumTotal', data.summary.total);
        PG.setText('sumOnline', data.summary.online);
        PG.setText('sumOffline', data.summary.offline);

        const wrap = document.getElementById('deviceCards');
        wrap.innerHTML = data.devices.length
            ? data.devices.map(card).join('')
            : '<div class="col-12"><div class="pg-card"><div class="pg-card-body text-center py-5 pg-muted">' +
              'No devices are registered yet.' +
              (canManage ? ' Use <strong>Add device</strong> to register your first ESP32 controller.' : '') +
              '</div></div></div>';
    }

    // -----------------------------------------------------------------
    // Load
    // -----------------------------------------------------------------
    let cache = [];

    async function load() {
        const data = await PG.api('api/devices/status.php');
        cache = data.devices;
        paint(data);
    }

    function refresh() {
        load().catch(function (e) { PG.toast(e.message, 'danger'); });
    }

    // -----------------------------------------------------------------
    // Management actions
    // -----------------------------------------------------------------
    function showError(message) {
        const el = document.getElementById('deviceError');
        if (!el) return;
        el.textContent = message || '';
        el.classList.toggle('d-none', !message);
    }

    function openCreate() {
        showError('');
        document.getElementById('deviceForm').reset();
        document.getElementById('dDeviceId').value = '';
        document.getElementById('deviceModalTitle').textContent = 'Add device';
        document.getElementById('uidGroup').classList.remove('d-none');
        document.getElementById('dUid').required = true;
        deviceModal.show();
    }

    function openEdit(id) {
        const d = cache.find(function (x) { return x.device_id === id; });
        if (!d) return;

        showError('');
        document.getElementById('dDeviceId').value = d.device_id;
        document.getElementById('dName').value = d.device_name;
        document.getElementById('dLocation').value = d.location || '';
        document.getElementById('deviceModalTitle').textContent = 'Edit device';
        // The UID is the device's identity in the firmware; it is not editable.
        document.getElementById('uidGroup').classList.add('d-none');
        document.getElementById('dUid').required = false;
        deviceModal.show();
    }

    async function save(e) {
        e.preventDefault();
        showError('');

        const id = document.getElementById('dDeviceId').value;
        const name = document.getElementById('dName').value.trim();
        const uid = document.getElementById('dUid').value.trim();
        const location = document.getElementById('dLocation').value.trim();

        if (name.length < 2) {
            showError('Enter a device name of at least 2 characters.');
            return;
        }
        if (!id) {
            if (uid.length < 4) {
                showError('Enter the ESP32 UID reported by the firmware.');
                return;
            }
            if (!/^[A-Za-z0-9_:-]+$/.test(uid)) {
                showError('The UID may only contain letters, digits, hyphens, underscores and colons.');
                return;
            }
        }

        const btn = document.getElementById('deviceSaveBtn');
        const done = PG.busy(btn, 'Saving');
        try {
            const body = id
                ? { action: 'update', device_id: parseInt(id, 10), device_name: name, location: location }
                : { action: 'create', device_name: name, esp32_uid: uid, location: location };

            const res = await PG.api('api/devices/save.php', { method: 'POST', body: body });
            deviceModal.hide();
            PG.toast(id ? 'Device updated.' : 'Device registered.', 'success');

            if (res.api_key) showKey(res.api_key);
            refresh();
        } catch (err) {
            showError(err.message);
        } finally {
            done();
        }
    }

    function showKey(key) {
        document.getElementById('keyValue').value = key;
        keyModal.show();
    }

    async function toggle(id) {
        const d = cache.find(function (x) { return x.device_id === id; });
        if (!d) return;
        const disabling = d.status === 'active';

        const ok = await PG.confirm({
            title: (disabling ? 'Disable' : 'Enable') + ' device',
            message: (disabling ? 'Disable' : 'Enable') + ' "' + d.device_name + '"?',
            detail: disabling
                ? 'The controller will be refused by the API and any queued commands are cancelled. ' +
                  'It keeps running its stored automation locally until you reflash or power it down.'
                : 'The controller will be able to report readings and receive commands again.',
            confirmText: disabling ? 'Disable device' : 'Enable device',
            variant: disabling ? 'danger' : 'success'
        });
        if (!ok) return;

        try {
            await PG.api('api/devices/save.php', {
                method: 'POST',
                body: { action: 'toggle', device_id: id }
            });
            PG.toast('Device ' + (disabling ? 'disabled' : 'enabled') + '.', 'success');
            refresh();
        } catch (e) {
            PG.toast(e.message, 'danger');
        }
    }

    async function rotateKey(id) {
        const d = cache.find(function (x) { return x.device_id === id; });
        if (!d) return;

        const ok = await PG.confirm({
            title: 'Generate a new API key',
            message: 'Replace the API key for "' + d.device_name + '"?',
            detail: 'The current key stops working immediately. The device cannot report ' +
                'readings until you flash the new key into the firmware. It will keep feeding ' +
                'and watering locally in the meantime and buffer its records.',
            confirmText: 'Generate new key',
            variant: 'warning'
        });
        if (!ok) return;

        try {
            const res = await PG.api('api/devices/save.php', {
                method: 'POST',
                body: { action: 'regenerate_key', device_id: id }
            });
            showKey(res.api_key);
            refresh();
        } catch (e) {
            PG.toast(e.message, 'danger');
        }
    }

    // -----------------------------------------------------------------
    // Wire up
    // -----------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        PG.poll(load, 20000);

        if (!canManage) return;

        deviceModal = new bootstrap.Modal(document.getElementById('deviceModal'));
        keyModal = new bootstrap.Modal(document.getElementById('keyModal'));

        document.getElementById('addDeviceBtn').addEventListener('click', openCreate);
        document.getElementById('deviceForm').addEventListener('submit', save);

        document.getElementById('deviceCards').addEventListener('click', function (e) {
            const btn = e.target.closest('button[data-act]');
            if (!btn) return;
            const id = parseInt(btn.dataset.id, 10);
            if (btn.dataset.act === 'edit') openEdit(id);
            else if (btn.dataset.act === 'toggle') toggle(id);
            else if (btn.dataset.act === 'key') rotateKey(id);
        });

        document.getElementById('copyKeyBtn').addEventListener('click', function () {
            const input = document.getElementById('keyValue');
            input.select();
            // clipboard API needs a secure context; fall back to execCommand.
            const copy = navigator.clipboard
                ? navigator.clipboard.writeText(input.value)
                : Promise.reject();
            copy.then(function () { PG.toast('API key copied.', 'success'); })
                .catch(function () {
                    try {
                        document.execCommand('copy');
                        PG.toast('API key copied.', 'success');
                    } catch (e) {
                        PG.toast('Select the key and copy it manually.', 'warning');
                    }
                });
        });
    });
})();
