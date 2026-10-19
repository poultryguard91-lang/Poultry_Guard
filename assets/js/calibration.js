/* =====================================================================
   PoultryGuard - sensor calibration page.

   Shows the live raw readings next to the stored calibration so an operator
   can see the effect of a change before committing it.
   ===================================================================== */

(function () {
    'use strict';

    const deviceId = PG.deviceId;
    if (!deviceId) return;

    let snapshot = null;
    // Set once the operator edits a field, so the poll does not overwrite
    // half-typed input underneath them.
    let formTouched = false;

    // -----------------------------------------------------------------
    // Painting
    // -----------------------------------------------------------------
    function pill(id, status) {
        const el = document.getElementById(id);
        if (!el) return;
        const v = String(status || 'unknown').toLowerCase();
        el.className = 'pg-pill ' + (v === 'ok' ? 'normal' : v === 'error' ? 'critical' : 'unknown');
        el.textContent = PG.fmt.label(status);
    }

    function paint(data) {
        snapshot = data;

        // --- load cell ---
        const lc = data.load_cell;
        pill('lcStatus', lc.sensor_status);
        PG.setText('lcWeight', lc.current_weight_display);
        PG.setText('lcRaw', lc.current_raw === null ? 'Not reported' : PG.fmt.number(lc.current_raw));
        PG.setText('lcAge', lc.reading_ago);
        PG.setText('lcFactor', lc.calibration_factor === null ? '--' : lc.calibration_factor.toFixed(4));
        PG.setText('lcOffset', lc.offset_value === null ? '--' : PG.fmt.number(lc.offset_value));
        PG.setText('lcDate', PG.fmt.datetime(lc.calibrated_at));

        // --- ultrasonic ---
        const us = data.ultrasonic;
        pill('usStatus', us.sensor_status);
        PG.setText('usDistance', PG.fmt.cm(us.current_distance));
        PG.setText('usLevel', PG.fmt.cm(us.current_level));
        PG.setText('usPercent', PG.fmt.percent(us.current_percentage));
        PG.setText('usAge', 'Last reading ' + us.reading_ago);

        if (!formTouched) {
            setValue('lcTareInput', lc.offset_value);
            setValue('lcFactorInput', lc.calibration_factor);
            setValue('usDepthInput', us.calibration_factor);
            setValue('usOffsetInput', us.offset_value);
            // Pre-fill the observed raw value so step 3 of the procedure is one click.
            if (lc.current_raw !== null) setValue('lcRawInput', lc.current_raw);
        }

        updateComputedFactor();
        updateUltrasonicPreview();
        paintHistory(data.history);
    }

    function setValue(id, v) {
        const el = document.getElementById(id);
        if (el && v !== null && v !== undefined) el.value = v;
    }

    function paintHistory(rows) {
        const body = document.getElementById('historyBody');
        if (!body) return;

        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="8" class="text-center pg-muted py-4">' +
                'No calibration has been recorded for this device yet.</td></tr>';
            return;
        }

        body.innerHTML = rows.map(function (r) {
            return '<tr>' +
                '<td class="text-nowrap">' + PG.escapeHtml(PG.fmt.datetime(r.calibrated_at)) + '</td>' +
                '<td>' + PG.escapeHtml(r.sensor_type === 'load_cell' ? 'Load cell' : 'Ultrasonic') + '</td>' +
                '<td class="pg-num pg-mono">' + PG.escapeHtml(Number(r.calibration_factor).toFixed(4)) + '</td>' +
                '<td class="pg-num pg-mono">' + PG.escapeHtml(Number(r.offset_value).toFixed(2)) + '</td>' +
                '<td class="pg-num pg-mono">' +
                    PG.escapeHtml(r.reference_value === null ? '--' : Number(r.reference_value).toFixed(2)) + '</td>' +
                '<td>' + (Number(r.is_active) === 1
                    ? '<span class="badge bg-success">Active</span>'
                    : '<span class="badge bg-light text-dark">Superseded</span>') + '</td>' +
                '<td class="pg-muted">' + PG.escapeHtml(r.calibrated_by_name || 'System') + '</td>' +
                '<td class="pg-muted small">' + PG.escapeHtml(r.notes || '--') + '</td>' +
            '</tr>';
        }).join('');
    }

    // -----------------------------------------------------------------
    // Live preview of what the entered values would produce
    // -----------------------------------------------------------------
    function updateComputedFactor() {
        const tare = parseFloat(document.getElementById('lcTareInput').value);
        const raw = parseFloat(document.getElementById('lcRawInput').value);
        const ref = parseFloat(document.getElementById('lcReference').value);
        const out = document.getElementById('lcComputed');

        if (isNaN(tare) || isNaN(raw) || isNaN(ref) || ref <= 0) {
            out.textContent = '--';
            return;
        }
        const factor = (raw - tare) / ref;
        out.textContent = Math.abs(factor) < 0.000001 ? 'invalid (zero)' : factor.toFixed(4);
    }

    function updateUltrasonicPreview() {
        if (!snapshot) return;
        const depth = parseFloat(document.getElementById('usDepthInput').value);
        const offset = parseFloat(document.getElementById('usOffsetInput').value);
        const distance = snapshot.ultrasonic.current_distance;

        const dEl = document.getElementById('usPreviewDistance');
        const lEl = document.getElementById('usPreviewLevel');
        const pEl = document.getElementById('usPreviewPercent');

        if (distance === null || isNaN(depth) || isNaN(offset) || depth <= 0) {
            dEl.textContent = distance === null ? 'no reading' : PG.fmt.cm(distance);
            lEl.textContent = '--';
            pEl.textContent = '--';
            return;
        }

        // Mirrors water_from_distance() on the server.
        let level = depth - (distance - offset);
        level = Math.max(0, Math.min(depth, level));

        dEl.textContent = PG.fmt.cm(distance);
        lEl.textContent = PG.fmt.cm(level);
        pEl.textContent = PG.fmt.percent(level / depth * 100);
    }

    // -----------------------------------------------------------------
    // Actions
    // -----------------------------------------------------------------
    function showError(id, message) {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = message || '';
        el.classList.toggle('d-none', !message);
    }

    async function load() {
        const data = await PG.api('api/sensors/calibration.php', { params: { device_id: deviceId } });
        paint(data);
    }

    async function saveLoadCell(e) {
        e.preventDefault();
        showError('lcError', '');

        const btn = document.getElementById('lcSaveBtn');
        const twoPoint = document.getElementById('lcTwoPoint').checked;
        const tare = parseFloat(document.getElementById('lcTareInput').value);

        if (isNaN(tare)) {
            showError('lcError', 'Enter the tare offset in raw counts.');
            return;
        }

        const body = { device_id: deviceId, sensor_type: 'load_cell', offset_value: tare,
                       notes: document.getElementById('lcNotes').value };

        if (twoPoint) {
            const ref = parseFloat(document.getElementById('lcReference').value);
            const raw = parseFloat(document.getElementById('lcRawInput').value);
            if (isNaN(ref) || ref <= 0) {
                showError('lcError', 'Enter the reference weight in grams.');
                return;
            }
            if (isNaN(raw)) {
                showError('lcError', 'Enter the raw value observed with the reference weight on the scale.');
                return;
            }
            if (Math.abs((raw - tare) / ref) < 0.000001) {
                showError('lcError',
                    'The raw value matches the tare offset, so no factor can be derived. ' +
                    'Make sure the reference mass is on the scale.');
                return;
            }
            body.reference_weight = ref;
            body.raw_value = raw;
        } else {
            const factor = parseFloat(document.getElementById('lcFactorInput').value);
            if (isNaN(factor) || Math.abs(factor) < 0.000001) {
                showError('lcError', 'Enter a non-zero calibration factor.');
                return;
            }
            body.calibration_factor = factor;
        }

        const ok = await PG.confirm({
            title: 'Save load cell calibration',
            message: 'Apply this calibration to the load cell?',
            detail: 'The new factor is sent to the ESP32, which will use it for every ' +
                'weight reading and for deciding when to dispense — including while offline.',
            confirmText: 'Save calibration',
            variant: 'warning'
        });
        if (!ok) return;

        const done = PG.busy(btn, 'Saving');
        try {
            const data = await PG.api('api/sensors/calibration.php', { method: 'POST', body: body });
            formTouched = false;
            paint(data);
            PG.toast('Load cell calibration saved.', 'success');
        } catch (err) {
            showError('lcError', err.message);
            PG.toast(err.message, 'danger');
        } finally {
            done();
        }
    }

    async function saveUltrasonic(e) {
        e.preventDefault();
        showError('usError', '');

        const btn = document.getElementById('usSaveBtn');
        const depth = parseFloat(document.getElementById('usDepthInput').value);
        const offset = parseFloat(document.getElementById('usOffsetInput').value);

        if (isNaN(depth) || depth <= 0) {
            showError('usError', 'Enter the usable tank depth in centimetres.');
            return;
        }
        if (isNaN(offset) || offset < 0) {
            showError('usError', 'Enter the sensor mount offset in centimetres (0 or more).');
            return;
        }
        if (depth + offset > 400) {
            showError('usError', 'Tank depth plus sensor offset exceeds the 400 cm range of the HC-SR04.');
            return;
        }

        const ok = await PG.confirm({
            title: 'Save ultrasonic calibration',
            message: 'Apply this calibration to the water sensor?',
            detail: 'Tank depth and sensor offset in Settings are updated to match, and the ' +
                'refill threshold and target are capped to the new depth if they exceed it.',
            confirmText: 'Save calibration',
            variant: 'primary'
        });
        if (!ok) return;

        const done = PG.busy(btn, 'Saving');
        try {
            const data = await PG.api('api/sensors/calibration.php', {
                method: 'POST',
                body: {
                    device_id: deviceId,
                    sensor_type: 'ultrasonic',
                    calibration_factor: depth,
                    offset_value: offset,
                    notes: document.getElementById('usNotes').value
                }
            });
            formTouched = false;
            paint(data);
            PG.toast('Ultrasonic calibration saved.', 'success');
        } catch (err) {
            showError('usError', err.message);
            PG.toast(err.message, 'danger');
        } finally {
            done();
        }
    }

    async function tare() {
        const btn = document.getElementById('tareBtn');

        const ok = await PG.confirm({
            title: 'Tare the scale',
            message: 'Zero the load cell now?',
            detail: 'Make sure the hopper is completely empty first. The device will report ' +
                'the new raw offset, which you can then save below.',
            confirmText: 'Tare scale',
            variant: 'warning'
        });
        if (!ok) return;

        const done = PG.busy(btn, 'Sending');
        try {
            await PG.api('api/sensors/calibration.php', {
                method: 'POST',
                body: { device_id: deviceId, action: 'tare' }
            });
            PG.toast('Tare command sent. Watch the raw value above for the new zero point.', 'success');
        } catch (err) {
            PG.toast(err.message, 'danger');
        } finally {
            done();
        }
    }

    // -----------------------------------------------------------------
    // Wire up
    // -----------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        PG.poll(load, Math.max(10000, PG_CONFIG.pollInterval || 30000));

        document.getElementById('loadCellForm').addEventListener('submit', saveLoadCell);
        document.getElementById('ultrasonicForm').addEventListener('submit', saveUltrasonic);
        document.getElementById('tareBtn').addEventListener('click', tare);

        // Toggle between two-point calibration and a directly entered factor.
        const twoPoint = document.getElementById('lcTwoPoint');
        twoPoint.addEventListener('change', function () {
            document.getElementById('lcTwoPointFields').classList.toggle('d-none', !this.checked);
            document.getElementById('lcDirectField').classList.toggle('d-none', this.checked);
        });

        ['lcTareInput', 'lcRawInput', 'lcReference'].forEach(function (id) {
            document.getElementById(id).addEventListener('input', function () {
                formTouched = true;
                updateComputedFactor();
            });
        });
        ['usDepthInput', 'usOffsetInput'].forEach(function (id) {
            document.getElementById(id).addEventListener('input', function () {
                formTouched = true;
                updateUltrasonicPreview();
            });
        });
        ['lcFactorInput', 'lcNotes', 'usNotes'].forEach(function (id) {
            document.getElementById(id).addEventListener('input', function () { formTouched = true; });
        });
    });
})();
