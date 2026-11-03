<?php
// resident_emergency.php — Resident: Emergency hotlines & what to do
session_start();
require_once 'includes/db.php';
require_once 'includes/site_settings.php';
require_once 'includes/barangay_info.php';
requireResidentLogin();

$BRGY_HOTLINES = getJsonSetting('emergency_hotlines', [
    ['Emergency (national)', '911'],
    ['Barangay Talisay Hotline', '(02) 555-0101'],
    ['Barangay Tanod 24/7', '(02) 555-0102'],
    ['Fire (BFP)', '(02) 555-0103'],
    ['Police (PNP)', '(02) 555-0104'],
    ['Health Center / Ambulance', '(02) 555-0105'],
]);
$BRGY_EVAC = getJsonSetting('emergency_centers', ['Barangay Talisay Elementary School', 'Barangay Talisay Covered Court', 'Talisay Parish Hall']);

$emergencyCenters = dbFetchAll(
    "SELECT id, name, description, coordinates, color
     FROM puroks
     WHERE type='emergency'
     ORDER BY name"
);

if (!$emergencyCenters) {
    $emergencyCenters = array_map(function ($name) {
        return ['id' => null, 'name' => $name, 'description' => '', 'coordinates' => null, 'color' => '#ef4444'];
    }, $BRGY_EVAC);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Emergency Info — Barangay Talisay</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <?php renderThemeStyle(); ?>
</head>
<body>
<div class="app">
  <?php include 'includes/sidebar_resident.php'; ?>
  <main class="main">
    <div class="topbar">
      <div><h3>Emergency Info</h3><div class="text-muted">Save these numbers. Stay calm.</div></div>
      <div class="topbar-actions"><a href="resident_incidents.php" class="btn btn-primary btn-sm">Report an Incident</a></div>
    </div>

    <div class="card">
      <h3>🚨 Hotlines</h3>
      <div style="display:grid;gap:10px">
        <?php foreach ($BRGY_HOTLINES as $h): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;background:#fee2e2;border-radius:10px;padding:12px 16px">
          <div><div style="font-size:13px;color:#991b1b"><?= htmlspecialchars($h[0]) ?></div><strong style="font-size:20px;color:#991b1b"><?= htmlspecialchars($h[1]) ?></strong></div>
          <a class="btn btn-sm" style="background:#ef4444;color:#fff" href="<?= telHref($h[1]) ?>">📞 Call</a>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
      <div class="card">
        <h3>What to do</h3>
        <ul style="margin:0;padding-left:20px;line-height:1.9;font-size:14px;color:var(--text-light)">
          <li>Stay calm. Say your name, address and what happened.</li>
          <li>Bring your go-bag: water, food, flashlight, medicine, IDs.</li>
          <li>Help children, seniors and persons with disability first.</li>
        </ul>
      </div>
      <div class="card">
        <h3>Emergency centers</h3>
        <?php foreach ($emergencyCenters as $center): ?>
        <div class="purok-item" style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;margin:6px 0;background:var(--muted);border:1px solid var(--border);border-radius:8px">
          <div>
            <div style="font-weight:600">🏫 <?= htmlspecialchars($center['name'] ?? 'Emergency Center') ?></div>
            <?php if (!empty($center['description'])): ?>
            <small class="text-muted"><?= htmlspecialchars($center['description']) ?></small>
            <?php endif; ?>
          </div>
          <?php if (!empty($center['coordinates'])): ?>
          <a href="resident_mapping.php" class="btn btn-sm" style="padding:4px 10px;font-size:12px">View map</a>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <a href="resident_mapping.php" class="text-muted" style="font-size:13px">View on Barangay Map</a>
      </div>
    </div>
  </main>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
