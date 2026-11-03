<?php
// resident_incidents.php — Resident: Report an incident / file a complaint, track status
session_start();
require_once 'includes/db.php';
requireResidentLogin();
$residentId = $_SESSION['resident_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_report') {
    $type     = ($_POST['report_type'] ?? '') === 'Complaint' ? 'Complaint' : 'Incident';
    $category = trim($_POST['category'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $details  = trim($_POST['details']  ?? '');
    if (!$category)             jsonError('Please choose what happened.');
    if (strlen($details) < 10)  jsonError('Please add a little more detail (at least 10 characters).');

    $prefix = $type === 'Complaint' ? 'CMP' : 'BLT';
    $count  = (int)(dbFetchOne("SELECT COUNT(*) c FROM incident_reports WHERE YEAR(created_at)=?", [date('Y')])['c'] ?? 0);
    $ref    = sprintf('%s-%s-%04d', $prefix, date('Y'), $count + 1);
    dbInsert("INSERT INTO incident_reports (reference_no, resident_id, report_type, category, location, details) VALUES (?,?,?,?,?,?)",
        [$ref, $residentId, $type, $category, $location, $details]);
    logActivity("Submitted $type report $ref", 'Incidents', 'resident');
    jsonOk(['reference_no' => $ref], "Report sent. Your reference number is $ref.");
}

$reports = dbFetchAll("SELECT * FROM incident_reports WHERE resident_id=? ORDER BY created_at DESC", [$residentId]);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Incidents & Complaints — Barangay Talisay</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <?php renderThemeStyle(); ?>
</head>
<body>
<div class="app">
  <?php include 'includes/sidebar_resident.php'; ?>
  <main class="main">
    <div class="topbar">
      <div><h3>Incidents &amp; Complaints</h3><div class="text-muted">Report what happened. Barangay staff will follow up.</div></div>
      <div class="topbar-actions"><a href="resident_emergency.php" class="btn btn-secondary btn-sm" style="color:#991b1b">🚨 Emergency numbers</a></div>
    </div>

    <div class="card">
      <h3>New Report</h3>
      <p class="text-muted" style="margin-bottom:16px">For emergencies, call the hotline first. Disputes between neighbors are first heard by the Lupon.</p>
      <div class="form-grid">
        <div class="form-group"><label>Type *</label>
          <select id="irType" class="form-control" onchange="fillCats()">
            <option value="Incident">Incident / Blotter</option><option value="Complaint">Complaint / Mediation</option>
          </select></div>
        <div class="form-group"><label>What happened? *</label><select id="irCat" class="form-control"></select></div>
        <div class="form-group"><label>Where? (Purok / Street)</label><input type="text" id="irLoc" class="form-control" placeholder="e.g., Zone 2, near the covered court"></div>
      </div>
      <div class="form-group"><label>Tell us more *</label><textarea id="irDetails" class="form-control" rows="4" placeholder="Describe what happened, when, and who was involved."></textarea></div>
      <button class="btn btn-primary" onclick="submitReport()">📨 Send Report</button>
    </div>

    <div class="card">
      <h3>My Reports</h3>
      <div class="table-container"><table>
        <thead><tr><th>Reference</th><th>Type</th><th>Category</th><th>Filed</th><th>Status</th><th>Barangay note</th></tr></thead>
        <tbody>
        <?php if ($reports): foreach ($reports as $r):
          $b = match($r['status']) { 'Resolved'=>'badge-success','Under Review'=>'badge-info','Dismissed'=>'badge-danger', default=>'badge-warning' }; ?>
          <tr><td><?= htmlspecialchars($r['reference_no']) ?></td><td><?= htmlspecialchars($r['report_type']) ?></td>
          <td><?= htmlspecialchars($r['category']) ?></td><td><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
          <td><span class="badge <?= $b ?>"><?= htmlspecialchars($r['status']) ?></span></td>
          <td><?= htmlspecialchars($r['admin_notes'] ?: '—') ?></td></tr>
        <?php endforeach; else: ?><tr><td colspan="6" style="text-align:center;color:#aaa;padding:16px">No reports yet.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </main>
</div>
<script src="assets/js/app.js"></script>
<script>
const CATS = {
  Incident:  ['Noise','Theft','Fight','Road hazard','Other'],
  Complaint: ['Neighbor dispute','Property boundary','Debt / payment','Other']
};
function fillCats() {
  const t = document.getElementById('irType').value;
  document.getElementById('irCat').innerHTML = CATS[t].map(c => `<option>${c}</option>`).join('');
}
fillCats();
async function submitReport() {
  const fd = new FormData();
  fd.append('action', 'submit_report');
  fd.append('report_type', document.getElementById('irType').value);
  fd.append('category',    document.getElementById('irCat').value);
  fd.append('location',    document.getElementById('irLoc').value);
  fd.append('details',     document.getElementById('irDetails').value);
  const res = await fetch('resident_incidents.php', { method:'POST', body:fd });
  const d = await res.json();
  showToast(d.message, d.success ? 'success' : 'error');
  if (d.success) setTimeout(() => location.reload(), 1200);
}
</script>
</body>
</html>
