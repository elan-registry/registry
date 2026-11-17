/**
 * Join Form Client-Side Failure Beacon
 *
 * Reports join submissions blocked entirely client-side (Turnstile failing
 * to load, render, error, or expire; an uncaught JS exception or unhandled
 * promise rejection originating from this page's own scripts; or a required
 * field's client-side widget failing, e.g. the location picker's GPS
 * lookup; or the browser's form validation blocking the submit) — cases
 * where the POST to join.php never happens and would otherwise leave zero
 * server-side trace.
 *
 * Reasons: turnstile_error, turnstile_expired, turnstile_not_loaded,
 * js_exception, location_gps_failed, location_search_failed,
 * client_validation. The join form's submit handler sends client_validation
 * with the invalid fields' names only, never their values (#2043). The
 * location picker sends location_search_failed with the HTTP status, api
 * for a search that the server refused with HTTP 200, or 0 for a network or
 * JSON error (#2533).
 * turnstile_error carries Cloudflare's error code, when Turnstile gives one.
 * A js_exception from an unhandled rejection carries the reason's type
 * (type=), its value (value=) or, for an object with no stack or message,
 * its key names (keys=), then a source hint: the Turnstile script state
 * (turnstile=loaded|absent), the widget state (widget=rendered|empty|none)
 * and the seconds since page load (t=). The error name, message and stack
 * follow after " | ", if any (#2728).
 * The beacon sends at most 5 js_exception reports for each page load.
 *
 * @package ElanRegistry
 * @since 2.29.2
 * @link https://github.com/elan-registry/registry/issues/1690
 */
(function () {
    'use strict';

    function reportJoinFailure(reason, detail) {
        // No CSRF token: the endpoint is anonymous and rate-limited, and a
        // token fixed at page render can go stale before this fires (#2227).
        var body = new URLSearchParams({
            reason: reason,
            detail: (detail || '').toString().slice(0, 300)
        });
        var url = (window.elanUrlRoot || '/') + 'app/api/shared/join-failure-report.php';
        fetch(url, { method: 'POST', body: body, keepalive: true }).then(function (response) {
            // A non-2xx (most notably 429, from the beacon's own
            // join_failure_beacon rate limit) must not be treated as success —
            // otherwise the one signal meant to catch "reporting itself failed"
            // goes silent exactly when it's needed most.
            if (!response.ok && window.console && console.warn) {
                console.warn('join-form-beacon: report rejected by server, status ' + response.status);
            }
        }).catch(function (err) {
            if (window.console && console.warn) {
                console.warn('join-form-beacon: report failed to send', err);
            }
        });
    }

    // Exposed so other join-page widgets (e.g. the location picker's GPS
    // button) can report their own client-side failures through the same
    // beacon without duplicating the fetch/CSRF/truncation logic.
    window.elanReportJoinFailure = reportJoinFailure;

    // Excludes only the one unambiguous "not attributable to this page"
    // signal: a browser's sanitized cross-origin error report. Per spec,
    // any script loaded without CORS from a different origin reports
    // exactly message === "Script error." with no filename/line/col/error
    // object — that combination is reserved for this case and cannot occur
    // for same-origin code (even dynamically-evaluated same-origin code
    // with no real filename, e.g. a bare setTimeout callback, still reports
    // its actual message text, never the literal "Script error." string).
    // A real third-party browser-extension error also can't reach this
    // path for the same reason. Deliberately permissive otherwise — an
    // empty filename from same-origin code (common for dynamically
    // constructed callbacks) still gets through, since the whole point of
    // this listener is catching failures wherever they originate on this
    // page, not just from files with a known-good URL.
    function isJoinPageError(event) {
        if (!event) return false;
        return event.message !== 'Script error.';
    }

    // Turnstile passes its error code as the first argument (#2728).
    window.elanTurnstileError = function (errorCode) {
        reportJoinFailure('turnstile_error', errorCode);
        var msg = document.getElementById('turnstile-status-message');
        if (msg) {
            msg.textContent = 'Verification failed to load. Please refresh the page and try again, or try a different browser.';
            msg.classList.remove('d-none');
        }
    };

    window.elanTurnstileExpired = function () {
        reportJoinFailure('turnstile_expired');
        var msg = document.getElementById('turnstile-status-message');
        if (msg) {
            msg.textContent = 'Verification expired — please complete the verification challenge again before submitting.';
            msg.classList.remove('d-none');
        }
        // Reset itself lives in the shared turnstile-reset.js (loaded before
        // this file, see usersc/views/_join.php), so both this join-specific
        // handler and the login page's plainer one share one implementation.
        if (typeof window.elanTurnstileReset === 'function') {
            window.elanTurnstileReset();
        }
    };

    // Covers the api.js <script> tag itself failing to fetch (network block,
    // DNS failure).
    window.elanTurnstileNotLoaded = function () {
        reportJoinFailure('turnstile_not_loaded', 'script failed to load');
        var msg = document.getElementById('turnstile-status-message');
        if (msg) {
            msg.textContent = 'Verification failed to load. Please refresh the page and try again, or try a different browser.';
            msg.classList.remove('d-none');
        }
    };

    // Wired here via addEventListener rather than an inline onerror="..."
    // attribute on the tag itself (usersc/includes/turnstile.php) — this
    // site's CSP has no 'unsafe-inline'/script-src-attr exception, so an
    // inline event-handler attribute is silently blocked by the browser and
    // never fires. A same-origin script attaching the listener isn't subject
    // to that restriction. Also covered, with a delay, by the render-poll
    // below — this listener just reports the failure immediately instead of
    // waiting up to 20s for the poll to notice the widget never rendered.
    var turnstileScript = document.getElementById('elan-turnstile-script');
    if (turnstileScript) {
        turnstileScript.addEventListener('error', window.elanTurnstileNotLoaded);
    }

    // Covers the case api.js loads and executes successfully but the widget
    // never actually renders (e.g. blocked by a CSP the browser silently
    // ignores, or a webview quirk that lets the script run but not paint the
    // iframe) — this is the specific failure shape #1690's own evidence
    // points at, and onerror/error-callback both require Turnstile's own JS
    // to be running to fire, so neither would catch it.
    //
    // Two staged checks (10s, then 20s) rather than a single fixed
    // threshold: a merely-slow-but-working connection could still be
    // rendering the widget right around a single 10s cutoff, which would
    // misreport a real (if delayed) success as a failure and show the user
    // an incorrect "please refresh" message. Only report if the widget is
    // STILL empty a full 10s after the first check.
    if (document.getElementById('join-form')) {
        var checkWidgetRendered = function () {
            var widget = document.querySelector('.cf-turnstile');
            // !widget is treated as "rendered" (i.e. nothing to report),
            // not "still needs checking" — covers Turnstile being
            // administratively disabled (addTurnstile() never emits the
            // div at all, see usersc/includes/turnstile.php), which is not
            // a failure. This also means a widget that existed but was
            // later removed from the DOM by something else silently stops
            // this poll from reporting — an accepted, narrow edge case,
            // since that implies something else already broke first.
            return !widget || widget.children.length > 0;
        };
        setTimeout(function () {
            if (checkWidgetRendered()) return;
            setTimeout(function () {
                if (!checkWidgetRendered()) {
                    window.elanTurnstileNotLoaded();
                }
            }, 10000);
        }, 10000);
    }

    // WebKit's stack holds only the frames, with no "Name: message" line, so
    // an iOS report would lose the message (#2728). Put the name and message
    // first when the stack does not already contain them.
    function errorText(err) {
        if (!err) return '';
        var stack = err.stack ? String(err.stack) : '';
        var head = (err.name || err.message) ? (err.name || 'Error') + ': ' + (err.message || '') : '';
        if (!head || stack.indexOf(head) !== -1) return stack;
        return stack ? head + '\n' + stack : head;
    }

    function errorDetail(errorObj, fallbackMessage) {
        // A stack trace is far more actionable than a bare message for a
        // "zero trace" bug — prefer it when available.
        return errorText(errorObj) || fallbackMessage || 'unknown error';
    }

    // One iOS Safari visitor sent 36 reports in about 4 minutes (#2728). A
    // repeating error says nothing new after the first few, and the reports
    // count toward the join_failure_beacon total that all visitors share.
    var MAX_JS_EXCEPTION_REPORTS = 5;
    var jsExceptionReports = 0;

    function reportJsException(detail) {
        if (jsExceptionReports >= MAX_JS_EXCEPTION_REPORTS) return;
        jsExceptionReports++;
        reportJoinFailure('js_exception', detail);
    }

    function turnstileWidgetState() {
        var widget = document.querySelector('.cf-turnstile');
        if (!widget) return 'none';
        return widget.children.length > 0 ? 'rendered' : 'empty';
    }

    // A rejection event has no filename or line, so the page state is the
    // only source hint. The hint goes first, because the 300-character limit
    // cuts the end of the detail, and a stack is long.
    function rejectionDetail(reason) {
        var isObject = reason !== null && (typeof reason === 'object' || typeof reason === 'function');
        var text = isObject ? errorText(reason) : '';
        // A long Symbol.toStringTag must not push the hint past the limit.
        var hint = 'type=' + Object.prototype.toString.call(reason).slice(8, -1).slice(0, 40);
        if (!isObject) {
            hint += ' value=' + String(reason).slice(0, 100);
        } else if (!text) {
            // An object with no stack or message, such as {code: 0}, shows
            // its key names. JSON.stringify could throw on a cycle.
            hint += ' keys=' + Object.keys(reason).slice(0, 5).join(',').slice(0, 100);
        }
        hint += ' turnstile=' + (window.turnstile ? 'loaded' : 'absent')
            + ' widget=' + turnstileWidgetState()
            + ' t=' + Math.round(window.performance.now() / 1000) + 's';
        return text ? hint + ' | ' + text : hint;
    }

    window.addEventListener('error', function (event) {
        if (document.getElementById('join-form') && isJoinPageError(event)) {
            reportJsException(errorDetail(event && event.error, event && event.message));
        }
    });

    // window's 'error' event does NOT fire for a rejected Promise with no
    // .catch() (e.g. an async function's internal failure) — that fires
    // 'unhandledrejection' instead. Both are realistic candidates for the
    // original #1690 webview mystery, so both must be covered or a real
    // root cause could again produce zero client-side trace.
    //
    // #2728: an iOS Safari visitor's rejections all had the reason 0. The
    // cause is not proven. None of our own scripts rejects with a number.
    // The same visitor sent turnstile_error reports, and the 0 reports came
    // about every 7 seconds, close to Turnstile's automatic retry. So
    // Turnstile's api.js is the likely source. The type and Turnstile state
    // in the detail confirm or disprove this on the next case.
    window.addEventListener('unhandledrejection', function (event) {
        if (document.getElementById('join-form')) {
            reportJsException(rejectionDetail(event && event.reason));
        }
    });
})();
