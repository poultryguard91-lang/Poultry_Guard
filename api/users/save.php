<?php
/**
 * POST /api/users/save.php
 *
 * User administration. Body: { "action": "create"|"update"|"toggle"|"password", ... }
 *
 * Requires the users.manage permission (Farm Owner). Passwords are only ever
 * written through password_hash(); nothing here can read an existing one back.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

api_require_method('POST');

/** Minimum password rules applied to every new or changed password. */
function validate_password(string $password): string
{
    if (mb_strlen($password) < 8) {
        throw new ValidationException('The password must be at least 8 characters long.');
    }
    if (mb_strlen($password) > 200) {
        throw new ValidationException('The password is too long.');
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        throw new ValidationException('The password must contain at least one letter and one number.');
    }
    return $password;
}

api_run(function (array $in): ApiResult {
    $actor = require_permission('users.manage');
    require_csrf($in);

    $action = v_enum($in['action'] ?? null, 'Action', ['create', 'update', 'toggle', 'password']);

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------
    if ($action === 'create') {
        $name     = v_string($in['name'] ?? null, 'Full name', 2, 100);
        $username = v_string($in['username'] ?? null, 'Username', 3, 50);
        $email    = v_string($in['email'] ?? null, 'Email address', 0, 120, '');
        $role     = v_enum($in['role'] ?? null, 'Role', [ROLE_OWNER, ROLE_STAFF]);
        $password = validate_password((string)($in['password'] ?? ''));

        if (!preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
            throw new ValidationException('The username may only contain letters, digits, dots, hyphens and underscores.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Please enter a valid email address.');
        }

        $dup = db()->prepare('SELECT user_id FROM users WHERE username = ?');
        $dup->execute([$username]);
        if ($dup->fetchColumn() !== false) {
            throw new ValidationException('That username is already taken.');
        }
        if ($email !== '') {
            $dupE = db()->prepare('SELECT user_id FROM users WHERE email = ?');
            $dupE->execute([$email]);
            if ($dupE->fetchColumn() !== false) {
                throw new ValidationException('That email address is already registered.');
            }
        }

        db()->prepare(
            'INSERT INTO users (name, username, email, password, role, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $name, $username, $email !== '' ? $email : null,
            password_hash($password, PASSWORD_DEFAULT), $role, 'active',
        ]);
        $userId = (int)db()->lastInsertId();

        log_activity('USER_CREATED', "User account {$username} ({$role}) created.", (int)$actor['user_id']);

        return api_ok(['user_id' => $userId], 'User account created successfully', 201);
    }

    // Remaining actions target an existing account.
    $userId = v_int($in['user_id'] ?? null, 'User', 1, PHP_INT_MAX);
    $st = db()->prepare('SELECT * FROM users WHERE user_id = ?');
    $st->execute([$userId]);
    $target = $st->fetch();
    if (!$target) {
        throw new NotFoundException('That user account could not be found.');
    }

    // ------------------------------------------------------------------
    // Update details / role
    // ------------------------------------------------------------------
    if ($action === 'update') {
        $name  = v_string($in['name'] ?? null, 'Full name', 2, 100, (string)$target['name']);
        $email = v_string($in['email'] ?? null, 'Email address', 0, 120, (string)($target['email'] ?? ''));
        $role  = v_enum($in['role'] ?? null, 'Role', [ROLE_OWNER, ROLE_STAFF], (string)$target['role']);

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Please enter a valid email address.');
        }
        if ($email !== '') {
            $dupE = db()->prepare('SELECT user_id FROM users WHERE email = ? AND user_id <> ?');
            $dupE->execute([$email, $userId]);
            if ($dupE->fetchColumn() !== false) {
                throw new ValidationException('That email address belongs to another account.');
            }
        }

        // Never allow the last active owner to lose the owner role: someone
        // has to be able to manage users and devices.
        if ($target['role'] === ROLE_OWNER && $role !== ROLE_OWNER && count_active_owners($userId) === 0) {
            throw new ValidationException('This is the only active Farm Owner account. Promote another owner before changing this one.');
        }

        db()->prepare('UPDATE users SET name = ?, email = ?, role = ? WHERE user_id = ?')
            ->execute([$name, $email !== '' ? $email : null, $role, $userId]);

        log_activity('USER_UPDATED', "User account {$target['username']} updated.", (int)$actor['user_id']);

        return api_ok(['user_id' => $userId], 'User account updated successfully');
    }

    // ------------------------------------------------------------------
    // Activate / deactivate
    // ------------------------------------------------------------------
    if ($action === 'toggle') {
        if ($userId === (int)$actor['user_id']) {
            throw new ValidationException('You cannot deactivate your own account.');
        }
        $status = $target['status'] === 'active' ? 'inactive' : 'active';

        if ($status === 'inactive' && $target['role'] === ROLE_OWNER && count_active_owners($userId) === 0) {
            throw new ValidationException('This is the only active Farm Owner account and cannot be deactivated.');
        }

        db()->prepare('UPDATE users SET status = ? WHERE user_id = ?')->execute([$status, $userId]);

        log_activity('USER_' . strtoupper($status),
            "User account {$target['username']} set to {$status}.", (int)$actor['user_id']);

        return api_ok(['user_id' => $userId, 'status' => $status],
            $status === 'active' ? 'Account activated' : 'Account deactivated');
    }

    // ------------------------------------------------------------------
    // Reset password
    // ------------------------------------------------------------------
    $password = validate_password((string)($in['password'] ?? ''));

    db()->prepare('UPDATE users SET password = ? WHERE user_id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);

    log_activity('USER_PASSWORD_RESET',
        "Password reset for user account {$target['username']}.", (int)$actor['user_id']);

    return api_ok(['user_id' => $userId], 'Password updated successfully');
});

/** How many active owners exist, ignoring one account. */
function count_active_owners(int $excludingUserId): int
{
    $st = db()->prepare(
        "SELECT COUNT(*) FROM users WHERE role = ? AND status = 'active' AND user_id <> ?"
    );
    $st->execute([ROLE_OWNER, $excludingUserId]);
    return (int)$st->fetchColumn();
}
