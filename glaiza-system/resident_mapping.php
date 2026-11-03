<?php
// ============================================================
// resident_mapping.php  — Resident: View-only geographic map
// Shows the same zone/purok boundaries as the admin map, but
// residents cannot draw, edit, save, or delete anything.
// Admin-only "markers" are intentionally NOT shown here.
// ============================================================
session_start();
require_once 'includes/db.php';
requireResidentLogin();

$residentId = $_SESSION['resident_id'];

$resident = dbFetchOne(
    "SELECT id, zone_purok, street_address, house_number, latitude, longitude
     FROM residents WHERE id=?",
    [$residentId]
);

// Zones/puroks, markers, and emergency centers are all shown to residents
$puroks = dbFetchAll(
    "SELECT id, name, type, description, color, coordinates
     FROM puroks
     WHERE type IN ('zone','marker','emergency')
     ORDER BY name"
);
$zoneList       = array_values(array_filter($puroks, fn($p) => ($p['type'] ?? 'zone') === 'zone'));
$markerList     = array_values(array_filter($puroks, fn($p) => ($p['type'] ?? 'zone') === 'marker'));
$emergencyList   = array_values(array_filter($puroks, fn($p) => ($p['type'] ?? 'zone') === 'emergency'));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Barangay Map — Barangay Talisay</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
  <style>
    #map { height:560px;border-radius:10px;margin-top:16px;box-shadow:0 1px 3px rgba(0,0,0,.1) }
    .purok-item { display:flex;justify-content:space-between;align-items:center;padding:12px;margin:8px 0;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb }
    .purok-name { font-weight:600;color:#1f2937 }
    .purok-color { width:22px;height:22px;border-radius:4px;border:2px solid #e5e7eb;flex-shrink:0 }
    .purok-color.round { border-radius:50% }
    .type-tag { font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:2px 8px;border-radius:10px;margin-left:8px }
    .type-tag.zone   { background:#dcfce7;color:#166534 }
    .type-tag.marker { background:#dbeafe;color:#1e40af }
    /* Searchable dropdown: looks like a <select>, but you can type to filter */
    .combo { position:relative }
    .combo-input { padding-right:38px !important; cursor:pointer }
    .combo-arrow { position:absolute;right:14px;top:50%;transform:translateY(-50%);pointer-events:none;color:#6b7280;font-size:14px }
    .combo-list { display:none;position:absolute;left:0;right:0;top:calc(100% + 4px);background:#fff;border:1px solid var(--border);border-radius:10px;box-shadow:var(--shadow);max-height:240px;overflow-y:auto;z-index:1500;list-style:none;margin:0;padding:6px }
    .combo-list.open { display:block }
    .combo-list li { padding:10px 12px;border-radius:6px;font-size:14px;cursor:pointer }
    .combo-list li:hover, .combo-list li.active { background:var(--primary-light);color:var(--primary) }
    .combo-list li.selected { font-weight:600 }
    .combo-list li[hidden] { display:none }
    .combo-list li.combo-empty { color:var(--text-light);cursor:default }
    .combo-list li.combo-empty:hover { background:transparent;color:var(--text-light) }
  </style>
  <?php renderThemeStyle(); ?>
</head>
<body>
<div class="app">
  <?php include 'includes/sidebar_resident.php'; ?>
  <main class="main">
    <div class="topbar">
      <div>
        <h3>Barangay Map</h3>
        <div class="text-muted">View barangay zones and puroks. This map is view-only.</div>
      </div>
    </div>

    <div class="card">
      <h3>Zone / Purok Map</h3>
      <p class="text-muted">Browse the barangay's official zone boundaries below. Select a zone from the list to focus the map.</p>
      <div class="form-grid" style="margin-top:16px">
        <div class="form-group">
          <label>Purok / Zone / Marker Filter</label>
          <div class="combo" id="zoneCombo">
            <input type="text" id="zoneComboInput" class="form-control combo-input"
                   placeholder="Select or search..." autocomplete="off" role="combobox" aria-expanded="false">
            <span class="combo-arrow">▾</span>
            <ul class="combo-list" id="zoneComboList">
              <li data-id="" data-label="Select...">Select...</li>
              <?php foreach ($puroks as $p):
                $lbl = $p['name'] . ' — ' . ((($p['type'] ?? 'zone') === 'marker') ? 'Marker' : 'Zone'); ?>
                <li data-id="<?= (int)$p['id'] ?>" data-label="<?= htmlspecialchars($lbl) ?>"><?= htmlspecialchars($lbl) ?></li>
              <?php endforeach; ?>
              <li class="combo-empty" hidden>No matches found</li>
            </ul>
          </div>
        </div>
      </div>
      <div id="map"></div>
    </div>

    <div class="card">
      <h3>Zones / Puroks</h3>
      <p class="text-muted">These are the official zone boundaries maintained by the barangay office.</p>
      <div id="puroksList" style="margin-top:12px">
        <?php if ($zoneList): foreach ($zoneList as $p): ?>
        <div class="purok-item" data-id="<?= $p['id'] ?>" data-type="zone" data-coords='<?= htmlspecialchars($p['coordinates'] ?? '[]') ?>'>
          <div>
            <div class="purok-name"><?= htmlspecialchars($p['name']) ?><span class="type-tag zone">Zone</span></div>
            <small class="text-muted"><?= htmlspecialchars($p['description'] ?? 'No description') ?></small>
          </div>
          <div class="purok-color" style="background:<?= htmlspecialchars($p['color']) ?>"></div>
          <button class="btn btn-sm" style="padding:4px 10px;font-size:12px" onclick="focusPurok(<?= $p['id'] ?>)">Focus</button>
        </div>
        <?php endforeach; else: ?>
        <p class="text-muted" style="padding:8px 0;font-size:13px">No zones have been published yet.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <h3>Points of Interest</h3>
      <p class="text-muted">Barangay landmarks, emergency centers, and other notable locations marked by the barangay office.</p>
      <div id="markersList" style="margin-top:12px">
        <?php if ($markerList || $emergencyList): foreach (array_merge($markerList, $emergencyList) as $p): ?>
        <div class="purok-item" data-id="<?= $p['id'] ?>" data-type="<?= htmlspecialchars($p['type'] ?? 'marker') ?>" data-coords='<?= htmlspecialchars($p['coordinates'] ?? '{}') ?>'>
          <div>
            <div class="purok-name"><?= htmlspecialchars($p['name']) ?><span class="type-tag <?= ($p['type'] ?? 'marker') === 'emergency' ? 'marker' : 'marker' ?>" style="<?= ($p['type'] ?? 'marker') === 'emergency' ? 'background:#fee2e2;color:#991b1b' : '' ?>"><?= ($p['type'] ?? 'marker') === 'emergency' ? 'Emergency' : 'Marker' ?></span></div>
            <small class="text-muted"><?= htmlspecialchars($p['description'] ?? 'No description') ?></small>
          </div>
          <div class="purok-color round" style="background:<?= htmlspecialchars(($p['type'] ?? 'marker') === 'emergency' ? '#ef4444' : ($p['color'] ?? '#34d399')) ?>"></div>
          <button class="btn btn-sm" style="padding:4px 10px;font-size:12px" onclick="focusPurok(<?= $p['id'] ?>)">Focus</button>
        </div>
        <?php endforeach; else: ?>
        <p class="text-muted" style="padding:8px 0;font-size:13px">No points of interest have been published yet.</p>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>

<script>
const savedPuroks = <?= json_encode($puroks) ?>;
const defaultMapCenter = [13.9714416, 121.2069803];
const myLat = <?= $resident['latitude']  ? (float)$resident['latitude']  : 'null' ?>;
const myLng = <?= $resident['longitude'] ? (float)$resident['longitude'] : 'null' ?>;
</script>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="assets/js/app.js"></script>
<script>
let mapInstance;

document.addEventListener('DOMContentLoaded', function() {
  const center = (myLat !== null && myLng !== null) ? [myLat, myLng] : defaultMapCenter;
  mapInstance = L.map('map', {
    // No editing controls of any kind — view only
    zoomControl: true,
    dragging: true,
    scrollWheelZoom: true
  }).setView(center, 17);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap', maxZoom: 19
  }).addTo(mapInstance);

  // Draw published zones, markers, and emergency centers (read-only, not editable/removable)
  savedPuroks.forEach(p => {
    if (!p.coordinates) return;
    const type = p.type || 'zone';
    try {
      if (type === 'marker' || type === 'emergency') {
        const pt = JSON.parse(p.coordinates);
        if (!pt || typeof pt.lat === 'undefined') return;
        const color = type === 'emergency' ? '#ef4444' : (p.color || '#34d399');
        const layer = L.circleMarker([pt.lat, pt.lng], {
          radius: 9,
          color,
          fillColor: color,
          fillOpacity: 0.9,
          weight: 2
        });
        layer.bindPopup(`<b>${type === 'emergency' ? '🚨' : '📍'} ${p.name}</b><br>${p.description || ''}`);
        layer.addTo(mapInstance);
        layer._purokId = p.id;
      } else {
        const coords = JSON.parse(p.coordinates);
        if (!coords || !coords.length) return;
        const layer = L.polygon(coords, {
          color: p.color, fillColor: p.color, fillOpacity: 0.2, weight: 2,
          interactive: true
        });
        layer.bindPopup(`<b>${p.name}</b><br>${p.description || ''}`);
        layer.addTo(mapInstance);
        layer._purokId = p.id;
      }
    } catch(e) {}
  });

  // Highlight the resident's own address, if geocoded; otherwise keep the barangay center
  if (myLat !== null && myLng !== null) {
    L.marker([myLat, myLng])
      .bindPopup('<b>📍 My Address</b>')
      .addTo(mapInstance)
      .openPopup();
    mapInstance.setView([myLat, myLng], 17);
  } else {
    mapInstance.setView(defaultMapCenter, 17);
  }
});

function focusPurok(id) {
  const item = document.querySelector(`.purok-item[data-id="${id}"]`);
  if (!item || !mapInstance) return;
  const type = item.dataset.type || 'zone';
  try {
    if (type === 'marker' || type === 'emergency') {
      const pt = JSON.parse(item.dataset.coords);
      mapInstance.setView([pt.lat, pt.lng], 18);
    } else {
      const coords = JSON.parse(item.dataset.coords);
      if (coords && coords.length) {
        const poly = L.polygon(coords);
        mapInstance.fitBounds(poly.getBounds().pad(0.2));
      }
    }
  } catch(e) {}
}

// ── Purok / Zone / Marker filter (plain dropdown) ──
function handleZoneFilterChange(id) {
  if (!id) return;
  focusPurok(parseInt(id, 10));
}

// ── Place search combo box (input + datalist, backed by OpenStreetMap Nominatim) ──
const placeSearchMap = {};
let placeSearchDebounce = null;

function handlePlaceSearchInput(value) {
  // If what's typed exactly matches an option the user picked from the dropdown, jump there
  if (placeSearchMap[value]) {
    const p = placeSearchMap[value];
    if (mapInstance) mapInstance.setView([p.lat, p.lon], 18);
    return;
  }
  clearTimeout(placeSearchDebounce);
  const q = value.trim();
  if (!q) { document.getElementById('placeSearchList').innerHTML = ''; return; }
  placeSearchDebounce = setTimeout(() => searchPlace(q), 400);
}

async function searchPlace(query) {
  try {
    const url = 'https://nominatim.openstreetmap.org/search?format=json&addressdetails=0&limit=8&countrycodes=ph&q=' + encodeURIComponent(query);
    const res  = await fetch(url);
    const data = await res.json();
    const list = document.getElementById('placeSearchList');
    list.innerHTML = '';
    data.forEach(place => {
      placeSearchMap[place.display_name] = { lat: parseFloat(place.lat), lon: parseFloat(place.lon) };
      const opt = document.createElement('option');
      opt.value = place.display_name;
      list.appendChild(opt);
    });
  } catch (err) {
    console.error('Place search failed:', err);
  }
}
// ── Searchable dropdown (looks like a <select>, but you can type to filter) ──
function initCombo(inputId, listId, onSelect) {
  const input = document.getElementById(inputId);
  const list  = document.getElementById(listId);
  if (!input || !list) return;
  const items = [...list.querySelectorAll('li[data-id]')];
  const empty = list.querySelector('.combo-empty');
  let active = -1, selectedLabel = '';

  const visible = () => items.filter(li => !li.hidden);
  const isOpen  = () => list.classList.contains('open');
  function open()  { list.classList.add('open');    input.setAttribute('aria-expanded', 'true'); }
  function close() { list.classList.remove('open'); input.setAttribute('aria-expanded', 'false'); setActive(-1); }
  function setActive(i) {
    items.forEach(li => li.classList.remove('active'));
    active = i;
    const v = visible();
    if (i >= 0 && v[i]) { v[i].classList.add('active'); v[i].scrollIntoView({ block: 'nearest' }); }
  }
  function filter(q) {
    q = q.trim().toLowerCase();
    let n = 0;
    items.forEach(li => {
      const isPlaceholder = li.dataset.id === '';
      const show = q ? (!isPlaceholder && li.dataset.label.toLowerCase().includes(q)) : true;
      li.hidden = !show;
      if (show && !isPlaceholder) n++;
    });
    empty.hidden = n > 0 || !q;
    setActive(-1);
  }
  function choose(li) {
    selectedLabel = li.dataset.id ? li.dataset.label : '';
    input.value = selectedLabel;
    items.forEach(x => x.classList.toggle('selected', x === li && !!li.dataset.id));
    close();
    onSelect(li.dataset.id);
  }
  function restore() { input.value = selectedLabel; filter(''); close(); }

  input.addEventListener('focus', () => { input.select(); filter(''); open(); });
  input.addEventListener('click', () => { if (!isOpen()) { filter(''); open(); } });
  input.addEventListener('input', () => { filter(input.value); open(); });
  input.addEventListener('blur',  restore);
  input.addEventListener('keydown', (e) => {
    const v = visible();
    if (e.key === 'ArrowDown') {
      e.preventDefault(); open(); setActive(Math.min(active + 1, v.length - 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault(); setActive(Math.max(active - 1, 0));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const real = v.filter(li => li.dataset.id !== '');
      if (active >= 0 && v[active]) choose(v[active]);
      else if (real.length === 1)   choose(real[0]);
    } else if (e.key === 'Escape') {
      restore(); input.blur();
    }
  });
  // mousedown (not click) so the input doesn't lose focus before the choice registers
  list.addEventListener('mousedown', (e) => {
    e.preventDefault();
    const li = e.target.closest('li[data-id]');
    if (li) choose(li);
  });
}

initCombo('zoneComboInput', 'zoneComboList', handleZoneFilterChange);
</script>
</body>
</html>