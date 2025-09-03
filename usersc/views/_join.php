<?php
/*
Enhanced Lotus Elan Registry Registration Page
Customized for the Lotus Elan Registry with improved UX and registry-specific features
Based on UserSpice 5 registration system
*/
?>

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

      <?php includeHook($hooks, 'body'); ?>

      <!-- Registration Form -->
      <div class="card registry-card">
        <div class="card-header">
          <h2 class="mb-0"><i class="fas fa-user-plus"></i> <strong>Create Your Account</strong></h2>
        </div>
        <div class="card-body">
          <form class="needs-validation" action="" method="POST" id="payment-form" novalidate>

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
                This information will be used to geocode your car's location on our registry maps.
              </p>
              
              <?php
              // Get the country list for the enhanced location section
              $city = Input::get('city') ?? '';
              $state = Input::get('state') ?? '';
              $country = Input::get('country') ?? '';
              ?>
              
              <div class="row">
                <div class="col-md-4 mb-3">
                  <label for="city" class="form-label">City *</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-building"></i></span>
                    <input type="text" class="form-control" id="city" name="city" 
                           placeholder="Enter your city"
                           value="<?php if (!$form_valid && !empty($_POST)) echo htmlspecialchars($city); ?>" 
                           required autocomplete="address-level2">
                    <div class="invalid-feedback">Please enter your city.</div>
                  </div>
                </div>
                
                <div class="col-md-4 mb-3">
                  <label for="state" class="form-label">State/Province *</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-map"></i></span>
                    <input type="text" class="form-control" id="state" name="state" 
                           placeholder="State or Province"
                           value="<?php if (!$form_valid && !empty($_POST)) echo htmlspecialchars($state); ?>" 
                           required autocomplete="address-level1">
                    <div class="invalid-feedback">Please enter your state or province.</div>
                  </div>
                </div>
                
                <div class="col-md-4 mb-3">
                  <label for="country" class="form-label">Country *</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-globe"></i></span>
                    <select class="form-control form-select" id="country" name="country" required>
                      <?php
                      if (!$form_valid && !empty($_POST) && !empty($country)) {
                          echo "<option selected value=\"" . htmlspecialchars($country) . "\">" . htmlspecialchars($country) . "</option>";
                      } else {
                          echo '<option value="">Select Country</option>';
                      }
                      
                      // First, show popular countries from registry data
                      if (!empty($popularCountries) && count($popularCountries) > 0) {
                          foreach ($popularCountries as $popularCountry) {
                              // Skip if already selected above
                              if (!(!$form_valid && !empty($_POST) && $country == $popularCountry)) {
                                  echo "<option value=\"" . htmlspecialchars($popularCountry) . "\">" . htmlspecialchars($popularCountry) . "</option>";
                              }
                          }
                          echo '<option disabled style="color: #999;">────────────────</option>';
                      }
                      
                      // Then show all other countries
                      if (isset($countrylist)) {
                          foreach ($countrylist as $c) {
                              // Skip separator-like entries, empty names, or countries already in popular list
                              if (!empty($c->name) && 
                                  strpos($c->name, '────') === false && 
                                  strpos($c->name, '---') === false &&
                                  (empty($popularCountries) || !in_array($c->name, $popularCountries))) {
                                  // Skip if already selected above
                                  if (!(!$form_valid && !empty($_POST) && $country == $c->name)) {
                                      echo "<option value=\"" . htmlspecialchars($c->name) . "\">" . htmlspecialchars($c->name) . "</option>";
                                  }
                              }
                          }
                      }
                      ?>
                    </select>
                    <div class="invalid-feedback">Please select your country.</div>
                  </div>
                </div>
              </div>
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
                      <div class="invalid-feedback">Please confirm your password.</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <?php } ?>

            <!-- Form Hooks and Additional Fields -->
            <?php includeHook($hooks, 'form'); ?>

            <!-- CSRF Protection -->
            <input type="hidden" value="<?= Token::generate(); ?>" name="csrf">

            <!-- Submit Section -->
            <div class="text-center pt-3">
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

<script>
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
  const form = document.getElementById('payment-form');
  if (form) {
    form.addEventListener('submit', function(event) {
      if (!form.checkValidity()) {
        event.preventDefault();
        event.stopPropagation();
      }
      form.classList.add('was-validated');
    });
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
});
</script>