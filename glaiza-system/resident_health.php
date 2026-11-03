<?php
// ============================================================
// resident_health.php  — Resident: Health Monitoring
// ============================================================
session_start();
require_once 'includes/db.php';
requireResidentLogin();

$residentId = $_SESSION['resident_id'];

// ── AJAX: update health profile ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_profile') {
        $bloodType  = trim($_POST['blood_type'] ?? '');
        $height     = ($_POST['height_cm'] ?? '') !== '' ? (float)$_POST['height_cm'] : null;
        $weight     = ($_POST['weight_kg'] ?? '') !== '' ? (float)$_POST['weight_kg'] : null;
        $allergies  = trim($_POST['allergies'] ?? '');
        $conditions = trim($_POST['medical_conditions'] ?? '');
        $meds       = trim($_POST['maintenance_medication'] ?? '');

        dbExecute(
            "INSERT INTO health_profiles
               (resident_id, blood_type, height_cm, weight_kg, allergies, medical_conditions, maintenance_medication)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               blood_type=VALUES(blood_type), height_cm=VALUES(height_cm), weight_kg=VALUES(weight_kg),
               allergies=VALUES(allergies), medical_conditions=VALUES(medical_conditions),
               maintenance_medication=VALUES(maintenance_medication)",
            [$residentId, $bloodType ?: null, $height, $weight, $allergies ?: null, $conditions ?: null, $meds ?: null]
        );
        logActivity('Updated health profile', 'Health Monitoring', 'resident');
        jsonOk([], 'Health profile updated.');
    }
    exit;
}

// ── Data ─────────────────────────────────────────────────────
$profile = dbFetchOne("SELECT * FROM health_profiles WHERE resident_id=?", [$residentId]) ?? [];

$checkupCount  = (int)(dbFetchOne("SELECT COUNT(*) c FROM health_checkups WHERE resident_id=?", [$residentId])['c'] ?? 0);
$vaccineCount  = (int)(dbFetchOne("SELECT COUNT(*) c FROM health_vaccinations WHERE resident_id=?", [$residentId])['c'] ?? 0);
$reminderCount = 0; // reminders removed

$allCheckups = dbFetchAll(
    "SELECT hc.*, a.name AS recorded_by_name
     FROM health_checkups hc LEFT JOIN admin_users a ON hc.recorded_by=a.id
     WHERE hc.resident_id=? ORDER BY hc.checkup_date DESC",
    [$residentId]
);
$recentCheckups = array_slice($allCheckups, 0, 5);

$allVaccines    = dbFetchAll("SELECT * FROM health_vaccinations WHERE resident_id=? ORDER BY date_given DESC", [$residentId]);
$recentVaccines = array_slice($allVaccines, 0, 5);

$allReminders = [];
$nextReminder = null;

$statusColor = [
    'Good'               => ['bg'=>'#dcfce7','text'=>'#166534'],
    'Needs Monitoring'   => ['bg'=>'#fef3c7','text'=>'#92400e'],
    'Requires Attention' => ['bg'=>'#fee2e2','text'=>'#991b1b'],
];
$overallStatus = $profile['overall_status'] ?? 'Good';
$sc = $statusColor[$overallStatus] ?? $statusColor['Good'];

function badgeForResult($r) {
    return match ($r) {
        'Requires Attention' => 'badge-danger',
        'Needs Monitoring'   => 'badge-warning',
        'Missed'             => 'badge-danger',
        'Scheduled'          => 'badge-info',
        default              => 'badge-success',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Health Monitoring — Barangay Talisay</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <?php renderThemeStyle(); ?>
  <style>
    .hm-tabs{display:flex;border-bottom:1px solid var(--border);overflow-x:auto}
    .hm-tab{background:none;border:none;padding:14px 20px;cursor:pointer;font-weight:600;
      font-size:14px;color:var(--text-light);border-bottom:3px solid transparent;white-space:nowrap}
    .hm-tab.active{color:var(--primary);border-bottom-color:var(--primary)}
    .hm-panel{display:none}
    .hm-panel.active{display:block}
    .hm-info-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px}
    .hm-info-row:last-child{border-bottom:none}
    .hm-info-row span:first-child{color:var(--text-light)}
    .hm-info-row span:last-child{font-weight:600}
  </style>
</head>
<body>
<div class="app">
  <?php include 'includes/sidebar_resident.php'; ?>
  <main class="main">
    <div class="topbar">
      <div>
        <h3>Health Monitoring</h3>
        <div class="text-muted">View and manage your health information and records.</div>
      </div>
    </div>

    <div class="card" style="padding:0;margin-bottom:24px">
      <div class="hm-tabs">
        <button class="hm-tab active" data-tab="overview">Overview</button>
        <button class="hm-tab" data-tab="profile">Health Profile</button>
        <button class="hm-tab" data-tab="records">Checkups</button>
        <button class="hm-tab" data-tab="vaccines">Vaccinations</button>
      </div>
    </div>

    <!-- ══════════ OVERVIEW ══════════ -->
    <div class="hm-panel active" id="tab-overview">
      <div class="stats">
        <div class="stat">
          <h4>Checkup Records</h4>
          <p data-target="<?= $checkupCount ?>">0</p>
          <div class="stat-icon">💙</div>
          <div class="stat-change positive">View all in Health Records</div>
        </div>
        <div class="stat">
          <h4>Vaccines</h4>
          <p data-target="<?= $vaccineCount ?>">0</p>
          <div class="stat-icon">💉</div>
          <div class="stat-change positive">View all in Vaccinations</div>
        </div>
        
        <div class="stat">
          <h4>Overall Status</h4>
          <p style="font-size:20px;color:<?= $sc['text'] ?>"><?= htmlspecialchars($overallStatus) ?></p>
          <div class="stat-icon">🛡️</div>
          <div class="stat-change positive">Assessed by barangay health worker</div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1.3fr 1fr;gap:18px;margin-bottom:28px">
        <div class="card" style="margin:0;grid-column:1/-1">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <h3 style="margin:0">Latest Health Profile</h3>
            <button class="btn btn-secondary btn-sm" onclick="switchTab('profile')">Edit Profile</button>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 24px">
            <div class="hm-info-row"><span>Blood Type</span><span><?= htmlspecialchars($profile['blood_type'] ?? '—') ?></span></div>
            <div class="hm-info-row"><span>Allergies</span><span><?= htmlspecialchars($profile['allergies'] ?? 'None') ?></span></div>
            <div class="hm-info-row"><span>Height</span><span><?= ($profile['height_cm'] ?? '') !== '' ? htmlspecialchars($profile['height_cm']) . ' cm' : '—' ?></span></div>
            <div class="hm-info-row"><span>Medical Conditions</span><span><?= htmlspecialchars($profile['medical_conditions'] ?? 'None') ?></span></div>
            <div class="hm-info-row"><span>Weight</span><span><?= ($profile['weight_kg'] ?? '') !== '' ? htmlspecialchars($profile['weight_kg']) . ' kg' : '—' ?></span></div>
            <div class="hm-info-row"><span>Maintenance Medication</span><span><?= htmlspecialchars($profile['maintenance_medication'] ?? 'None') ?></span></div>
          </div>
          <div class="text-muted" style="margin-top:12px;font-size:12px">
            Last updated: <?= !empty($profile['updated_at']) ? date('M d, Y', strtotime($profile['updated_at'])) : 'Not yet set up' ?>
          </div>
        </div>

        <!-- Reminders removed -->
      </div>

      <div style="display:grid;grid-template-columns:1.3fr 1fr;gap:18px">
        <div class="card" style="margin:0">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <h3 style="margin:0">Recent Checkup Records</h3>
            <a href="javascript:void(0)" onclick="switchTab('records')" class="text-muted" style="font-size:13px">View All</a>
          </div>
          <div class="table-container">
            <table>
              <thead><tr><th>Date</th><th>Type</th><th>Results</th><th>Recorded By</th></tr></thead>
              <tbody>
                <?php if ($recentCheckups): foreach ($recentCheckups as $c): ?>
                <tr>
                  <td><?= date('M d, Y', strtotime($c['checkup_date'])) ?></td>
                  <td><?= htmlspecialchars($c['checkup_type']) ?></td>
                  <td><span class="badge <?= badgeForResult($c['results']) ?>"><?= htmlspecialchars($c['results']) ?></span></td>
                  <td><?= htmlspecialchars($c['recorded_by_name'] ?? '—') ?></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="4" style="text-align:center;color:#aaa;padding:16px">No checkup records yet.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card" style="margin:0">
          <h3>Quick Actions</h3>
          <div style="display:flex;flex-direction:column;gap:10px;margin-top:12px">
            <div class="content-cards" style="grid-template-columns:1fr;gap:10px;margin-top:0">
              <div class="card quick-action-card" style="padding:14px;text-align:left" onclick="switchTab('profile')">
                <h4 style="font-size:14px">👤 Update Health Profile</h4>
                <small class="text-muted">Edit your basic health information</small>
              </div>
              <div class="card quick-action-card" style="padding:14px;text-align:left" onclick="location.href='resident_appointments.php'">
                <h4 style="font-size:14px">📅 Request Checkup</h4>
                <small class="text-muted">Book an appointment for checkup</small>
              </div>
              <div class="card quick-action-card" style="padding:14px;text-align:left" onclick="window.print()">
                <h4 style="font-size:14px">⬇️ Download Records</h4>
                <small class="text-muted">Print / save your health records</small>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
          <h3 style="margin:0">Vaccination Records</h3>
          <a href="javascript:void(0)" onclick="switchTab('vaccines')" class="text-muted" style="font-size:13px">View All</a>
        </div>
        <div class="table-container">
          <table>
            <thead><tr><th>Vaccine</th><th>Date Given</th><th>Dose</th><th>Status</th></tr></thead>
            <tbody>
              <?php if ($recentVaccines): foreach ($recentVaccines as $v): ?>
              <tr>
                <td><?= htmlspecialchars($v['vaccine_name']) ?></td>
                <td><?= date('M d, Y', strtotime($v['date_given'])) ?></td>
                <td><?= htmlspecialchars($v['dose'] ?: '—') ?></td>
                <td><span class="badge <?= badgeForResult($v['status']) ?>"><?= htmlspecialchars($v['status']) ?></span></td>
              </tr>
              <?php endforeach; else: ?>
              <tr><td colspan="4" style="text-align:center;color:#aaa;padding:16px">No vaccination records yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════ HEALTH PROFILE ══════════ -->
    <div class="hm-panel" id="tab-profile">
      <div class="card">
        <h3>Edit Health Profile</h3>
        <p class="text-muted" style="margin-bottom:16px">This information helps barangay health workers assist you better during checkups and emergencies.</p>
        <div class="form-grid">
          <div class="form-group">
            <label>Blood Type</label>
            <select id="pBloodType" class="form-control">
              <?php $bt = $profile['blood_type'] ?? ''; ?>
              <option value="">Select...</option>
              <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $b): ?>
                <option value="<?= $b ?>" <?= $bt===$b?'selected':'' ?>><?= $b ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Height (cm)</label>
            <input type="number" step="0.1" id="pHeight" class="form-control" value="<?= htmlspecialchars($profile['height_cm'] ?? '') ?>" placeholder="e.g., 170">
          </div>
          <div class="form-group">
            <label>Weight (kg)</label>
            <input type="number" step="0.1" id="pWeight" class="form-control" value="<?= htmlspecialchars($profile['weight_kg'] ?? '') ?>" placeholder="e.g., 65">
          </div>
        </div>
        <div class="form-group">
          <label>Allergies</label>
          <input type="text" id="pAllergies" class="form-control" value="<?= htmlspecialchars($profile['allergies'] ?? '') ?>" placeholder="e.g., Penicillin, Shellfish — or 'None'">
        </div>
        <div class="form-group">
          <label>Medical Conditions</label>
          <input type="text" id="pConditions" class="form-control" value="<?= htmlspecialchars($profile['medical_conditions'] ?? '') ?>" placeholder="e.g., Asthma (Mild) — or 'None'">
        </div>
        <div class="form-group">
          <label>Maintenance Medication</label>
          <input type="text" id="pMeds" class="form-control" value="<?= htmlspecialchars($profile['maintenance_medication'] ?? '') ?>" placeholder="e.g., Salbutamol Inhaler — or 'None'">
        </div>
        <button class="btn btn-primary" onclick="saveProfile()">💾 Save Health Profile</button>
      </div>
    </div>

    <!-- ══════════ HEALTH RECORDS ══════════ -->
    <div class="hm-panel" id="tab-records">
      <div class="card">
        <h3>All Checkup Records</h3>
        <div class="table-container">
          <table>
            <thead><tr><th>Date</th><th>Type</th><th>Blood Pressure</th><th>Temp.</th><th>Results</th><th>Recorded By</th><th>Notes</th></tr></thead>
            <tbody>
              <?php if ($allCheckups): foreach ($allCheckups as $c): ?>
              <tr>
                <td><?= date('M d, Y', strtotime($c['checkup_date'])) ?></td>
                <td><?= htmlspecialchars($c['checkup_type']) ?></td>
                <td><?= htmlspecialchars($c['blood_pressure'] ?: '—') ?></td>
                <td><?= htmlspecialchars($c['temperature'] ?: '—') ?></td>
                <td><span class="badge <?= badgeForResult($c['results']) ?>"><?= htmlspecialchars($c['results']) ?></span></td>
                <td><?= htmlspecialchars($c['recorded_by_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($c['notes'] ?: '—') ?></td>
              </tr>
              <?php endforeach; else: ?>
              <tr><td colspan="7" style="text-align:center;color:#aaa;padding:20px">No checkup records yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════ VACCINATIONS ══════════ -->
    <div class="hm-panel" id="tab-vaccines">
      <div class="card">
        <h3>All Vaccination Records</h3>
        <div class="table-container">
          <table>
            <thead><tr><th>Vaccine</th><th>Date Given</th><th>Dose</th><th>Status</th></tr></thead>
            <tbody>
              <?php if ($allVaccines): foreach ($allVaccines as $v): ?>
              <tr>
                <td><?= htmlspecialchars($v['vaccine_name']) ?></td>
                <td><?= date('M d, Y', strtotime($v['date_given'])) ?></td>
                <td><?= htmlspecialchars($v['dose'] ?: '—') ?></td>
                <td><span class="badge <?= badgeForResult($v['status']) ?>"><?= htmlspecialchars($v['status']) ?></span></td>
              </tr>
              <?php endforeach; else: ?>
              <tr><td colspan="4" style="text-align:center;color:#aaa;padding:20px">No vaccination records yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Health reminders feature removed -->
  </main>
</div>

<script src="assets/js/app.js"></script>
<script>
function switchTab(name) {
  document.querySelectorAll('.hm-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
  document.querySelectorAll('.hm-panel').forEach(p => p.classList.toggle('active', p.id === 'tab-' + name));
}
document.querySelectorAll('.hm-tab').forEach(t => t.addEventListener('click', () => switchTab(t.dataset.tab)));

async function saveProfile() {
  const fd = new FormData();
  fd.append('action', 'update_profile');
  fd.append('blood_type', document.getElementById('pBloodType').value);
  fd.append('height_cm', document.getElementById('pHeight').value);
  fd.append('weight_kg', document.getElementById('pWeight').value);
  fd.append('allergies', document.getElementById('pAllergies').value);
  fd.append('medical_conditions', document.getElementById('pConditions').value);
  fd.append('maintenance_medication', document.getElementById('pMeds').value);

  const res  = await fetch('resident_health.php', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.success) {
    showToast(data.message, 'success');
    setTimeout(() => location.reload(), 800);
  } else {
    showToast(data.message || 'Error', 'error');
  }
}
</script>
</body>
</html>
