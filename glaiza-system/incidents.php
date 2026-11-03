<?php
// incidents.php — Admin: Incident / blotter & complaint management
session_start();
require_once 'includes/db.php';
requireAdminLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_report') {
    $id = (int)($_POST['id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $notes  = trim($_POST['notes']  ?? '');
    if (!in_array($status, ['Pending','Under Review','Resolved','Dismissed'])) jsonError('Invalid status.');
    if (!dbFetchOne("SELECT id FROM incident_reports WHERE id=?", [$id])) jsonError('Report not found.');
    dbExecute("UPDATE incident_reports SET status=?, admin_notes=?, handled_by=? WHERE id=?", [$status, $notes, $_SESSION['admin_id'], $id]);
    logActivity("Updated report #$id to $status", 'Incidents');
    jsonOk([], 'Report updated.');
}

$filter = trim($_GET['status'] ?? '');
$type   = trim($_GET['type']   ?? '');
$cond = []; $params = [];
if ($filter) { $cond[] = 'ir.status=?';      $params[] = $filter; }
if ($type)   { $cond[] = 'ir.report_type=?'; $params[] = $type; }
$where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';
$reports = dbFetchAll("SELECT ir.*, CONCAT(r.first_name,' ',r.last_name) AS resident_name, r.barangay_id, r.phone
    FROM incident_reports ir JOIN residents r ON ir.resident_id=r.id $where
    ORDER BY FIELD(ir.status,'Pending','Under Review','Resolved','Dismissed'), ir.created_at DESC LIMIT 100", $params);
$s = dbFetchOne("SELECT COUNT(*) total, SUM(status='Pending') pending, SUM(status='Under Review') review, SUM(status='Resolved') resolved FROM incident_reports") ?? [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Incidents & Complaints — <?= getSiteName() ?></title>
  <link rel="stylesheet" href="assets/css/style.css">
  <?php renderThemeStyle(); ?>
</head>
<body>
<div class="app">
  <?php include 'includes/sidebar_admin.php'; ?>
  <main class="main">
    <div class="topbar">
      <div><h3>Incidents &amp; Complaints</h3><div class="text-muted">Review blotter reports and mediation requests from residents.</div></div>
    </div>
    <div class="stats">
      <div class="stat"><h4>Total Reports</h4><p data-target="<?= (int)($s['total'] ?? 0) ?>">0</p><div class="stat-icon">🛡️</div></div>
      <div class="stat"><h4>Pending</h4><p data-target="<?= (int)($s['pending'] ?? 0) ?>">0</p><div class="stat-icon">⏳</div><div class="stat-change warning">Needs action</div></div>
      <div class="stat"><h4>Under Review</h4><p data-target="<?= (int)($s['review'] ?? 0) ?>">0</p><div class="stat-icon">🔎</div></div>
      <div class="stat"><h4>Resolved</h4><p data-target="<?= (int)($s['resolved'] ?? 0) ?>">0</p><div class="stat-icon">✅</div><div class="stat-change positive">Closed</div></div>
    </div>
    <div class="card">
      <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <div class="form-group" style="margin:0;min-width:160px"><label>Type</label><select name="type">
          <option value="">All</option><?php foreach (['Incident','Complaint'] as $t): ?><option <?= $type===$t?'selected':'' ?>><?= $t ?></option><?php endforeach; ?></select></div>
        <div class="form-group" style="margin:0;min-width:160px"><label>Status</label><select name="status">
          <option value="">All</option><?php foreach (['Pending','Under Review','Resolved','Dismissed'] as $t): ?><option <?= $filter===$t?'selected':'' ?>><?= $t ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-primary" type="submit">Filter</button><a href="incidents.php" class="btn">Clear</a>
      </form>
    </div>
    <div class="card">
      <h3>Reports</h3>
      <div class="table-container"><table>
        <thead><tr><th>Reference</th><th>Resident</th><th>Type / Category</th><th>Location</th><th>Filed</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php if ($reports): foreach ($reports as $r):
          $b = match($r['status']) { 'Resolved'=>'badge-success','Under Review'=>'badge-info','Dismissed'=>'badge-danger', default=>'badge-warning' }; ?>
          <tr><td><?= htmlspecialchars($r['reference_no']) ?></td>
          <td><?= htmlspecialchars($r['resident_name']) ?><small class="text-muted" style="display:block"><?= htmlspecialchars($r['barangay_id']) ?></small></td>
          <td><?= htmlspecialchars($r['report_type']) ?><small class="text-muted" style="display:block"><?= htmlspecialchars($r['category']) ?></small></td>
          <td><?= htmlspecialchars($r['location'] ?: '—') ?></td><td><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
          <td><span class="badge <?= $b ?>"><?= htmlspecialchars($r['status']) ?></span></td>
          <td><button class="btn btn-sm" style="background:#3b82f6;color:#fff;padding:6px 10px;border:none;border-radius:6px;cursor:pointer;font-size:12px"
              onclick='openReport(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)'>Review</button></td></tr>
        <?php endforeach; else: ?><tr><td colspan="7" style="text-align:center;color:#aaa;padding:20px">No reports found.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </main>
</div>

<div id="reportModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:white;border-radius:12px;padding:28px;width:90%;max-width:480px;max-height:90vh;overflow-y:auto">
    <h3 style="margin-top:0" id="rmTitle">Report</h3>
    <p id="rmMeta" class="text-muted" style="font-size:13px;margin:0 0 10px"></p>
    <div id="rmDetails" style="background:var(--muted);border-radius:8px;padding:12px;font-size:14px;margin-bottom:14px"></div>
    <input type="hidden" id="rmId">
    <div class="form-group"><label>Status</label><select id="rmStatus" class="form-control">
      <option>Pending</option><option>Under Review</option><option>Resolved</option><option>Dismissed</option></select></div>
    <div class="form-group"><label>Note to resident</label><textarea id="rmNotes" class="form-control" rows="3" placeholder="e.g., Lupon hearing set for Friday 9 AM"></textarea></div>
    <div style="display:flex;gap:10px"><button class="btn btn-primary" style="flex:1" onclick="saveReport()">Save</button>
    <button class="btn btn-secondary" style="flex:1" onclick="document.getElementById('reportModal').style.display='none'">Cancel</button></div>
  </div>
</div>
<script src="assets/js/app.js"></script>
<script>
function openReport(r) {
  rmId.value = r.id; rmStatus.value = r.status; rmNotes.value = r.admin_notes || '';
  rmTitle.textContent = r.reference_no + ' — ' + r.category;
  rmMeta.textContent = r.resident_name + ' (' + r.barangay_id + ')' + (r.phone ? ' · ' + r.phone : '');
  rmDetails.textContent = r.details;
  reportModal.style.display = 'flex';
}
async function saveReport() {
  const fd = new FormData();
  fd.append('action','update_report'); fd.append('id', rmId.value);
  fd.append('status', rmStatus.value);  fd.append('notes', rmNotes.value);
  const d = await (await fetch('incidents.php', {method:'POST', body:fd})).json();
  showToast(d.message, d.success ? 'success' : 'error');
  if (d.success) setTimeout(() => location.reload(), 700);
}
</script>
</body>
</html>
