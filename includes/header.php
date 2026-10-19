<?php
/**
 * PoultryGuard - page shell (opening half).
 *
 * A page sets these before including this file:
 *   $PAGE_TITLE    string  heading shown at the top of the content area
 *   $PAGE_SUBTITLE string  one-line description under the heading (optional)
 *   $NAV_ACTIVE    string  key of the sidebar item to highlight
 *   $PAGE_ACTIONS  string  raw HTML for buttons on the right of the heading (optional)
 *   $SHOW_DEVICE_PICKER bool  default true
 *
 * The page must already have called require_page_auth() so that the session
 * and permissions are settled before any output is sent.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$CURRENT_USER = require_page_auth();

$PAGE_TITLE    = $PAGE_TITLE    ?? APP_NAME;
$PAGE_SUBTITLE = $PAGE_SUBTITLE ?? '';
$NAV_ACTIVE    = $NAV_ACTIVE    ?? '';
$PAGE_ACTIONS  = $PAGE_ACTIONS  ?? '';
$SHOW_DEVICE_PICKER = $SHOW_DEVICE_PICKER ?? true;

/**
 * Resolve which device the page is showing. The choice comes from the URL
 * when present, otherwise from the session, otherwise the first active
 * device - and it is remembered so it survives navigating between pages.
 */
$ALL_DEVICES    = get_devices();
$CURRENT_DEVICE = null;

if ($ALL_DEVICES !== []) {
    $requested = isset($_GET['device_id']) && is_numeric($_GET['device_id'])
        ? (int)$_GET['device_id']
        : (int)($_SESSION['device_id'] ?? 0);

    foreach ($ALL_DEVICES as $d) {
        if ((int)$d['device_id'] === $requested) {
            $CURRENT_DEVICE = $d;
            break;
        }
    }
    if ($CURRENT_DEVICE === null) {
        foreach ($ALL_DEVICES as $d) {
            if ($d['status'] === 'active') {
                $CURRENT_DEVICE = $d;
                break;
            }
        }
        $CURRENT_DEVICE ??= $ALL_DEVICES[0];
    }
    $_SESSION['device_id'] = (int)$CURRENT_DEVICE['device_id'];
}

$CURRENT_DEVICE_ID = $CURRENT_DEVICE ? (int)$CURRENT_DEVICE['device_id'] : null;
$DEVICE_SETTINGS   = $CURRENT_DEVICE_ID ? get_settings($CURRENT_DEVICE_ID) : null;
$DEVICE_ONLINE     = $CURRENT_DEVICE
    ? device_is_online($CURRENT_DEVICE['last_seen'], (int)($DEVICE_SETTINGS['offline_timeout'] ?? DEFAULT_OFFLINE_TIMEOUT))
    : false;
$UNREAD_ALERTS = unread_alert_count();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($PAGE_TITLE) ?> &middot; <?= e(APP_NAME) ?></title>
<link rel="icon" type="image/png" href="<?= e(asset_url('assets/img/favicon.png')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
</head>
<body>

<!-- =============================== Top bar =============================== -->
<header class="pg-topbar">
    <button class="btn btn-sm btn-light d-lg-none" id="pgSidebarToggle" aria-label="Toggle navigation">
        <span aria-hidden="true">&#9776;</span>
    </button>

    <a class="pg-brand" href="<?= e(url('dashboard/')) ?>">
        <img class="pg-brand-mark" src="<?= e(asset_url('assets/img/logo-mark.png')) ?>" alt="" aria-hidden="true">
        <?php
        // The logo sets "Guard" in the brighter green; mirror that in the wordmark.
        $brandHead = APP_NAME;
        $brandTail = '';
        if (str_ends_with(APP_NAME, 'Guard')) {
            $brandHead = substr(APP_NAME, 0, -5);
            $brandTail = 'Guard';
        }
        ?>
        <span class="d-none d-sm-inline"><?= e($brandHead) ?><span class="pg-brand-accent"><?= e($brandTail) ?></span></span>
    </a>

    <div class="ms-auto d-flex align-items-center gap-2">

        <?php if ($SHOW_DEVICE_PICKER && count($ALL_DEVICES) > 0): ?>
            <span class="pg-live <?= $DEVICE_ONLINE ? '' : 'is-offline' ?> d-none d-md-inline-flex" id="pgLiveBadge">
                <span class="dot" aria-hidden="true"></span>
                <span id="pgLiveText"><?= $DEVICE_ONLINE ? 'Device online' : 'Device offline' ?></span>
            </span>

            <?php if (count($ALL_DEVICES) > 1): ?>
                <label class="visually-hidden" for="pgDeviceSelect">Select device</label>
                <select class="form-select form-select-sm" id="pgDeviceSelect" style="max-width: 210px;">
                    <?php foreach ($ALL_DEVICES as $d): ?>
                        <option value="<?= (int)$d['device_id'] ?>"
                            <?= (int)$d['device_id'] === $CURRENT_DEVICE_ID ? 'selected' : '' ?>>
                            <?= e($d['device_name']) ?><?= $d['status'] !== 'active' ? ' (disabled)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        <?php endif; ?>

        <a href="<?= e(url('alerts/')) ?>" class="btn btn-sm btn-light position-relative" title="Alerts">
            <span aria-hidden="true">&#128276;</span>
            <span class="visually-hidden">Alerts</span>
            <?php if ($UNREAD_ALERTS > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                      id="pgAlertBadge"><?= $UNREAD_ALERTS > 99 ? '99+' : $UNREAD_ALERTS ?></span>
            <?php else: ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none"
                      id="pgAlertBadge">0</span>
            <?php endif; ?>
        </a>

        <div class="dropdown">
            <button class="btn btn-sm btn-light dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="d-none d-sm-inline"><?= e($CURRENT_USER['name']) ?></span>
                <span class="d-sm-none" aria-hidden="true">&#128100;</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li class="dropdown-header">
                    <?= e($CURRENT_USER['name']) ?><br>
                    <span class="pg-muted small">
                        <?= $CURRENT_USER['role'] === ROLE_OWNER ? 'Farm Owner' : 'Farm Staff' ?>
                    </span>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= e(url('settings/')) ?>">System settings</a></li>
                <?php if (user_can('users.manage')): ?>
                    <li><a class="dropdown-item" href="<?= e(url('users/')) ?>">User management</a></li>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="<?= e(url('logout.php')) ?>" id="pgLogout">Sign out</a></li>
            </ul>
        </div>
    </div>
</header>

<?php require __DIR__ . '/sidebar.php'; ?>

<!-- =============================== Content =============================== -->
<main class="pg-main">

    <!-- Only visible when the page is printed -->
    <div class="pg-print-header">
        <img class="pg-print-logo mb-2" src="<?= e(asset_url('assets/img/logo.png')) ?>" alt="<?= e(APP_NAME) ?>">
        <h2 class="mb-1"><?= e($PAGE_TITLE) ?></h2>
        <p class="mb-0 small">
            <?php if ($CURRENT_DEVICE): ?>
                Device: <?= e($CURRENT_DEVICE['device_name']) ?>
                <?= $CURRENT_DEVICE['location'] ? '(' . e($CURRENT_DEVICE['location']) . ')' : '' ?> &middot;
            <?php endif; ?>
            Generated <?= e(date('M j, Y g:i A')) ?> by <?= e($CURRENT_USER['name']) ?>
        </p>
        <hr>
    </div>

    <div class="pg-page-head">
        <div>
            <h1><?= e($PAGE_TITLE) ?></h1>
            <?php if ($PAGE_SUBTITLE !== ''): ?>
                <p><?= e($PAGE_SUBTITLE) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($PAGE_ACTIONS !== ''): ?>
            <div class="d-flex gap-2 flex-wrap pg-no-print"><?= $PAGE_ACTIONS ?></div>
        <?php endif; ?>
    </div>

    <?php if ($msg = flash('success')): ?>
        <div class="alert alert-success alert-dismissible fade show pg-no-print">
            <?= e($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($msg = flash('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show pg-no-print">
            <?= e($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['denied'])): ?>
        <div class="alert alert-warning alert-dismissible fade show pg-no-print">
            <strong>Unauthorised access.</strong>
            Your role does not have permission to open that page.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($ALL_DEVICES === []): ?>
        <div class="alert alert-warning pg-no-print">
            <strong>No devices registered yet.</strong>
            <?php if (user_can('devices.manage')): ?>
                Add your ESP32 controller on the <a href="<?= e(url('devices/')) ?>">Devices</a> page
                to start collecting sensor data.
            <?php else: ?>
                Ask the farm owner to register an ESP32 controller before using this page.
            <?php endif; ?>
        </div>
    <?php endif; ?>
