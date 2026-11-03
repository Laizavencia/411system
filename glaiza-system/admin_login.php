<?php
// ============================================================
// admin_login.php  — Admin authentication
// ============================================================
session_start();
require_once 'includes/db.php';

function ensureAdminMustChangePasswordColumn(): void {
    $exists = dbFetchOne("SHOW COLUMNS FROM admin_users LIKE 'must_change_password'");
    if (!$exists) {
        try {
            dbExecute("ALTER TABLE admin_users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash");
        } catch (Throwable $e) {
            // Ignore migration errors here; the app can still function if the column is missing.
        }
    }
}

ensureAdminMustChangePasswordColumn();

// If already logged in (or a valid "remember me" cookie restores the
// session), redirect straight to the dashboard instead of showing the
// login form again.
if (!empty($_SESSION['admin_id']) || attemptAutoLogin('admin')) {
    if (!empty($_SESSION['force_pwd_change'])) {
        header('Location: change_password_required.php');
        exit;
    }

    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else {
        $admin = dbFetchOne(
            "SELECT * FROM admin_users WHERE (username=? OR email=?) AND status='Active' LIMIT 1",
            [$username, $username]
        );

        if ($admin && password_verify($password, $admin['password_hash'])) {
            recordSuccessfulLogin($username, 'admin');

            $_SESSION['admin_id']   = $admin['id'];
            $_SESSION['admin_name'] = $admin['name'];
            $_SESSION['admin_role'] = $admin['role'];

            $forcePwdChange = false;
            if (isset($admin['must_change_password'])) {
                $forcePwdChange = (bool) $admin['must_change_password'];
            } elseif (password_verify('admin123', $admin['password_hash'])) {
                $forcePwdChange = true;
            }

            if ($forcePwdChange) {
                $_SESSION['force_pwd_change'] = true;
                if (!empty($_POST['remember'])) {
                    issueRememberToken('admin', $admin['id']);
                }
                logActivity('Admin login', 'Auth');
                header('Location: change_password_required.php');
                exit;
            }

            if (!empty($_POST['remember'])) {
                issueRememberToken('admin', $admin['id']);
            }
            logActivity('Admin login', 'Auth');
            header('Location: dashboard.php');
            exit;
        }

        recordFailedLogin($username, 'admin');
        $error = 'Invalid username or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin Login — Barangay Talisay</title>
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
        <h3>Admin Access</h3>
        <p>Sign in with admin credentials to access the management dashboard.</p>
      </div>
    </div>
    <div class="login-right">
      <h3>Admin Sign In</h3>
      <?php if ($error): ?>
        <div style="background:#fee2e2;color:#991b1b;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:14px;">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>
      <form method="POST">
        <div class="form-group">
          <input class="form-control" name="username" placeholder="Admin Username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </div>
        <div class="form-group">
          <div style="position:relative">
            <input class="form-control" name="password" id="loginPwd" placeholder="Password" type="password" required style="padding-right:44px">
            <span onclick="togglePwd('loginPwd', this)" aria-label="Show password" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);cursor:pointer;user-select:none;display:flex;color:#6b7280">
              <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.6 18.6 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </span>
          </div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <label class="remember"><input type="checkbox" name="remember" value="1" checked> Remember me</label>
        </div>
        <div class="login-actions">
          <button type="submit" class="btn btn-primary">Login</button>
        </div>
        <div class="login-footer text-muted">
          Are you a resident? <a href="index.php" style="color:var(--primary);font-weight:600;text-decoration:none">Resident Login</a>
        </div>
      </form>
    </div>
  </div>
</div>
<script src="assets/js/app.js"></script>
<script>
const EYE_OPEN  = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>';
const EYE_OFF   = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.6 18.6 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
function togglePwd(id, icon) {
  const input = document.getElementById(id);
  if (input.type === 'password') {
    input.type = 'text';
    icon.innerHTML = EYE_OPEN;
    icon.setAttribute('aria-label', 'Hide password');
  } else {
    input.type = 'password';
    icon.innerHTML = EYE_OFF;
    icon.setAttribute('aria-label', 'Show password');
  }
}
</script>
</body>
</html>
