<?php
// ============================================================
// includes/site_settings.php
// Load barangay settings from DB once per request.
// Include this AFTER db.php on every page that needs settings.
// ============================================================

function getSiteSettings(bool $forceRefresh = false): array {
    static $cache = null;
    if ($cache === null || $forceRefresh) {
        $rows  = dbFetchAll("SELECT setting_key, setting_value FROM barangay_settings");
        $cache = array_column($rows, 'setting_value', 'setting_key');
    }
    return $cache;
}

/**
 * Output an inline <style> block that sets the --primary CSS variable.
 * Uses the default color since theme editing has been removed.
 * Still called inside <head> on every page.
 */
function renderThemeStyle(): void {
    echo "<style>
:root {
  --primary: #0066cc;
  --primary-light: #e6f0ff;
  --accent: #00b894;
  --success: #10b981;
  --warning: #f59e0b;
  --danger: #ef4444;
  --info: #3b82f6;
  --muted: #f9fafb;
  --sidebar: #ffffff;
  --text: #1f2937;
  --text-light: #6b7280;
  --border: #e5e7eb;
  --shadow: 0 10px 40px rgba(0,0,0,0.08);
  --shadow-sm: 0 4px 12px rgba(0,0,0,0.05);
  --shadow-lg: 0 20px 60px rgba(0,0,0,0.15);
}
</style>\n";
}

/**
 * Return the logo path to use in <img src="...">.
 */
function getSiteLogo(): string {
    $s = getSiteSettings();
    return htmlspecialchars($s['logo_path'] ?? 'assets/images/logo.jpg');
}

/**
 * Return the barangay name.
 */
function getSiteName(): string {
    $s = getSiteSettings();
    return htmlspecialchars($s['barangay_name'] ?? 'Barangay Talisay');
}

function getJsonSetting(string $key, array $fallback = []): array {
    $s = getSiteSettings();
    $raw = $s[$key] ?? null;
    if ($raw === null || trim((string)$raw) === '') {
        return $fallback;
    }

    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function formatJsonForInput(array $value): string {
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}