<?php
/**
 * PoultryGuard - user management.
 *
 * Farm Owner only. Accounts, their roles, and the recent activity log.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_page_auth('users.manage');

$PAGE_TITLE    = 'User Management';
$PAGE_SUBTITLE = 'Accounts, roles and the activity log.';
$NAV_ACTIVE    = 'users';
$PAGE_SCRIPTS  = [asset_url('assets/js/users.js')];
$PAGE_ACTIONS  = '<button class="btn btn-sm btn-primary" id="addUserBtn">Add user</button>';
$SHOW_DEVICE_PICKER = false;

require __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="pg-card"><div class="pg-card-body">
            <p class="pg-metric-label">Accounts</p><p class="pg-metric-value" id="sumTotal">--</p>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="pg-card"><div class="pg-card-body">
            <p class="pg-metric-label">Farm Owners</p><p class="pg-metric-value" id="sumOwners">--</p>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="pg-card"><div class="pg-card-body">
            <p class="pg-metric-label">Farm Staff</p><p class="pg-metric-value" id="sumStaff">--</p>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="pg-card"><div class="pg-card-body">
            <p class="pg-metric-label">Inactive</p>
            <p class="pg-metric-value text-secondary" id="sumInactive">--</p>
        </div></div>
    </div>
</div>

<!-- Accounts -->
<div class="pg-card mb-3">
    <div class="pg-card-head"><h2>User accounts</h2></div>
    <div class="pg-table-wrap">
        <table class="table pg-table mb-0">
            <thead>
                <tr>
                    <th>Name</th><th>Username</th><th>Email</th>
                    <th>Role</th><th>Status</th><th>Last sign-in</th><th>Actions</th>
                </tr>
            </thead>
            <tbody id="userBody">
                <tr><td colspan="7" class="text-center pg-muted py-4">Loading&hellip;</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Activity log -->
<div class="pg-card">
    <div class="pg-card-head">
        <h2>Recent activity</h2>
        <span class="small pg-muted">Last 30 entries</span>
    </div>
    <div class="pg-table-wrap">
        <table class="table pg-table mb-0">
            <thead>
                <tr><th>When</th><th>User</th><th>Action</th><th>Description</th><th>IP</th></tr>
            </thead>
            <tbody id="logBody">
                <tr><td colspan="5" class="text-center pg-muted py-4">Loading&hellip;</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ===================== Add / edit user modal ===================== -->
<div class="modal fade" id="userModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="userForm" novalidate>
      <div class="modal-header">
        <h5 class="modal-title" id="userModalTitle">Add user</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="uUserId">

        <div class="mb-3">
          <label class="form-label small fw-semibold" for="uName">Full name</label>
          <input type="text" class="form-control" id="uName" maxlength="100" required>
        </div>

        <div class="mb-3" id="usernameGroup">
          <label class="form-label small fw-semibold" for="uUsername">Username</label>
          <input type="text" class="form-control" id="uUsername" maxlength="50">
          <div class="form-text">Letters, digits, dots, hyphens and underscores. Cannot be changed later.</div>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold" for="uEmail">Email (optional)</label>
          <input type="email" class="form-control" id="uEmail" maxlength="120">
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold" for="uRole">Role</label>
          <select class="form-select" id="uRole" required>
            <option value="staff">Farm Staff &mdash; operates the equipment</option>
            <option value="owner">Farm Owner &mdash; oversees and reports</option>
          </select>
        </div>

        <div class="mb-3" id="passwordGroup">
          <label class="form-label small fw-semibold" for="uPassword">Password</label>
          <input type="password" class="form-control" id="uPassword" autocomplete="new-password">
          <div class="form-text">
            At least 8 characters, including a letter and a number.
            Stored only as a bcrypt hash.
          </div>
        </div>

        <div class="alert alert-danger py-2 px-3 small d-none" id="userError"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" id="userSaveBtn">Save user</button>
      </div>
    </form>
  </div>
</div>

<!-- ===================== Reset password modal ===================== -->
<div class="modal fade" id="passwordModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="passwordForm" novalidate>
      <div class="modal-header">
        <h5 class="modal-title">Reset password</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="pUserId">
        <p class="small">Setting a new password for <strong id="pUserName"></strong>.</p>
        <div class="mb-2">
          <label class="form-label small fw-semibold" for="pPassword">New password</label>
          <input type="password" class="form-control" id="pPassword" autocomplete="new-password" required>
          <div class="form-text">At least 8 characters, including a letter and a number.</div>
        </div>
        <div class="alert alert-danger py-2 px-3 small d-none" id="passwordError"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-warning" id="passwordSaveBtn">Reset password</button>
      </div>
    </form>
  </div>
</div>

<script>
window.PG_PAGE = { currentUserId: <?= (int)$CURRENT_USER['user_id'] ?> };
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
