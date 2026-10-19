/* =====================================================================
   PoultryGuard - manual control pages (feed and water).

   Every button that moves hardware asks for confirmation first, states
   plainly what will happen, and then reports whether the controller
   actually collected the command.
   ===================================================================== */

(function () {
    'use strict';

    const kind = (window.PG_PAGE || {}).kind === 'water' ? 'water' : 'feed';
    const deviceId = PG.deviceId;
    if (!deviceId) return;

    const spec = kind === 'feed'
        ? {
            readEndpoint: 'api/feed/read.php',
            actEndpoint: 'api/feed/dispense.php',
            targetField: 'target_weight',
            unit: PG.fmt.weight,
            unitName: 'grams',
            barClass: 'feed',
            noun: 'feed dispensing',
            actuator: 'auger',
            startVerb: 'Dispense feed',
            confirmVariant: 'warning'
        }
        : {
            readEndpoint: 'api/water/read.php',
            actEndpoint: 'api/water/dispense.php',
            targetField: 'target_level',
            unit: PG.fmt.cm,
            unitName: 'centimetres',
            barClass: 'water',
            noun: 'water refill',
            actuator: 'solenoid valve',
            startVerb: 'Start refill',
            confirmVariant: 'primary'
        };

    // Latest snapshot, so the confirmation dialog can quote real numbers.
    let state = { current: null, device: null };
    let poller = null;

    // -----------------------------------------------------------------
    // Painting
    // -----------------------------------------------------------------
    function paint(data) {
        const c = data.current;
        const d = data.device;
        state.current = c;
        state.device = d;

        PG.setText('mainReading', kind === 'feed' ? c.weight_display : c.level_display);
        PG.setText('mainPercent', PG.fmt.percent(c.percentage));
        PG.setText('infoCapacity', spec.unit(c.capacity));
        PG.setText('infoTarget', spec.unit(c.target));
        if (kind === 'water') PG.setText('mainLiters', PG.fmt.liters(c.liters));

        const actuator = kind === 'feed' ? d.motor_status : d.solenoid_status;
        PG.setText('motorStatus', d.online ? PG.fmt.label(actuator) : 'Unknown');
        PG.setText('deviceStatus', d.online ? 'Online' : 'Offline');

        const pill = document.getElementById('statusPill');
        if (pill) {
            const cls = String(c.status || 'unknown').toLowerCase();
            pill.className = 'pg-pill ' + (['normal', 'low', 'critical'].indexOf(cls) !== -1 ? cls : 'unknown');
        }
        PG.setText('statusText', c.status);

        const bar = document.getElementById('levelBar');
        if (bar) {
            const w = c.percentage === null ? 0 : Math.max(0, Math.min(100, c.percentage));
            bar.style.width = w + '%';
            bar.className = 'pg-level-fill ' +
                (c.status === 'CRITICAL' ? 'critical' : c.status === 'LOW' ? 'low' : spec.barClass);
        }

        // Offline disables starting, but never disables stopping.
        const warn = document.getElementById('offlineWarning');
        if (warn) warn.classList.toggle('d-none', d.online);
        const startBtn = document.getElementById('dispenseBtn');
        if (startBtn) startBtn.disabled = !d.online;

        // Seed the target field once, with the configured refill target.
        const input = document.getElementById('targetWeight');
        if (input && input.value === '') {
            input.value = c.target;
            input.max = c.capacity;
        }
    }

    function paintCommands(data) {
        const body = document.getElementById('commandBody');
        if (!body) return;

        const rows = data.commands;
        PG.setText('queueSummary', data.pending > 0
            ? data.pending + ' command(s) waiting for the controller'
            : 'No commands waiting');

        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="6" class="text-center pg-muted py-4">' +
                'No manual commands have been issued for this device.</td></tr>';
            return;
        }

        body.innerHTML = rows.map(function (c) {
            let detail = '--';
            if (c.payload) {
                if (c.payload.target_weight !== undefined) {
                    detail = 'Target ' + PG.fmt.weight(c.payload.target_weight);
                } else if (c.payload.target_level !== undefined) {
                    detail = 'Target ' + PG.fmt.cm(c.payload.target_level);
                }
            }
            return '<tr>' +
                '<td class="text-nowrap">' + PG.escapeHtml(PG.fmt.datetime(c.created_at)) +
                    '<div class="pg-muted" style="font-size:.72rem">' + PG.escapeHtml(c.ago) + '</div></td>' +
                '<td class="fw-semibold">' + PG.escapeHtml(c.label) + '</td>' +
                '<td>' + PG.escapeHtml(detail) + '</td>' +
                '<td><span class="badge bg-' + c.state_color + '">' +
                    PG.escapeHtml(c.state_label) + '</span></td>' +
                '<td class="pg-muted small">' + PG.escapeHtml(c.result || '--') + '</td>' +
                '<td class="pg-muted">' + PG.escapeHtml(c.issued_by_name || 'System') + '</td>' +
            '</tr>';
        }).join('');
    }

    async function load() {
        const [reading, commands] = await Promise.all([
            PG.api(spec.readEndpoint, { params: { device_id: deviceId, limit: 1, hours: 1 } }),
            PG.api('api/automation/commands.php', { params: { device_id: deviceId } })
        ]);
        paint(reading);
        paintCommands(commands);
    }

    // -----------------------------------------------------------------
    // Actions
    // -----------------------------------------------------------------
    function showFieldError(message) {
        const box = document.getElementById('targetError');
        if (!box) return;
        box.textContent = message || '';
        box.classList.toggle('d-none', !message);
    }

    async function startDispensing() {
        const input = document.getElementById('targetWeight');
        const btn = document.getElementById('dispenseBtn');
        showFieldError('');

        const target = parseFloat(input.value);
        const c = state.current;

        // Client-side validation mirrors the server rules so obvious mistakes
        // are caught before a request is made. The server re-checks regardless.
        if (isNaN(target)) {
            showFieldError('Enter a target in ' + spec.unitName + '.');
            input.focus();
            return;
        }
        if (target <= 0) {
            showFieldError('The target must be greater than zero.');
            input.focus();
            return;
        }
        if (c && target > c.capacity) {
            showFieldError('The target cannot exceed the ' + spec.unit(c.capacity) + ' capacity.');
            input.focus();
            return;
        }
        const currentValue = c ? (kind === 'feed' ? c.weight : c.level) : 0;
        if (c && target <= currentValue) {
            showFieldError('The target must be above the current level of ' + spec.unit(currentValue) + '.');
            input.focus();
            return;
        }

        const willAdd = c ? spec.unit(target - currentValue) : spec.unit(target);

        const ok = await PG.confirm({
            title: 'Confirm manual ' + spec.noun,
            message: 'Are you sure you want to manually ' +
                (kind === 'feed' ? 'dispense feed' : 'refill the tank') + '?',
            detail: 'This will run the ' + spec.actuator + ' until the level reaches ' +
                spec.unit(target) + ' — about ' + willAdd + ' will be added. ' +
                'The device stops automatically at the target or at the safety timeout.',
            confirmText: spec.startVerb,
            variant: spec.confirmVariant
        });
        if (!ok) return;

        const done = PG.busy(btn, 'Sending command');
        try {
            const body = { device_id: deviceId, action: 'start' };
            body[spec.targetField] = target;

            const res = await PG.api(spec.actEndpoint, { method: 'POST', body: body });
            PG.toast('Command queued. The controller will act on it within a few seconds.', 'success');
            console.info('[PoultryGuard] command #' + res.command_id + ' queued');
            await load();
        } catch (e) {
            PG.toast(e.message, 'danger');
            showFieldError(e.message);
        } finally {
            done();
        }
    }

    async function stopDispensing() {
        const btn = document.getElementById('stopBtn');

        const ok = await PG.confirm({
            title: 'Stop the ' + spec.actuator,
            message: 'Stop the ' + spec.actuator + ' now?',
            detail: 'Any dispensing in progress will be cut short and recorded as aborted.',
            confirmText: 'Stop now',
            variant: 'danger'
        });
        if (!ok) return;

        const done = PG.busy(btn, 'Sending stop');
        try {
            await PG.api(spec.actEndpoint, {
                method: 'POST',
                body: { device_id: deviceId, action: 'stop' }
            });
            PG.toast('Stop command sent.', 'success');
            await load();
        } catch (e) {
            PG.toast(e.message, 'danger');
        } finally {
            done();
        }
    }

    async function emergencyStop() {
        const btn = document.getElementById('emergencyBtn');

        const ok = await PG.confirm({
            title: 'Emergency stop',
            message: 'Stop all feeding and watering on this device immediately?',
            detail: 'The auger and valve stop, and automatic feeding and watering are ' +
                'switched OFF until you re-enable them in Settings. The birds will not be ' +
                'fed or watered automatically until you do.',
            confirmText: 'Emergency stop',
            variant: 'danger'
        });
        if (!ok) return;

        const done = PG.busy(btn, 'Stopping');
        try {
            const res = await PG.api('api/automation/emergency-stop.php', {
                method: 'POST',
                body: { device_id: deviceId }
            });
            PG.toast(res.message || 'Emergency stop sent.', 'warning');
            PG.toast('Automatic feeding and watering are now OFF. Re-enable them in Settings.', 'danger');
            await load();
        } catch (e) {
            PG.toast(e.message, 'danger');
        } finally {
            done();
        }
    }

    // -----------------------------------------------------------------
    // Wire up
    // -----------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        poller = PG.poll(load, Math.max(8000, (PG_CONFIG.pollInterval || 30000) / 2));

        const form = document.getElementById('dispenseForm');
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                startDispensing();
            });
        }

        const stop = document.getElementById('stopBtn');
        if (stop) stop.addEventListener('click', stopDispensing);

        const emergency = document.getElementById('emergencyBtn');
        if (emergency) emergency.addEventListener('click', emergencyStop);

        // Quick-fill presets
        document.querySelectorAll('[data-preset]').forEach(function (b) {
            b.addEventListener('click', function () {
                if (!state.current) return;
                const input = document.getElementById('targetWeight');
                input.value = this.dataset.preset === 'full'
                    ? state.current.capacity
                    : state.current.target;
                showFieldError('');
            });
        });
    });
})();
