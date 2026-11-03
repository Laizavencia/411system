<?php
// Simple Terms of Service placeholder
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/site_settings.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Terms of Service — Barangay Talisay</title>
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
        <p class="text-muted">Read our Terms of Service</p>
      </div>
    </div>
    <div class="login-right" style="max-height:80vh;overflow-y:auto;justify-content:flex-start !important;padding:28px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
        <h3>Terms of Service</h3>
        <a href="signup.php" class="text-muted" style="font-size:13px;text-decoration:none">Back to Sign Up</a>
      </div>

      <div style="line-height:1.6;color:var(--text,#111);">
        <p>Welcome to the Barangay Talisay resident portal. These Terms of Service explain how you may use this site and what we expect from users. By creating an account and using the portal you agree to these terms.</p>

        <h4>Using the Service</h4>
        <p>Use the portal to access barangay services, request documents, and manage your resident information. Use the service only for lawful purposes and provide accurate information when prompted.</p>

        <h4>Your Account</h4>
        <p>Keep your account credentials secure. You are responsible for activity that happens under your account. If you suspect unauthorized access, contact the barangay administration immediately.</p>

        <h4>Acceptable Use</h4>
        <p>Do not attempt to access areas of the site you are not authorized to use, and do not submit content that is abusive, illegal, or offensive. The barangay may suspend or remove accounts that violate these rules.</p>

        <h4>Privacy</h4>
        <p>We collect and store the personal information you provide to operate the service. For details about what we collect and how we use it, see our <a href="privacy.php">Privacy Policy</a>.</p>

        <h4>Changes & Termination</h4>
        <p>We may update these terms or the service from time to time. The barangay may suspend or terminate accounts for misuse or inactivity.</p>

        <h4>Disclaimers</h4>
        <p>The portal is provided as-is. We strive to keep the service available and accurate, but we do not guarantee uninterrupted access or error-free content.</p>
      </div>
    </div>
  </div>
</div>
</body>
</html>
