<?php
// ============================================================
// mapping.php  — Admin: Geographic mapping + purok management
// ============================================================
session_start();
require_once 'includes/db.php';
requireAdminLogin();

// ── AJAX handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_purok') {
        $name   = trim($_POST['name']        ?? '');
        $color  = trim($_POST['color']       ?? '#34d399');
        $coords = trim($_POST['coordinates'] ?? '');
        $desc   = trim($_POST['description'] ?? '');
        $type   = trim($_POST['type']        ?? 'zone');

        if (!$name) jsonError('Name is required.');
        if (!in_array($type, ['zone','marker','emergency'], true)) $type = 'zone';
        if (!$coords || $coords === '[]' || $coords === '{}') {
            jsonError('Please draw a marker or shape on the map before saving.');
        }

        dbInsert(
            "INSERT INTO puroks (name, type, description, color, coordinates) VALUES (?,?,?,?,?)",
            [$name, $type, $desc, $color, $coords]
        );
        if ($type === 'emergency') {
            $label = 'Emergency Center';
        } elseif ($type === 'marker') {
            $label = 'Marker';
        } else {
            $label = 'Purok';
        }
        logActivity("Saved $label: $name", 'Mapping');
        jsonOk([], "$label '$name' saved.");
    }
    if ($_POST['action'] === 'delete_purok') {
        $id = (int)($_POST['id'] ?? 0);
        dbExecute("DELETE FROM puroks WHERE id=?", [$id]);
        logActivity("Deleted map item #$id", 'Mapping');
        jsonOk([], 'Deleted.');
    }
    exit;
}

$puroks       = dbFetchAll("SELECT * FROM puroks ORDER BY created_at DESC");
$zoneList     = array_values(array_filter($puroks, fn($p) => ($p['type'] ?? 'zone') === 'zone'));
$markerList   = array_values(array_filter($puroks, fn($p) => ($p['type'] ?? 'zone') === 'marker'));
$emergencyList = array_values(array_filter($puroks, fn($p) => ($p['type'] ?? 'zone') === 'emergency'));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Geographic Mapping — Barangay Talisay</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
  <link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css"/>
  <style>
    #map { height:560px;border-radius:10px;margin-top:16px;box-shadow:0 1px 3px rgba(0,0,0,.1) }
    .purok-item { display:flex;justify-content:space-between;align-items:center;padding:12px;margin:8px 0;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb }
    .purok-name { font-weight:600;color:#1f2937 }
    .purok-color { width:22px;height:22px;border-radius:4px;border:2px solid #e5e7eb;flex-shrink:0 }
    .purok-color.round { border-radius:50% }
    .type-tag { font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:2px 8px;border-radius:10px;margin-left:8px }
    .type-tag.zone   { background:#dcfce7;color:#166534 }
    .type-tag.marker { background:#dbeafe;color:#1e40af }
    .draw-mode-toggle { display:flex;gap:8px;margin-bottom:12px }
    .draw-mode-toggle button { flex:1;padding:10px;border-radius:8px;border:2px solid var(--border);background:#fff;cursor:pointer;font-weight:600;font-size:13px }
    .draw-mode-toggle button.active { border-color:var(--primary);background:var(--primary-light);color:var(--primary) }
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
  <?php include 'includes/sidebar_admin.php'; ?>
  <main class="main">
    <div class="topbar">
      <div>
        <h3>Geographic Mapping</h3>
        <div class="text-muted">Draw zone/purok boundaries, admin markers, or emergency-center points.</div>
      </div>
    </div>

    <div class="card">
      <h3>Interactive Map &amp; Purok Management</h3>
      <p class="text-muted">Choose whether you're drawing a <strong>Zone/Purok</strong>, a <strong>Marker</strong>, or an <strong>Emergency Center</strong>, then place it on the map.</p>
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

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px">
      <!-- Drawing tools -->
      <div class="card">
        <h3>Drawing Tools</h3>
        <p class="text-muted">Pick a type, draw on the map, fill in the details, then save.</p>

        <div class="draw-mode-toggle" style="margin-top:16px">
          <button type="button" id="modeZoneBtn" class="active" onclick="setDrawMode('zone')">🗺️ Zone / Purok</button>
          <button type="button" id="modeMarkerBtn" onclick="setDrawMode('marker')">📍 Marker</button>
          <button type="button" id="modeEmergencyBtn" onclick="setDrawMode('emergency')">🚨 Emergency</button>
        </div>

        <div style="display:grid;gap:12px">
          <div class="form-group">
            <label>Name *</label>
            <input type="text" id="purokName" class="form-control" placeholder="e.g., Purok Mabuhay or Barangay Hall">
          </div>
          <div class="form-group">
            <label>Description</label>
            <input type="text" id="purokDesc" class="form-control" placeholder="Optional description">
          </div>
          <div class="form-group">
            <label>Color</label>
            <input type="color" id="purokColor" value="#34d399">
          </div>
        </div>
        <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn btn-primary" onclick="savePurok()">💾 Save</button>
          <button class="btn" onclick="clearDrawing()" style="margin-left:0">🗑️ Clear Drawing</button>
        </div>
        <div style="margin-top:12px;padding:12px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb;font-size:13px;color:#666">
          <strong>How to use:</strong>
          <ul style="margin:8px 0;padding-left:20px">
            <li><strong>Zone / Purok</strong> mode: use the polygon or rectangle tool. This is what residents see.</li>
            <li><strong>Marker</strong> mode: use the marker (pin) tool for a single general point. These are admin-only reference points.</li>
            <li><strong>Emergency</strong> mode: use the marker pin for emergency centers. These appear in the emergency-info section and update live.</li>
            <li>Fill in the name above and click Save.</li>
          </ul>
        </div>
      </div>

      <!-- Saved items list -->
      <div class="card">
        <h3>Saved Puroks &amp; Markers</h3>
        <p class="text-muted">Zones/Puroks are visible to residents. Markers are admin-only.</p>

        <div style="margin-top:16px;font-size:13px;font-weight:700;color:#166534">🗺️ Zones / Puroks (resident-visible)</div>
        <div id="puroksList" style="margin-top:8px;max-height:220px;overflow-y:auto">
          <?php if ($zoneList): foreach ($zoneList as $p): ?>
          <div class="purok-item" data-id="<?= $p['id'] ?>" data-type="zone" data-coords='<?= htmlspecialchars($p['coordinates'] ?? '[]') ?>'>
            <div>
              <div class="purok-name"><?= htmlspecialchars($p['name']) ?><span class="type-tag zone">Zone</span></div>
              <small class="text-muted"><?= htmlspecialchars($p['description'] ?? 'No description') ?></small>
            </div>
            <div class="purok-color" style="background:<?= htmlspecialchars($p['color']) ?>"></div>
            <div style="display:flex;gap:6px">
              <button class="btn btn-sm" style="padding:4px 8px;font-size:12px" onclick="focusPurok(<?= $p['id'] ?>)">Focus</button>
              <button class="btn btn-sm" style="padding:4px 8px;font-size:12px;background:#fee2e2;color:#991b1b;border-color:#fca5a5" onclick="deletePurok(<?= $p['id'] ?>, this)">Delete</button>
            </div>
          </div>
          <?php endforeach; else: ?>
          <p class="text-muted" style="padding:8px 0;font-size:13px">No zones saved yet.</p>
          <?php endif; ?>
        </div>

        <div style="margin-top:20px;font-size:13px;font-weight:700;color:#1e40af">📍 Markers (admin-only)</div>
        <div id="markersList" style="margin-top:8px;max-height:220px;overflow-y:auto">
          <?php if ($markerList): foreach ($markerList as $p): ?>
          <div class="purok-item" data-id="<?= $p['id'] ?>" data-type="marker" data-coords='<?= htmlspecialchars($p['coordinates'] ?? '{}') ?>'>
            <div>
              <div class="purok-name"><?= htmlspecialchars($p['name']) ?><span class="type-tag marker">Marker</span></div>
              <small class="text-muted"><?= htmlspecialchars($p['description'] ?? 'No description') ?></small>
            </div>
            <div class="purok-color round" style="background:<?= htmlspecialchars($p['color']) ?>"></div>
            <div style="display:flex;gap:6px">
              <button class="btn btn-sm" style="padding:4px 8px;font-size:12px" onclick="focusPurok(<?= $p['id'] ?>)">Focus</button>
              <button class="btn btn-sm" style="padding:4px 8px;font-size:12px;background:#fee2e2;color:#991b1b;border-color:#fca5a5" onclick="deletePurok(<?= $p['id'] ?>, this)">Delete</button>
            </div>
          </div>
          <?php endforeach; else: ?>
          <p class="text-muted" style="padding:8px 0;font-size:13px">No markers saved yet.</p>
          <?php endif; ?>
        </div>

        <div style="margin-top:20px;font-size:13px;font-weight:700;color:#b91c1c">🚨 Emergency Centers</div>
        <div id="emergencyList" style="margin-top:8px;max-height:220px;overflow-y:auto">
          <?php if ($emergencyList): foreach ($emergencyList as $p): ?>
          <div class="purok-item" data-id="<?= $p['id'] ?>" data-type="emergency" data-coords='<?= htmlspecialchars($p['coordinates'] ?? '{}') ?>'>
            <div>
              <div class="purok-name"><?= htmlspecialchars($p['name']) ?><span class="type-tag marker" style="background:#fee2e2;color:#991b1b">Emergency</span></div>
              <small class="text-muted"><?= htmlspecialchars($p['description'] ?? 'Emergency center') ?></small>
            </div>
            <div class="purok-color round" style="background:#ef4444"></div>
            <div style="display:flex;gap:6px">
              <button class="btn btn-sm" style="padding:4px 8px;font-size:12px" onclick="focusPurok(<?= $p['id'] ?>)">Focus</button>
              <button class="btn btn-sm" style="padding:4px 8px;font-size:12px;background:#fee2e2;color:#991b1b;border-color:#fca5a5" onclick="deletePurok(<?= $p['id'] ?>, this)">Delete</button>
            </div>
          </div>
          <?php endforeach; else: ?>
          <p class="text-muted" style="padding:8px 0;font-size:13px">No emergency centers saved yet.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>
</div>

<!-- Purok data for JS -->
<script>
const savedPuroks = <?= json_encode($puroks) ?>;
let currentDrawMode = 'zone'; // 'zone' or 'marker'
</script>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>
<script src="assets/js/app.js"></script>
<script>
let mapInstance, drawnItems, lastDrawnLayer = null;
let drawControl, zonePolygonHandler, zoneRectHandler, markerHandler;

document.addEventListener('DOMContentLoaded', function() {
  const defaultMapCenter = [13.9714416, 121.2069803];
  mapInstance = L.map('map').setView(defaultMapCenter, 17); // Barangay Talisay, Lipa City, Batangas

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap', maxZoom: 19
  }).addTo(mapInstance);

  drawnItems = new L.FeatureGroup().addTo(mapInstance);

  drawControl = new L.Control.Draw({
    edit: { featureGroup: drawnItems },
    draw: { polygon:true, rectangle:true, polyline:false, circle:false, marker:true, circlemarker:false }
  });
  mapInstance.addControl(drawControl);

  // Load saved zones (polygons) onto the map
  savedPuroks.forEach(p => {
    if (!p.coordinates) return;
    const type = p.type || 'zone';
    try {
      if (type === 'marker' || type === 'emergency') {
        const pt = JSON.parse(p.coordinates);
        if (!pt || typeof pt.lat === 'undefined') return;
        const layer = L.circleMarker([pt.lat, pt.lng], {
          radius: 9,
          color: type === 'emergency' ? '#ef4444' : (p.color || '#34d399'),
          fillColor: type === 'emergency' ? '#ef4444' : (p.color || '#34d399'),
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
          color: p.color, fillColor: p.color, fillOpacity: 0.2, weight: 2
        });
        layer.bindPopup(`<b>${p.name}</b><br>${p.description || ''}`);
        layer.addTo(mapInstance);
        layer._purokId = p.id;
      }
    } catch(e) {}
  });

  // Load resident markers (from resident records, separate from admin markers above)
  fetch('api/residents_map.php')
    .then(r => r.json())
    .then(data => {
      if (!data.residents) return;
      data.residents.forEach(r => {
        if (!r.latitude || !r.longitude) return;
        L.marker([r.latitude, r.longitude])
          .bindPopup(`<b>${r.full_name}</b><br>${r.zone_purok || ''}`)
          .addTo(mapInstance);
      });
    }).catch(() => {});

  mapInstance.on('draw:created', function(e) {
    const isMarker = e.layerType === 'marker';
    const isZoneShape = ['polygon','rectangle'].includes(e.layerType);

    if (currentDrawMode === 'zone' && !isZoneShape) {
      showToast('You are in Zone/Purok mode. Switch to Marker mode to drop a pin, or use the polygon/rectangle tool.', 'error');
      return;
    }
    if ((currentDrawMode === 'marker' || currentDrawMode === 'emergency') && !isMarker) {
      showToast(currentDrawMode === 'emergency'
        ? 'Emergency mode only accepts a single point marker.'
        : 'Marker mode only accepts a single point marker.', 'error');
      return;
    }
    if (lastDrawnLayer) drawnItems.removeLayer(lastDrawnLayer);
    lastDrawnLayer = e.layer;
    drawnItems.addLayer(lastDrawnLayer);
    showToast('Drawn! Fill in the name and click Save.', 'info');
  });
});

// ── Zone vs Marker mode toggle ──────────────────────────────────
function setDrawMode(mode) {
  currentDrawMode = mode;
  document.getElementById('modeZoneBtn').classList.toggle('active', mode === 'zone');
  document.getElementById('modeMarkerBtn').classList.toggle('active', mode === 'marker');
  document.getElementById('modeEmergencyBtn').classList.toggle('active', mode === 'emergency');
  clearDrawing();
  const message = mode === 'zone'
    ? 'Zone/Purok mode — use the polygon or rectangle tool.'
    : mode === 'marker'
      ? 'Marker mode — use the marker (pin) tool.'
      : 'Emergency mode — use the marker (pin) tool for emergency centers.';
  showToast(message, 'info');
}

async function savePurok() {
  const name  = document.getElementById('purokName').value.trim();
  const desc  = document.getElementById('purokDesc').value.trim();
  const color = document.getElementById('purokColor').value;

  if (!name) { showToast('Please enter a name.', 'error'); return; }
  if (!lastDrawnLayer) { showToast('Please draw a marker or shape on the map first.', 'error'); return; }

  let coords = '';
  let type = currentDrawMode;

  if (lastDrawnLayer instanceof L.Marker) {
    type = currentDrawMode;
    const ll = lastDrawnLayer.getLatLng();
    coords = JSON.stringify({ lat: ll.lat, lng: ll.lng });
  } else if (lastDrawnLayer.getLatLngs) {
    type = 'zone';
    const lls = lastDrawnLayer.getLatLngs();
    const flat = Array.isArray(lls[0]) ? lls[0] : lls;
    coords = JSON.stringify(flat.map(ll => [ll.lat, ll.lng]));
  }

  if (!coords) { showToast('Could not read the drawn shape. Try drawing again.', 'error'); return; }

  const fd = new FormData();
  fd.append('action',      'save_purok');
  fd.append('name',        name);
  fd.append('description', desc);
  fd.append('color',       color);
  fd.append('coordinates', coords);
  fd.append('type',        type);

  const res  = await fetch('mapping.php', { method:'POST', body:fd });
  const data = await res.json();
  if (data.success) {
    showToast(data.message, 'success');
    setTimeout(() => location.reload(), 800);
  } else {
    showToast(data.message, 'error');
  }
}

function clearDrawing() {
  if (lastDrawnLayer) { drawnItems.removeLayer(lastDrawnLayer); lastDrawnLayer = null; }
  document.getElementById('purokName').value = '';
  document.getElementById('purokDesc').value = '';
}

async function deletePurok(id, btn) {
  if (!confirm('Delete this item?')) return;
  const fd = new FormData();
  fd.append('action','delete_purok'); fd.append('id',id);
  const res  = await fetch('mapping.php',{method:'POST',body:fd});
  const data = await res.json();
  if (data.success) { btn.closest('.purok-item').remove(); showToast('Deleted.','info'); }
}

function focusPurok(id) {
  const item = document.querySelector(`.purok-item[data-id="${id}"]`);
  if (!item || !mapInstance) return;
  const type = item.dataset.type;
  const raw  = item.dataset.coords;
  try {
    if (type === 'marker' || type === 'emergency') {
      const pt = JSON.parse(raw);
      mapInstance.setView([pt.lat, pt.lng], 18);
    } else {
      const coords = JSON.parse(raw);
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