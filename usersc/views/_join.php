<?php
declare(strict_types=1);

/**
 * View variables set by usersc/join.php before it requires this file.
 * $email, $fname and $lname are set only on POST; every use below is guarded
 * by !empty($_POST).
 *
 * @var array<mixed> $hooks
 * @var bool $form_valid
 * @var string $email
 * @var string $fname
 * @var string $lname
 */

/*
Enhanced Lotus Elan Registry Registration Page
Customized for the Lotus Elan Registry with improved UX and registry-specific features
Based on UserSpice 5 registration system
*/

// Drain session flash messages here, before users/includes/html_footer.php's
// system_messages_footer.php gets a chance to (parseSessionMessages() reads
// and clears the session slot on first call — whichever call site runs first
// "wins"). This routes registration failures into a modal that stays on
// screen until dismissed, instead of a 6-second auto-fading toast that's easy
// to miss (#1406 — the enumeration-safe generic message is subtle enough on
// its own without also being hard to notice).
//
// Scoped to valErr only — the only message type #1406's join.php failure
// path ever sets. Any valSuc/genMsg content (not currently used on this
// page, but parseSessionMessages() drains all three slots regardless) is
// re-queued via usSuccess()/usMessage() so it still reaches the standard
// toast later in this same request instead of being silently dropped.
//
// Escaped with htmlspecialchars() here per CLAUDE.md ("Apply htmlspecialchars()
// at the render layer only" — this is that render layer). No call site on
// this page currently interpolates user-controlled data into a flash message,
// but this is the sanctioned reusable "Danger Modal" pattern (see
// UI_STANDARDS.md), so it must be safe by default for a future consumer that
// does.
$joinInlineMessages = function_exists('parseSessionMessages') ? parseSessionMessages() : [];
$joinFailureMessage = $joinInlineMessages['valErr'] ?? '';

if (!empty($joinInlineMessages['valSuc']) && function_exists('usSuccess')) {
    usSuccess($joinInlineMessages['valSuc']);
}
if (!empty($joinInlineMessages['genMsg']) && function_exists('usMessage')) {
    usMessage($joinInlineMessages['genMsg']);
}
?>

<?php if ($joinFailureMessage !== ''): ?>
<div class="modal fade" id="joinFailureModal" tabindex="-1" aria-labelledby="joinFailureModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="joinFailureModalLabel"><i class="fas fa-exclamation-circle me-2"></i>Registration Issue</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-danger mb-0"><?= htmlspecialchars($joinFailureMessage, ENT_QUOTES, 'UTF-8') ?></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<script nonce="<?= htmlspecialchars($userspice_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
document.addEventListener('DOMContentLoaded', function() {
    var joinFailureModalEl = document.getElementById('joinFailureModal');
    if (joinFailureModalEl) {
        bootstrap.Modal.getOrCreateInstance(joinFailureModalEl).show();
    }
});
</script>
<?php endif; ?>

<div class="container">
  <div class="row justify-content-center">
    <div class="col-12 col-lg-10 col-xl-8">
      <!-- Registry Welcome Header -->
      <div class="card registry-card mb-4">
        <div class="card-body text-center py-4">
          <div class="mb-3">
            <i class="fas fa-car fa-3x text-primary"></i>
          </div>
          <h1 class="h3 mb-3 text-primary">Join the Lotus Elan Registry</h1>
          <p class="text-muted mb-0">
            Welcome to the world's most comprehensive database of Lotus Elan ownership and history.
            Register your account to add your Elan to our growing registry of over 2,000 vehicles.
          </p>
        </div>
      </div>

      <!-- Hidden username elements expected by autoassignun plugin -->
      <label id="username-label" style="display:none"></label>
      <input type="hidden" id="username" name="username">

      <?php includeHook($hooks, 'body'); ?>

      <!-- Registration Form -->
      <div class="card registry-card">
        <div class="card-header card-header-er-primary">
          <h2 class="mb-0 card-header-er-primary-text"><i class="fas fa-user-plus"></i> <strong>Create Your Account</strong></h2>
        </div>
        <div class="card-body">
          <form class="needs-validation" action="" method="POST" id="join-form" novalidate>

            <!-- Account Information Section -->
            <div class="form-section mb-4">
              <h5 class="section-title mb-3">
                <i class="fas fa-user text-primary"></i> Account Information
              </h5>
              
              <div class="row">
                <div class="col-12 mb-3">
                  <label for="email" class="form-label"><?= lang("GEN_EMAIL"); ?> *</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                    <input type="email" class="form-control" id="email" name="email" 
                           placeholder="your.email@example.com"
                           value="<?php if (!$form_valid && !empty($_POST)) echo htmlspecialchars($email); ?>" 
                           required autocomplete="email">
                    <span class="input-group-text field-status-icon d-none" id="email-status-icon" aria-hidden="true">
                      <i class="fas"></i>
                    </span>
                    <div class="invalid-feedback">Please provide a valid email address.</div>
                  </div>
                  <div class="form-text text-muted">
                    <i class="fas fa-info-circle"></i>
                    Your username will be automatically generated from your email address.
                  </div>
                </div>
              </div>
            </div>

            <!-- Personal Information Section -->
            <div class="form-section mb-4">
              <h5 class="section-title mb-3">
                <i class="fas fa-id-card text-primary"></i> Personal Information
              </h5>
              
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label for="fname" class="form-label"><?= lang("GEN_FNAME"); ?> *</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-user-circle"></i></span>
                    <input type="text" class="form-control" id="fname" name="fname" 
                           placeholder="First name"
                           value="<?php if (!$form_valid && !empty($_POST)) echo htmlspecialchars($fname); ?>" 
                           required autocomplete="given-name">
                    <span class="input-group-text field-status-icon d-none" id="fname-status-icon" aria-hidden="true">
                      <i class="fas"></i>
                    </span>
                    <div class="invalid-feedback">Please enter your first name.</div>
                  </div>
                </div>
                
                <div class="col-md-6 mb-3">
                  <label for="lname" class="form-label"><?= lang("GEN_LNAME"); ?> *</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-user-circle"></i></span>
                    <input type="text" class="form-control" id="lname" name="lname" 
                           placeholder="Last name"
                           value="<?php if (!$form_valid && !empty($_POST)) echo htmlspecialchars($lname); ?>" 
                           required autocomplete="family-name">
                    <span class="input-group-text field-status-icon d-none" id="lname-status-icon" aria-hidden="true">
                      <i class="fas"></i>
                    </span>
                    <div class="invalid-feedback">Please enter your last name.</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Location Information Section -->
            <div class="form-section mb-4">
              <h5 class="section-title mb-3">
                <i class="fas fa-map-marker-alt text-primary"></i> Location Information
              </h5>
              <p class="text-muted mb-3">
                <i class="fas fa-info-circle"></i>
                Your location helps other registry members find Elans in their area and assists with regional statistics.
                Use the GPS button on mobile or search for your location below.
              </p>

              <!-- Location Picker Component -->
              <div id="location-picker-registration" class="location-picker-container"></div>
            </div>

            <!-- Password Section -->
            <?php if ($settings->no_passwords == 0) { ?>
            <div class="form-section mb-4">
              <h5 class="section-title mb-3">
                <i class="fas fa-lock text-primary"></i> Password Security
              </h5>
              
              <div class="row">
                <div class="col-lg-5 mb-3">
                  <?php 
                    if(file_exists($abs_us_root . $us_url_root . 'usersc/includes/password_meter.php')) {
                      include($abs_us_root . $us_url_root . 'usersc/includes/password_meter.php');
                    } else {
                      include($abs_us_root . $us_url_root . 'users/includes/password_meter.php');
                    }
                  ?>
                </div>
                
                <div class="col-lg-7">
                  <div class="mb-3">
                    <label for="password" class="form-label"><?= lang("GEN_PASS"); ?> *</label>
                    <div class="input-group">
                      <span class="input-group-text"><i class="fas fa-key"></i></span>
                      <input type="password" class="form-control" id="password" name="password" 
                             placeholder="Enter secure password" required autocomplete="new-password" tabindex="1">
                      <button type="button" class="btn btn-outline-secondary password-toggle" data-target="password" tabindex="-1">
                        <i class="fas fa-eye"></i>
                      </button>
                      <span class="input-group-text field-status-icon d-none" id="password-status-icon" aria-hidden="true">
                        <i class="fas"></i>
                      </span>
                      <div class="invalid-feedback">Please enter a password.</div>
                    </div>
                  </div>
                  
                  <div class="mb-3">
                    <label for="confirm" class="form-label"><?= lang("PW_CONF"); ?> *</label>
                    <div class="input-group">
                      <span class="input-group-text"><i class="fas fa-key"></i></span>
                      <input type="password" class="form-control" id="confirm" name="confirm" 
                             placeholder="Confirm password" required autocomplete="new-password" tabindex="2">
                      <button type="button" class="btn btn-outline-secondary password-toggle" data-target="confirm" tabindex="-1">
                        <i class="fas fa-eye"></i>
                      </button>
                      <span class="input-group-text field-status-icon d-none" id="confirm-status-icon" aria-hidden="true">
                        <i class="fas"></i>
                      </span>
                      <div class="invalid-feedback">Please confirm your password.</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <?php } ?>

            <!-- Form Hooks and Additional Fields -->
            <?php includeHook($hooks, 'form'); ?>

            <div id="turnstile-status-message" class="alert alert-warning d-none" role="alert"></div>

            <!-- CSRF Protection -->
            <input type="hidden" value="<?= Token::generate(); ?>" name="csrf">

            <!-- Submit Section -->
            <div class="text-center pt-3">
              <div id="join-validation-summary" class="alert alert-danger text-start d-none" role="alert"></div>
              <button type="submit" class="btn btn-primary btn-lg px-5" id="next_button">
                <i class="fas fa-user-plus me-2"></i>
                Create Registry Account
              </button>
              <div class="mt-3">
                <small class="text-muted">
                  Already have an account? 
                  <a href="<?= $us_url_root ?>users/login.php" class="text-primary">Sign in here</a>
                </small>
              </div>
            </div>

          </form>
        </div>
      </div>

      <!-- Social Logins (if enabled) -->
      <?php
      if (file_exists($abs_us_root . $us_url_root . "usersc/views/_social_logins.php")) {
        require_once $abs_us_root . $us_url_root . "usersc/views/_social_logins.php";
      } else {
        require_once $abs_us_root . $us_url_root . "users/views/_social_logins.php";
      }
      ?>

    </div>
  </div>
</div>

<!-- Custom Registration Styles and Scripts -->
<style>
.form-section {
  border-left: 3px solid var(--bs-primary);
  padding-left: 1rem;
}

.section-title {
  color: var(--bs-gray-700);
  font-weight: 600;
}

.input-group-text {
  background-color: var(--bs-light);
  border-color: var(--bs-border-color);
  color: var(--bs-primary);
}

.form-control:focus {
  border-color: var(--bs-primary);
  box-shadow: 0 0 0 0.2rem rgba(var(--bs-primary-rgb), 0.25);
}

.password-toggle {
  cursor: pointer;
}

/* Per-field status border (#1690) — wraps the whole input-group so the
   border reads as one continuous outline instead of fighting Bootstrap's
   individual child-element borders. */
.input-group.field-status-valid {
  outline: 2px solid var(--bs-success);
  outline-offset: 1px;
  border-radius: var(--bs-border-radius);
}

.input-group.field-status-invalid {
  outline: 2px solid var(--bs-danger);
  outline-offset: 1px;
  border-radius: var(--bs-border-radius);
}

.password-toggle:hover {
  background-color: var(--bs-light);
}

@media (max-width: 768px) {
  .form-section {
    border-left: none;
    border-top: 3px solid var(--bs-primary);
    padding-left: 0;
    padding-top: 1rem;
  }
}
</style>

<script nonce="<?= htmlspecialchars($userspice_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
window.elanUrlRoot = '<?= $us_url_root ?>';

document.addEventListener('DOMContentLoaded', function() {
  // Enhanced password visibility toggle
  document.querySelectorAll('.password-toggle').forEach(function(button) {
    button.addEventListener('click', function() {
      const targetId = this.dataset.target;
      const targetInput = document.getElementById(targetId);
      const icon = this.querySelector('i');
      
      if (targetInput.type === 'password') {
        targetInput.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
      } else {
        targetInput.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
      }
    });
  });

  // Form validation feedback
  const form = document.getElementById('join-form');
  const validationSummary = document.getElementById('join-validation-summary');

  // The label text comes from the page markup, never from what the visitor
  // typed, so the summary cannot echo an email or password back (#2043).
  function fieldLabel(control) {
    const label = control.labels && control.labels.length > 0 ? control.labels[0] : null;
    const text = label ? label.textContent.replace(/\*/g, '').trim() : '';
    return text || control.getAttribute('aria-label') || control.getAttribute('placeholder') || control.name || control.id;
  }

  function hideValidationSummary() {
    if (validationSummary) {
      validationSummary.classList.add('d-none');
      validationSummary.textContent = '';
    }
  }

  // form.elements, not :invalid, so a control placed outside the <form>
  // with form="join-form" (a plugin hook) is still listed.
  function invalidControls() {
    return Array.prototype.filter.call(form.elements, function (control) {
      return control.willValidate && !control.validity.valid;
    });
  }

  function summaryText(invalid) {
    if (invalid.length === 0) {
      return 'Please check the form and try again.';
    }
    const labels = invalid.map(fieldLabel);
    return (labels.length === 1 ? 'Please fix this field before you continue: ' : 'Please fix these fields before you continue: ')
      + labels.join(', ') + '.';
  }

  // One blocked visitor pressing submit again and again must not use up the
  // shared join_failure_beacon limit and hide other reasons (Turnstile, GPS).
  let lastReportedDetail = null;

  if (form) {
    form.addEventListener('submit', function(event) {
      if (!form.checkValidity()) {
        event.preventDefault();
        event.stopPropagation();
        form.classList.add('was-validated');

        // Bootstrap marks each field, but a field inside the location picker
        // or out of view is easy to miss. Name the fields above the button.
        // The scroll to the first field is immediate. Focus follows in the
        // next frame, after the summary text is set (#2043).
        const invalid = invalidControls();
        if (invalid.length > 0) {
          invalid[0].scrollIntoView({ block: 'center' });
        }

        // Build the text before the box shows, so a failure here cannot
        // leave an empty red alert on the page.
        const text = summaryText(invalid);
        if (validationSummary) {
          // Empty first, so a screen reader announces the alert again when
          // the visitor submits twice with the same fields.
          validationSummary.textContent = '';
          validationSummary.classList.remove('d-none');
        }
        window.requestAnimationFrame(function () {
          if (validationSummary) {
            validationSummary.textContent = text;
          }
          // Text before focus, so the alert does not cut off the field's own description.
          if (invalid.length > 0) {
            invalid[0].focus({ preventScroll: true });
          }
        });

        // Field identifiers only — never .value, because the form holds a password.
        const detail = invalid.length > 0 ? invalid.map(function (control) {
          return control.name || control.id;
        }).join(',') : 'unknown';
        if (detail !== lastReportedDetail && typeof window.elanReportJoinFailure === 'function') {
          lastReportedDetail = detail;
          window.elanReportJoinFailure('client_validation', detail);
        }
        return;
      }
      hideValidationSummary();
      form.classList.add('was-validated');
    });

    // While the summary shows, keep its list current as fields are fixed.
    function refreshSummary() {
      if (!validationSummary || validationSummary.classList.contains('d-none')) {
        return;
      }
      const invalid = invalidControls();
      if (invalid.length === 0) {
        hideValidationSummary();
      } else {
        // Write only on a change: each write to a role="alert" region can be
        // announced again, which would interrupt every keystroke.
        const text = summaryText(invalid);
        if (validationSummary.textContent !== text) {
          validationSummary.textContent = text;
        }
      }
    }
    form.addEventListener('input', refreshSummary);
    form.addEventListener('change', refreshSummary);
  }

  // Password confirmation validation
  const password = document.getElementById('password');
  const confirm = document.getElementById('confirm');
  
  if (password && confirm) {
    function validatePasswordMatch() {
      if (confirm.value && password.value !== confirm.value) {
        confirm.setCustomValidity('Passwords do not match');
      } else {
        confirm.setCustomValidity('');
      }
    }
    
    password.addEventListener('input', validatePasswordMatch);
    confirm.addEventListener('input', validatePasswordMatch);
  }

  // Per-field status icons — visible immediately for required fields (red X
  // when empty/invalid, green check once valid), not just after interaction,
  // so an unfilled required field is never silently blank (#1690).
  var trackedFields = ['email', 'fname', 'lname', 'password', 'confirm'];
  trackedFields.forEach(function (id) {
    var input = document.getElementById(id);
    var icon = document.getElementById(id + '-status-icon');
    var group = input ? input.closest('.input-group') : null;
    if (!input || !icon) return;
    function updateIcon() {
      var isValid = input.checkValidity();
      icon.classList.remove('d-none');
      icon.querySelector('i').className = 'fas ' + (isValid ? 'fa-check-circle text-success' : 'fa-times-circle text-danger');
      if (group) {
        group.classList.toggle('field-status-valid', isValid);
        group.classList.toggle('field-status-invalid', !isValid);
      }
    }
    if (input.required) {
      updateIcon();
    }
    input.addEventListener('input', updateIcon);
    input.addEventListener('blur', updateIcon);
  });
});
</script>

<!-- Shared Turnstile reset helper — must load before the beacon script below,
     which redefines elanTurnstileError/elanTurnstileExpired with its own
     join-specific versions that call window.elanTurnstileReset() -->
<script src="<?=$us_url_root?>app/assets/js/turnstile-reset.min.js?v=<?= ASSET_VERSION ?>"></script>

<!-- Join Form Beacon Script -->
<script src="<?=$us_url_root?>app/assets/js/join-form-beacon.min.js?v=<?= ASSET_VERSION ?>"></script>