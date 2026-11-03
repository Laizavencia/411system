<?php
// Simple Privacy Policy placeholder
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/site_settings.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Privacy Policy — Barangay Talisay</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <?php renderThemeStyle(); ?>
</head>
<body>
<div class="login-wrap">
  <div class="login-card" style="max-width:980px;">
    <div class="login-left">
      <div class="brand">
        <div class="logo"><img src="<?= getSiteLogo() ?>" alt="Logo"></div>
        <div>
          <h2><?= getSiteName() ?></h2>
          <p class="text-muted">Resident Portal</p>
        </div>
      </div>
      <div style="margin-top:24px">
        <p class="text-muted">Read our Privacy Policy</p>
      </div>
    </div>
    <div class="login-right" style="max-height:80vh;overflow-y:auto;justify-content:flex-start !important;padding:28px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
        <h3>Privacy Policy</h3>
        <a href="signup.php" class="text-muted" style="font-size:13px;text-decoration:none">Back to Sign Up</a>
      </div>

      <div style="line-height:1.6;color:var(--text,#111);">
        <p>Barangay Talisay respects your privacy. This page explains what personal information we collect when you use the portal and how we use and protect it.</p>

        <h4>Information We Collect</h4>
        <p>When you register or use the portal we may collect personal information such as your name, email, phone number, date of birth, address, and any documents you upload to request services.</p>

        <h4>How We Use Your Data</h4>
        <p>We use your information to provide barangay services, process document requests, and communicate with you about your account or requests. We do not sell your personal data.</p>

        <h4>Sharing and Disclosure</h4>
        <p>We may share information with authorized barangay staff or third parties where necessary to fulfill requests (for example, to issue official documents). We only share the minimum required information.</p>

        <h4>Security</h4>
        <p>We take reasonable measures to protect your data, including storing passwords hashed and using access controls. No system is perfectly secure — if you suspect a data issue, contact the barangay administration.</p>

        <h4>Your Rights</h4>
        <p>You can request access to, correction of, or deletion of your personal information by contacting the barangay administration. Some records required by law may need to be retained.</p>

        <p><a href="signup.php">Back to Sign Up</a></p>
      </div>
    </div>
  </div>
</div>
</body>
</html>
