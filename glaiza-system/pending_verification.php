<?php
session_start();
require_once 'includes/db.php';

if (empty($_SESSION['resident_id'])) {
    header('Location: index.php');
    exit;
}

$resident = dbFetchOne(
    "SELECT id, first_name, last_name, barangay_id, status FROM residents WHERE id=? LIMIT 1",
    [$_SESSION['resident_id']]
);

if (!$resident) {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}

if ($resident['status'] === 'Verified') {
    header('Location: resident_portal.php');
    exit;
}

$fullName = trim(($resident['first_name'] ?? '') . ' ' . ($resident['last_name'] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Pending Verification</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <?php renderThemeStyle(); ?>
</head>
<body>
<div class="login-wrap">
  <div class="login-card" style="max-width:760px;">
    <div class="login-left">
      <div class="brand">
        <div class="logo"><img src="<?= getSiteLogo() ?>" alt="ALAB-SI Logo"></div>
        <div>
          <h2>Resident Verification</h2>
          <p class="text-muted">Your account needs approval before access is granted.</p>
        </div>
      </div>
      <div style="margin-top:28px">
        <h3>Thank you for registering.</h3>
        <p>Your account is currently under review by the barangay staff.</p>
      </div>
    </div>
    <div class="login-right" style="padding:28px;">
      <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 16px;margin-bottom:18px;box-shadow:0 1px 2px rgba(0,0,0,0.03);">
        <h3 style="margin:0 0 8px; color:#111827;">Pending Approval</h3>
        <p style="margin:0; color:#374151; line-height:1.6;">
          Hello <strong><?= htmlspecialchars($fullName ?: 'Resident') ?></strong>,<br>
          your account is not yet verified. Please wait for barangay staff to confirm your details.
        </p>
      </div>

      <div style="margin-bottom:16px;">
        <div class="form-group" style="margin-bottom:10px;">
          <label>Resident ID</label>
          <input class="form-control" value="<?= htmlspecialchars($resident['barangay_id'] ?? '') ?>" readonly>
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label>Status</label>
          <input class="form-control" value="Pending" readonly style="background:#fff;color:#111827;border-color:#d1d5db;">
        </div>
      </div>

      <div style="padding:16px;background:#f3f4f6;border-radius:10px;border:1px solid #e5e7eb;">
        <h4 style="margin:0 0 10px;">What to do next</h4>
        <ul style="margin:0;padding-left:18px;line-height:1.8; color:var(--text-muted);">
           <li>Bring any valid identification document to the barangay office, such as a government ID or other accepted ID.</li>
          <li>Wait for staff verification to approve your account.</li>
          <li>Once approved, you can log in normally to the resident dashboard.</li>
        </ul>
      </div>

      <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap;">
        <a href="logout.php" class="btn btn-secondary">Logout</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
