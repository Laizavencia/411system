<?php
session_start();
require_once 'includes/db.php';

function ensurePasswordChangeFlagColumn(string $table): void {
    $exists = dbFetchOne("SHOW COLUMNS FROM $table LIKE 'must_change_password'");
    if (!$exists) {
        try {
            dbExecute("ALTER TABLE $table ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash");
        } catch (Throwable $e) {
            // Ignore migration errors; the feature will be unavailable until the DB is updated.
        }
    }
}

$userType = !empty($_SESSION['admin_id']) ? 'admin' : (!empty($_SESSION['resident_id']) ? 'resident' : null);
if (!$userType) { header('Location: index.php'); exit; }

if ($userType === 'admin') {
    ensurePasswordChangeFlagColumn('admin_users');
} else {
    ensurePasswordChangeFlagColumn('residents');
}

// Nothing to do if they don't actually need to change it — send them onward.
if (empty($_SESSION['force_pwd_change'])) {
    $forceChangeFromDb = false;
    if ($userType === 'admin') {
        $admin = dbFetchOne("SELECT must_change_password, password_hash FROM admin_users WHERE id=?", [$_SESSION['admin_id']]);
        if ($admin && (!empty($admin['must_change_password']) || password_verify('admin123', $admin['password_hash']))) {
            $forceChangeFromDb = true;
        }
    } else {
        $resident = dbFetchOne("SELECT must_change_password FROM residents WHERE id=?", [$_SESSION['resident_id']]);
        if ($resident && !empty($resident['must_change_password'])) {
            $forceChangeFromDb = true;
        }
    }

    if (!$forceChangeFromDb) {
        header('Location: ' . ($userType === 'admin' ? 'dashboard.php' : 'resident_portal.php'));
        exit;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($userType === 'admin') {
        $userId = $_SESSION['admin_id'];
        $row    = dbFetchOne("SELECT password_hash FROM admin_users WHERE id=?", [$userId]);
        $table  = 'admin_users';
    } else {
        $userId = $_SESSION['resident_id'];
        $row    = dbFetchOne("SELECT password_hash FROM residents WHERE id=?", [$userId]);
        $table  = 'residents';
    }

    if (!$row || !password_verify($current, $row['password_hash'])) {
        $error = 'The temporary password you entered is incorrect.';
    } elseif (strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (password_verify($new, $row['password_hash'])) {
        $error = 'Please choose a password different from your temporary one.';
    } else {
        dbExecute("UPDATE $table SET password_hash=?, must_change_password=0 WHERE id=?",
            [password_hash($new, PASSWORD_BCRYPT), $userId]);
        $_SESSION['force_pwd_change'] = false;
        logActivity('Completed forced password change', 'Auth', $userType);
        header('Location: ' . ($userType === 'admin' ? 'dashboard.php' : 'resident_portal.php'));
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Set a New Password — Barangay Talisay</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <?php renderThemeStyle(); ?>
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-left">
      <div class="brand">
        <div class="logo"><img src="<?= getSiteLogo() ?>" alt="Logo"></div>
        <div>
          <h2>Local Assistance for Barangay Services</h2>
          <p class="text-muted">Barangay administration portal</p>
        </div>
      </div>
      <div style="margin-top:28px">
        <h3>One Last Step</h3>
        <p>You're signing in with a temporary password. Set a permanent password to continue.</p>
      </div>
    </div>
    <div class="login-right">
      <h3>Set New Password</h3>
      <?php if ($error): ?>
        <div style="background:#fee2e2;color:#991b1b;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:14px;">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>
      <form method="POST">
        <div class="form-group">
          <input class="form-control" name="current_password" type="password" placeholder="Temporary password" required>
        </div>
        <div class="form-group">
          <input class="form-control" name="new_password" type="password" placeholder="New password (min. 8 characters)" required>
        </div>
        <div class="form-group">
          <input class="form-control" name="confirm_password" type="password" placeholder="Confirm new password" required>
        </div>
        <button type="submit" class="btn btn-primary">Set Password &amp; Continue</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>