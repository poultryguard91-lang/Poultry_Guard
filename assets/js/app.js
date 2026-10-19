/* =====================================================================
   PoultryGuard - shared front-end runtime.

   One place for: talking to the REST API (with the CSRF token attached),
   user feedback (toasts and confirmation dialogs), number/date formatting
   and the polling loop that keeps the dashboard live without reloading.
   ===================================================================== */

window.PG = (function () {
    'use strict';

    // Injected by includes/header.php
    const cfg = window.PG_CONFIG || {};
    const baseUrl = cfg.baseUrl || '';
    const csrfToken = cfg.csrfToken || '';

    // -----------------------------------------------------------------
    // API client
    // -----------------------------------------------------------------

    /**
     * Call a PoultryGuard API endpoint.
     *
     * Resolves with the `data` object on success and rejects with an Error
     * carrying the server's user-facing message on failure, so every caller
     * can simply try/catch and show err.message.
     *
     * @param {string} path   e.g. 'api/feed/read.php'
     * @param {object} opts   { method, body, params, signal }
     */
    async function api(path, opts) {
        opts = opts || {};
        const method = (opts.method || 'GET').toUpperCase();

        let url = baseUrl + '/' + String(path).replace(/^\/+/, '');
        if (opts.params) {
            const qs = new URLSearchParams();
            Object.keys(opts.params).forEach(function (k) {
                const v = opts.params[k];
                if (v !== null && v !== undefined && v !== '') qs.append(k, v);
            });
            const q = qs.toString();
            if (q) url += (url.indexOf('?') === -1 ? '?' : '&') + q;
        }

        const init = {
            method: method,
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
            signal: opts.signal
        };

        if (method !== 'GET' && method !== 'HEAD') {
            init.headers['Content-Type'] = 'application/json';
            // Every state-changing request carries the CSRF token.
            init.headers['X-CSRF-Token'] = csrfToken;
            init.body = JSON.stringify(opts.body || {});
        }

        let res;
        try {
            res = await fetch(url, init);
        } catch (e) {
            if (e.name === 'AbortError') throw e;
            // fetch only rejects on a genuine network/transport failure.
            throw new Error('Cannot reach the PoultryGuard server. Check your network connection.');
        }

        let payload = null;
        const text = await res.text();
        if (text) {
            try { payload = JSON.parse(text); } catch (e) { payload = null; }
        }

        if (res.status === 401) {
            // The session expired underneath the page - send them to sign in again.
            window.location.href = baseUrl + '/login.php?expired=1';
            throw new Error('Your session expired.');
        }

        if (!res.ok || !payload || payload.success !== true) {
            const msg = (payload && payload.message)
                ? payload.message
                : 'The server returned an unexpected response (HTTP ' + res.status + ').';
            const err = new Error(msg);
            err.status = res.status;
            throw err;
        }

        return payload.data;
    }

    // -----------------------------------------------------------------
    // Toasts
    // -----------------------------------------------------------------
    function toastStack() {
        let el = document.querySelector('.pg-toast-stack');
        if (!el) {
            el = document.createElement('div');
            el.className = 'pg-toast-stack';
            document.body.appendChild(el);
        }
        return el;
    }

    /**
     * Show a transient message.
     * @param {string} message
     * @param {'success'|'danger'|'warning'|'info'} type
     */
    function toast(message, type) {
        type = type || 'info';
        const icons = { success: '✓', danger: '!', warning: '!', info: 'i' };
        const el = document.createElement('div');
        el.className = 'toast align-items-center text-bg-' + type + ' border-0 show';
        el.setAttribute('role', 'alert');
        el.innerHTML =
            '<div class="d-flex">' +
            '<div class="toast-body"><strong class="me-1">' + (icons[type] || '') + '</strong>' +
            escapeHtml(message) + '</div>' +
            '<button type="button" class="btn-close btn-close-white me-2 m-auto" aria-label="Close"></button>' +
            '</div>';

        el.querySelector('.btn-close').addEventListener('click', function () { remove(); });
        toastStack().appendChild(el);

        // Errors stay longer - people need time to read what went wrong.
        const life = (type === 'danger') ? 8000 : 4500;
        const timer = setTimeout(remove, life);

        function remove() {
            clearTimeout(timer);
            el.classList.remove('show');
            setTimeout(function () { el.remove(); }, 200);
        }
        return el;
    }

    // -----------------------------------------------------------------
    // Confirmation dialog
    // -----------------------------------------------------------------

    /**
     * Ask the operator to confirm before anything touches the hardware.
     * @returns {Promise<boolean>}
     */
    function confirmAction(options) {
        const o = Object.assign({
            title: 'Please confirm',
            message: 'Are you sure?',
            detail: '',
            confirmText: 'Confirm',
            cancelText: 'Cancel',
            variant: 'primary'
        }, options || {});

        return new Promise(function (resolve) {
            const wrap = document.createElement('div');
            wrap.className = 'modal fade';
            wrap.setAttribute('tabindex', '-1');
            wrap.innerHTML =
                '<div class="modal-dialog modal-dialog-centered">' +
                  '<div class="modal-content">' +
                    '<div class="modal-header">' +
                      '<h5 class="modal-title">' + escapeHtml(o.title) + '</h5>' +
                      '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>' +
                    '</div>' +
                    '<div class="modal-body">' +
                      '<p class="mb-' + (o.detail ? '2' : '0') + '">' + escapeHtml(o.message) + '</p>' +
                      (o.detail ? '<div class="alert alert-warning py-2 px-3 mb-0 small">' + escapeHtml(o.detail) + '</div>' : '') +
                    '</div>' +
                    '<div class="modal-footer">' +
                      '<button type="button" class="btn btn-light" data-bs-dismiss="modal">' + escapeHtml(o.cancelText) + '</button>' +
                      '<button type="button" class="btn btn-' + o.variant + '" data-pg-confirm>' + escapeHtml(o.confirmText) + '</button>' +
                    '</div>' +
                  '</div>' +
                '</div>';

            document.body.appendChild(wrap);
            const modal = new bootstrap.Modal(wrap);
            let answer = false;

            wrap.querySelector('[data-pg-confirm]').addEventListener('click', function () {
                answer = true;
                modal.hide();
            });
            wrap.addEventListener('hidden.bs.modal', function () {
                wrap.remove();
                resolve(answer);
            });
            modal.show();
        });
    }

    // -----------------------------------------------------------------
    // Polling
    // -----------------------------------------------------------------

    /**
     * Run `fn` now and then every `intervalMs`.
     *
     * Polling pauses while the tab is hidden (a background tab does not need
     * live data and the requests would pile up) and resumes with an immediate
     * refresh when the operator comes back.
     *
     * @returns {{stop: function, refresh: function}}
     */
    function poll(fn, intervalMs) {
        let timer = null;
        let stopped = false;
        let running = false;

        async function tick() {
            if (stopped || running) return;
            running = true;
            try {
                await fn();
            } catch (e) {
                if (e.name !== 'AbortError') {
                    console.error('[PoultryGuard] poll failed:', e);
                }
            } finally {
                running = false;
            }
        }

        function start() {
            if (timer) clearInterval(timer);
            timer = setInterval(tick, intervalMs);
        }

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                if (timer) { clearInterval(timer); timer = null; }
            } else if (!stopped) {
                tick();
                start();
            }
        });

        tick();
        start();

        return {
            stop: function () { stopped = true; if (timer) clearInterval(timer); },
            refresh: tick
        };
    }

    // -----------------------------------------------------------------
    // Formatting
    // -----------------------------------------------------------------
    const fmt = {
        /** Grams, switching to kg above 1 kg - matches the PHP helper. */
        weight: function (grams) {
            if (grams === null || grams === undefined || isNaN(grams)) return '--';
            const g = Number(grams);
            return Math.abs(g) >= 1000
                ? (g / 1000).toFixed(2) + ' kg'
                : Math.round(g) + ' g';
        },
        number: function (n, dp) {
            if (n === null || n === undefined || isNaN(n)) return '--';
            return Number(n).toLocaleString(undefined, {
                minimumFractionDigits: dp === undefined ? 0 : dp,
                maximumFractionDigits: dp === undefined ? 0 : dp
            });
        },
        percent: function (n, dp) {
            if (n === null || n === undefined || isNaN(n)) return '--';
            return Number(n).toFixed(dp === undefined ? 1 : dp) + '%';
        },
        cm: function (n) {
            if (n === null || n === undefined || isNaN(n)) return '--';
            return Number(n).toFixed(1) + ' cm';
        },
        liters: function (n) {
            if (n === null || n === undefined || isNaN(n)) return '--';
            return Number(n).toFixed(1) + ' L';
        },
        seconds: function (ms) {
            if (ms === null || ms === undefined || isNaN(ms)) return '--';
            return (Number(ms) / 1000).toFixed(1) + ' s';
        },
        /** MySQL datetime -> readable local string. */
        datetime: function (ts) {
            if (!ts) return 'Never';
            const d = parseTs(ts);
            if (!d) return 'Never';
            return d.toLocaleString(undefined, {
                month: 'short', day: 'numeric', year: 'numeric',
                hour: 'numeric', minute: '2-digit'
            });
        },
        time: function (ts) {
            const d = parseTs(ts);
            return d ? d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : '--';
        },
        date: function (ts) {
            const d = parseTs(ts);
            return d ? d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : '--';
        },
        /** Title-case a snake_case identifier for display. */
        label: function (s) {
            if (!s) return '--';
            return String(s).replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
        }
    };

    /** Safari will not parse "YYYY-MM-DD HH:MM:SS", so normalise it. */
    function parseTs(ts) {
        if (!ts) return null;
        const d = new Date(String(ts).replace(' ', 'T'));
        return isNaN(d.getTime()) ? null : d;
    }

    // -----------------------------------------------------------------
    // DOM helpers
    // -----------------------------------------------------------------
    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s === null || s === undefined ? '' : String(s);
        return d.innerHTML;
    }

    /** Set an element's text by id, skipping missing nodes. */
    function setText(id, value) {
        const el = document.getElementById(id);
        if (el) {
            el.textContent = value === null || value === undefined ? '--' : value;
            el.classList.remove('pg-skeleton');
        }
    }

    /** Replace an element's class list suffix, e.g. status pills. */
    function setClass(id, base, extra) {
        const el = document.getElementById(id);
        if (el) el.className = base + (extra ? ' ' + extra : '');
    }

    function show(id, visible) {
        const el = document.getElementById(id);
        if (el) el.hidden = !visible;
    }

    /** Put a button into a loading state and return a restore function. */
    function busy(button, label) {
        if (!button) return function () {};
        const original = button.innerHTML;
        const wasDisabled = button.disabled;
        button.disabled = true;
        button.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2" role="status"></span>' +
            escapeHtml(label || 'Working...');
        return function () {
            button.innerHTML = original;
            button.disabled = wasDisabled;
        };
    }

    /** Map a status word to a Bootstrap contextual colour. */
    function statusColor(status) {
        switch (String(status || '').toUpperCase()) {
            case 'CRITICAL': case 'ERROR': case 'FAILED': case 'TIMEOUT': return 'danger';
            case 'LOW': case 'WARNING': case 'RUNNING': return 'warning';
            case 'NORMAL': case 'OK': case 'ONLINE': case 'COMPLETED': case 'CONNECTED': return 'success';
            case 'OFFLINE': case 'DISCONNECTED': case 'UNKNOWN': case 'EXPIRED': return 'secondary';
            default: return 'info';
        }
    }

    /**
     * Keep the selected device in the URL so the choice survives navigation,
     * bookmarking and a refresh.
     */
    function switchDevice(deviceId) {
        const u = new URL(window.location.href);
        u.searchParams.set('device_id', deviceId);
        window.location.href = u.toString();
    }

    // -----------------------------------------------------------------
    // Global wiring
    // -----------------------------------------------------------------
    // Live farm clock in the top bar. Anchored to the server's time so a wrong
    // phone/PC clock cannot mislead the operator; only the seconds tick locally.
    function startClock() {
        const out = document.getElementById('pgClockText');
        if (!out || !cfg.serverTimeMs) return;

        const offset = cfg.serverTimeMs - Date.now();
        const opts = {
            weekday: 'short', month: 'short', day: 'numeric', year: 'numeric',
            hour: 'numeric', minute: '2-digit', second: '2-digit'
        };
        let fmt;
        try {
            fmt = new Intl.DateTimeFormat('en-US', Object.assign({ timeZone: cfg.timezone }, opts));
        } catch (e) {
            fmt = new Intl.DateTimeFormat('en-US', opts);
        }
        function tick() {
            // "Mon, Oct 19, 2026, 1:52:40 PM" -> "Mon, Oct 19, 2026 1:52:40 PM"
            out.textContent = fmt.format(new Date(Date.now() + offset)).replace(/(\d{4}),/, '$1');
        }
        tick();
        setInterval(tick, 1000);
    }

    document.addEventListener('DOMContentLoaded', function () {
        startClock();

        // Mobile sidebar toggle
        const toggle = document.getElementById('pgSidebarToggle');
        const sidebar = document.getElementById('pgSidebar');
        if (toggle && sidebar) {
            toggle.addEventListener('click', function () {
                const open = sidebar.classList.toggle('is-open');
                let backdrop = document.querySelector('.pg-backdrop');
                if (open && !backdrop) {
                    backdrop = document.createElement('div');
                    backdrop.className = 'pg-backdrop';
                    backdrop.addEventListener('click', function () {
                        sidebar.classList.remove('is-open');
                        backdrop.remove();
                    });
                    document.body.appendChild(backdrop);
                } else if (!open && backdrop) {
                    backdrop.remove();
                }
            });
        }

        // Device selector in the top bar
        const picker = document.getElementById('pgDeviceSelect');
        if (picker) {
            picker.addEventListener('change', function () { switchDevice(this.value); });
        }

        // Sign out
        const logout = document.getElementById('pgLogout');
        if (logout) {
            logout.addEventListener('click', async function (e) {
                e.preventDefault();
                const ok = await confirmAction({
                    title: 'Sign out',
                    message: 'Sign out of PoultryGuard?',
                    detail: 'Automatic feeding and watering keep running on the device.',
                    confirmText: 'Sign out',
                    variant: 'success'
                });
                if (!ok) return;
                try {
                    const data = await api('api/auth/logout.php', { method: 'POST' });
                    window.location.href = data.redirect;
                } catch (err) {
                    toast(err.message, 'danger');
                }
            });
        }

        // Any element with data-pg-print triggers the browser print dialog
        document.querySelectorAll('[data-pg-print]').forEach(function (btn) {
            btn.addEventListener('click', function () { window.print(); });
        });
    });

    return {
        api: api,
        toast: toast,
        confirm: confirmAction,
        poll: poll,
        fmt: fmt,
        escapeHtml: escapeHtml,
        setText: setText,
        setClass: setClass,
        show: show,
        busy: busy,
        statusColor: statusColor,
        switchDevice: switchDevice,
        deviceId: cfg.deviceId || null,
        baseUrl: baseUrl
    };
})();
