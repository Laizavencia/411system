<?php
// ============================================================
// forgot_password.php  — Resident: Forgot password info page
// No online reset. Residents must visit the barangay office in
// person; staff verifies their ID and issues a temporary password
// via my_inhabitants.php on the admin side.
// ============================================================
session_start();
require_once 'includes/db.php';

$settings = getSiteSettings();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Forgot Password — Barangay Talisay</title>
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
        <h3>Forgot Your Password?</h3>
        <p>For your security, we don't reset passwords online.</p>
      </div>
    </div>
    <div class="login-right">
      <h3>Visit the Barangay Office</h3>
      <div style="background:#dbeafe;color:#1e40af;padding:14px 16px;border-radius:10px;margin-bottom:18px;font-size:14px;line-height:1.6">
        Please visit <strong><?= htmlspecialchars($settings['barangay_name'] ?? 'the barangay office') ?></strong> in person
        with a valid government-issued ID. Staff will verify your identity and give you a new temporary password
        on the spot, which you'll be asked to change the first time you sign in.
      </div>
      <div style="font-size:14px;color:var(--text-light);line-height:1.9">
        <?php if (!empty($settings['address'])): ?>
        <div>📍 <?= htmlspecialchars($settings['address']) ?></div>
        <?php endif; ?>
        <?php if (!empty($settings['contact_number'])): ?>
        <div>📞 <?= htmlspecialchars($settings['contact_number']) ?></div>
        <?php endif; ?>
        <div>🕐 Office Hours: Mon–Fri, 8:00 AM – 5:00 PM</div>
      </div>
      <div class="login-footer text-muted" style="margin-top:24px">
        <a href="index.php" style="color:var(--primary);font-weight:600;text-decoration:none">← Back to Sign In</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>