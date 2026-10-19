/* =====================================================================
   PoultryGuard - user management page.
   ===================================================================== */

(function () {
    'use strict';

    const currentUserId = (window.PG_PAGE || {}).currentUserId;
    let userModal = null;
    let passwordModal = null;
    let cache = [];

    // -----------------------------------------------------------------
    // Painting
    // -----------------------------------------------------------------
    function paint(data) {
        PG.setText('sumTotal', data.summary.total);
        PG.setText('sumOwners', data.summary.owners);
        PG.setText('sumStaff', data.summary.staff);
        PG.setText('sumInactive', data.summary.inactive);

        cache = data.users;
        paintUsers(data.users);
        paintLogs(data.logs);
    }

    function paintUsers(rows) {
        const body = document.getElementById('userBody');
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="7" class="text-center pg-muted py-4">No accounts.</td></tr>';
            return;
        }

        body.innerHTML = rows.map(function (u) {
            const isSelf = u.user_id === currentUserId;
            const active = u.status === 'active';

            return '<tr' + (active ? '' : ' class="opacity-50"') + '>' +
                '<td class="fw-semibold">' + PG.escapeHtml(u.name) +
                    (isSelf ? ' <span class="badge bg-info">you</span>' : '') + '</td>' +
                '<td class="pg-mono">' + PG.escapeHtml(u.username) + '</td>' +
                '<td class="pg-muted">' + PG.escapeHtml(u.email || '--') + '</td>' +
                // Role is an attribute, not a health signal, so it stays on the
                // brand/neutral pair - green is reserved for the status column.
                '<td><span class="badge ' + (u.role === 'owner' ? 'bg-primary' : 'bg-secondary') + '">' +
                    (u.role === 'owner' ? 'Farm Owner' : 'Farm Staff') + '</span></td>' +
                '<td><span class="badge ' + (active ? 'bg-success' : 'bg-secondary') + '">' +
                    PG.escapeHtml(u.status) + '</span></td>' +
                '<td class="pg-muted small">' + PG.escapeHtml(u.last_login_ago) + '</td>' +
                '<td class="text-nowrap">' +
                    '<button class="btn btn-sm btn-light me-1" data-act="edit" data-id="' + u.user_id + '">Edit</button>' +
                    '<button class="btn btn-sm btn-light me-1" data-act="password" data-id="' + u.user_id + '">Password</button>' +
                    (isSelf
                        ? '<button class="btn btn-sm btn-light" disabled title="You cannot deactivate your own account">' +
                          (active ? 'Deactivate' : 'Activate') + '</button>'
                        : '<button class="btn btn-sm btn-outline-' + (active ? 'danger' : 'success') +
                          '" data-act="toggle" data-id="' + u.user_id + '">' +
                          (active ? 'Deactivate' : 'Activate') + '</button>') +
                '</td>' +
            '</tr>';
        }).join('');
    }

    function paintLogs(rows) {
        const body = document.getElementById('logBody');
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="5" class="text-center pg-muted py-4">No activity recorded.</td></tr>';
            return;
        }

        body.innerHTML = rows.map(function (l) {
            return '<tr>' +
                '<td class="text-nowrap small">' + PG.escapeHtml(PG.fmt.datetime(l.created_at)) +
                    '<div class="pg-muted" style="font-size:.72rem">' + PG.escapeHtml(l.ago) + '</div></td>' +
                '<td>' + PG.escapeHtml(l.user_name || 'System / device') + '</td>' +
                '<td><span class="badge bg-light text-dark">' + PG.escapeHtml(l.action) + '</span></td>' +
                '<td class="small">' + PG.escapeHtml(l.description) + '</td>' +
                '<td class="pg-muted pg-mono small">' + PG.escapeHtml(l.ip_address || '--') + '</td>' +
            '</tr>';
        }).join('');
    }

    // -----------------------------------------------------------------
    // Load
    // -----------------------------------------------------------------
    async function load() {
        paint(await PG.api('api/users/get.php'));
    }
    function refresh() {
        load().catch(function (e) { PG.toast(e.message, 'danger'); });
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

    function validatePassword(pwd) {
        if (pwd.length < 8) return 'The password must be at least 8 characters long.';
        if (!/[A-Za-z]/.test(pwd) || !/[0-9]/.test(pwd)) {
            return 'The password must contain at least one letter and one number.';
        }
        return null;
    }

    function openCreate() {
        showError('userError', '');
        document.getElementById('userForm').reset();
        document.getElementById('uUserId').value = '';
        document.getElementById('userModalTitle').textContent = 'Add user';
        document.getElementById('usernameGroup').classList.remove('d-none');
        document.getElementById('passwordGroup').classList.remove('d-none');
        userModal.show();
    }

    function openEdit(id) {
        const u = cache.find(function (x) { return x.user_id === id; });
        if (!u) return;

        showError('userError', '');
        document.getElementById('uUserId').value = u.user_id;
        document.getElementById('uName').value = u.name;
        document.getElementById('uEmail').value = u.email || '';
        document.getElementById('uRole').value = u.role;
        document.getElementById('userModalTitle').textContent = 'Edit ' + u.username;
        // Username is the sign-in identity; password has its own dialog.
        document.getElementById('usernameGroup').classList.add('d-none');
        document.getElementById('passwordGroup').classList.add('d-none');
        userModal.show();
    }

    async function saveUser(e) {
        e.preventDefault();
        showError('userError', '');

        const id = document.getElementById('uUserId').value;
        const name = document.getElementById('uName').value.trim();
        const email = document.getElementById('uEmail').value.trim();
        const role = document.getElementById('uRole').value;

        if (name.length < 2) {
            showError('userError', 'Enter the person’s full name.');
            return;
        }
        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showError('userError', 'Enter a valid email address, or leave it blank.');
            return;
        }

        const body = { name: name, email: email, role: role };

        if (id) {
            body.action = 'update';
            body.user_id = parseInt(id, 10);
        } else {
            const username = document.getElementById('uUsername').value.trim();
            const password = document.getElementById('uPassword').value;

            if (username.length < 3) {
                showError('userError', 'Enter a username of at least 3 characters.');
                return;
            }
            if (!/^[A-Za-z0-9._-]+$/.test(username)) {
                showError('userError',
                    'The username may only contain letters, digits, dots, hyphens and underscores.');
                return;
            }
            const pwdProblem = validatePassword(password);
            if (pwdProblem) {
                showError('userError', pwdProblem);
                return;
            }
            body.action = 'create';
            body.username = username;
            body.password = password;
        }

        const done = PG.busy(document.getElementById('userSaveBtn'), 'Saving');
        try {
            await PG.api('api/users/save.php', { method: 'POST', body: body });
            userModal.hide();
            PG.toast(id ? 'User updated.' : 'User account created.', 'success');
            refresh();
        } catch (err) {
            showError('userError', err.message);
        } finally {
            done();
        }
    }

    function openPassword(id) {
        const u = cache.find(function (x) { return x.user_id === id; });
        if (!u) return;

        showError('passwordError', '');
        document.getElementById('passwordForm').reset();
        document.getElementById('pUserId').value = u.user_id;
        document.getElementById('pUserName').textContent = u.name + ' (' + u.username + ')';
        passwordModal.show();
    }

    async function savePassword(e) {
        e.preventDefault();
        showError('passwordError', '');

        const password = document.getElementById('pPassword').value;
        const problem = validatePassword(password);
        if (problem) {
            showError('passwordError', problem);
            return;
        }

        const done = PG.busy(document.getElementById('passwordSaveBtn'), 'Saving');
        try {
            await PG.api('api/users/save.php', {
                method: 'POST',
                body: {
                    action: 'password',
                    user_id: parseInt(document.getElementById('pUserId').value, 10),
                    password: password
                }
            });
            passwordModal.hide();
            PG.toast('Password updated.', 'success');
            refresh();
        } catch (err) {
            showError('passwordError', err.message);
        } finally {
            done();
        }
    }

    async function toggle(id) {
        const u = cache.find(function (x) { return x.user_id === id; });
        if (!u) return;
        const deactivating = u.status === 'active';

        const ok = await PG.confirm({
            title: (deactivating ? 'Deactivate' : 'Activate') + ' account',
            message: (deactivating ? 'Deactivate' : 'Activate') + ' the account for ' + u.name + '?',
            detail: deactivating
                ? 'They will be signed out and refused at the next request. The account and its ' +
                  'activity history are kept.'
                : 'They will be able to sign in again with their existing password.',
            confirmText: deactivating ? 'Deactivate' : 'Activate',
            variant: deactivating ? 'danger' : 'success'
        });
        if (!ok) return;

        try {
            await PG.api('api/users/save.php', {
                method: 'POST',
                body: { action: 'toggle', user_id: id }
            });
            PG.toast('Account ' + (deactivating ? 'deactivated' : 'activated') + '.', 'success');
            refresh();
        } catch (e) {
            PG.toast(e.message, 'danger');
        }
    }

    // -----------------------------------------------------------------
    // Wire up
    // -----------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        userModal = new bootstrap.Modal(document.getElementById('userModal'));
        passwordModal = new bootstrap.Modal(document.getElementById('passwordModal'));

        refresh();

        document.getElementById('addUserBtn').addEventListener('click', openCreate);
        document.getElementById('userForm').addEventListener('submit', saveUser);
        document.getElementById('passwordForm').addEventListener('submit', savePassword);

        document.getElementById('userBody').addEventListener('click', function (e) {
            const btn = e.target.closest('button[data-act]');
            if (!btn) return;
            const id = parseInt(btn.dataset.id, 10);
            if (btn.dataset.act === 'edit') openEdit(id);
            else if (btn.dataset.act === 'password') openPassword(id);
            else if (btn.dataset.act === 'toggle') toggle(id);
        });
    });
})();
