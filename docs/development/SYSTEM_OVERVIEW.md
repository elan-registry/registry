# Elan Registry — System Overview

What the registry does, who it does it for, and which parts of it are not what
they appear. This is the feature-level map that sits above the implementation
docs: read this first, then follow the links for detail.

**Scope note.** The registry has been developed since 2003 and, until this
document, had no feature-level documentation — only code, ADRs recording
*implementation* decisions, and a six-bullet feature list in the README. This
overview was therefore reconstructed by reading the code, not by summarising
existing documents. Where a behaviour could not be verified in code it is
marked **unverified**; where the shipped behaviour differs from what other
documents claim, the code is treated as authoritative and the discrepancy is
called out.

---

## Contents

- [1. What the registry is](#1-what-the-registry-is)
- [2. Who uses it](#2-who-uses-it)
- [3. Capabilities](#3-capabilities)
  - [3.1. Public — no account needed](#31-public--no-account-needed)
  - [3.2. Owner — logged in](#32-owner--logged-in)
  - [3.3. Editor and administrator](#33-editor-and-administrator)
  - [3.4. System — runs without a person](#34-system--runs-without-a-person)
- [4. How the capabilities connect](#4-how-the-capabilities-connect)
- [5. Privacy and data protection](#5-privacy-and-data-protection)
- [6. What is deliberately not built](#6-what-is-deliberately-not-built)
- [7. What is built but broken or incomplete](#7-what-is-built-but-broken-or-incomplete)
- [8. Where to read more](#8-where-to-read-more)

---

## 1. What the registry is

The Lotus Elan Registry is a public record of surviving Lotus Elan and Elan +2
cars — the 1963–1973 Elan and 1967–1974 Elan +2 — hosted at
<https://elanregistry.org>. It began in January 2003 after a thread on
LotusElan.net asked whether a register existed.

Its product is **accuracy over time**. Anyone can photograph a car; the
registry's value is that a chassis number can be traced across decades of
owners, restorations, colour changes and sales, and that the record is still
right years later. Almost every design decision follows from that: audit
trails on car records, ownership transfer as a reviewed workflow rather than a
self-service edit, and a periodic prompt asking owners to confirm their data
still holds.

It is a single-registrar community project, not a commercial platform. There is
one administrator, a small number of editors, roughly 1,500 active car records,
and about 60 new registrations a year.

## 2. Who uses it

| Role | Permission | Who they are |
| --- | --- | --- |
| **Visitor** | none | Anyone browsing. Can see the registry, statistics, and reference material without an account. |
| **Owner** | 1 (User) | A registered member. Owns zero or more car records and controls their own profile. |
| **Editor** | 3 | A trusted volunteer. Effectively a junior administrator — can curate car and owner data and approve transfers, but cannot touch system settings, backups, or destructive scripts. |
| **Administrator** | 2 | The registrar. Everything, including settings, backups, maintenance scripts, and account cleanup. |

Two things about this model routinely surprise people:

- **`isRegistryAdmin()` means admin *or editor*** — it is `hasPerm([2, 3])`
  (`usersc/includes/custom_functions.php`). Despite the name it is not an
  admin-only check. Anything gated by it, or by `requireAdminAjax()` which
  wraps it, is reachable by editors too.
- **Permission 3 (Editor) is not "below" 2 (Administrator)** in a numeric
  hierarchy. They are two separate grants; admin-only code calls UserSpice's
  `isAdmin()`, which matches level 2 exclusively.

**Terminology.** *Users* is the authentication concept (the `users` table,
sessions, UserSpice). *Owners* is the business concept (a person with cars in
the registry). The distinction is deliberate and is used consistently in code.

## 3. Capabilities

### 3.1. Public — no account needed

Public reach is deliberate: the registry exists to be found by someone
researching a chassis number.

- **Browse the registry** (`app/owner/cars/index.php`) — the full car list,
  filterable and searchable, served through DataTables with server-side
  processing so the ~1,500 records paginate without shipping the whole table.
- **View a car** (`app/owner/cars/details.php`) — the central page.
  Specifications, chassis, colour and engine, purchase and sale dates,
  comments, photo carousel, approximate location on a map, factory
  cross-reference (labelled "Unverified"), and the **complete field-level
  change history**. All of it renders identically for anonymous visitors; only
  the action buttons differ — "Log in to contact owner" for a guest, "Update
  Car" for the owner, an admin banner and edit link for staff, "Contact Owner"
  for another member.
- **Factory records** (`app/owner/cars/factory.php`) — original build data
  matched to a chassis number where the registry holds it.
- **Statistics** (`app/owner/reports/statistics.php`) — production trends,
  geographic distribution, paint-colour popularity, and data-completeness
  charts across the whole registry.
- **Reference library** (`docs/reference/`) — chassis-number validation rules,
  a variant identification guide, factory paint codes, technical articles,
  workshop material, and a hardened PDF viewer for the Club Lotus documents.
- **Car stories** (`docs/car-stories.php`, `docs/stories/`) — long-form
  histories of individual cars.
- **Guides** (`docs/guides/`) — how-to material including the car-transfer FAQ.
- **Privacy policy** (`app/owner/privacy.php`).
- **Sitemap** (`app/api/shared/sitemap.php`) — XML for crawlers. A documented
  exception to the API conventions: no auth, no CSRF, no rate limit, because it
  must stay freely crawlable.

Note that these pages still call `securePage()`. In UserSpice that registers the
page for permission lookup; it does not by itself require a login. Public versus
private is decided by `PagePermissionClassifier`, which treats `app/admin/*`,
anything containing `admin` or `edit`, `app/owner/contact/*`, `app/api/*` and
`usersc/*` as private, and most other paths — including the car list, car
detail, factory and statistics pages — as public.

### 3.2. Owner — logged in

- **Register and manage an account** — UserSpice handles registration, login,
  password reset, remember-me, TOTP two-factor and passkeys. The registry adds
  Cloudflare Turnstile on public forms for spam resistance.
- **Add and edit cars** (`app/owner/cars/edit.php`) — specifications, dates,
  notes, links, and photographs. Every change is written to `cars_hist` by
  database trigger, so the record's history survives the edit.
- **Upload photographs** — stored as a JSON array of filenames in `cars.image`,
  with files under `userimages/{carid}/` and resized variants generated at 100,
  300, 768, 1024 and 2048 px. Images are re-encoded through GD during resizing,
  which discards EXIF metadata including GPS coordinates from the generated
  variants.
- **Mark a car sold** — the record stays in the registry; the sale becomes part
  of its history rather than removing it.
- **Request an ownership transfer** (`app/api/cars/transfer-request.php`) — when
  you buy a car already in the registry, you request it rather than creating a
  duplicate. There is no "claim this car" button on the detail page: the entry
  point is the Add Car form — typing an already-registered chassis triggers the
  transfer flow. The server blocks self-transfer and duplicate pending
  requests. See [§4](#4-how-the-capabilities-connect) for what happens next.
- **Contact another owner** (`app/owner/contact/owner.php`) — the first message
  is relayed through the site so neither address is exposed. The endpoint
  verifies the recipient actually owns the referenced car before sending.
- **Send feedback to the registrar** (`app/owner/contact/index.php`).

### 3.3. Editor and administrator

The admin surface is split across two separately-gated parent pages, and the
split *is* the permission boundary:

**`app/admin/index.php` — administrators and editors**

- **Car management** — the ownership-transfer queue, plus reassigning, merging
  and permanently deleting car records.
- **Manage cars** — data-quality triage; find incomplete or suspect records and
  contact the owner about them.
- **Owner management** — search owners, edit profiles, re-sync a profile's
  location to the cars it owns.
- **Account cleanup** — the page is reachable by editors, but the delete and
  restore actions are administrator-only.

**`app/admin/maintenance.php` — administrators only**

Single page, no tabs (#1225): database backups, the fix/maintenance script
runner, and one-time migration scripts are all immediately visible. Live
health signals (backup attention, pending migrations) surface as header
chips and a conditional alert, replacing the former read-only Health
dashboard. The former Configuration/Settings tab (image/email/expiry
settings) was removed in #1067 — those values are now `config.php`
constants / `.env` vars, not a web-editable DB-backed settings tab.

**Administrator-only regardless of which page hosts them:** backup operations,
execution of any fix or maintenance script, settings updates, and account
deletion/restoration. The fix-script runner enforces this itself rather than
relying on the page it is embedded in — so an editor cannot reach a destructive
script even if a page were mis-registered.

**Car verification** — the annual "is this still correct?" prompt to
owners. Removed in #1613 (was broken end to end and unreachable from any
navigation); see [§6](#6-what-is-deliberately-not-built). A rebuild is
planned (#1155/#1156).

### 3.4. System — runs without a person

- **Audit trail** — three database triggers write every car insert, update and
  delete into `cars_hist`. This is the mechanism the registry's accuracy claim
  rests on. It has no actor column, so *what* changed is guaranteed while *who
  changed it* depends on discretionary `logger()` calls.
- **Application logging** — `logger()` with `LogCategories` constants
  throughout; roughly 90+ categories, consistently adopted.
- **Transactional email** — Brevo, via a plugin that overrides UserSpice's
  global `email()` function and sends over the HTTP API because the host blocks
  SMTP. Templates live in `app/views/email/`.
- **Backups** — `BackupManager` dumps a named set of critical tables into
  `backups/`. It exports, verifies and cleans up. **It has no restore method**;
  restoring a dump is a manual operation outside the application.
- **GDPR deletion** — when a user is deleted, a UserSpice post-delete hook
  (`usersc/scripts/after_user_deletion.php`) transactionally reassigns their
  cars to a system account named `noowner`, hard-deletes their profile row, and
  expires any pending transfers. The car records — the registry's actual value —
  survive; the personal data does not. See ADR-010.
- **Schema migrations** — Phinx, applied automatically on deploy by the
  server-side post-receive hook. See ADR-009.
- **Edge caching** — Cloudflare. Rocket Loader and Email Obfuscation must stay
  disabled; both inject inline scripts that break the CSP nonce policy.

## 4. How the capabilities connect

Four relationships explain most of the system's behaviour.

**Ownership transfer is reviewed, not peer-approved.** An authenticated user
requests a car; the request lands in the admin queue; an **administrator or
editor** approves or denies it (`process-transfer-approve.php` line 28 is
`requireAdminAjax('transfer approval')`). Approval is transactional: it claims
the pending row, moves `cars.user_id`, writes a `cars_hist` row with
`operation='NEWOWNER'`, commits, then sends a best-effort email.

This matters because it is easy to assume otherwise. ADR-008 explicitly
considered letting the current owner approve directly — "Alternative D, Peer
Approval" — and **rejected** it for removing admin oversight. The registry has
no current-owner consent step. A `security_token` column is written when a
request is created and **never read anywhere in production code**; there is no
email-link possession-proof flow. Requests carry a 30-day window, and nothing
enforces expiry automatically — admin queries filter on the timestamp.

**Verification is what keeps the data true — and it is not running.** The
intended cycle is: periodically email an owner, they confirm / mark sold /
update, and the record's freshness clock resets. In practice the cycle has been
broken for years (see §6), which means every other feature reads from records
that nothing re-checks.
Since v2.30.0 (#1872) the scheduled-job transport — UserSpice's `cron.php`,
triggered every 10 minutes on every environment — does exist; what is missing
is the jobs, not the scheduler (see
[DEPLOYMENT.md — Cron Transport](DEPLOYMENT.md#cron-transport-userspice-cron-manager)).

**Deletion preserves the car, not the person.** Because the registry's value is
continuity of the car record, GDPR erasure reassigns cars to `noowner` rather
than cascading a delete. A car whose owner left the registry keeps its history.

**The denormalised `cars` table caches owner data.** Owner name, email and
location are copied onto each car row for query performance, and kept in sync by
triggers and an explicit admin re-sync tool. Never write the denormalised
columns directly — update the profile and let the sync run (ADR-002).

## 5. Privacy and data protection

The registry holds real people's names, locations and cars, and the privacy
posture is more deliberate than most hobby projects.

- **Location is coarse by construction, and this is the main protection.**
  There is no address field anywhere. Owners pick a city/region from an
  autocomplete or use a one-shot GPS reverse-geocode, so the stored coordinate
  is a place centroid. `LocationService` rounds it to four decimals before
  storage. Location also belongs to the **owner's profile, not the car** —
  every car a person owns shares one coordinate, so a car's position is really
  "roughly where its owner is."
- **The statistics map adds a further offset; the car page does not.** The
  aggregate map applies a deterministic per-car jitter of up to ~0.01°
  (~1.1 km) — `sin($car->id) * 0.01` — stable across page loads. The
  single-car detail page renders the stored coordinate directly, with an
  "approximate location" caption that is a label, not a transformation. The
  privacy policy's "we deliberately fuzz the location data" is therefore true
  of the map view specifically; on a car page the imprecision comes entirely
  from the coordinate being a city centroid to begin with.
- **Photo metadata is discarded.** Uploads are re-encoded through GD during
  resizing, which drops EXIF including GPS tags. Uploaded filenames are also
  discarded and regenerated server-side (`img_<32 hex>`), and MIME type is
  sniffed with `finfo` rather than trusted from the client. *(Unverified:
  whether the original, as distinct from the resized variants, is re-encoded.)*
- **Email addresses are never exposed.** Owner-to-owner contact is relayed
  through the site; correspondents can move off-site afterwards by choice.
- **Right to erasure is supported** but not self-service — the privacy policy
  directs users to email the registrar, and deletion is performed by an
  administrator. There is no export- or delete-my-data UI anywhere in the app.
- **Profile updates cannot escalate privilege.** `Owner::update()` filters
  input through hard-coded allowlists — `fname, lname, email, password` for the
  user row and `city, state, country, lat, lon, website` for the profile.
  `permissions` and `active` are not writable through this path.
- **A car's public page shows more than most people expect.** Car list, car
  detail, factory records, statistics and the privacy policy are all **public**
  — no login. A car page renders the full specification, photo carousel, map,
  owner's first name and city, and the complete field-level change history to
  anonymous visitors. Only the action buttons vary by viewer. Privacy comes
  from what is never stored or shown — last name and email address are not on
  the page for anyone — rather than from per-viewer redaction.
- **Public read endpoints are rate-limited, not CSRF-gated.** The car list,
  factory records, car history, and statistics endpoints are all public and
  read-only: they change no state, require no login, and return no data beyond
  what the corresponding public page already renders. Per
  [ADR-019](adr/ADR-019-no-csrf-on-public-read-only-endpoints.md), these
  endpoints carry no CSRF token check; abuse is bounded by app-layer rate
  limiting instead. This eliminates the stale-token failure when a session
  ends, and aligns the security model with the endpoints' actual risk profile.

## 6. What is deliberately not built

Recording these prevents the most expensive documentation failure: a future
reader assuming an absent feature was an oversight and "restoring" it.

- **No peer-approved transfers.** Rejected in ADR-008 as removing admin
  oversight. The unused `security_token` column is a remnant of that design and
  is reserved for it, not evidence it exists.
- **No self-service account deletion.** Erasure is deliberately a
  human-reviewed action, because it reassigns car records.
- **No automatic transfer expiry.** The 30-day window is enforced by query, not
  by a sweep job — an accepted simplification.
- **No public write API.** All AJAX endpoints are first-party, CSRF-protected
  and session-bound. `sitemap.php` is the sole deliberate unauthenticated
  endpoint.
- **No general-purpose email campaign tooling.** Transactional mail only.
- **No ORM.** Queries are hand-written against UserSpice's `DB` wrapper
  (ADR-001).
- **jQuery cannot be removed.** It is a UserSpice 6 dependency, not a project
  choice (ADR-015, ADR-016).
- **No car-owner verification flow, for now.** `app/admin/verify/` was
  removed entirely in #1613 — the emailed link pointed at a nonexistent
  path, carried a session-bound CSRF token that could never match a
  recipient's session, and sat behind an admin-only auth gate on what was
  meant to be an owner-facing page. No navigation linked to it. The
  underlying service layer (`CarVerificationManager`, `Car`/`CarRepository`
  verification methods) is retained; #1155 shipped the data-model foundation
  in v2.30.0, and the rebuild continues as #1156's follow-on issues — the
  send pipeline in v2.30.3 and owner/admin self-service in v2.30.5.

## 7. What is built but broken or incomplete

Verified against code. Each of these is a real gap, not a documentation error.

- **`TransferStatus::Approved` and `::Expired` are never written.** Production
  transitions go straight from `Pending` to `Completed` or `Denied`.
- **`car_transfer_requests.security_token` is write-only** (see §4).
- **`BackupManager` cannot restore.** Export, verify and cleanup only, despite a
  `backups/rollback/` directory existing.
- **Audit coverage is cars-only.** There is no `users_hist` or `profiles_hist`;
  owner and profile changes rely on discretionary logging with no database-level
  guarantee.
- **No email delivery log, queue or retry.** Failures are logged and dropped.
- **Chassis validation rules are duplicated in three places** — the
  `ChassisValidator` class, the prose on `docs/reference/chassis-validation.php`,
  and the help modal in `edit.php`. They currently agree, but the prose is
  hand-maintained rather than generated from the class, so they will drift.
- **`docs/reference/identification-guide.php` is orphaned from its own index** —
  live, navigation-linked and in the sitemap, but absent from the card grid on
  `docs/reference/index.php`.
- **`docs/reference/paint-colors.php` is hardcoded**, carrying an explicit
  `TODO: Move to database table`.
- **`CLAUDE.md`'s `userimages/orphan/` claim looks stale** — no live code path
  writing there was found; the only reference is in an archived README.
- **The Data Quality dashboard computes a "% verified" figure and throws it
  away.** `StatisticsDataService::getDataCompleteness()` selects
  `COUNT(last_verified) AS verified_cars` (and `has_sold_date`) on every
  request and ships both to the browser; the frontend reads a hard-coded
  allowlist of six fields that excludes them. Nothing in
  `app/assets/js/statistics.js` references `verified_cars`. The registry
  measures how much of its data has been verified, on every page load, and
  never shows the answer — which is consistent with the answer being ~zero.

## 8. Where to read more

| Topic | Document |
| --- | --- |
| Database schema and relationships | [DATABASE.md](DATABASE.md) |
| Application classes | [CLASSES.md](CLASSES.md) |
| Request lifecycle and initialization | [PAGE_LOADING_FLOW.md](PAGE_LOADING_FLOW.md) |
| Error handling and API conventions | [ERROR_HANDLING.md](ERROR_HANDLING.md) |
| Audit logging categories | [LOG_CATEGORIES.md](LOG_CATEGORIES.md) |
| Email configuration | [EMAIL_SYSTEM.md](EMAIL_SYSTEM.md) |
| Backups | [BACKUP_SYSTEM.md](BACKUP_SYSTEM.md) |
| Why decisions were made | [adr/](adr/) |
| Installation and onboarding | [GitHub Wiki](https://github.com/elan-registry/registry/wiki) |

---

**Maintenance note.** This document describes *what the system does*. When a
capability is added, removed, or changes who can perform it, update this file in
the same pull request. Sections 6 and 7 are the ones that rot invisibly — a
deliberate omission that gets built, or a broken feature that gets fixed, leaves
this document quietly wrong with no other signal.
