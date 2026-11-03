<?php
// resident_directory.php — Resident: Fees & requirements, offices, weekly hours
session_start();
require_once 'includes/db.php';
require_once 'includes/site_settings.php';
require_once 'includes/barangay_info.php';
requireResidentLogin();

$BRGY_FEES = getJsonSetting('barangay_fees', [
    ['Barangay Clearance', '₱50', 'Same day', 'Valid ID'],
    ['Certificate of Residency', '₱30', 'Same day', 'Valid ID, proof of address'],
    ['Certificate of Indigency', 'Free', 'Same day', 'Valid ID'],
    ['Business Permit', '₱300+', '2 working days', "DTI/Mayor's permit, valid ID, lease"],
    ['Barangay ID', '₱100', '3 working days (Tue, Thu)', 'Photo ID, proof of address'],
]);
$BRGY_OFFICES = getJsonSetting('barangay_offices', [
    ['Punong Barangay', 'Hon. Maria L. Santos', '(02) 555-0101', 'Mon–Fri 8–5'],
    ['Secretary (records)', 'Ramon C. Dela Cruz', '(02) 555-0102', 'Mon–Fri 8–5'],
    ['Treasurer (payments)', 'Elena P. Reyes', '(02) 555-0103', 'Mon–Fri 8–4'],
    ['Lupon (mediation)', 'Atty. Jose Aquino', '(02) 555-0104', 'Mon, Wed, Fri 9–4'],
    ['Health Center', 'Nurse Ana Ramos', '(02) 555-0105', 'Mon–Sat 8–5'],
    ['Tanod', 'Duty officer', '(02) 555-0102', '24/7'],
]);
$BRGY_HOURS = getJsonSetting('barangay_hours', [
    ['Mon–Fri', '8–5 General services'],
    ['Tue & Thu', 'Barangay ID applications'],
    ['Sat', '8–12 Health Center only'],
    ['Sun', 'Closed (emergency line open)'],
]);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Services & Offices — Barangay Talisay</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <?php renderThemeStyle(); ?>
</head>
<body>
<div class="app">
  <?php include 'includes/sidebar_resident.php'; ?>
  <main class="main">
    <div class="topbar">
      <div><h3>Services &amp; Offices</h3><div class="text-muted">Fees, requirements, office contacts and hours.</div></div>
      <div class="topbar-actions"><a href="resident_documents.php" class="btn btn-primary btn-sm">Request a Document</a></div>
    </div>

    <div class="card">
      <h3>Permits &amp; Clearance</h3>
      <div class="table-container"><table>
        <thead><tr><th>Service</th><th>Fee</th><th>Processing time</th><th>Bring</th></tr></thead>
        <tbody><?php foreach ($BRGY_FEES as $f): ?>
          <tr><td><strong><?= htmlspecialchars($f[0]) ?></strong></td><td><?= htmlspecialchars($f[1]) ?></td><td><?= htmlspecialchars($f[2]) ?></td><td><?= htmlspecialchars($f[3]) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    </div>

    <div class="card">
      <h3>Office Directory</h3>
      <div class="table-container"><table>
        <thead><tr><th>Office</th><th>Contact person</th><th>Hours</th><th>Call</th></tr></thead>
        <tbody><?php foreach ($BRGY_OFFICES as $o): ?>
          <tr><td><strong><?= htmlspecialchars($o[0]) ?></strong></td><td><?= htmlspecialchars($o[1]) ?></td><td><?= htmlspecialchars($o[3]) ?></td>
          <td><a class="btn btn-primary btn-sm" href="<?= telHref($o[2]) ?>">📞 <?= htmlspecialchars($o[2]) ?></a></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    </div>

    <div class="card">
      <h3>Weekly Hours</h3>
      <div class="table-container"><table><tbody>
        <?php foreach ($BRGY_HOURS as $w): ?><tr><td style="width:140px"><strong><?= htmlspecialchars($w[0]) ?></strong></td><td><?= htmlspecialchars($w[1]) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <p class="text-muted" style="margin-top:12px">Need a visit? <a href="resident_appointments.php" style="color:var(--primary);font-weight:600">Book an appointment</a>.</p>
    </div>
  </main>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
