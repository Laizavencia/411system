<?php
session_start();
require_once 'includes/db.php';
requireAdminLogin();

$type = $_GET['type'] ?? 'population';
if (!in_array($type, ['population', 'request', 'household'])) $type = 'population';

$startDate = $_GET['start'] ?? date('Y-01-01');
$endDate   = $_GET['end']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = date('Y-01-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))   $endDate   = date('Y-m-d');
if ($startDate > $endDate) { [$startDate, $endDate] = [$endDate, $startDate]; }

$zone      = trim($_GET['zone']   ?? '');
$reqStatus = trim($_GET['status'] ?? ''); // Request report only

$zoneRows    = dbFetchAll("SELECT name FROM puroks WHERE type='zone' ORDER BY name");
$zoneOptions = $zoneRows ?: [['name'=>'Zone 1'],['name'=>'Zone 2'],['name'=>'Zone 3'],['name'=>'Zone 4']];

$requestStatuses = ['Pending','Processing','Ready for Pickup','Completed','Rejected'];

function badgeFor(string $status): string {
    return match ($status) {
        'Completed', 'Ready for Pickup', 'Verified' => 'badge-success',
        'Processing', 'Confirmed'                    => 'badge-info',
        'Pending'                                     => 'badge-warning',
        'Rejected', 'Cancelled', 'Inactive'           => 'badge-danger',
        default                                        => 'badge-info',
    };
}

if ($type === 'population') {
    $zoneWhere  = $zone ? "AND zone_purok = ?" : "";
    $zoneParams = $zone ? [$zone] : [];

    $popStats = dbFetchOne("
        SELECT COUNT(*) AS total,
               SUM(status='Verified') AS verified,
               SUM(status='Pending')  AS pending,
               SUM(status='Inactive') AS inactive
        FROM residents WHERE 1=1 $zoneWhere
    ", $zoneParams) ?? [];

    $newRegs = (int)(dbFetchOne("
        SELECT COUNT(*) AS c FROM residents
        WHERE created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY) $zoneWhere
    ", array_merge([$startDate, $endDate], $zoneParams))['c'] ?? 0);

    // Demographic breakdowns look at active (non-Inactive) residents only
    $demWhere  = "status <> 'Inactive'" . ($zone ? " AND zone_purok = ?" : "");
    $demParams = $zone ? [$zone] : [];

    $genderRows = dbFetchAll("SELECT gender, COUNT(*) AS c FROM residents WHERE $demWhere GROUP BY gender", $demParams);
    $genderMap  = array_column($genderRows, 'c', 'gender');

    $ageRow = dbFetchOne("
        SELECT
          SUM(TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE()) BETWEEN 0  AND 12) AS g0_12,
          SUM(TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE()) BETWEEN 13 AND 25) AS g13_25,
          SUM(TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE()) BETWEEN 26 AND 40) AS g26_40,
          SUM(TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE()) BETWEEN 41 AND 60) AS g41_60,
          SUM(TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE()) > 60)              AS g60plus
        FROM residents WHERE $demWhere
    ", $demParams) ?? [];

    $zoneTable = dbFetchAll("
        SELECT r.zone_purok,
               COUNT(*) AS total,
               SUM(r.gender='Male')   AS male,
               SUM(r.gender='Female') AS female,
               SUM(TIMESTAMPDIFF(YEAR,r.date_of_birth,CURDATE()) > 60) AS seniors,
               (SELECT COUNT(*) FROM households h WHERE h.zone_purok = r.zone_purok) AS households
        FROM residents r
        WHERE $demWhere AND r.zone_purok IS NOT NULL AND r.zone_purok <> ''
        GROUP BY r.zone_purok
        ORDER BY total DESC
    ", $demParams);
}

if ($type === 'request') {
    $conds  = ["dr.requested_date BETWEEN ? AND ?"];
    $params = [$startDate, $endDate];
    if ($zone)      { $conds[] = "r.zone_purok = ?"; $params[] = $zone; }
    if ($reqStatus) { $conds[] = "dr.status = ?";    $params[] = $reqStatus; }
    $where = implode(' AND ', $conds);

    $reqStats = dbFetchOne("
        SELECT COUNT(*) AS total,
               SUM(dr.status IN ('Completed','Ready for Pickup')) AS done,
               SUM(dr.status IN ('Pending','Processing'))         AS pending,
               SUM(dr.status IN ('Rejected','Cancelled'))         AS rejected,
               AVG(CASE WHEN dr.completed_date IS NOT NULL
                        THEN DATEDIFF(dr.completed_date, dr.requested_date) END) AS avg_days
        FROM document_requests dr JOIN residents r ON dr.resident_id = r.id
        WHERE $where
    ", $params) ?? [];

    $overdueCount = (int)(dbFetchOne("
        SELECT COUNT(*) AS c
        FROM document_requests dr JOIN residents r ON dr.resident_id = r.id
        WHERE $where AND dr.due_date IS NOT NULL AND dr.due_date < CURDATE()
              AND dr.status NOT IN ('Completed','Ready for Pickup','Rejected','Cancelled')
    ", $params)['c'] ?? 0);

    $byType = dbFetchAll("
        SELECT dr.document_type,
               COUNT(*) AS c,
               SUM(dr.status IN ('Completed','Ready for Pickup')) AS done
        FROM document_requests dr JOIN residents r ON dr.resident_id = r.id
        WHERE $where
        GROUP BY dr.document_type ORDER BY c DESC
    ", $params);

    $byMonth = dbFetchAll("
        SELECT DATE_FORMAT(dr.requested_date, '%b %Y') AS label, COUNT(*) AS c
        FROM document_requests dr JOIN residents r ON dr.resident_id = r.id
        WHERE $where
        GROUP BY YEAR(dr.requested_date), MONTH(dr.requested_date)
        ORDER BY YEAR(dr.requested_date), MONTH(dr.requested_date)
    ", $params);

    $reqRows = dbFetchAll("
        SELECT dr.*, CONCAT(r.first_name,' ',r.last_name) AS resident_name, r.barangay_id, r.zone_purok
        FROM document_requests dr JOIN residents r ON dr.resident_id = r.id
        WHERE $where
        ORDER BY dr.requested_date DESC
        LIMIT 300
    ", $params);
}

if ($type === 'household') {
    $conds  = ["h.created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)"];
    $params = [$startDate, $endDate];
    if ($zone) { $conds[] = "h.zone_purok = ?"; $params[] = $zone; }
    $where = implode(' AND ', $conds);

    $hhStats = dbFetchOne("
        SELECT COUNT(*) AS total, AVG(member_count) AS avg_members
        FROM households h WHERE $where
    ", $params) ?? [];

    $zoneWhereR  = $zone ? "AND zone_purok = ?" : "";
    $zoneParamsR = $zone ? [$zone] : [];

    $noHousehold = (int)(dbFetchOne("
        SELECT COUNT(*) AS c FROM residents
        WHERE household_id IS NULL AND status <> 'Inactive' $zoneWhereR
    ", $zoneParamsR)['c'] ?? 0);

    $pendingHeadReq = (int)(dbFetchOne("SELECT COUNT(*) AS c FROM head_requests WHERE status='Pending'")['c'] ?? 0);

    $hhByZone = dbFetchAll("
        SELECT zone_purok, COUNT(*) AS c, AVG(member_count) AS avgm
        FROM households h WHERE $where AND zone_purok IS NOT NULL AND zone_purok <> ''
        GROUP BY zone_purok ORDER BY c DESC
    ", $params);

    $hhRows = dbFetchAll("
        SELECT h.*, CONCAT(r.first_name,' ',r.last_name) AS head_name
        FROM households h LEFT JOIN residents r ON h.household_head = r.id
        WHERE $where
        ORDER BY h.member_count DESC, h.created_at DESC
    ", $params);
}

if (($_GET['export'] ?? '') === 'csv') {
    $filename = $type . '_report_' . $startDate . '_to_' . $endDate . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');

    if ($type === 'population') {
        fputcsv($out, ['Population Report', $startDate . ' to ' . $endDate, $zone ?: 'All Zones']);
        fputcsv($out, []);
        fputcsv($out, ['Zone/Purok', 'Total', 'Male', 'Female', 'Seniors (60+)', 'Households']);
        foreach ($zoneTable as $z) {
            fputcsv($out, [$z['zone_purok'], $z['total'], $z['male'], $z['female'], $z['seniors'], $z['households']]);
        }
    } elseif ($type === 'request') {
        fputcsv($out, ['Document Request Report', $startDate . ' to ' . $endDate, $zone ?: 'All Zones']);
        fputcsv($out, []);
        fputcsv($out, ['Request No.', 'Resident', 'Barangay ID', 'Document Type', 'Purpose', 'Requested Date', 'Due Date', 'Completed Date', 'Status']);
        foreach ($reqRows as $r) {
            fputcsv($out, [
                $r['request_number'], $r['resident_name'], $r['barangay_id'], $r['document_type'],
                $r['purpose'], $r['requested_date'], $r['due_date'], $r['completed_date'], $r['status'],
            ]);
        }
    } elseif ($type === 'household') {
        fputcsv($out, ['Household Report', $startDate . ' to ' . $endDate, $zone ?: 'All Zones']);
        fputcsv($out, []);
        fputcsv($out, ['House Number', 'Zone/Purok', 'Head', 'Members', 'Address', 'Created']);
        foreach ($hhRows as $h) {
            fputcsv($out, [$h['house_number'], $h['zone_purok'], $h['head_name'], $h['member_count'], $h['address'], $h['created_at']]);
        }
    }

    fclose($out);
    exit;
}

$siteSettings = getSiteSettings();
$reportLabels = ['population' => 'Population Report', 'request' => 'Document Request Report', 'household' => 'Household Report'];
$reportTitle  = $reportLabels[$type];

function urlWith(array $override = []): string {
    $q = array_merge($_GET, $override);
    $q = array_filter($q, fn($v) => $v !== '' && $v !== null);
    unset($q['export']);
    return '?' . http_build_query($q);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Reports Management — <?= getSiteName() ?></title>
  <link rel="stylesheet" href="assets/css/style.css">
  <?php renderThemeStyle(); ?>
  <style>
    .print-header { display:none; }
    .print-footer { display:none; }
    @media print {
      .sidebar, .topbar-actions, .no-print { display:none !important; }
      .app { display:block; }
      .main { padding:0; }
      .card { box-shadow:none; border:1px solid #ddd; break-inside:avoid; }
      .print-header {
        display:flex; justify-content:space-between; align-items:flex-start;
        border-bottom:2px solid #0066cc; padding-bottom:14px; margin-bottom:20px;
      }
      .print-header img { width:56px;height:56px;object-fit:cover;border-radius:8px }
      .print-header h1 { font-size:18px;margin:0 0 4px }
      .print-header .sub { font-size:12px;color:#555 }
      .print-header .meta { text-align:right;font-size:12px;color:#555 }
      .print-footer {
        display:flex; justify-content:space-between; margin-top:60px; font-size:13px;
      }
      .print-footer .sig { width:45%; text-align:center; }
      .print-footer .sig .line { border-top:1px solid #333; margin-top:40px; padding-top:6px; }
    }
  </style>
</head>
<body>
<div class="app">
  <?php include 'includes/sidebar_admin.php'; ?>
  <main class="main">
    <div class="topbar">
      <div>
        <h3>Reports Management</h3>
        <div class="text-muted">Generate and export key barangay analytics reports.</div>
      </div>
      <div class="topbar-actions">
        <a href="<?= urlWith(['export' => 'csv']) ?>" class="btn btn-secondary btn-sm">⬇️ Export CSV</a>
        <button class="btn btn-primary btn-sm" onclick="window.print()">🖨️ Print Report</button>
      </div>
    </div>

    <!-- Print-only header -->
    <div class="print-header">
      <div style="display:flex;gap:14px;align-items:center">
        <img src="<?= getSiteLogo() ?>" alt="Logo">
        <div>
          <h1><?= htmlspecialchars($siteSettings['barangay_name'] ?? getSiteName()) ?></h1>
          <div class="sub"><?= htmlspecialchars($siteSettings['address'] ?? '') ?></div>
          <div class="sub"><?= htmlspecialchars($siteSettings['contact_number'] ?? '') ?> · <?= htmlspecialchars($siteSettings['email'] ?? '') ?></div>
        </div>
      </div>
      <div class="meta">
        <div style="font-size:15px;font-weight:700;color:#111"><?= htmlspecialchars($reportTitle) ?></div>
        <div>Period: <?= date('M d, Y', strtotime($startDate)) ?> – <?= date('M d, Y', strtotime($endDate)) ?></div>
        <div>Zone: <?= htmlspecialchars($zone ?: 'All Zones') ?></div>
        <div>Generated: <?= date('M d, Y g:i A') ?></div>
      </div>
    </div>

    <!-- Filters -->
    <div class="card no-print">
      <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <div class="form-group" style="margin:0;min-width:200px">
          <label>Report Type</label>
          <select name="type" class="form-control" id="reportTypeSelect" onchange="toggleStatusField()">
            <option value="population" <?= $type==='population'?'selected':'' ?>>Population Report</option>
            <option value="request"    <?= $type==='request'   ?'selected':'' ?>>Request Report</option>
            <option value="household"  <?= $type==='household' ?'selected':'' ?>>Household Report</option>
          </select>
        </div>
        <div class="form-group" style="margin:0">
          <label>Start Date</label>
          <input type="date" name="start" class="form-control" value="<?= htmlspecialchars($startDate) ?>">
        </div>
        <div class="form-group" style="margin:0">
          <label>End Date</label>
          <input type="date" name="end" class="form-control" value="<?= htmlspecialchars($endDate) ?>">
        </div>
        <div class="form-group" style="margin:0;min-width:160px">
          <label>Zone / Purok</label>
          <select name="zone" class="form-control">
            <option value="">All Zones</option>
            <?php foreach ($zoneOptions as $z): ?>
              <option value="<?= htmlspecialchars($z['name']) ?>" <?= $zone===$z['name']?'selected':'' ?>><?= htmlspecialchars($z['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0;min-width:160px" id="statusFieldGroup">
          <label>Request Status</label>
          <select name="status" class="form-control">
            <option value="">All</option>
            <?php foreach ($requestStatuses as $s): ?>
              <option value="<?= $s ?>" <?= $reqStatus===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn-primary" type="submit">Apply</button>
        <a href="reports.php" class="btn">Clear</a>
      </form>
    </div>

    <?php if ($type === 'population'): ?>

      <div class="stats">
        <div class="stat"><h4>Total Residents</h4><p data-target="<?= (int)($popStats['total'] ?? 0) ?>">0</p><div class="stat-icon">👥</div><div class="stat-change positive">All statuses, selected zone</div></div>
        <div class="stat"><h4>Verified</h4><p data-target="<?= (int)($popStats['verified'] ?? 0) ?>">0</p><div class="stat-icon">✅</div><div class="stat-change positive">Active accounts</div></div>
        <div class="stat"><h4>Pending</h4><p data-target="<?= (int)($popStats['pending'] ?? 0) ?>">0</p><div class="stat-icon">⏳</div><div class="stat-change warning">Awaiting verification</div></div>
        <div class="stat"><h4>New Registrations</h4><p data-target="<?= $newRegs ?>">0</p><div class="stat-icon">🆕</div><div class="stat-change positive">Within selected period</div></div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
        <div class="card">
          <h3>By Gender</h3>
          <div class="table-container">
            <table>
              <thead><tr><th>Gender</th><th>Count</th></tr></thead>
              <tbody>
                <?php if ($genderMap): foreach ($genderMap as $g => $c): ?>
                <tr><td><?= htmlspecialchars($g ?: 'Not specified') ?></td><td><?= (int)$c ?></td></tr>
                <?php endforeach; else: ?>
                <tr><td colspan="2" style="text-align:center;color:#aaa;padding:16px">No data.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="card">
          <h3>By Age Group</h3>
          <div class="table-container">
            <table>
              <thead><tr><th>Age Group</th><th>Count</th></tr></thead>
              <tbody>
                <tr><td>0–12</td><td><?= (int)($ageRow['g0_12'] ?? 0) ?></td></tr>
                <tr><td>13–25</td><td><?= (int)($ageRow['g13_25'] ?? 0) ?></td></tr>
                <tr><td>26–40</td><td><?= (int)($ageRow['g26_40'] ?? 0) ?></td></tr>
                <tr><td>41–60</td><td><?= (int)($ageRow['g41_60'] ?? 0) ?></td></tr>
                <tr><td>60+</td><td><?= (int)($ageRow['g60plus'] ?? 0) ?></td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card">
        <h3>By Zone / Purok</h3>
        <div class="table-container">
          <table>
            <thead><tr><th>Zone/Purok</th><th>Total</th><th>Male</th><th>Female</th><th>Seniors (60+)</th><th>Households</th></tr></thead>
            <tbody>
              <?php if ($zoneTable): foreach ($zoneTable as $z): ?>
              <tr>
                <td><?= htmlspecialchars($z['zone_purok']) ?></td>
                <td><?= (int)$z['total'] ?></td>
                <td><?= (int)$z['male'] ?></td>
                <td><?= (int)$z['female'] ?></td>
                <td><?= (int)$z['seniors'] ?></td>
                <td><?= (int)$z['households'] ?></td>
              </tr>
              <?php endforeach; else: ?>
              <tr><td colspan="6" style="text-align:center;color:#aaa;padding:20px">No residents match this filter.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    <?php elseif ($type === 'request'): ?>

      <div class="stats">
        <div class="stat"><h4>Total Requests</h4><p data-target="<?= (int)($reqStats['total'] ?? 0) ?>">0</p><div class="stat-icon">📄</div><div class="stat-change positive">Selected period</div></div>
        <div class="stat"><h4>Completed / Ready</h4><p data-target="<?= (int)($reqStats['done'] ?? 0) ?>">0</p><div class="stat-icon">✅</div><div class="stat-change positive">Done</div></div>
        <div class="stat"><h4>Pending / Processing</h4><p data-target="<?= (int)($reqStats['pending'] ?? 0) ?>">0</p><div class="stat-icon">⏳</div><div class="stat-change warning">In progress</div></div>
        <div class="stat"><h4>Overdue</h4><p data-target="<?= $overdueCount ?>">0</p><div class="stat-icon">⚠️</div><div class="stat-change negative">Past due date</div></div>
      </div>

      <div class="card">
        <h3>Average Processing Time</h3>
        <p style="font-size:28px;font-weight:700;margin:0"><?= $reqStats['avg_days'] !== null ? round($reqStats['avg_days'], 1) . ' days' : '—' ?></p>
        <p class="text-muted" style="margin-top:6px">From requested date to completed date, for requests marked completed in this period.</p>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
        <div class="card">
          <h3>By Document Type</h3>
          <div class="table-container">
            <table>
              <thead><tr><th>Document Type</th><th>Total</th><th>Completed</th></tr></thead>
              <tbody>
                <?php if ($byType): foreach ($byType as $t): ?>
                <tr><td><?= htmlspecialchars($t['document_type']) ?></td><td><?= (int)$t['c'] ?></td><td><?= (int)$t['done'] ?></td></tr>
                <?php endforeach; else: ?>
                <tr><td colspan="3" style="text-align:center;color:#aaa;padding:16px">No requests found.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="card">
          <h3>By Month</h3>
          <div class="table-container">
            <table>
              <thead><tr><th>Month</th><th>Requests</th></tr></thead>
              <tbody>
                <?php if ($byMonth): foreach ($byMonth as $m): ?>
                <tr><td><?= htmlspecialchars($m['label']) ?></td><td><?= (int)$m['c'] ?></td></tr>
                <?php endforeach; else: ?>
                <tr><td colspan="2" style="text-align:center;color:#aaa;padding:16px">No requests found.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card">
        <h3>Request Details <span class="text-muted" style="font-size:13px;font-weight:400">(most recent 300)</span></h3>
        <div class="table-container">
          <table>
            <thead><tr><th>Request No.</th><th>Resident</th><th>Document</th><th>Requested</th><th>Due</th><th>Status</th></tr></thead>
            <tbody>
              <?php if ($reqRows): foreach ($reqRows as $r):
                $overdue = $r['due_date'] && $r['due_date'] < date('Y-m-d') && !in_array($r['status'], ['Completed','Ready for Pickup','Rejected','Cancelled']);
              ?>
              <tr<?= $overdue ? ' style="background:#fef2f2"' : '' ?>>
                <td><?= htmlspecialchars($r['request_number']) ?></td>
                <td><?= htmlspecialchars($r['resident_name']) ?> <small class="text-muted"><?= htmlspecialchars($r['barangay_id']) ?></small></td>
                <td><?= htmlspecialchars($r['document_type']) ?></td>
                <td><?= date('M d, Y', strtotime($r['requested_date'])) ?></td>
                <td><?= $r['due_date'] ? date('M d, Y', strtotime($r['due_date'])) : '—' ?><?= $overdue ? ' ⚠️' : '' ?></td>
                <td><span class="badge <?= badgeFor($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
              </tr>
              <?php endforeach; else: ?>
              <tr><td colspan="6" style="text-align:center;color:#aaa;padding:20px">No requests match this filter.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    <?php elseif ($type === 'household'): ?>

      <div class="stats">
        <div class="stat"><h4>Total Households</h4><p data-target="<?= (int)($hhStats['total'] ?? 0) ?>">0</p><div class="stat-icon">🏠</div><div class="stat-change positive">Created in period</div></div>
        <div class="stat"><h4>Avg. Members</h4><p style="font-size:28px"><?= $hhStats['avg_members'] !== null ? round($hhStats['avg_members'], 1) : '—' ?></p><div class="stat-icon">👨‍👩‍👧</div><div class="stat-change positive">Per household</div></div>
        <div class="stat"><h4>Residents Without Household</h4><p data-target="<?= $noHousehold ?>">0</p><div class="stat-icon">👤</div><div class="stat-change warning">Not yet assigned</div></div>
        <div class="stat"><h4>Pending Head Requests</h4><p data-target="<?= $pendingHeadReq ?>">0</p><div class="stat-icon">📨</div><div class="stat-change warning">Awaiting review</div></div>
      </div>

      <div class="card">
        <h3>By Zone / Purok</h3>
        <div class="table-container">
          <table>
            <thead><tr><th>Zone/Purok</th><th>Households</th><th>Avg. Members</th></tr></thead>
            <tbody>
              <?php if ($hhByZone): foreach ($hhByZone as $z): ?>
              <tr><td><?= htmlspecialchars($z['zone_purok']) ?></td><td><?= (int)$z['c'] ?></td><td><?= round($z['avgm'], 1) ?></td></tr>
              <?php endforeach; else: ?>
              <tr><td colspan="3" style="text-align:center;color:#aaa;padding:16px">No households found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <h3>Households</h3>
        <div class="table-container">
          <table>
            <thead><tr><th>House No.</th><th>Zone</th><th>Head</th><th>Members</th><th>Created</th></tr></thead>
            <tbody>
              <?php if ($hhRows): foreach ($hhRows as $h): ?>
              <tr>
                <td><?= htmlspecialchars($h['house_number'] ?: '—') ?></td>
                <td><?= htmlspecialchars($h['zone_purok'] ?: '—') ?></td>
                <td><?= htmlspecialchars($h['head_name'] ?? '—') ?></td>
                <td><?= (int)$h['member_count'] ?></td>
                <td><?= date('M d, Y', strtotime($h['created_at'])) ?></td>
              </tr>
              <?php endforeach; else: ?>
              <tr><td colspan="5" style="text-align:center;color:#aaa;padding:20px">No households match this filter.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    <?php endif; ?>

    <!-- Print-only signature block -->
    <div class="print-footer">
      <div class="sig"><div class="line">Prepared by</div></div>
      <div class="sig"><div class="line">Noted by — Barangay Captain</div></div>
    </div>

  </main>
</div>
<script src="assets/js/app.js"></script>
<script src="assets/js/searchable-select.js"></script>
<script>
function toggleStatusField() {
  const type = document.getElementById('reportTypeSelect').value;
  document.getElementById('statusFieldGroup').style.display = (type === 'request') ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', toggleStatusField);
</script>
</body>
</html>