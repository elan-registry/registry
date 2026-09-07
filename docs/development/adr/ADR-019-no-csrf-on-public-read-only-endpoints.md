# ADR-019: Do Not Apply CSRF Tokens to Public Read-Only Endpoints

## Status

In Review

## Date

2026-09-04

## Context

The registry exposes several JSON endpoints that serve the public browse
surface: the car list DataTable, the factory records DataTable, a car's audit
history, and the statistics page's tab data. Each was built with a CSRF token
check copied from the application's write endpoints, on the general principle
that API endpoints should be protected.

Applying that check to these particular endpoints was a mistake, and
issue #1913 is the bill arriving.

### What CSRF protection actually defends against

A CSRF token defends one specific thing: an attacker causing a victim's browser
to perform a **state-changing action** using the victim's ambient credentials,
from a page the attacker controls. The token works because the attacker can
make the browser *send* a request but cannot *read* a same-origin response to
learn the token.

Two properties must hold for the control to be worth anything:

1. The request must change state, or return data the attacker could not
   otherwise obtain.
2. The endpoint must do something with the victim's authority that the attacker
   lacks.

For the four endpoints above, **neither holds**:

- None performs an authentication or authorization check of its own. They are
  reachable unauthenticated, so there is no ambient authority to borrow. (The
  four *host pages* do call `securePage()`; it admits anonymous visitors only
  because each page's `pages.private` column is `0`. That is database state,
  not code — see the criterion 3 note below.)
- None changes state. All are `SELECT`-and-render.
- None returns data beyond what the corresponding public page already renders
  to an anonymous visitor. `CarDataTablesService` runs a fixed SELECT list that
  excludes owner PII for every caller (`user_id`, `lat` and `lon` were removed
  in #1501); `list.php` returns `fname`, `city`, `state`, `country` and car
  specifications — the exact contents of the public table.

An attacker who wants this data does not need a victim. They can request the
public page directly. Worse, the token offered no obstacle even to a determined
scraper: it was embedded in the page HTML, so any client could `GET` the page,
read the token, and proceed.

This was already recorded in `docs/development/SYSTEM_OVERVIEW.md`:

> **`app/api/cars/history.php` is CSRF-gated but not login-gated.** Any holder
> of a valid CSRF token can read any car's audit history. … the API has no
> independent access check of its own.

The documentation had reached the correct conclusion; the code had not
followed.

### The cost the control imposed

The token is per-session and embedded at render time. `car-list.js` captured it
once and resent that value for the life of the page. If the session ended —
idle GC, browser restart; the session cookie is `lifetime=0` — the next
DataTables draw failed `Token::check()` and returned 403. The user saw an empty
table and "Could not load car list. Please refresh the page."

Production `logs` recorded **14 such rejections between 2026-07-10 and
2026-09-02**, from 11 distinct addresses, ~0.3% of draws. Thirteen were
anonymous. The fourteenth was a member six minutes into their first-ever
session, for whom this was an introduction to the registry's primary browse
surface.

So the control's ledger reads: **zero security benefit, a recurring
user-facing failure on the most important public page.**

An earlier fix attempt (#1852 / PR #1861) treated this as a test bug and made
the Playwright test fetch a token, concluding "production's own JS consumer
always sends this token." True, but it sends the token captured *at render*,
which the browser cannot refresh after session loss. The production 403s
continued after that PR merged.

### The real risk on these endpoints

Removing the token does not leave the endpoints unprotected, because the token
was never protecting them. The genuine risk on an unauthenticated public read
endpoint is **volume**: scraping and load. CSRF tokens do not address volume;
rate limiting does. `app/api/shared/statistics.php` already carried both, which
is what made the redundancy visible.

## Decision

**CSRF tokens are not applied to endpoints that are public, read-only, and
return no data beyond what the corresponding public page already renders.
Those endpoints are protected by rate limiting instead.**

An endpoint qualifies only if **all** of the following hold. If any is false,
it keeps its CSRF token.

1. **No state change.** No `INSERT`, `UPDATE`, `DELETE`, no email dispatch, no
   session mutation, no third-party side effect.
2. **No privileged read.** It returns nothing an anonymous visitor could not
   already obtain from the public page — no PII, no per-viewer branching.
3. **No authority to borrow.** The endpoint performs no authentication or
   authorization check of its own, so a victim's session confers no capability
   an attacker lacks. Note this is a property of the *endpoint*: the host page
   may call `securePage()` and still be public by virtue of
   `pages.private == 0`. If a page's visibility is ever changed, re-check the
   endpoint against these criteria — the endpoint will not have changed with
   it.
4. **A rate limit is configured** in `usersc/includes/rate_limits.php`, under
   its own action key.

Applied to the current codebase:

| Endpoint | CSRF | Rate limit key |
| --- | --- | --- |
| `app/api/cars/list.php` | removed | `cars_list` |
| `app/api/cars/factory-list.php` | removed | `factory_list` |
| `app/api/cars/history.php` | removed | `car_history` |
| `app/api/shared/statistics.php` | removed | `statistics_request` (existing) |

Explicitly **not** qualifying, and retaining their tokens:

- `app/api/shared/location-search.php`, `location-reverse.php` — read-only and
  public-facing, but they proxy a **quota-limited third-party geocoding
  service**. Criterion 1 fails on the third-party side effect: removing the
  token would create an open proxy against someone else's paid API. This is a
  resource-abuse concern rather than a CSRF one, and it is why criterion 1 is
  written to include external side effects.
- `app/api/cars/models.php`, `chassis-validate.php`, `chassis-availability.php`
  — serve the Add/Edit Car flow, whose own token-expiry handling is #1455
  finding 2.
- Every write endpoint, without exception.

### Rate limits get their own action key

Limits in `usersc/includes/rate_limits.php` are counted **per action name**, so
two endpoints sharing a key share one budget — heavy factory-list browsing
would consume the car list's allowance. Each endpoint gets its own key.

Sizing, and why these numbers:

| Action key | `total_max` per 300s | Endpoint |
| --- | --- | --- |
| `cars_list` | 10000 | `app/api/cars/list.php` |
| `factory_list` | 10000 | `app/api/cars/factory-list.php` |
| `car_history` | 5000 | `app/api/cars/history.php` |

`total_max` is the operative limit (see the third trap below). These ceilings
are far above any interactive session — DataTables debounces search input by
400ms, so even continuous typing plus sorting and paging stays well under them —
while still bounding a runaway client. `car_history` is sized lower because it
fires once per car-details view rather than per keystroke.

**The headroom is deliberate, and the reason is the fourth trap below: in
production these buckets are not per-visitor.** Behind Cloudflare, and with no
trusted-proxy configuration, every visitor arriving through the same edge node
shares one bucket. A ceiling sized for a single browsing session would be
consumed by aggregate traffic and would reproduce the very failure this ADR set
out to remove. The same argument applies, at smaller scale, to visitors behind a
shared NAT or corporate egress IP.

Four traps worth recording:

- **`RateLimit::check()` returns `true` for an unconfigured action**
  (`RateLimit::check()`, in its `!isset($this->rateLimits[$action])` early
  return). A missing or mistyped key does not
  error; it silently disables the limit — and with no CSRF check behind it,
  that leaves the endpoint with no control at all.
  `tests/unit/system/RateLimitConfigTest.php` pins both halves: that each key
  exists in the config, and that each endpoint's source actually calls
  `checkRateLimit()` with that exact string. Pinning only the config would
  still let a typo on the endpoint side fail open silently.
- **Sizing must reflect DataTables' draw rate.** A draw fires per search
  keystroke, per sort and per page change. Reusing `statistics_request`'s
  50-per-300s IP limit would trade a rare 403 for a common 429.
- **`ip_max` / `user_max` count failed attempts only; `total_max` counts all.**
  `check()` compares `ip_max`/`user_max` against rows recorded with
  `success = false`, and `total_max` against every row
  (compare the `$failedCount` and `$totalCount` calls to `getAttemptCount()`
  inside `RateLimit::check()` — the former passes `false`, the latter `null`).
  These endpoints record each admitted
  draw as a success and record nothing on rejection, so only `total_max` can
  ever fire. Do not "tighten" `ip_max` expecting an effect. Recording a failure
  on the rejection path is specifically avoided: those rows would be the only
  writers of failed attempts, so a client already blocked by `total_max` would
  accumulate its way into the separate `ip_max` window purely by being
  blocked.
- **Behind Cloudflare, the per-IP bucket is not per-visitor.** `getRealIP()`
  (`users/classes/RateLimit.php`) only consults `CF-Connecting-IP` /
  `X-Forwarded-For` when the `behind_reverse_proxy` setting is on *and*
  `us_rate_limit_proxy_settings` lists a trusted proxy. Both are currently
  unset, so it returns `REMOTE_ADDR` — which in production is the Cloudflare
  **edge node**, not the visitor. Every visitor through that node therefore
  shares one bucket, which is why the ceilings above are sized with an order of
  magnitude of headroom. Configuring `CF-Connecting-IP` as a trusted header
  would make the buckets genuinely per-visitor and would allow much tighter
  limits; until then, treat these numbers as governing aggregate edge traffic
  rather than individual browsing.

## Consequences

### Positive

- The #1913 failure class is eliminated rather than mitigated: with no token to
  go stale, there is no stale-token path to retry.
- No token-refresh endpoint and no retry logic in any consumer. The alternative
  would have added both to four consumers, permanently, to keep a no-op control
  working.
- Net deletion of code: four `Token::check()` blocks, four page-render token
  embeds, four client-side sends.
- The public browse surface no longer depends on session continuity — correct
  for pages explicitly designed to work logged-out (#1305).
- `factory-list.php` and `history.php` gain a control they never had. Note
  what rate limiting does and does not buy: `total_max` is scoped per
  identifier (per IP for anonymous callers), not site-wide, so it bounds
  accidental load and single-source hammering. It does not deter distributed
  scraping — an attacker rotating IPs never sees a 429, and raising the
  threshold cannot fix that, because per-identifier bucketing is the
  limitation rather than the number. Bulk-extraction resistance, if it is ever
  wanted, belongs at the CDN edge.
- The security posture becomes stateable in one sentence, so the next public
  read endpoint does not re-inherit the token by cargo cult.

### Negative

- **A security control is being removed, and that deserves scrutiny.** The
  justification rests on the four criteria above being *actually* true of each
  endpoint, verified rather than assumed. If any endpoint later starts
  returning per-viewer data or gains a side effect, it must regain its token —
  this is the failure mode to guard against in review.
- The criteria require judgement. "Returns nothing beyond the public page" is
  true today because of #1501's PII removal; a future column addition could
  quietly falsify it. The pinning tests exist for this reason.
- Anyone reading only the endpoint code will see no token check and may assume
  an oversight. This ADR is the answer; the wiring tests in
  `tests/unit/system/RateLimitConfigTest.php` and
  `tests/unit/cars/CarActionsHistoryAndValidationWiringTest.php` are what stop
  the token being reinstated by reflex.
- Rate limiting is a coarser instrument: it is measured in requests per window,
  not correctness, and a misjudged limit degrades normal browsing.

### Neutral

- The endpoints keep `POST` and the DataTables request contract. They are
  semantically reads, and `GET` would be more honest, but the migration would
  break the DataTables wiring for no benefit to this decision. Deliberately
  deferred.
- `car-list.js`'s error handler is retained — a 500 or network failure is still
  possible — with its message improved to offer a reload action.
- No change to what any endpoint returns, for any viewer, logged in or out.

## Alternatives Considered

### Refresh the token and retry the draw (the approach #1913 originally proposed)

Add `app/api/shared/csrf-token.php`, have `car-list.js` treat 403 as
recoverable, fetch a fresh token, retry once, and only then show an error.

Rejected. It is a working fix for the symptom, and it was the initially chosen
option, but it preserves a control with no security value and pays for it
forever: a new endpoint, plus refresh-and-retry logic in each of four
consumers, plus the tests to hold all of it. The endpoint would also have to be
reachable without a token itself — which concedes the very point that a public
read endpoint does not need one.

### Keep the token; extend the session lifetime

Does not fix it. A longer session moves the failure rather than removing it,
and lengthening session lifetime to work around a client-side caching bug
trades a real security parameter for a cosmetic gain.

### Remove the token and add no rate limit

Relies on Cloudflare's edge rate limiting alone. Rejected as inconsistent:
`statistics.php` already carries an app-layer limit, and these endpoints
currently set no cache headers, so origin load is real. App-layer limiting also
survives a change of CDN.

### Remove the login-gate distinction instead — make `list.php` require login

Rejected outright: the public car list is a deliberate product decision
(#1305), and the registry's value depends on being browsable by anonymous
visitors.

## References

- Issue #1913 — cars-list DataTable never recovers from a stale/lost CSRF token
- Issue #1852 / PR #1861 — earlier attempt, closed as a test bug
- Issue #1305 — PII endpoints given login gates; public browse surface retained
- Issue #1501 — owner PII removed from the DataTables SELECT list
- Issue #1455 finding 2 — Add/Edit Car flow token expiry (separate scope)
- ADR-004 — Pattern A API responses (`ApiResponse` shape used by the 429 path)
- ADR-007 — CSP and security headers
- ADR-011 — DataTables with server-side processing
- `docs/development/SYSTEM_OVERVIEW.md` — public-surface description
- `RateLimit::check()` — unconfigured actions are unlimited
