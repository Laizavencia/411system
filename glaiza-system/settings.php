<?php
// ============================================================
// settings.php  — Admin: Barangay settings management
// ============================================================
session_start();
require_once 'includes/db.php';
require_once 'includes/site_settings.php';
requireAdminLogin();

$success = '';
$error   = '';

$defaultEmergencyHotlines = [
    ['Emergency (national)', '911'],
    ['Barangay Talisay Hotline', '(02) 555-0101'],
    ['Barangay Tanod 24/7', '(02) 555-0102'],
    ['Fire (BFP)', '(02) 555-0103'],
    ['Police (PNP)', '(02) 555-0104'],
    ['Health Center / Ambulance', '(02) 555-0105'],
];
$defaultBarangayFees = [
    ['Barangay Clearance', '₱50', 'Same day', 'Valid ID'],
    ['Certificate of Residency', '₱30', 'Same day', 'Valid ID, proof of address'],
    ['Certificate of Indigency', 'Free', 'Same day', 'Valid ID'],
    ['Business Permit', '₱300+', '2 working days', "DTI/Mayor's permit, valid ID, lease"],
    ['Barangay ID', '₱100', '3 working days (Tue, Thu)', 'Photo ID, proof of address'],
];
$defaultBarangayOffices = [
    ['Punong Barangay', 'Hon. Maria L. Santos', '(02) 555-0101', 'Mon–Fri 8–5'],
    ['Secretary (records)', 'Ramon C. Dela Cruz', '(02) 555-0102', 'Mon–Fri 8–5'],
    ['Treasurer (payments)', 'Elena P. Reyes', '(02) 555-0103', 'Mon–Fri 8–4'],
    ['Lupon (mediation)', 'Atty. Jose Aquino', '(02) 555-0104', 'Mon, Wed, Fri 9–4'],
    ['Health Center', 'Nurse Ana Ramos', '(02) 555-0105', 'Mon–Sat 8–5'],
    ['Tanod', 'Duty officer', '(02) 555-0102', '24/7'],
];
$defaultBarangayHours = [
    ['Mon–Fri', '8–5 General services'],
    ['Tue & Thu', 'Barangay ID applications'],
    ['Sat', '8–12 Health Center only'],
    ['Sun', 'Closed (emergency line open)'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Update barangay info ───────────────────────────────────
    if ($action === 'update_info') {
        $fields = [
            'barangay_name'  => trim($_POST['barangay_name']  ?? ''),
            'address'        => trim($_POST['address']        ?? ''),
            'contact_number' => trim($_POST['contact_number'] ?? ''),
            'email'          => trim($_POST['email']          ?? ''),
        ];

        if ($fields['email'] && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (!$fields['barangay_name']) {
            $error = 'Barangay name is required.';
        } else {
            foreach ($fields as $key => $val) {
                dbExecute(
                    "INSERT INTO barangay_settings (setting_key, setting_value)
                     VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = ?",
                    [$key, $val, $val]
                );
            }
            logActivity('Updated barangay settings', 'Settings');
            getSiteSettings(true);
            $success = 'Settings saved successfully!';
        }
    }

    // ── Update resident public info ────────────────────────────
    if ($action === 'update_public_info') {
        $hotlines = [];
        foreach (($_POST['hotline_label'] ?? []) as $i => $label) {
            $label = trim((string)$label);
            $number = trim((string)($_POST['hotline_number'][$i] ?? ''));
            if ($label !== '' || $number !== '') {
                $hotlines[] = [$label, $number];
            }
        }
        if (!$hotlines) {
            $hotlines = $defaultEmergencyHotlines;
        }

        $fees = [];
        foreach (($_POST['fee_name'] ?? []) as $i => $name) {
            $name = trim((string)$name);
            $amount = trim((string)($_POST['fee_amount'][$i] ?? ''));
            $time = trim((string)($_POST['fee_time'][$i] ?? ''));
            $requirements = trim((string)($_POST['fee_requirements'][$i] ?? ''));
            if ($name !== '' || $amount !== '' || $time !== '' || $requirements !== '') {
                $fees[] = [$name, $amount, $time, $requirements];
            }
        }
        if (!$fees) {
            $fees = $defaultBarangayFees;
        }

        $offices = [];
        foreach (($_POST['office_name'] ?? []) as $i => $name) {
            $name = trim((string)$name);
            $person = trim((string)($_POST['office_person'][$i] ?? ''));
            $phone = trim((string)($_POST['office_phone'][$i] ?? ''));
            $hours = trim((string)($_POST['office_hours'][$i] ?? ''));
            if ($name !== '' || $person !== '' || $phone !== '' || $hours !== '') {
                $offices[] = [$name, $person, $phone, $hours];
            }
        }
        if (!$offices) {
            $offices = $defaultBarangayOffices;
        }

        $hours = [];
        foreach (($_POST['schedule_day'] ?? []) as $i => $day) {
            $day = trim((string)$day);
            $details = trim((string)($_POST['schedule_details'][$i] ?? ''));
            if ($day !== '' || $details !== '') {
                $hours[] = [$day, $details];
            }
        }
        if (!$hours) {
            $hours = $defaultBarangayHours;
        }

        $jsonFields = [
            'emergency_hotlines' => $hotlines,
            'barangay_fees' => $fees,
            'barangay_offices' => $offices,
            'barangay_hours' => $hours,
        ];

        foreach ($jsonFields as $key => $value) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            dbExecute(
                "INSERT INTO barangay_settings (setting_key, setting_value)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = ?",
                [$key, $encoded, $encoded]
            );
        }

        logActivity('Updated public service information', 'Settings');
        getSiteSettings(true);
        $success = 'Public service information updated successfully!';
    }

    // ── Change admin password ──────────────────────────────────
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $admin = dbFetchOne("SELECT * FROM admin_users WHERE id=?", [$_SESSION['admin_id']]);

        if (!password_verify($current, $admin['password_hash'])) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $error = 'New passwords do not match.';
        } else {
            dbExecute(
                "UPDATE admin_users SET password_hash=? WHERE id=?",
                [password_hash($new, PASSWORD_BCRYPT), $_SESSION['admin_id']]
            );
            logActivity('Changed admin password', 'Settings');
            $success = 'Password updated successfully.';
        }
    }
}

$settings = getSiteSettings(true);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Settings — <?= getSiteName() ?></title>
  <link rel="stylesheet" href="assets/css/style.css">
  <?php renderThemeStyle(); ?>
</head>
<body>
<div class="app">
  <?php include 'includes/sidebar_admin.php'; ?>
  <main class="main">
    <div class="topbar">
      <div>
        <h3>Settings</h3>
        <div class="text-muted">Manage barangay information and account security.</div>
      </div>
    </div>

    <?php if ($success): ?>
      <div style="background:#dcfce7;color:#166534;padding:14px 18px;border-radius:10px;margin-bottom:20px;display:flex;align-items:center;gap:10px">
        <span style="font-size:18px">✅</span> <?= htmlspecialchars($success) ?>
      </div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div style="background:#fee2e2;color:#991b1b;padding:14px 18px;border-radius:10px;margin-bottom:20px;display:flex;align-items:center;gap:10px">
        <span style="font-size:18px">❌</span> <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <!-- ── Barangay Information ─────────────────────────────── -->
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="update_info">
      <div class="card">
        <h3>🏛️ Barangay Information</h3>
        <p class="text-muted" style="margin-bottom:18px">This information appears throughout the system including the resident portal office details.</p>
        <div class="form-grid">
          <div class="form-group">
            <label>Barangay Name *</label>
            <input type="text" name="barangay_name" class="form-control"
              value="<?= htmlspecialchars($settings['barangay_name'] ?? 'Barangay Talisay') ?>"
              required placeholder="e.g., Barangay Talisay">
          </div>
          <div class="form-group">
            <label>Address</label>
            <input type="text" name="address" class="form-control"
              value="<?= htmlspecialchars($settings['address'] ?? '') ?>"
              placeholder="e.g., 123 Sampaguita St., Zone 2">
          </div>
          <div class="form-group">
            <label>Contact Number</label>
            <input type="text" name="contact_number" class="form-control"
              value="<?= htmlspecialchars($settings['contact_number'] ?? '') ?>"
              placeholder="e.g., (02) 555-1234">
          </div>
          <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" class="form-control"
              value="<?= htmlspecialchars($settings['email'] ?? '') ?>"
              placeholder="e.g., info@barangay.gov.ph">
          </div>
        </div>
        <button class="btn btn-primary" type="submit" style="margin-top:8px">💾 Save Information</button>
      </div>
    </form>

    <!-- ── Public resident info ─────────────────────────────── -->
    <form method="POST" style="margin-top:20px">
      <input type="hidden" name="action" value="update_public_info">

      <div class="card">
        <h3>📞 Emergency Hotlines</h3>
        <div class="form-grid">
          <div class="form-group" style="grid-column:1 / -1">
            <div id="hotlines-list">
              <?php $hotlines = getJsonSetting('emergency_hotlines', $defaultEmergencyHotlines); foreach ($hotlines as $index => $hotline): ?>
                <div class="entry-row" style="display:grid;grid-template-columns:1.4fr 1fr auto;gap:8px;margin-bottom:8px">
                  <input type="text" name="hotline_label[]" class="form-control" value="<?= htmlspecialchars($hotline[0] ?? '') ?>" placeholder="Label">
                  <input type="text" name="hotline_number[]" class="form-control" value="<?= htmlspecialchars($hotline[1] ?? '') ?>" placeholder="Phone number">
                  <button type="button" class="btn btn-sm btn-secondary remove-row">Remove</button>
                </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-secondary add-row" data-target="hotlines-list" data-mode="hotline" style="margin-top:6px">+ Add hotline</button>
          </div>
        </div>
      </div>

      <div class="card">
        <h3>🧾 Services & Fees</h3>
        <div class="form-grid">
          <div class="form-group" style="grid-column:1 / -1">
            <div id="fees-list">
              <?php $fees = getJsonSetting('barangay_fees', $defaultBarangayFees); foreach ($fees as $fee): ?>
                <div class="entry-row" style="display:grid;grid-template-columns:1.4fr 0.8fr 1fr 1.2fr auto;gap:8px;margin-bottom:8px;align-items:center">
                  <input type="text" name="fee_name[]" class="form-control" value="<?= htmlspecialchars($fee[0] ?? '') ?>" placeholder="Service name">
                  <input type="text" name="fee_amount[]" class="form-control" value="<?= htmlspecialchars($fee[1] ?? '') ?>" placeholder="Fee">
                  <input type="text" name="fee_time[]" class="form-control" value="<?= htmlspecialchars($fee[2] ?? '') ?>" placeholder="Processing time">
                  <input type="text" name="fee_requirements[]" class="form-control" value="<?= htmlspecialchars($fee[3] ?? '') ?>" placeholder="Requirements">
                  <button type="button" class="btn btn-sm btn-secondary remove-row">Remove</button>
                </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-secondary add-row" data-target="fees-list" data-mode="fee" style="margin-top:6px">+ Add service</button>
          </div>
        </div>
      </div>

      <div class="card">
        <h3>🏢 Barangay Offices</h3>
        <div class="form-grid">
          <div class="form-group" style="grid-column:1 / -1">
            <div id="offices-list">
              <?php $offices = getJsonSetting('barangay_offices', $defaultBarangayOffices); foreach ($offices as $office): ?>
                <div class="entry-row" style="display:grid;grid-template-columns:1.2fr 1.2fr 1fr 1fr auto;gap:8px;margin-bottom:8px;align-items:center">
                  <input type="text" name="office_name[]" class="form-control" value="<?= htmlspecialchars($office[0] ?? '') ?>" placeholder="Office name">
                  <input type="text" name="office_person[]" class="form-control" value="<?= htmlspecialchars($office[1] ?? '') ?>" placeholder="Contact person">
                  <input type="text" name="office_phone[]" class="form-control" value="<?= htmlspecialchars($office[2] ?? '') ?>" placeholder="Phone">
                  <input type="text" name="office_hours[]" class="form-control" value="<?= htmlspecialchars($office[3] ?? '') ?>" placeholder="Hours">
                  <button type="button" class="btn btn-sm btn-secondary remove-row">Remove</button>
                </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-secondary add-row" data-target="offices-list" data-mode="office" style="margin-top:6px">+ Add office</button>
          </div>
        </div>
      </div>

      <div class="card">
        <h3>🗓️ Weekly Hours</h3>
        <div class="form-grid">
          <div class="form-group" style="grid-column:1 / -1">
            <div id="schedule-list">
              <?php $hours = getJsonSetting('barangay_hours', $defaultBarangayHours); foreach ($hours as $slot): ?>
                <div class="entry-row" style="display:grid;grid-template-columns:0.8fr 1.5fr auto;gap:8px;margin-bottom:8px;align-items:center">
                  <input type="text" name="schedule_day[]" class="form-control" value="<?= htmlspecialchars($slot[0] ?? '') ?>" placeholder="Day">
                  <input type="text" name="schedule_details[]" class="form-control" value="<?= htmlspecialchars($slot[1] ?? '') ?>" placeholder="Schedule details">
                  <button type="button" class="btn btn-sm btn-secondary remove-row">Remove</button>
                </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-secondary add-row" data-target="schedule-list" data-mode="schedule" style="margin-top:6px">+ Add schedule</button>
          </div>
        </div>
      </div>

      <div style="margin-top:18px">
        <button class="btn btn-primary" type="submit">💾 Save Resident Info</button>
      </div>
    </form>

    <!-- ── Security / Password ─────────────────────────────── -->
    <form method="POST" style="margin-top:20px">
      <input type="hidden" name="action" value="change_password">
      <div class="card">
        <h3>🔒 Security Settings</h3>
        <p class="text-muted" style="margin-bottom:18px">Change your admin account password.</p>
        <div class="form-grid">
          <div class="form-group">
            <label>Current Password</label>
            <div style="position:relative">
              <input type="password" name="current_password" id="curPwd" class="form-control" placeholder="Enter current password">
              <span onclick="togglePwd('curPwd',this)" style="position:absolute;right:14px;top:50%;transform:translateY(-50%);cursor:pointer;font-size:16px;user-select:none">👁️</span>
            </div>
          </div>
          <div class="form-group">
            <label>New Password</label>
            <div style="position:relative">
              <input type="password" name="new_password" id="newPwd" class="form-control" placeholder="Min. 8 characters"
                oninput="checkPwdStrength(this.value)">
              <span onclick="togglePwd('newPwd',this)" style="position:absolute;right:14px;top:50%;transform:translateY(-50%);cursor:pointer;font-size:16px;user-select:none">👁️</span>
            </div>
            <div style="margin-top:8px;height:4px;background:var(--border);border-radius:2px;overflow:hidden">
              <div id="pwdStrengthBar" style="height:100%;width:0%;background:#ef4444;border-radius:2px;transition:all 0.3s"></div>
            </div>
            <small id="pwdStrengthLabel" class="text-muted"></small>
          </div>
          <div class="form-group">
            <label>Confirm New Password</label>
            <div style="position:relative">
              <input type="password" name="confirm_password" id="conPwd" class="form-control" placeholder="Repeat new password">
              <span onclick="togglePwd('conPwd',this)" style="position:absolute;right:14px;top:50%;transform:translateY(-50%);cursor:pointer;font-size:16px;user-select:none">👁️</span>
            </div>
          </div>
        </div>
        <button class="btn btn-secondary" type="submit" style="margin-top:4px">🔒 Update Password</button>
      </div>
    </form>

  </main>
</div>

<script src="assets/js/app.js"></script>
<script>
function togglePwd(id, icon) {
  const input = document.getElementById(id);
  if (input.type === 'password') { input.type = 'text'; icon.textContent = '🙈'; }
  else { input.type = 'password'; icon.textContent = '👁️'; }
}

function checkPwdStrength(val) {
  const bar   = document.getElementById('pwdStrengthBar');
  const label = document.getElementById('pwdStrengthLabel');
  let score = 0;
  if (val.length >= 8)           score++;
  if (/[A-Z]/.test(val))         score++;
  if (/[0-9]/.test(val))         score++;
  if (/[^A-Za-z0-9]/.test(val)) score++;
  const levels = [
    { pct:'0%',   color:'#ef4444', text:'' },
    { pct:'25%',  color:'#ef4444', text:'Weak' },
    { pct:'50%',  color:'#f59e0b', text:'Fair' },
    { pct:'75%',  color:'#3b82f6', text:'Good' },
    { pct:'100%', color:'#10b981', text:'Strong' },
  ];
  const lvl = levels[score] ?? levels[0];
  bar.style.width      = lvl.pct;
  bar.style.background = lvl.color;
  label.textContent    = lvl.text;
  label.style.color    = lvl.color;
}

function createEntryRow(mode) {
  const row = document.createElement('div');
  row.className = 'entry-row';
  row.style.display = 'grid';
  row.style.gap = '8px';
  row.style.marginBottom = '8px';
  row.style.alignItems = 'center';

  if (mode === 'hotline') {
    row.style.gridTemplateColumns = '1.4fr 1fr auto';
    row.innerHTML = '<input type="text" name="hotline_label[]" class="form-control" placeholder="Label">' +
      '<input type="text" name="hotline_number[]" class="form-control" placeholder="Phone number">' +
      '<button type="button" class="btn btn-sm btn-secondary remove-row">Remove</button>';
  } else if (mode === 'center') {
    row.style.gridTemplateColumns = '1fr auto';
    row.innerHTML = '<input type="text" name="center_name[]" class="form-control" placeholder="Center name">' +
      '<button type="button" class="btn btn-sm btn-secondary remove-row">Remove</button>';
  } else if (mode === 'fee') {
    row.style.gridTemplateColumns = '1.4fr 0.8fr 1fr 1.2fr auto';
    row.innerHTML = '<input type="text" name="fee_name[]" class="form-control" placeholder="Service name">' +
      '<input type="text" name="fee_amount[]" class="form-control" placeholder="Fee">' +
      '<input type="text" name="fee_time[]" class="form-control" placeholder="Processing time">' +
      '<input type="text" name="fee_requirements[]" class="form-control" placeholder="Requirements">' +
      '<button type="button" class="btn btn-sm btn-secondary remove-row">Remove</button>';
  } else if (mode === 'office') {
    row.style.gridTemplateColumns = '1.2fr 1.2fr 1fr 1fr auto';
    row.innerHTML = '<input type="text" name="office_name[]" class="form-control" placeholder="Office name">' +
      '<input type="text" name="office_person[]" class="form-control" placeholder="Contact person">' +
      '<input type="text" name="office_phone[]" class="form-control" placeholder="Phone">' +
      '<input type="text" name="office_hours[]" class="form-control" placeholder="Hours">' +
      '<button type="button" class="btn btn-sm btn-secondary remove-row">Remove</button>';
  } else if (mode === 'schedule') {
    row.style.gridTemplateColumns = '0.8fr 1.5fr auto';
    row.innerHTML = '<input type="text" name="schedule_day[]" class="form-control" placeholder="Day">' +
      '<input type="text" name="schedule_details[]" class="form-control" placeholder="Schedule details">' +
      '<button type="button" class="btn btn-sm btn-secondary remove-row">Remove</button>';
  }

  return row;
}

document.addEventListener('click', function (event) {
  const addButton = event.target.closest('.add-row');
  const removeButton = event.target.closest('.remove-row');

  if (addButton) {
    const targetId = addButton.getAttribute('data-target');
    const mode = addButton.getAttribute('data-mode');
    const container = document.getElementById(targetId);
    if (container) {
      container.appendChild(createEntryRow(mode));
    }
  }

  if (removeButton) {
    const row = removeButton.closest('.entry-row');
    if (row) row.remove();
  }
});
</script>
</body>
</html>
