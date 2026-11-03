<?php
// ============================================================
// appointments.php  — Admin: Manage resident appointment bookings
// Reads/writes the SAME `appointments` table used by
// resident_appointments.php, so both sides stay in sync.
// ============================================================
session_start();
require_once 'includes/db.php';
requireAdminLogin();

// Provide health-dependent purpose options for a given resident (admin AJAX)
if (isset($_GET['action']) && $_GET['action'] === 'get_health_purposes') {
  $residentId = (int)($_GET['resident_id'] ?? 0);
  if (!$residentId) {
    header('Content-Type: application/json'); echo json_encode(['success'=>false,'purposes'=>[]]); exit;
  }
  $profile = dbFetchOne("SELECT * FROM health_profiles WHERE resident_id=?", [$residentId]);
  $purposes = ['Health Checkup', 'Vaccination'];
  if (!empty($profile)) {
    if (!empty($profile['maintenance_medication'])) $purposes[] = 'Medication Refill';
    if (!empty($profile['medical_conditions'])) {
      $conds = preg_split('/[,;]+/', $profile['medical_conditions']);
      $first = trim($conds[0]);
      if ($first !== '') $purposes[] = 'Follow-up: ' . $first;
    }
  }
  header('Content-Type: application/json');
  echo json_encode(['success'=>true,'purposes'=>$purposes]);
  exit;
}

// ── AJAX handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Update status (Confirm / Complete / Cancel), optionally reschedule date+time
    if ($action === 'update_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $notes  = trim($_POST['notes'] ?? '');

        $validStatuses = ['Pending','Confirmed','Rescheduled','Completed','Cancelled'];
        if (!$id || !in_array($status, $validStatuses)) jsonError('Invalid request.');

        $existing = dbFetchOne("SELECT * FROM appointments WHERE id=?", [$id]);
        if (!$existing) jsonError('Appointment not found.');

        // If rescheduling, require a new date/time and update those columns too
        if ($status === 'Rescheduled') {
            $newDate = trim($_POST['new_date'] ?? '');
            $newTime = trim($_POST['new_time'] ?? '');
            if (!$newDate || !$newTime) jsonError('Please provide a new date and time to reschedule.');

            dbExecute(
                "UPDATE appointments SET status=?, preferred_date=?, preferred_time=?, notes=? WHERE id=?",
                [$status, $newDate, $newTime, $notes ?: $existing['notes'], $id]
            );
            logActivity("Rescheduled appointment #$id to $newDate $newTime", 'Appointments');
            jsonOk([], 'Appointment rescheduled.');
        }

        dbExecute(
            "UPDATE appointments SET status=?, notes=? WHERE id=?",
            [$status, $notes ?: $existing['notes'], $id]
        );
        logActivity("Updated appointment #$id to $status", 'Appointments');
        jsonOk([], 'Appointment updated.');
    }

    // Book an appointment on behalf of a resident (walk-in / phone request)
    if ($action === 'admin_book') {
        $residentId = (int)($_POST['resident_id'] ?? 0);
        $purpose    = trim($_POST['purpose'] ?? '');
        $date       = trim($_POST['date'] ?? '');
        $time       = trim($_POST['time'] ?? '');
        $notes      = trim($_POST['notes'] ?? '');

        if (!$residentId || !$purpose || !$date || !$time) jsonError('Resident, purpose, date, and time are required.');

        $resident = dbFetchOne("SELECT id FROM residents WHERE id=?", [$residentId]);
        if (!$resident) jsonError('Resident not found.');

        dbInsert(
            "INSERT INTO appointments (resident_id, purpose, preferred_date, preferred_time, notes, status)
             VALUES (?,?,?,?,?,'Confirmed')",
            [$residentId, $purpose, $date, $time, $notes]
        );
        logActivity("Admin booked appointment for resident #$residentId", 'Appointments');
        jsonOk([], 'Appointment booked and confirmed.');
    }
    exit;
}

// ── Filters ──────────────────────────────────────────────────
$search       = trim($_GET['search'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterDate   = trim($_GET['date']   ?? '');

$conditions = [];
$params     = [];

if ($search) {
    $conditions[] = "(CONCAT(r.first_name,' ',r.last_name) LIKE ? OR r.barangay_id LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filterStatus) {
    $conditions[] = "a.status=?";
    $params[] = $filterStatus;
}
if ($filterDate) {
    $conditions[] = "a.preferred_date=?";
    $params[] = $filterDate;
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$appointments = dbFetchAll("
  SELECT a.*,
         CONCAT(r.first_name,' ',r.last_name) AS resident_name,
         r.barangay_id, r.phone
  FROM appointments a
  JOIN residents r ON a.resident_id = r.id
  $where
  ORDER BY
    FIELD(a.status,'Pending','Confirmed','Rescheduled','Completed','Cancelled'),
    a.preferred_date ASC
  LIMIT 100
", $params);

// ── Stats ────────────────────────────────────────────────────
$statsRow = dbFetchOne("
  SELECT
    SUM(preferred_date = CURDATE() AND status NOT IN ('Cancelled')) AS today,
    SUM(status='Pending')   AS pending,
    SUM(status='Confirmed') AS confirmed,
    SUM(status='Completed') AS completed
  FROM appointments
") ?? [];

// Residents list for the "Book for Resident" modal
$residentsList = dbFetchAll(
    "SELECT id, CONCAT(first_name,' ',last_name) AS full_name, barangay_id
     FROM residents WHERE status != 'Inactive' ORDER BY first_name"
);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Appointments — <?= getSiteName() ?></title>
  <link rel="stylesheet" href="assets/css/style.css">
  <?php renderThemeStyle(); ?>
</head>
<body>
<div class="app">
  <?php include 'includes/sidebar_admin.php'; ?>
  <main class="main">
    <div class="topbar">
      <div>
        <h3>Appointments</h3>
        <div class="text-muted">Manage resident appointment bookings — confirm, reschedule, or complete visits.</div>
      </div>
      <div class="topbar-actions">
        <button class="btn btn-primary btn-sm" onclick="document.getElementById('bookModal').style.display='flex'">+ Book for Resident</button>
      </div>
    </div>

    <div class="stats">
      <div class="stat">
        <h4>Today</h4>
        <p data-target="<?= (int)($statsRow['today'] ?? 0) ?>">0</p>
        <div class="stat-icon">📅</div>
        <div class="stat-change positive">Scheduled today</div>
      </div>
      <div class="stat">
        <h4>Pending</h4>
        <p data-target="<?= (int)($statsRow['pending'] ?? 0) ?>">0</p>
        <div class="stat-icon">⏳</div>
        <div class="stat-change warning">Needs review</div>
      </div>
      <div class="stat">
        <h4>Confirmed</h4>
        <p data-target="<?= (int)($statsRow['confirmed'] ?? 0) ?>">0</p>
        <div class="stat-icon">✅</div>
        <div class="stat-change positive">Upcoming</div>
      </div>
      <div class="stat">
        <h4>Completed</h4>
        <p data-target="<?= (int)($statsRow['completed'] ?? 0) ?>">0</p>
        <div class="stat-icon">🏁</div>
        <div class="stat-change positive">Finished visits</div>
      </div>
    </div>

    <!-- Filters -->
    <div class="card">
      <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <div class="form-group" style="flex:1;min-width:180px;margin:0">
          <label>Search Resident</label>
          <input type="text" name="search" placeholder="Name or Barangay ID" value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="form-group" style="margin:0;min-width:160px">
          <label>Status</label>
          <select name="status">
            <option value="">All</option>
            <?php foreach (['Pending','Confirmed','Rescheduled','Completed','Cancelled'] as $s): ?>
              <option value="<?= $s ?>" <?= $filterStatus===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0;min-width:160px">
          <label>Date</label>
          <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>">
        </div>
        <button class="btn btn-primary" type="submit">Filter</button>
        <a href="appointments.php" class="btn">Clear</a>
      </form>
    </div>

    <!-- Appointments Table -->
    <div class="card">
      <h3>All Appointments</h3>
      <div class="table-container">
        <table>
          <thead>
            <tr>
              <th>Resident</th>
              <th>Purpose</th>
              <th>Date</th>
              <th>Time</th>
              <th>Status</th>
              <th>Notes</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody id="appointmentsBody">
            <?php if ($appointments): foreach ($appointments as $ap):
              $badge = match($ap['status']) {
                  'Confirmed'   => 'badge-success',
                  'Completed'   => 'badge-success',
                  'Pending'     => 'badge-warning',
                  'Rescheduled' => 'badge-info',
                  'Cancelled'   => 'badge-danger',
                  default       => 'badge-info',
              };
            ?>
            <tr id="row-<?= $ap['id'] ?>">
              <td>
                <?= htmlspecialchars($ap['resident_name']) ?>
                <small class="text-muted" style="display:block"><?= htmlspecialchars($ap['barangay_id']) ?></small>
              </td>
              <td><?= htmlspecialchars($ap['purpose']) ?></td>
              <td><?= date('M d, Y', strtotime($ap['preferred_date'])) ?></td>
              <td><?= htmlspecialchars($ap['preferred_time']) ?></td>
              <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($ap['status']) ?></span></td>
              <td style="max-width:200px;font-size:12px;color:var(--text-light)"><?= htmlspecialchars($ap['notes'] ?: '—') ?></td>
              <td>
                <button class="btn btn-sm" style="background:#3b82f6;color:white;padding:6px 10px;border:none;border-radius:6px;cursor:pointer;font-size:12px"
                  onclick='openStatusModal(<?= htmlspecialchars(json_encode($ap)) ?>)'>
                  Manage
                </button>
              </td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="7" style="text-align:center;color:#aaa;padding:20px;">No appointments found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>

<!-- Manage Status Modal -->
<div id="statusModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:12px;padding:28px;width:90%;max-width:440px">
    <h3 style="margin-top:0">Manage Appointment</h3>
    <p id="modalResidentInfo" style="font-size:13px;color:var(--text-light);margin:0 0 16px"></p>
    <div style="display:grid;gap:14px">
      <div class="form-group" style="margin:0">
        <label>Status</label>
        <select id="modalStatus" class="form-control" onchange="toggleRescheduleFields()">
          <option value="Pending">Pending</option>
          <option value="Confirmed">Confirmed</option>
          <option value="Rescheduled">Rescheduled</option>
          <option value="Completed">Completed</option>
          <option value="Cancelled">Cancelled</option>
        </select>
      </div>
      <div id="rescheduleFields" style="display:none;gap:14px;grid-template-columns:1fr 1fr" class="form-grid">
        <div class="form-group" style="margin:0">
          <label>New Date</label>
          <input type="date" id="modalNewDate" class="form-control">
        </div>
        <div class="form-group" style="margin:0">
          <label>New Time</label>
          <select id="modalNewTime" class="form-control">
            <option>8:00 AM - 9:00 AM</option>
            <option>9:00 AM - 10:00 AM</option>
            <option>10:00 AM - 11:00 AM</option>
            <option>11:00 AM - 12:00 PM</option>
            <option>1:00 PM - 2:00 PM</option>
            <option>2:00 PM - 3:00 PM</option>
            <option>3:00 PM - 4:00 PM</option>
          </select>
        </div>
      </div>
      <div class="form-group" style="margin:0">
        <label>Notes (visible to resident context)</label>
        <textarea id="modalNotes" class="form-control" rows="3" placeholder="Optional notes about this appointment..."></textarea>
      </div>
      <input type="hidden" id="modalId">
      <div style="display:flex;gap:10px;margin-top:4px">
        <button class="btn btn-primary" style="flex:1" onclick="saveStatus()">Save</button>
        <button class="btn btn-secondary" style="flex:1" onclick="document.getElementById('statusModal').style.display='none'">Cancel</button>
      </div>
    </div>
  </div>
</div>

<!-- Book for Resident Modal -->
<div id="bookModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:12px;padding:28px;width:90%;max-width:460px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
      <h3 style="margin:0">Book Appointment for Resident</h3>
      <button onclick="document.getElementById('bookModal').style.display='none'" style="background:none;border:none;font-size:20px;cursor:pointer;color:#9ca3af">✕</button>
    </div>
    <div style="display:grid;gap:14px">
      <div class="form-group" style="margin:0">
        <label>Resident *</label>
        <select id="bkResident" class="form-control">
          <option value="">Select resident...</option>
          <?php foreach ($residentsList as $r): ?>
          <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['full_name']) ?> (<?= htmlspecialchars($r['barangay_id']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0">
        <label>Purpose *</label>
        <select id="bkPurpose" class="form-control">
          <option value="">Select...</option>
          <option>Request Document</option>
          <option>Update Records</option>
          <option>Certificate of Residency</option>
          <option>Permit Application</option>
          <option>General Inquiry</option>
        </select>
      </div>
      <div class="form-grid" style="margin:0">
        <div class="form-group" style="margin:0">
          <label>Date *</label>
          <input type="date" id="bkDate" class="form-control" min="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group" style="margin:0">
          <label>Time *</label>
          <select id="bkTime" class="form-control">
            <option>8:00 AM - 9:00 AM</option>
            <option>9:00 AM - 10:00 AM</option>
            <option>10:00 AM - 11:00 AM</option>
            <option>11:00 AM - 12:00 PM</option>
            <option>1:00 PM - 2:00 PM</option>
            <option>2:00 PM - 3:00 PM</option>
            <option>3:00 PM - 4:00 PM</option>
          </select>
        </div>
      </div>
      <div class="form-group" style="margin:0">
        <label>Notes</label>
        <textarea id="bkNotes" class="form-control" rows="2" placeholder="Optional"></textarea>
      </div>
      <div style="display:flex;gap:10px;margin-top:4px">
        <button class="btn btn-primary" style="flex:1" onclick="submitAdminBook()">Book Appointment</button>
        <button class="btn btn-secondary" style="flex:1" onclick="document.getElementById('bookModal').style.display='none'">Cancel</button>
      </div>
    </div>
  </div>
</div>

<script src="assets/js/app.js"></script>
<script>
function openStatusModal(ap) {
  document.getElementById('modalId').value     = ap.id;
  document.getElementById('modalStatus').value = ap.status;
  document.getElementById('modalNotes').value  = ap.notes || '';
  document.getElementById('modalNewDate').value = ap.preferred_date;
  document.getElementById('modalNewTime').value = ap.preferred_time;
  document.getElementById('modalResidentInfo').textContent =
    ap.resident_name + ' (' + ap.barangay_id + ') — ' + ap.purpose;
  toggleRescheduleFields();
  document.getElementById('statusModal').style.display = 'flex';
}

function toggleRescheduleFields() {
  const status = document.getElementById('modalStatus').value;
  document.getElementById('rescheduleFields').style.display = (status === 'Rescheduled') ? 'grid' : 'none';
}

async function saveStatus() {
  const status = document.getElementById('modalStatus').value;
  const fd = new FormData();
  fd.append('action', 'update_status');
  fd.append('id',     document.getElementById('modalId').value);
  fd.append('status', status);
  fd.append('notes',  document.getElementById('modalNotes').value);
  if (status === 'Rescheduled') {
    fd.append('new_date', document.getElementById('modalNewDate').value);
    fd.append('new_time', document.getElementById('modalNewTime').value);
  }

  const res  = await fetch('appointments.php', { method:'POST', body:fd });
  const data = await res.json();
  if (data.success) {
    showToast(data.message, 'success');
    document.getElementById('statusModal').style.display = 'none';
    setTimeout(() => location.reload(), 700);
  } else {
    showToast(data.message || 'Error', 'error');
  }
}

async function submitAdminBook() {
  const resident = document.getElementById('bkResident').value;
  const purpose  = document.getElementById('bkPurpose').value;
  const date     = document.getElementById('bkDate').value;
  const time     = document.getElementById('bkTime').value;
  const notes    = document.getElementById('bkNotes').value;

  if (!resident || !purpose || !date) { showToast('Resident, purpose, and date are required.', 'error'); return; }

  const fd = new FormData();
  fd.append('action',      'admin_book');
  fd.append('resident_id', resident);
  fd.append('purpose',     purpose);
  fd.append('date',        date);
  fd.append('time',        time);
  fd.append('notes',       notes);

  const res  = await fetch('appointments.php', { method:'POST', body:fd });
  const data = await res.json();
  if (data.success) {
    showToast(data.message, 'success');
    document.getElementById('bookModal').style.display = 'none';
    setTimeout(() => location.reload(), 800);
  } else {
    showToast(data.message || 'Error', 'error');
  }
}

// Populate admin purpose select with health-based options when a resident is chosen
document.getElementById('bkResident')?.addEventListener('change', async function() {
  const residentId = this.value;
  const sel = document.getElementById('bkPurpose');
  if (!sel) return;
  // remove previous health-inserted options
  [...sel.querySelectorAll('option[data-health]')].forEach(o => o.remove());
  if (!residentId) return;
  try {
    const res = await fetch('appointments.php?action=get_health_purposes&resident_id=' + encodeURIComponent(residentId));
    const d = await res.json();
    if (!d.success) return;
    const firstOpt = sel.querySelector('option[value=""]') || sel.options[0];
    const insertBefore = firstOpt ? firstOpt.nextSibling : sel.firstChild;
    d.purposes.forEach(p => {
      const opt = document.createElement('option');
      opt.value = p; opt.textContent = p; opt.setAttribute('data-health', '1');
      if (insertBefore) sel.insertBefore(opt, insertBefore);
      else sel.appendChild(opt);
    });
  } catch (e) {
    console.error(e);
  }
});
</script>
</body>
</html>
