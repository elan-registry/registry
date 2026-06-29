<?php
/**
 * Custom override of users/includes/system_messages_footer.php
 *
 * Changes from upstream:
 * - Bootstrap 4 compatible (no BS5 CSS variables, no Toast component)
 * - Hardcoded color values instead of var(--bs-*) references
 * - Manual show/hide instead of bootstrap.Toast API
 * - BS4 class names (close, ml-2, mr-2 instead of btn-close, ms-2, me-2)
 *
 * Issue #536: Toast notifications overlap page heading
 *
 * @todo Issue #234 (BS5 migration): Remove this override. The upstream
 *       users/includes/system_messages_footer.php uses BS5 features:
 *       - CSS: var(--bs-success), var(--bs-primary-rgb), etc.
 *       - JS: bootstrap.Toast API, btn-close class, data-bs-dismiss,
 *             ms-2/me-2 utility classes
 *       All of these work natively with Bootstrap 5. After migration:
 *       1. Delete this file (upstream will be used automatically)
 *       2. Verify toast colors render (var(--bs-*) CSS variables resolve)
 *       3. Verify close button works (data-bs-dismiss="toast")
 *       4. Verify auto-hide works (bootstrap.Toast API available)
 */

// Collect messages
$usSessionMessages = function_exists('parseSessionMessages') ? parseSessionMessages() : [];

$usSessionMessageClasses = [
  'err'    => 'primary',
  'msg'    => 'info',
  'genMsg' => 'dark',
  'valSuc' => 'success',
  'valErr' => 'danger',
];

// Capture superglobals into local vars before the script block
$usGetErr = !empty($_GET['err']) ? (string)$_GET['err'] : null;
$usGetMsg = !empty($_GET['msg']) ? (string)$_GET['msg'] : null;
?>
<style>
/* Toast notification bar styles (BS4 compatible - no CSS variables) */
.us-toast-bar {
  height: 4px;
  width: 100%;
  border-radius: 0.25rem 0.25rem 0 0;
}

.us-bar-primary {
  background: linear-gradient(90deg, #007bff, #6610f2);
}

.us-bar-info {
  background: linear-gradient(90deg, #17a2b8, #6f42c1);
}

.us-bar-dark {
  background: linear-gradient(90deg, #343a40, #6c757d);
}

.us-bar-success {
  background: linear-gradient(90deg, #28a745, #20c997);
}

.us-bar-danger {
  background: linear-gradient(90deg, #dc3545, #fd7e14);
}

/* Toast element styles */
.us-toast {
  background: #fff;
  border: 1px solid rgba(0, 0, 0, 0.1);
  border-radius: 0.25rem;
  box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
  margin-bottom: 0.5rem;
  min-width: 250px;
  max-width: 400px;
  overflow: hidden;
  opacity: 0;
  transition: opacity 0.3s ease;
}

.us-toast.us-toast-show {
  opacity: 1;
}

.us-toast .toast-body {
  padding: 0.75rem 1rem;
  color: #495057;
  font-weight: 500;
}

.us-toast .us-toast-close {
  background: transparent;
  border: 0;
  font-size: 1.2rem;
  font-weight: 700;
  line-height: 1;
  color: #000;
  opacity: 0.5;
  padding: 0.5rem 0.75rem;
  cursor: pointer;
}

.us-toast .us-toast-close:hover {
  opacity: 1;
}
</style>
<script nonce="<?=htmlspecialchars($userspice_nonce ?? '')?>">
(function(){
  var container = document.getElementById('us-toast-container');
  var justify = container ? (container.getAttribute('data-justify') || 'left') : 'left';
  var LEFT = justify === 'left';

  // A randomized break token to prevent attackers from forcing breaks.
  var USERSPICE_BREAK = '---USERSPICE_BREAK-' + Math.random().toString(36).substring(7) + '---';
  var MAX_MESSAGE_LENGTH = 500;

  function userSpiceMessage(message, bootstrapType) {
    var wrap = container || document.body;

    var toast = document.createElement('div');
    toast.className = 'us-toast' + (LEFT ? ' us-toast-left' : '');
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');

    var bar = document.createElement('div');
    bar.className = 'us-toast-bar ' + mapTypeToBar(bootstrapType);
    toast.appendChild(bar);

    var row = document.createElement('div');
    row.className = 'd-flex' + (LEFT ? ' flex-row-reverse' : ' flex-row') + ' align-items-start';

    var body = document.createElement('div');
    body.className = 'toast-body flex-grow-1';
    buildToastBodyContent(body, String(message == null ? '' : message));

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'us-toast-close';
    btn.setAttribute('aria-label', 'Close');
    btn.innerHTML = '&times;';
    btn.onclick = function() { dismissToast(toast); };

    row.appendChild(body);
    row.appendChild(btn);
    toast.appendChild(row);
    wrap.appendChild(toast);

    // Trigger show after append (allows CSS transition)
    requestAnimationFrame(function() {
      toast.classList.add('us-toast-show');
    });

    // Auto-hide after 6 seconds
    setTimeout(function() {
      dismissToast(toast);
    }, 6000);
  }

  function dismissToast(toast) {
    toast.classList.remove('us-toast-show');
    setTimeout(function() {
      if (toast.parentNode) {
        toast.parentNode.removeChild(toast);
      }
    }, 300);
  }

  function mapTypeToBar(t){
    switch(t){
      case 'primary': return 'us-bar-primary';
      case 'info': return 'us-bar-info';
      case 'dark': return 'us-bar-dark';
      case 'success': return 'us-bar-success';
      case 'danger': return 'us-bar-danger';
      default: return 'us-bar-info';
    }
  }

  /**
   * Sanitizes HTML using a safe token-replacement approach.
   */
  function sanitizeAndFormat(html) {
    var temp = String(html).substring(0, MAX_MESSAGE_LENGTH);

    var tokens = [];
    var TOKEN_PREFIX = USERSPICE_BREAK;

    var allowedPatterns = [
      { regex: /<\s*br\s*\/?>/gi, tag: '<br>' },
      { regex: /<\s*\/?\s*strong\s*>/gi, restore: true },
      { regex: /<\s*\/?\s*b\s*>/gi, restore: true },
      { regex: /<\s*\/?\s*em\s*>/gi, restore: true },
      { regex: /<\s*\/?\s*i\s*>/gi, restore: true },
      { regex: /<\s*\/?\s*u\s*>/gi, restore: true },
      { regex: /<\s*\/?\s*ul\s*>/gi, restore: true },
      { regex: /<\s*\/?\s*li\s*>/gi, restore: true }
    ];

    allowedPatterns.forEach(function(pattern) {
      temp = temp.replace(pattern.regex, function(match) {
        var tokenIndex = tokens.length;
        var normalized = pattern.tag || match.toLowerCase().replace(/\s+/g, '');
        tokens.push(normalized);
        return TOKEN_PREFIX + tokenIndex + TOKEN_PREFIX;
      });
    });

    var doc = new DOMParser().parseFromString(temp, 'text/html');
    var safeText = doc.body.textContent || "";

    tokens.forEach(function(tag, index) {
      var escaped = TOKEN_PREFIX.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&');
      var tokenPattern = new RegExp(escaped + index + escaped, 'g');
      safeText = safeText.replace(tokenPattern, tag);
    });

    return safeText;
  }

  function buildToastBodyContent(bodyElement, message) {
    var safeText = sanitizeAndFormat(String(message == null ? '' : message));
    bodyElement.innerHTML = safeText;
  }

  // Shorthand helpers
  window.usSuccess = function(msg){ userSpiceMessage(msg,'success'); };
  window.usError   = function(msg){ userSpiceMessage(msg,'danger'); };
  window.usInfo    = function(msg){ userSpiceMessage(msg,'info'); };
  window.usPrimary = function(msg){ userSpiceMessage(msg,'primary'); };
  window.usDark    = function(msg){ userSpiceMessage(msg,'dark'); };

  // Emit from PHP
  <?php if ($usGetErr !== null): ?>
    userSpiceMessage(<?php echo json_encode($usGetErr, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>,'<?php echo $usSessionMessageClasses['err']; ?>');
  <?php endif; ?>
  <?php if ($usGetMsg !== null): ?>
    userSpiceMessage(<?php echo json_encode($usGetMsg, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>,'<?php echo $usSessionMessageClasses['msg']; ?>');
  <?php endif; ?>
  <?php foreach (['genMsg','valSuc','valErr'] as $k): if (!empty($usSessionMessages[$k])): ?>
    userSpiceMessage(<?php echo json_encode((string)$usSessionMessages[$k], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>,'<?php echo $usSessionMessageClasses[$k]; ?>');
  <?php endif; endforeach; ?>
})();
</script>
