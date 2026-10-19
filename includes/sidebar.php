<?php
/**
 * PoultryGuard - sidebar navigation.
 *
 * The menu is generated from a single declarative tree. Each entry names the
 * permission required to see it, so a Farm Staff account simply never renders
 * the Reports or Users sections - and because the same permission strings guard
 * the pages and the API endpoints, the menu can never offer something the user
 * would be refused.
 *
 * Expects $NAV_ACTIVE, $CURRENT_DEVICE_ID and $UNREAD_ALERTS from header.php.
 */

declare(strict_types=1);

/** Keep the selected device when moving between pages. */
$navUrl = static function (string $path) use ($CURRENT_DEVICE_ID): string {
    $u = url($path);
    return $CURRENT_DEVICE_ID ? $u . (str_contains($u, '?') ? '&' : '?') . 'device_id=' . $CURRENT_DEVICE_ID : $u;
};

/**
 * section => label for a group heading (null for a standalone link)
 * items    => key, label, path, permission, icon, badge
 */
$NAV = [
    [
        'section' => null,
        'items' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'path' => 'dashboard/',
             'perm' => 'dashboard.view', 'icon' => '&#9632;'],
        ],
    ],
    [
        'section' => 'Monitoring',
        'items' => [
            ['key' => 'monitoring.water', 'label' => 'Water', 'path' => 'monitoring/water.php',
             'perm' => 'monitoring.view', 'icon' => '&#128167;'],
        ],
    ],
    [
        'section' => 'Automation',
        'items' => [
            ['key' => 'automation.feed', 'label' => 'Feed Control', 'path' => 'automation/feed.php',
             'perm' => 'automation.control', 'icon' => '&#9881;'],
            ['key' => 'automation.water', 'label' => 'Water Control', 'path' => 'automation/water.php',
             'perm' => 'automation.control', 'icon' => '&#9881;'],
        ],
    ],
    [
        'section' => 'Reports',
        'items' => [
            ['key' => 'reports.water', 'label' => 'Water', 'path' => 'reports/water.php',
             'perm' => 'reports.view', 'icon' => '&#128202;'],
            ['key' => 'reports.alerts', 'label' => 'System', 'path' => 'reports/alerts.php',
             'perm' => 'reports.view', 'icon' => '&#128202;'],
        ],
    ],
    [
        'section' => 'Management',
        'items' => [
            ['key' => 'alerts', 'label' => 'Alerts', 'path' => 'alerts/',
             'perm' => 'alerts.view', 'icon' => '&#128276;', 'badge' => $UNREAD_ALERTS],
            ['key' => 'devices', 'label' => 'Devices', 'path' => 'devices/',
             'perm' => 'devices.view', 'icon' => '&#128225;'],
            ['key' => 'settings', 'label' => 'Settings', 'path' => 'settings/',
             'perm' => 'settings.view', 'icon' => '&#9881;'],
            ['key' => 'users', 'label' => 'Users', 'path' => 'users/',
             'perm' => 'users.manage', 'icon' => '&#128101;'],
        ],
    ],
];
?>
<aside class="pg-sidebar" id="pgSidebar">
    <nav aria-label="Main navigation">
    <?php foreach ($NAV as $group): ?>
        <?php
        // Drop a whole section when the user can see none of its items.
        $visible = array_values(array_filter(
            $group['items'],
            static fn(array $i): bool => user_can($i['perm'])
        ));
        if ($visible === []) {
            continue;
        }
        ?>
        <?php if ($group['section'] !== null): ?>
            <div class="pg-nav-section"><?= e($group['section']) ?></div>
        <?php endif; ?>

        <?php foreach ($visible as $item): ?>
            <a class="pg-nav-link <?= $NAV_ACTIVE === $item['key'] ? 'active' : '' ?><?= $group['section'] !== null ? ' is-sub' : '' ?>"
               href="<?= e($navUrl($item['path'])) ?>"
               <?= $NAV_ACTIVE === $item['key'] ? 'aria-current="page"' : '' ?>>
                <span class="pg-ico" aria-hidden="true"><?= $item['icon'] ?></span>
                <span><?= e($item['label']) ?></span>
                <?php if (!empty($item['badge'])): ?>
                    <span class="pg-nav-badge" id="pgSidebarAlertBadge">
                        <?= (int)$item['badge'] > 99 ? '99+' : (int)$item['badge'] ?>
                    </span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </nav>

    <div class="pg-nav-section mt-3">Signed in as</div>
    <div class="pg-sidebar-user">
        <div class="name"><?= e($CURRENT_USER['name']) ?></div>
        <div><?= $CURRENT_USER['role'] === ROLE_OWNER ? 'Farm Owner' : 'Farm Staff' ?></div>
        <div class="ver"><?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?></div>
    </div>
</aside>
