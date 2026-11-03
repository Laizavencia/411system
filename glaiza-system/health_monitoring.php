<?php
// ============================================================
// health_monitoring.php  — Admin: Health Monitoring module
// Redesigned: Dashboard tab (unchanged) + Residents tab where you
// search/select a resident and view their own Overview / Health
// Profile / Checkups / Vaccinations sub-tabs.
// ============================================================
session_start();
require_once 'includes/db.php';
requireAdminLogin();

// ── AJAX handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add_checkup') {
        $residentId = (int)($_POST['resident_id'] ?? 0);
        $date       = trim($_POST['checkup_date'] ?? '');
        $type       = trim($_POST['checkup_type'] ?? '');
        $bp         = trim($_POST['blood_pressure'] ?? '');
        $weight     = trim($_POST['weight_kg'] ?? '');
        $height     = trim($_POST['height_cm'] ?? '');
        $temp       = trim($_POST['temperature'] ?? '');
        $results    = trim($_POST['results'] ?? 'Normal');
        $notes      = trim($_POST['notes'] ?? '');
        $followup   = trim($_POST['next_followup_date'] ?? '');

        if (!$residentId || !$date || !$type) jsonError('Resident, date, and checkup type are required.');
        if (!in_array($results, ['Normal','Needs Monitoring','Requires Attention'])) $results = 'Normal';

        dbInsert(
            "INSERT INTO health_checkups
               (resident_id, checkup_date, checkup_type, blood_pressure, weight_kg, height_cm, temperature, results, notes, next_followup_date, recorded_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [
                $residentId, $date, $type, $bp ?: null,
                $weight !== '' ? $weight : null, $height !== '' ? $height : null,
                $temp ?: null, $results, $notes ?: null, $followup ?: null,
                $_SESSION['admin_id'],
            ]
        );

        // Keep the resident's overall health status roughly in sync with their latest checkup
        dbExecute(
            "INSERT INTO health_profiles (resident_id, overall_status) VALUES (?,?)
             ON DUPLICATE KEY UPDATE overall_status=VALUES(overall_status)",
            [$residentId, $results === 'Normal' ? 'Good' : $results]
        );

        logActivity("Recorded checkup for resident #$residentId", 'Health Monitoring');
        jsonOk([], 'Checkup recorded.');
    }

    if ($action === 'add_vaccination') {
        $residentId = (int)($_POST['resident_id'] ?? 0);
        $vaccine    = trim($_POST['vaccine_name'] ?? '');
        $date       = trim($_POST['date_given'] ?? '');
        $dose       = trim($_POST['dose'] ?? '');
        $status     = trim($_POST['status'] ?? 'Completed');
        $remarks    = trim($_POST['remarks'] ?? '');

        if (!$residentId || !$vaccine || !$date) jsonError('Resident, vaccine name, and date are required.');
        if (!in_array($status, ['Completed','Scheduled','Missed'])) $status = 'Completed';

        dbInsert(
            "INSERT INTO health_vaccinations (resident_id, vaccine_name, date_given, dose, status, remarks, recorded_by)
             VALUES (?,?,?,?,?,?,?)",
            [$residentId, $vaccine, $date, $dose ?: null, $status, $remarks ?: null, $_SESSION['admin_id']]
        );
        logActivity("Recorded vaccination for resident #$residentId", 'Health Monitoring');
        jsonOk([], 'Vaccination recorded.');
    }

    if ($action === 'update_status') {
        $residentId = (int)($_POST['resident_id'] ?? 0);
        $status     = trim($_POST['overall_status'] ?? '');
        if (!in_array($status, ['Good','Needs Monitoring','Requires Attention'])) jsonError('Invalid status.');
        dbExecute(
            "INSERT INTO health_profiles (resident_id, overall_status) VALUES (?,?)
             ON DUPLICATE KEY UPDATE overall_status=VALUES(overall_status)",
            [$residentId, $status]
        );
        logActivity("Updated health status for resident #$residentId to $status", 'Health Monitoring');
        jsonOk([], 'Health status updated.');
    }
    exit;
}

// ── Dashboard stats (unchanged) ────────────────────────────────
$totalWithProfile  = (int)(dbFetchOne("SELECT COUNT(*) c FROM health_profiles")['c'] ?? 0);
$checkupsThisMonth = (int)(dbFetchOne("SELECT COUNT(*) c FROM health_checkups WHERE MONTH(checkup_date)=MONTH(CURDATE()) AND YEAR(checkup_date)=YEAR(CURDATE())")['c'] ?? 0);
$vaccinesThisMonth = (int)(dbFetchOne("SELECT COUNT(*) c FROM health_vaccinations WHERE MONTH(date_given)=MONTH(CURDATE()) AND YEAR(date_given)=YEAR(CURDATE())")['c'] ?? 0);
$totalResidentsAll = (int)(dbFetchOne("SELECT COUNT(*) c FROM residents WHERE status != 'Inactive'")['c'] ?? 0);

$statusBreakdown = dbFetchAll("SELECT overall_status, COUNT(*) c FROM health_profiles GROUP BY overall_status");
$statusMap = ['Good'=>0,'Needs Monitoring'=>0,'Requires Attention'=>0];
foreach ($statusBreakdown as $s) { $statusMap[$s['overall_status']] = (int)$s['c']; }

$topConcerns = dbFetchAll(
    "SELECT medical_conditions, COUNT(*) c FROM health_profiles
     WHERE medical_conditions IS NOT NULL AND medical_conditions != '' AND LOWER(medical_conditions) != 'none'
     GROUP BY medical_conditions ORDER BY c DESC LIMIT 5"
);

$recentCheckups = dbFetchAll(
    "SELECT hc.*, hc.resident_id, CONCAT(r.first_name,' ',r.last_name) AS resident_name
     FROM health_checkups hc JOIN residents r ON hc.resident_id=r.id
     ORDER BY hc.created_at DESC LIMIT 8"
);

// ── Residents search / list (for the Residents tab) ────────────
$search  = trim($_GET['search'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 8;

$where  = '';
$params = [];
if ($search) {
    $where  = "WHERE (CONCAT(r.first_name,' ',r.last_name) LIKE ? OR r.barangay_id LIKE ?)";
    $params = ["%$search%", "%$search%"];
}

$totalResidents = (int)(dbFetchOne("SELECT COUNT(*) c FROM residents r $where", $params)['c'] ?? 0);
$totalPages     = max(1, (int)ceil($totalResidents / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$residentsList = dbFetchAll(
    "SELECT r.id, CONCAT(r.first_name,' ',r.last_name) AS full_name, r.barangay_id,
            TIMESTAMPDIFF(YEAR,r.date_of_birth,CURDATE()) AS age, r.gender,
            hp.blood_type, COALESCE(hp.overall_status,'Good') AS overall_status
     FROM residents r
     LEFT JOIN health_profiles hp ON r.id = hp.resident_id
     $where
     ORDER BY r.first_name
     LIMIT $perPage OFFSET $offset",
    $params
);

// ── Selected resident profile (Overview / Profile / Checkups / Vaccinations) ──
$viewResidentId = (int)($_GET['resident_id'] ?? 0);
$viewResident   = null;
$vProfile       = [];
$vCheckups      = [];
$vVaccines      = [];
$vOverallStatus = 'Good';

if ($viewResidentId) {
    $viewResident = dbFetchOne(
        "SELECT r.*, TIMESTAMPDIFF(YEAR,r.date_of_birth,CURDATE()) AS age,
                CONCAT(r.first_name,' ',r.last_name) AS full_name
         FROM residents r WHERE r.id=?",
        [$viewResidentId]
    );
    if ($viewResident) {
        $vProfile = dbFetchOne("SELECT * FROM health_profiles WHERE resident_id=?", [$viewResidentId]) ?? [];
        $vCheckups = dbFetchAll(
            "SELECT hc.*, a.name AS recorded_by_name
             FROM health_checkups hc LEFT JOIN admin_users a ON hc.recorded_by=a.id
             WHERE hc.resident_id=? ORDER BY hc.checkup_date DESC",
            [$viewResidentId]
        );
        $vVaccines = dbFetchAll(
            "SELECT hv.*, a.name AS recorded_by_name
             FROM health_vaccinations hv LEFT JOIN admin_users a ON hv.recorded_by=a.id
             WHERE hv.resident_id=? ORDER BY hv.date_given DESC",
            [$viewResidentId]
        );
        $vOverallStatus = $vProfile['overall_status'] ?? 'Good';
    } else {
        $viewResidentId = 0;
    }
}

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
  <title>Health Monitoring — <?= getSiteName() ?></title>
  <link rel="stylesheet" href="assets/css/style.css">
  <?php renderThemeStyle(); ?>
  <style>
    .hm-tabs{display:flex;border-bottom:1px solid var(--border);overflow-x:auto}
    .hm-tab{background:none;border:none;padding:14px 20px;cursor:pointer;font-weight:600;
      font-size:14px;color:var(--text-light);border-bottom:3px solid transparent;white-space:nowrap}
    .hm-tab.active{color:var(--primary);border-bottom-color:var(--primary)}
    .hm-panel{display:none}
    .hm-panel.active{display:block}
    .sub-tab{background:none;border:none;padding:14px 20px;cursor:pointer;font-weight:600;
      font-size:14px;color:var(--text-light);border-bottom:3px solid transparent;white-space:nowrap}
    .sub-tab.active{color:var(--primary);border-bottom-color:var(--primary)}
    .sub-panel{display:none}
    .sub-panel.active{display:block}
    .concern-bar-track{background:var(--muted);border-radius:6px;height:8px;overflow:hidden;margin-top:6px}
    .concern-bar-fill{height:100%;background:var(--primary);border-radius:6px}
    .profile-avatar{width:64px;height:64px;border-radius:50%;background:var(--primary-light);
      display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:700;color:var(--primary);flex-shrink:0}
  </style>
</head>
<body>
<div class="app">
  <?php include 'includes/sidebar_admin.php'; ?>
  <main class="main">
    <div class="topbar">
      <div>
        <h3>Health Monitoring</h3>
        <div class="text-muted">Search a resident to view and manage their health records.</div>
      </div>
    </div>

    <div class="card" style="padding:0;margin-bottom:24px">
      <div class="hm-tabs">
        <button class="hm-tab" data-tab="dashboard">Dashboard</button>
        <button class="hm-tab" data-tab="residents">Residents</button>
      </div>
    </div>

    <!-- ══════════ DASHBOARD ══════════ -->
    <div class="hm-panel" id="tab-dashboard">
      <div class="stats">
        <div class="stat">
          <h4>Residents with Health Records</h4>
          <p data-target="<?= $totalWithProfile ?>">0</p>
          <div class="stat-icon">📋</div>
          <div class="stat-change positive">of <?= $totalResidentsAll ?> total residents</div>
        </div>
        <div class="stat">
          <h4>Checkup Records This Month</h4>
          <p data-target="<?= $checkupsThisMonth ?>">0</p>
          <div class="stat-icon">🩺</div>
          <div class="stat-change positive">Current month</div>
        </div>
        <div class="stat">
          <h4>Vaccination Records This Month</h4>
          <p data-target="<?= $vaccinesThisMonth ?>">0</p>
          <div class="stat-icon">💉</div>
          <div class="stat-change positive">Current month</div>
        </div>
      </div>

      <div class="charts-container">
        <div class="chart-card">
          <h4>Recent Checkup Entries</h4>
          <div class="table-container">
            <table>
              <thead><tr><th>Resident</th><th>Date</th><th>Type</th><th>Result</th></tr></thead>
              <tbody>
                <?php if ($recentCheckups): foreach ($recentCheckups as $c): ?>
                <tr>
                  <td><a href="health_monitoring.php?tab=residents&resident_id=<?= $c['resident_id'] ?>" style="color:var(--primary);text-decoration:none"><?= htmlspecialchars($c['resident_name']) ?></a></td>
                  <td><?= date('M d, Y', strtotime($c['checkup_date'])) ?></td>
                  <td><?= htmlspecialchars($c['checkup_type']) ?></td>
                  <td><span class="badge <?= badgeForResult($c['results']) ?>"><?= htmlspecialchars($c['results']) ?></span></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="4" style="text-align:center;color:#aaa;padding:16px">No checkups recorded yet.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="chart-card">
          <h4>Health Status Overview</h4>
          <canvas id="statusChart" style="max-height:220px"></canvas>
          <div style="display:flex;justify-content:space-around;margin-top:14px;font-size:12px">
            <div>🟢 Good (<?= $statusMap['Good'] ?>)</div>
            <div>🟡 Needs Monitoring (<?= $statusMap['Needs Monitoring'] ?>)</div>
            <div>🔴 Requires Attention (<?= $statusMap['Requires Attention'] ?>)</div>
          </div>
        </div>
      </div>

      <div class="card">
        <h3>Top Health Concerns</h3>
        <?php if ($topConcerns): $max = max(array_column($topConcerns, 'c')); foreach ($topConcerns as $tc): ?>
        <div style="margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;font-size:13px">
            <span><?= htmlspecialchars($tc['medical_conditions']) ?></span>
            <span class="text-muted"><?= (int)$tc['c'] ?></span>
          </div>
          <div class="concern-bar-track"><div class="concern-bar-fill" style="width:<?= round($tc['c']/$max*100) ?>%"></div></div>
        </div>
        <?php endforeach; else: ?>
        <p class="text-muted">No recorded medical conditions yet.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- ══════════ RESIDENTS ══════════ -->
    <div class="hm-panel" id="tab-residents">
      <?php if ($viewResident): ?>

      <!-- ── Resident Profile ── -->
      <div class="card">
        <a href="health_monitoring.php?tab=residents<?= $search ? '&search=' . urlencode($search) : '' ?>"
           style="font-size:13px;color:var(--primary);text-decoration:none;display:inline-block;margin-bottom:14px">← Back to Residents</a>
        <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
          <div class="profile-avatar"><?= strtoupper(substr($viewResident['first_name'],0,1) . substr($viewResident['last_name'],0,1)) ?></div>
          <div>
            <h3 style="margin:0"><?= htmlspecialchars($viewResident['full_name']) ?></h3>
            <div class="text-muted" style="font-size:13px"><?= htmlspecialchars($viewResident['barangay_id']) ?></div>
            <div style="font-size:13px;color:var(--text-light);margin-top:4px">
              🎂 <?= (int)($viewResident['age'] ?? 0) ?> years old &nbsp;•&nbsp;
              <?= htmlspecialchars($viewResident['gender'] ?? '—') ?> &nbsp;•&nbsp;
              📍 <?= htmlspecialchars(trim(($viewResident['house_number'] ?? '') . ' ' . ($viewResident['street_address'] ?? '') . ', ' . ($viewResident['zone_purok'] ?? ''), ' ,')) ?>
            </div>
          </div>
          <div style="margin-left:auto">
            <span class="badge <?= badgeForResult($vOverallStatus) ?>" style="font-size:13px"><?= htmlspecialchars($vOverallStatus) ?></span>
          </div>
        </div>
      </div>

      <div class="card" style="padding:0;margin-bottom:24px">
        <div class="hm-tabs">
          <button class="sub-tab active" data-sub="overview">Overview</button>
          <button class="sub-tab" data-sub="profile">Health Profile</button>
          <button class="sub-tab" data-sub="checkups">Checkups</button>
          <button class="sub-tab" data-sub="vaccines">Vaccinations</button>
        </div>
      </div>

      <!-- Overview -->
      <div class="sub-panel active" id="sub-overview">
        <div class="stats">
          <div class="stat">
            <h4>Checkup Records</h4>
            <p data-target="<?= count($vCheckups) ?>">0</p>
            <div class="stat-icon">💙</div>
          </div>
          <div class="stat">
            <h4>Vaccines</h4>
            <p data-target="<?= count($vVaccines) ?>">0</p>
            <div class="stat-icon">💉</div>
          </div>
          <div class="stat">
            <h4>Overall Status</h4>
            <p style="font-size:20px"><?= htmlspecialchars($vOverallStatus) ?></p>
            <div class="stat-icon">🛡️</div>
          </div>
        </div>
        <div class="card">
          <h3>Recent Checkups</h3>
          <div class="table-container">
            <table>
              <thead><tr><th>Date</th><th>Type</th><th>Results</th></tr></thead>
              <tbody>
                <?php if ($vCheckups): foreach (array_slice($vCheckups, 0, 5) as $c): ?>
                <tr>
                  <td><?= date('M d, Y', strtotime($c['checkup_date'])) ?></td>
                  <td><?= htmlspecialchars($c['checkup_type']) ?></td>
                  <td><span class="badge <?= badgeForResult($c['results']) ?>"><?= htmlspecialchars($c['results']) ?></span></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="3" style="text-align:center;color:#aaa;padding:16px">No checkup records yet.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="card">
          <h3>Recent Vaccinations</h3>
          <div class="table-container">
            <table>
              <thead><tr><th>Vaccine</th><th>Date Given</th><th>Status</th></tr></thead>
              <tbody>
                <?php if ($vVaccines): foreach (array_slice($vVaccines, 0, 5) as $v): ?>
                <tr>
                  <td><?= htmlspecialchars($v['vaccine_name']) ?></td>
                  <td><?= date('M d, Y', strtotime($v['date_given'])) ?></td>
                  <td><span class="badge <?= badgeForResult($v['status']) ?>"><?= htmlspecialchars($v['status']) ?></span></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="3" style="text-align:center;color:#aaa;padding:16px">No vaccination records yet.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Health Profile -->
      <div class="sub-panel" id="sub-profile">
        <div class="card">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <h3 style="margin:0">Health Profile</h3>
            <button class="btn btn-primary btn-sm" onclick='openStatusModal(<?= $viewResidentId ?>, <?= htmlspecialchars(json_encode($viewResident['full_name']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($vOverallStatus), ENT_QUOTES) ?>)'>Update Status</button>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 24px">
            <div class="hm-info-row" style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px"><span style="color:var(--text-light)">Blood Type</span><span style="font-weight:600"><?= htmlspecialchars($vProfile['blood_type'] ?? '—') ?></span></div>
            <div class="hm-info-row" style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px"><span style="color:var(--text-light)">Allergies</span><span style="font-weight:600"><?= htmlspecialchars($vProfile['allergies'] ?? 'None') ?></span></div>
            <div class="hm-info-row" style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px"><span style="color:var(--text-light)">Height</span><span style="font-weight:600"><?= ($vProfile['height_cm'] ?? '') !== '' ? htmlspecialchars($vProfile['height_cm']) . ' cm' : '—' ?></span></div>
            <div class="hm-info-row" style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px"><span style="color:var(--text-light)">Medical Conditions</span><span style="font-weight:600"><?= htmlspecialchars($vProfile['medical_conditions'] ?? 'None') ?></span></div>
            <div class="hm-info-row" style="display:flex;justify-content:space-between;padding:8px 0;font-size:13px"><span style="color:var(--text-light)">Weight</span><span style="font-weight:600"><?= ($vProfile['weight_kg'] ?? '') !== '' ? htmlspecialchars($vProfile['weight_kg']) . ' kg' : '—' ?></span></div>
            <div class="hm-info-row" style="display:flex;justify-content:space-between;padding:8px 0;font-size:13px"><span style="color:var(--text-light)">Maintenance Medication</span><span style="font-weight:600"><?= htmlspecialchars($vProfile['maintenance_medication'] ?? 'None') ?></span></div>
          </div>
          <div class="text-muted" style="margin-top:12px;font-size:12px">
            Last updated: <?= !empty($vProfile['updated_at']) ? date('M d, Y', strtotime($vProfile['updated_at'])) : 'Not yet set up by resident' ?>
          </div>
        </div>
      </div>

      <!-- Checkups -->
      <div class="sub-panel" id="sub-checkups">
        <div class="card">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <h3 style="margin:0">Checkup Records</h3>
            <button class="btn btn-primary btn-sm" onclick="document.getElementById('checkupModal').style.display='flex'">+ Add Checkup Record</button>
          </div>
          <div class="table-container">
            <table>
              <thead><tr><th>Date</th><th>Type</th><th>BP</th><th>Weight</th><th>Temp</th><th>Findings / Remarks</th><th>Next Follow-up</th><th>Action</th></tr></thead>
              <tbody>
                <?php if ($vCheckups): foreach ($vCheckups as $c): ?>
                <tr>
                  <td><?= date('M d, Y', strtotime($c['checkup_date'])) ?></td>
                  <td><?= htmlspecialchars($c['checkup_type']) ?></td>
                  <td><?= htmlspecialchars($c['blood_pressure'] ?: '—') ?></td>
                  <td><?= ($c['weight_kg'] ?? '') !== '' && $c['weight_kg'] !== null ? htmlspecialchars($c['weight_kg']) . ' kg' : '—' ?></td>
                  <td><?= htmlspecialchars($c['temperature'] ?: '—') ?></td>
                  <td><span class="badge <?= badgeForResult($c['results']) ?>"><?= htmlspecialchars($c['results']) ?></span></td>
                  <td><?= !empty($c['next_followup_date']) ? date('M d, Y', strtotime($c['next_followup_date'])) : '—' ?></td>
                  <td>
                    <button class="btn btn-sm" style="background:none;border:none;cursor:pointer;font-size:16px"
                      onclick='viewCheckup(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)' title="View details">&#128065</button>
                  </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="8" style="text-align:center;color:#aaa;padding:20px">No checkups recorded yet.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Vaccinations -->
      <div class="sub-panel" id="sub-vaccines">
        <div class="card">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <h3 style="margin:0">Vaccination Records</h3>
            <button class="btn btn-primary btn-sm" onclick="document.getElementById('vaccineModal').style.display='flex'">+ Add Vaccination Record</button>
          </div>
          <div class="table-container">
            <table>
              <thead><tr><th>Vaccine</th><th>Date Given</th><th>Dose</th><th>Remarks</th><th>Administered By</th><th>Status</th><th>Action</th></tr></thead>
              <tbody>
                <?php if ($vVaccines): foreach ($vVaccines as $v): ?>
                <tr>
                  <td><?= htmlspecialchars($v['vaccine_name']) ?></td>
                  <td><?= date('M d, Y', strtotime($v['date_given'])) ?></td>
                  <td><?= htmlspecialchars($v['dose'] ?: '—') ?></td>
                  <td><?= htmlspecialchars($v['remarks'] ?? '' ?: '—') ?></td>
                  <td><?= htmlspecialchars($v['recorded_by_name'] ?? '—') ?></td>
                  <td><span class="badge <?= badgeForResult($v['status']) ?>"><?= htmlspecialchars($v['status']) ?></span></td>
                  <td>
                    <button class="btn btn-sm" style="background:none;border:none;cursor:pointer;font-size:16px"
                      onclick='viewVaccine(<?= htmlspecialchars(json_encode($v), ENT_QUOTES) ?>)' title="View details">👁️</button>
                  </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="7" style="text-align:center;color:#aaa;padding:20px">No vaccinations recorded yet.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <?php else: ?>

      <!-- ── Resident search & list ── -->
      <div class="card">
        <h3>Find a Resident</h3>
        <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
          <input type="hidden" name="tab" value="residents">
          <div class="form-group" style="flex:1;min-width:220px;margin:0">
            <label>Search by Name or Barangay ID</label>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="e.g., Juan Dela Cruz or BRGY-2026-0001">
          </div>
          <button class="btn btn-primary" type="submit">Search</button>
          <a href="health_monitoring.php?tab=residents" class="btn">Clear</a>
        </form>
      </div>

      <div class="card">
        <h3>Residents</h3>
        <div class="table-container">
          <table>
            <thead><tr><th>Name</th><th>Age / Gender</th><th>Blood Type</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
              <?php if ($residentsList): foreach ($residentsList as $rl): ?>
              <tr>
                <td><?= htmlspecialchars($rl['full_name']) ?><br><small class="text-muted"><?= htmlspecialchars($rl['barangay_id']) ?></small></td>
                <td><?= (int)($rl['age'] ?? 0) ?> / <?= htmlspecialchars($rl['gender'] ?? '—') ?></td>
                <td><?= htmlspecialchars($rl['blood_type'] ?: '—') ?></td>
                <td><span class="badge <?= badgeForResult($rl['overall_status']) ?>"><?= htmlspecialchars($rl['overall_status']) ?></span></td>
                <td>
                  <a class="btn btn-sm" style="background:#3b82f6;color:#fff;border:none;padding:6px 12px;border-radius:6px;text-decoration:none;font-size:12px"
                     href="health_monitoring.php?tab=residents&resident_id=<?= $rl['id'] ?><?= $search ? '&search=' . urlencode($search) : '' ?>">
                     View Records
                  </a>
                </td>
              </tr>
              <?php endforeach; else: ?>
              <tr><td colspan="5" style="text-align:center;color:#aaa;padding:20px">No residents found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding-top:14px;flex-wrap:wrap;gap:8px">
          <span class="text-muted" style="font-size:13px">
            Showing <?= $totalResidents ? ($offset + 1) : 0 ?>–<?= min($offset + $perPage, $totalResidents) ?> of <?= $totalResidents ?> residents
          </span>
          <div style="display:flex;gap:8px">
            <?php if ($page > 1): ?>
              <a class="btn btn-secondary btn-sm" href="?tab=residents&page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>">← Previous</a>
            <?php else: ?>
              <button class="btn btn-secondary btn-sm" disabled>← Previous</button>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
              <a class="btn btn-secondary btn-sm" href="?tab=residents&page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>">Next →</a>
            <?php else: ?>
              <button class="btn btn-secondary btn-sm" disabled>Next →</button>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php endif; ?>
    </div>
  </main>
</div>

<!-- Add Checkup Modal -->
<div id="checkupModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:28px;width:90%;max-width:460px;max-height:90vh;overflow-y:auto">
    <h3 style="margin-top:0">Add Checkup Record</h3>
    <?php if ($viewResident): ?>
    <div class="form-group">
      <label>Resident</label>
      <input type="text" class="form-control" value="<?= htmlspecialchars($viewResident['full_name']) ?> (<?= htmlspecialchars($viewResident['barangay_id']) ?>)" disabled>
    </div>
    <?php endif; ?>
    <input type="hidden" id="ckResidentId" value="<?= $viewResidentId ?>">
    <div style="display:grid;gap:14px">
      <div class="form-group"><label>Date of Checkup *</label><input type="date" id="ckDate" class="form-control" value="<?= date('Y-m-d') ?>"></div>
      <div class="form-group">
        <label>Checkup Type / Reason *</label>
        <select id="ckType" class="form-control">
          <option>General Checkup</option><option>Blood Pressure</option><option>Vaccination Screening</option>
          <option>Prenatal Checkup</option><option>Dental Checkup</option><option>Other</option>
        </select>
      </div>
      <div class="form-grid" style="margin:0">
        <div class="form-group" style="margin:0"><label>Blood Pressure</label><input type="text" id="ckBp" class="form-control" placeholder="e.g., 120/80"></div>
        <div class="form-group" style="margin:0"><label>Weight (kg)</label><input type="number" step="0.1" id="ckWeight" class="form-control" placeholder="e.g., 70"></div>
      </div>
      <div class="form-grid" style="margin:0">
        <div class="form-group" style="margin:0"><label>Height (cm)</label><input type="number" step="0.1" id="ckHeight" class="form-control" placeholder="e.g., 170"></div>
        <div class="form-group" style="margin:0"><label>Temperature (°C)</label><input type="text" id="ckTemp" class="form-control" placeholder="e.g., 36.5"></div>
      </div>
      <div class="form-group">
        <label>Findings / Remarks</label>
        <textarea id="ckNotes" class="form-control" rows="3" placeholder="Enter findings or remarks..."></textarea>
      </div>
      <div class="form-grid" style="margin:0">
        <div class="form-group" style="margin:0">
          <label>Results</label>
          <select id="ckResults" class="form-control">
            <option>Normal</option><option>Needs Monitoring</option><option>Requires Attention</option>
          </select>
        </div>
        <div class="form-group" style="margin:0"><label>Next Follow-up Date</label><input type="date" id="ckFollowup" class="form-control"></div>
      </div>
      <div style="display:flex;gap:10px">
        <button class="btn btn-secondary" style="flex:1" onclick="document.getElementById('checkupModal').style.display='none'">Cancel</button>
        <button class="btn btn-primary" style="flex:1" onclick="submitCheckup()">Save Record</button>
      </div>
    </div>
  </div>
</div>

<!-- Add Vaccination Modal -->
<div id="vaccineModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:28px;width:90%;max-width:420px">
    <h3 style="margin-top:0">Add Vaccination Record</h3>
    <?php if ($viewResident): ?>
    <div class="form-group">
      <label>Resident</label>
      <input type="text" class="form-control" value="<?= htmlspecialchars($viewResident['full_name']) ?> (<?= htmlspecialchars($viewResident['barangay_id']) ?>)" disabled>
    </div>
    <?php endif; ?>
    <input type="hidden" id="vxResidentId" value="<?= $viewResidentId ?>">
    <div style="display:grid;gap:14px">
      <div class="form-grid" style="margin:0">
        <div class="form-group" style="margin:0"><label>Vaccine *</label><input type="text" id="vxName" class="form-control" placeholder="e.g., COVID-19 Vaccine"></div>
        <div class="form-group" style="margin:0"><label>Date Given *</label><input type="date" id="vxDate" class="form-control" value="<?= date('Y-m-d') ?>"></div>
      </div>
      <div class="form-group"><label>Dose</label><input type="text" id="vxDose" class="form-control" placeholder="e.g., Booster, 1st dose"></div>
      <div class="form-group"><label>Remarks</label><textarea id="vxRemarks" class="form-control" rows="2" placeholder="Optional remarks (e.g., no adverse effects)"></textarea></div>
      <div class="form-group">
        <label>Status</label>
        <select id="vxStatus" class="form-control"><option>Completed</option><option>Scheduled</option><option>Missed</option></select>
      </div>
      <div style="display:flex;gap:10px">
        <button class="btn btn-secondary" style="flex:1" onclick="document.getElementById('vaccineModal').style.display='none'">Cancel</button>
        <button class="btn btn-primary" style="flex:1" onclick="submitVaccine()">Save Record</button>
      </div>
    </div>
  </div>
</div>

<!-- View Record Modal (read-only, for the 👁 action) -->
<div id="viewRecordModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:28px;width:90%;max-width:420px">
    <h3 id="vrTitle" style="margin-top:0">Record Details</h3>
    <div id="vrBody" style="font-size:14px;line-height:1.9"></div>
    <button class="btn btn-secondary" style="margin-top:16px;width:100%" onclick="document.getElementById('viewRecordModal').style.display='none'">Close</button>
  </div>
</div>

<!-- Update Status Modal -->
<div id="statusModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:28px;width:90%;max-width:380px">
    <h3 style="margin-top:0">Update Health Status — <span id="stName"></span></h3>
    <input type="hidden" id="stResidentId">
    <div class="form-group">
      <label>Overall Status</label>
      <select id="stStatus" class="form-control">
        <option value="Good">Good</option>
        <option value="Needs Monitoring">Needs Monitoring</option>
        <option value="Requires Attention">Requires Attention</option>
      </select>
    </div>
    <div style="display:flex;gap:10px;margin-top:14px">
      <button class="btn btn-secondary" style="flex:1" onclick="document.getElementById('statusModal').style.display='none'">Cancel</button>
      <button class="btn btn-primary" style="flex:1" onclick="saveStatus()">Save</button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="assets/js/app.js"></script>
<script>
// ── Top-level tabs (Dashboard / Residents) ──────────────────────
function activateTopTab(name) {
  document.querySelectorAll('.hm-tab').forEach(x => x.classList.toggle('active', x.dataset.tab === name));
  document.querySelectorAll(':scope > .hm-panel, .main > .hm-panel').forEach(() => {}); // no-op guard
  document.getElementById('tab-dashboard').classList.toggle('active', name === 'dashboard');
  document.getElementById('tab-residents').classList.toggle('active', name === 'residents');
}
document.querySelectorAll('.hm-tab').forEach(t => t.addEventListener('click', () => activateTopTab(t.dataset.tab)));

// Default to Residents tab if we're viewing/searching a resident, otherwise Dashboard
const initialTab = <?= json_encode(($viewResidentId || $search || isset($_GET['tab'])) ? 'residents' : 'dashboard') ?>;
activateTopTab(initialTab);

// ── Sub-tabs inside a resident profile (Overview / Profile / Checkups / Vaccinations) ──
document.querySelectorAll('.sub-tab').forEach(t => t.addEventListener('click', () => {
  document.querySelectorAll('.sub-tab').forEach(x => x.classList.toggle('active', x === t));
  document.querySelectorAll('.sub-panel').forEach(p => p.classList.toggle('active', p.id === 'sub-' + t.dataset.sub));
}));

// Health status doughnut
window.addEventListener('DOMContentLoaded', () => {
  const ctx = document.getElementById('statusChart')?.getContext('2d');
  if (ctx) {
    new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: ['Good', 'Needs Monitoring', 'Requires Attention'],
        datasets: [{
          data: [<?= $statusMap['Good'] ?>, <?= $statusMap['Needs Monitoring'] ?>, <?= $statusMap['Requires Attention'] ?>],
          backgroundColor: ['#22c55e', '#f59e0b', '#ef4444'],
          hoverOffset: 8,
        }]
      },
      options: { responsive: true, plugins: { legend: { display: false } } }
    });
  }
});

async function submitCheckup() {
  const resident = document.getElementById('ckResidentId').value;
  const date     = document.getElementById('ckDate').value;
  if (!resident || !date) { showToast('Missing resident or date.', 'error'); return; }

  const fd = new FormData();
  fd.append('action', 'add_checkup');
  fd.append('resident_id', resident);
  fd.append('checkup_date', date);
  fd.append('checkup_type', document.getElementById('ckType').value);
  fd.append('blood_pressure', document.getElementById('ckBp').value);
  fd.append('weight_kg', document.getElementById('ckWeight').value);
  fd.append('height_cm', document.getElementById('ckHeight').value);
  fd.append('temperature', document.getElementById('ckTemp').value);
  fd.append('results', document.getElementById('ckResults').value);
  fd.append('notes', document.getElementById('ckNotes').value);
  fd.append('next_followup_date', document.getElementById('ckFollowup').value);

  const res = await fetch('health_monitoring.php', { method: 'POST', body: fd });
  const d   = await res.json();
  if (d.success) { showToast(d.message, 'success'); setTimeout(() => location.reload(), 800); }
  else showToast(d.message, 'error');
}

async function submitVaccine() {
  const resident = document.getElementById('vxResidentId').value;
  const name     = document.getElementById('vxName').value.trim();
  const date     = document.getElementById('vxDate').value;
  if (!resident || !name || !date) { showToast('Vaccine name and date are required.', 'error'); return; }

  const fd = new FormData();
  fd.append('action', 'add_vaccination');
  fd.append('resident_id', resident);
  fd.append('vaccine_name', name);
  fd.append('date_given', date);
  fd.append('dose', document.getElementById('vxDose').value);
  fd.append('remarks', document.getElementById('vxRemarks').value);
  fd.append('status', document.getElementById('vxStatus').value);

  const res = await fetch('health_monitoring.php', { method: 'POST', body: fd });
  const d   = await res.json();
  if (d.success) { showToast(d.message, 'success'); setTimeout(() => location.reload(), 800); }
  else showToast(d.message, 'error');
}

function viewCheckup(c) {
  document.getElementById('vrTitle').textContent = 'Checkup — ' + c.checkup_type;
  document.getElementById('vrBody').innerHTML = `
    <div><strong>Date:</strong> ${c.checkup_date}</div>
    <div><strong>Blood Pressure:</strong> ${c.blood_pressure || '—'}</div>
    <div><strong>Weight:</strong> ${c.weight_kg ? c.weight_kg + ' kg' : '—'}</div>
    <div><strong>Height:</strong> ${c.height_cm ? c.height_cm + ' cm' : '—'}</div>
    <div><strong>Temperature:</strong> ${c.temperature || '—'}</div>
    <div><strong>Results:</strong> ${c.results}</div>
    <div><strong>Findings / Remarks:</strong> ${c.notes || '—'}</div>
    <div><strong>Next Follow-up:</strong> ${c.next_followup_date || '—'}</div>
    <div><strong>Recorded By:</strong> ${c.recorded_by_name || '—'}</div>
  `;
  document.getElementById('viewRecordModal').style.display = 'flex';
}

function viewVaccine(v) {
  document.getElementById('vrTitle').textContent = 'Vaccination — ' + v.vaccine_name;
  document.getElementById('vrBody').innerHTML = `
    <div><strong>Date Given:</strong> ${v.date_given}</div>
    <div><strong>Dose:</strong> ${v.dose || '—'}</div>
    <div><strong>Status:</strong> ${v.status}</div>
    <div><strong>Remarks:</strong> ${v.remarks || '—'}</div>
    <div><strong>Administered By:</strong> ${v.recorded_by_name || '—'}</div>
  `;
  document.getElementById('viewRecordModal').style.display = 'flex';
}

function openStatusModal(id, name, currentStatus) {
  document.getElementById('stResidentId').value = id;
  document.getElementById('stName').textContent = name;
  document.getElementById('stStatus').value = currentStatus;
  document.getElementById('statusModal').style.display = 'flex';
}

async function saveStatus() {
  const fd = new FormData();
  fd.append('action', 'update_status');
  fd.append('resident_id', document.getElementById('stResidentId').value);
  fd.append('overall_status', document.getElementById('stStatus').value);
  const res = await fetch('health_monitoring.php', { method: 'POST', body: fd });
  const d   = await res.json();
  if (d.success) { showToast(d.message, 'success'); setTimeout(() => location.reload(), 700); }
  else showToast(d.message, 'error');
}
</script>
</body>
</html>