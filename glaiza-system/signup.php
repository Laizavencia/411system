<?php
// ============================================================
// signup.php  — Resident registration
// ============================================================
session_start();
require_once 'includes/db.php';

if (!empty($_SESSION['resident_id'])) {
    header('Location: resident_portal.php');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect & sanitize
    $firstName   = trim($_POST['first_name']   ?? '');
    $middleName  = trim($_POST['middle_name']  ?? '');
    $lastName    = trim($_POST['last_name']    ?? '');
    $email       = trim($_POST['email']        ?? '');
    $phoneRaw    = trim($_POST['phone']        ?? '');
    $phone       = preg_replace('/\D+/', '', $phoneRaw); // keep digits only
    $dob         = trim($_POST['dob']          ?? '');
    $gender      = trim($_POST['gender']       ?? '');
    $street      = trim($_POST['street']       ?? '');
    $zone        = trim($_POST['zone']         ?? '');
    $house       = trim($_POST['house_number'] ?? '');
    $occupation  = trim($_POST['occupation']   ?? '');
    $password    = $_POST['password']          ?? '';
    $confirm     = $_POST['confirm_password']  ?? '';

    // Validate — required fields (occupation is optional)
    if (!$firstName || !$lastName || !$email || !$phoneRaw || !$dob || !$gender
      || !$street || !$zone || !$house || !$password || !$confirm) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif (strlen($password) < 8) {
      $error = 'Password must be at least 8 characters.';
    } else {
      // Stronger password rules
      $hasUpper = preg_match('/[A-Z]/', $password);
      $hasLower = preg_match('/[a-z]/', $password);
      $hasNumber = preg_match('/[0-9]/', $password);
      $hasSpecial = preg_match('/[!@#\$%]/', $password);

      $commonPasswords = [
        'password', 'password123', '12345678', '123456789', 'qwerty', '123456', '111111'
      ];

      if ($password !== $confirm) {
        $error = 'Passwords do not match.';
      } elseif (!$hasUpper || !$hasLower || !$hasNumber || !$hasSpecial) {
        $error = 'Password must include uppercase, lowercase, a number, and a special character (! @ # $ %).';
        } elseif (in_array(strtolower($password), $commonPasswords, true)) {
          $error = 'That password is too common — choose a stronger password.';
        }
      }

      if (!$error) {
        if (!preg_match('/^\d{11}$/', $phone)) {
          $error = 'Phone number must be exactly 11 digits (numbers only).';
        } else {
          // Check email uniqueness
          $exists = dbFetchOne('SELECT id FROM residents WHERE email=? LIMIT 1', [$email]);
          if ($exists) {
            $error = 'An account with that email already exists.';
          } else {
            try {
              $barangayId   = generateResidentId();
              $passwordHash = password_hash($password, PASSWORD_BCRYPT);

              $residentId = dbInsert(
                "INSERT INTO residents
                   (barangay_id, first_name, middle_name, last_name, email, phone,
                  date_of_birth, gender, street_address, zone_purok, house_number,
                  occupation, password_hash, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'Pending')",
                [
                  $barangayId, $firstName, $middleName ?: null, $lastName, $email, $phone,
                  $dob ?: null, $gender, $street, $zone, $house,
                  $occupation, $passwordHash,
                ]
              );

              logActivity("New resident registered: $barangayId", 'Signup', 'resident');
              $success = "Account created! Your Barangay ID is <strong>$barangayId</strong>. You can now <a href='index.php'>sign in</a>.";

            } catch (Exception $e) {
              $error = 'Registration failed. Please try again.';
            }
          }
        }
      }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Sign Up — Barangay Talisay</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <?php renderThemeStyle(); ?>
  <style>
    .pw-feedback{list-style:none;padding:0;margin:8px 0 16px 0;display:flex;flex-wrap:wrap;gap:8px}
    .pw-feedback li{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;background:#f3f4f6;color:#374151;font-size:13px}
    .pw-feedback li.ok{background:#ecfdf5;color:#065f46}
    .pw-feedback li.fail{background:#fff1f2;color:#9f1239}
    .pw-feedback .icon{width:16px;display:inline-block;text-align:center;font-weight:700}

    .pw-input{position:relative}
    .pw-input .pw-toggle{position:absolute;right:8px;top:50%;transform:translateY(-50%);background:transparent;border:none;cursor:pointer;font-size:16px;padding:6px;line-height:1;color:#000}
    .pw-input .pw-toggle:focus{outline:2px solid rgba(59,130,246,0.25);border-radius:6px}
  </style>
</head>
<body>
<div class="login-wrap">
  <div class="login-card" style="width:920px">
    <div class="login-left">
      <div class="brand">
        <div class="logo"><img src="<?= getSiteLogo() ?>" alt="Logo"></div>
        <div>
          <h2>Barangay Talisay</h2>
          <p class="text-muted">Resident Registration Portal</p>
        </div>
      </div>
      <div style="margin-top:28px">
        <h3>Welcome to Our Community</h3>
        <p>Register as a resident to access barangay services, request documents, and stay connected with your community.</p>
      </div>
    </div>
    <div class="login-right" style="max-height:90vh;overflow-y:auto;justify-content:flex-start !important">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
        <h3>Create Account</h3>
        <a href="index.php" class="text-muted" style="font-size:13px;text-decoration:none">Back to Login</a>
      </div>

      <?php if ($error): ?>
        <div style="background:#fee2e2;color:#991b1b;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:14px;">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div style="background:#dcfce7;color:#166534;padding:14px;border-radius:8px;margin-bottom:16px;font-size:14px;">
          <?= $success ?>
        </div>
      <?php endif; ?>

      <?php if (!$success): ?>
      <form method="POST" id="signupForm">
        <div class="form-grid">
          <div class="form-group">
            <label>First Name *</label>
            <input class="form-control" type="text" name="first_name" placeholder="Jeivi" required value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>">
          </div>
        <div class="form-group">
          <label>Middle Name <span style="font-weight:400;color:#9ca3af">(optional)</span></label>
          <input class="form-control" type="text" name="middle_name" placeholder="Rodelas" value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>">
        </div>
          <div class="form-group">
            <label>Last Name *</label>
            <input class="form-control" type="text" name="last_name" placeholder="Vera Cruz" required value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
          </div>
        </div>
        <div class="form-grid">
          <div class="form-group">
            <label>Email Address *</label>
            <input class="form-control" type="email" name="email" placeholder="juan@example.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Phone Number *</label>
            <input id="phoneInput" class="form-control" type="tel" name="phone" placeholder="09175550123" required maxlength="11" pattern="\d{11}" title="Enter 11 digits" inputmode="numeric"
              oninput="this.value=this.value.replace(/\D/g,'').slice(0,11)" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
          </div>
        </div>
        <div class="form-grid">
          <div class="form-group">
            <label>Date of Birth *</label>
            <input class="form-control" type="date" name="dob" required value="<?= htmlspecialchars($_POST['dob'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Gender *</label>
            <select class="form-control" name="gender" required>
              <option value="">Select...</option>
              <option value="Male"   <?= (($_POST['gender'] ?? '') === 'Male'   ? 'selected' : '') ?>>Male</option>
              <option value="Female" <?= (($_POST['gender'] ?? '') === 'Female' ? 'selected' : '') ?>>Female</option>
              <option value="Other"  <?= (($_POST['gender'] ?? '') === 'Other'  ? 'selected' : '') ?>>Other</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Street Address *</label>
          <input class="form-control" type="text" name="street" placeholder="123 Sampaguita St." required value="<?= htmlspecialchars($_POST['street'] ?? '') ?>">
        </div>
        <div class="form-grid">
          <div class="form-group">
            <label>Zone / Purok *</label>
            <select class="form-control" name="zone" required>
              <option value="">Select Zone...</option>
              <?php
              $puroks = dbFetchAll("SELECT name FROM puroks WHERE type='zone' ORDER BY name");
              if ($puroks) {
                  foreach ($puroks as $p) {
                      $sel = (($_POST['zone'] ?? '') === $p['name']) ? 'selected' : '';
                      echo "<option value=\"".htmlspecialchars($p['name'])."\" $sel>".htmlspecialchars($p['name'])."</option>";
                  }
              } else {
                  foreach (['Zone 1','Zone 2','Zone 3','Zone 4'] as $z) {
                      $sel = (($_POST['zone'] ?? '') === $z) ? 'selected' : '';
                      echo "<option value=\"$z\" $sel>$z</option>";
                  }
              }
              ?>
            </select>
          </div>
          <div class="form-group">
            <label>House Number *</label>
            <input class="form-control" type="text" name="house_number" placeholder="Lot 5" required value="<?= htmlspecialchars($_POST['house_number'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Occupation</label>
          <input class="form-control" type="text" name="occupation" placeholder="e.g. Teacher, Farmer (optional)" value="<?= htmlspecialchars($_POST['occupation'] ?? '') ?>">
        </div>
        <div class="form-grid">
          <div class="form-group">
            <label>Password *</label>
            <div class="pw-input">
              <input id="password" class="form-control" type="password" name="password" placeholder="Min. 8 characters" required>
              <button type="button" class="pw-toggle" aria-label="Show password" data-target="password">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.6 18.6 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
            </div>
          </div>
          <div class="form-group">
            <label>Confirm Password *</label>
            <div class="pw-input">
              <input id="confirm_password" class="form-control" type="password" name="confirm_password" placeholder="Re-enter password" required>
              <button type="button" class="pw-toggle" aria-label="Show confirm password" data-target="confirm_password">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.6 18.6 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
            </div>
          </div>
        </div>
        <ul id="passwordFeedback" class="pw-feedback" aria-live="polite">
          <li data-key="len" class="fail"><span class="icon">✗</span><span>Minimum 8 characters</span></li>
          <li data-key="upper" class="fail"><span class="icon">✗</span><span>Uppercase A-Z</span></li>
          <li data-key="lower" class="fail"><span class="icon">✗</span><span>Lowercase a-z</span></li>
          <li data-key="num" class="fail"><span class="icon">✗</span><span>Number 0-9</span></li>
          <li data-key="special" class="fail"><span class="icon">✗</span><span>Special ! @ # $ %</span></li>
        </ul>
        <div style="margin:16px 0;display:flex;align-items:flex-start;gap:8px">
          <input type="checkbox" id="agreeTerms" name="agreeTerms" required style="margin-top:2px">
          <label for="agreeTerms" style="font-size:13px;line-height:1.4">I agree to the <a href="terms.php" target="_blank" style="color:var(--primary);text-decoration:underline">Terms of Service</a> and <a href="privacy.php" target="_blank" style="color:var(--primary);text-decoration:underline">Privacy Policy</a> of Barangay Talisay and confirm that the information provided is accurate.</label>
        </div>
        <button class="btn btn-primary" type="submit" style="width:100%">Create Account</button>
        <div class="login-footer text-muted" style="margin-top:16px">
          Already have an account? <a href="index.php" style="color:var(--primary);font-weight:600;text-decoration:none">Sign In</a>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
<script>

(() => {
  const form = document.getElementById('signupForm');
  const pwd = document.getElementById('password');
  const confirm = document.getElementById('confirm_password');
  const feedback = document.getElementById('passwordFeedback');
  const items = {
    len: feedback.querySelector('li[data-key="len"]'),
    upper: feedback.querySelector('li[data-key="upper"]'),
    lower: feedback.querySelector('li[data-key="lower"]'),
    num: feedback.querySelector('li[data-key="num"]'),
    special: feedback.querySelector('li[data-key="special"]'),
  };
  

  function checkPassword(value) {
    return {
      len: value.length >= 8,
      upper: /[A-Z]/.test(value),
      lower: /[a-z]/.test(value),
      num: /[0-9]/.test(value),
      special: /[!@#\$%]/.test(value),
    };
  }

  function setState(el, ok) {
    if (!el) return;
    const icon = el.querySelector('.icon');
    if (ok) {
      el.classList.remove('fail'); el.classList.add('ok'); icon.textContent = '✓';
    } else {
      el.classList.remove('ok'); el.classList.add('fail'); icon.textContent = '✗';
    }
  }

  function renderFeedback() {
    const v = pwd.value || '';
    const r = checkPassword(v);
    setState(items.len, r.len);
    setState(items.upper, r.upper);
    setState(items.lower, r.lower);
    setState(items.num, r.num);
    setState(items.special, r.special);
  }

  pwd.addEventListener('input', renderFeedback);
  confirm.addEventListener('input', renderFeedback);

  form.addEventListener('submit', function (e) {
    const v = pwd.value || '';
    const r = checkPassword(v);
    const allOk = r.len && r.upper && r.lower && r.num && r.special;
    if (!allOk) {
      e.preventDefault(); renderFeedback(); pwd.focus(); alert('Please fix your password to meet the required rules.'); return false;
    }
    if (v !== (confirm.value || '')) { e.preventDefault(); confirm.focus(); alert('Passwords do not match.'); return false; }
  });

  // initial render
  renderFeedback();
})();

// Eye toggles for password visibility
(function () {
  const toggles = document.querySelectorAll('.pw-toggle');
  const eyeSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>';
  const eyeOffSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.6 18.6 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
  toggles.forEach(btn => {
    btn.addEventListener('click', function () {
      const targetId = this.getAttribute('data-target');
      const input = document.getElementById(targetId);
      if (!input) return;
      if (input.type === 'password') {
        input.type = 'text';
        this.innerHTML = eyeOffSvg;
        this.setAttribute('aria-label', 'Hide password');
      } else {
        input.type = 'password';
        this.innerHTML = eyeSvg;
        this.setAttribute('aria-label', 'Show password');
      }
    });
  });
})();
</script>
</html>