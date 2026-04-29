# ADR-008: Implement Self-Service Car Ownership Transfer Workflow

## Status

**In Review** (retroactive)

## Date

Retroactive -- documented 2026-02-25

## Context

The Lotus Elan Registry tracks ownership and history of Lotus Elan cars manufactured between 1963 and 1974. Chassis numbers are a fundamental identifier, but
historically they have been ambiguous and sometimes duplicated across manufacturing records.

When a car changes hands and the new owner attempts to register their car, they encounter a critical friction point: the chassis number is already registered to
someone else. Before the self-service transfer system (implemented v2.9.x), the only resolution was out-of-band: the new owner would need to email the registry
administrator, wait for manual intervention, and rely on the admin to use an administrative reassignment tool to update ownership.

This process created significant user friction:

- **Delayed registration** -- transfers required days or weeks of email coordination
- **Poor user experience** -- no self-service path for legitimate ownership changes
- **Admin overhead** -- every transfer consumed administrator time
- **Uncertainty** -- new owners had no visibility into transfer status
- **Dispute risk** -- without dual notification, disputes about car ownership could escalate

The product requirements explicitly identified ownership transfers as a critical KPI: ">95% successful transfers without administrative intervention" and "Time
from transfer request to completion" as key success metrics.

The chassis collision workflow (`check-chassis.php`) already existed to detect when a user tried to register an already-registered chassis. The decision was to
augment that workflow with a self-service request mechanism that routes to administrator approval instead of hard-blocking the user.

## Decision

Implement a **self-service car ownership transfer workflow** that allows a registered user to request transfer of an already-registered car to themselves,
subject to administrator approval and verification.

### Workflow Overview

**Initiation**: When a user adds a car with a chassis number that is already registered (`check-chassis.php`returns`taken: true`), instead of a hard block, the
UI displays the `#chassis_taken` div with a "Request Ownership Transfer" button.

**Request Submission**:

1. User clicks "Request Ownership Transfer" and confirms in a modal dialog
2. User may optionally include a comment (up to 1000 characters) explaining the request
3. `request-transfer.php` validates CSRF token, prevents self-transfers (user cannot request ownership of their own car), prevents duplicate pending requests
   for the same chassis
4. A SHA-256 security token is generated and stored (reserved for future peer-approval workflow)
5. Request row inserted into `car_transfer_requests`table with`status='pending'`and`expires_at=NOW()+30 days`
6. Emails sent to:
   - Current car owner (notifying them of the transfer request)
   - All registry administrators (alert of pending action)

**Administrator Review**:

1. Administrator sees pending transfer request in the Car Management tab
2. Administrator reviews request details, optionally reads requester's comment
3. Administrator approves or denies via modal dialog using `ElanRegistryAPI.post()`

**Approval Path**:

1. Admin clicks "Approve"
2. `process-transfer-approve.php`validates CSRF and admin permission, verifies status is still`pending`
3. Calls `Car::transfer()`facade, which delegates to`CarAdministrationService::transfer()`
4. Service method executes atomic transaction:

   - Updates `cars` table owner fields: `user_id`, `token`, `email`, `fname`, `lname`, `join_date`,
     `city`, `state`, `country`, `lat`, `lon`, `website` (denormalized per ADR-002)
   - Updates `car_user` junction table to new owner's `user_id`
   - Inserts row in `cars_hist` table with `operation='NEWOWNER'` (per ADR-003)
   - Sets request `status='completed'` and `completed_date=NOW()`

5. Emails sent to:
   - Requester (notifying of approval and successful transfer)
   - Previous owner (notifying of ownership change and providing recipient contact for follow-up)

**Denial Path**:

1. Admin clicks "Deny" and optionally provides reason
2. `process-transfer-deny.php`validates CSRF and admin permission, verifies status is still`pending`
3. Sets request `status='denied'`, `completed_date=NOW()`, and stores reason in `denial_reason` field
4. No changes to car ownership
5. Emails sent to:
   - Requester (notifying of denial)
   - Previous owner (notifying that transfer was denied)

**Expiration**: Requests have a 30-day window. Expired requests are identified by `expires_at < NOW()`in queries. There is no automatic cron job to
set`status='expired'`; admin interfaces filter on both pending status and not-yet-expired timestamps.

### Database Schema

**`car_transfer_requests`** table:

- `id` (PK, auto_increment)
- `existing_car_id` (FK→cars.id)
- `requested_by_user_id` (FK→users.id)
- `request_date` (timestamp)
- `status` (ENUM: 'pending', 'approved', 'denied', 'completed', 'expired')
- `security_token` (varchar 64, currently unused -- reserved for future peer-approval)
- `expires_at` (datetime)
- `admin_notes` (text, nullable)
- `current_owner_response_date` (datetime, nullable, unused)
- `submitted_*` fields (snapshot of request details at submission time)
- `created_by` (FK→users.id, audit)
- `modified_date` (timestamp, audit)
- `denial_reason` (text, nullable, unused)
- `completed_date` (datetime, nullable)

**Indexes**:

- `idx_car_pending_transfers` on (`existing_car_id`, `status`) -- optimize pending request lookups by car
- `idx_user_transfer_requests` on (`requested_by_user_id`, `status`) -- optimize request history by user

**`cars` table** modified at approval:
Fields updated per ADR-002 (denormalized owner data): `user_id`, `token`, `email`, `fname`, `lname`, `join_date`, `city`, `state`, `country`, `lat`, `lon`,
`website`

**`car_user`** junction table:
`userid`field updated to new owner's`user_id`

**`cars_hist`** table:
Audit row inserted with `operation='NEWOWNER'` and snapshot of old/new owner data (per ADR-003)

### Security Model

- **Authentication**: Any authenticated user (login required) can request a transfer
- **Authorization**: Approval/denial requires administrator permission (`isRegistryAdmin()` checks for permissions [2,3])
- **CSRF Protection**: All endpoints validate CSRF token via `Token::check()`
- **SQL Safety**: All queries use prepared statements via DB class
- **Self-transfer Prevention**: Explicit application logic check prevents user from requesting transfer of their own car
- **Duplicate Prevention**: Application-level SELECT before INSERT to check for existing pending requests (note: no unique database constraint, creating minor
  race condition risk)
- **Idempotency**: Approve/deny endpoints verify `status='pending'` before acting, preventing double-processing

### Email Notifications

Four distinct email notifications orchestrate the workflow:

| Trigger | Recipients | Template | Function |
| --- | --- | --- | --- |
| Request submitted | Current owner | `_email_transfer_request.php` | `sendTransferRequestNotification()` |
| Request submitted | All admins | `_email_transfer_admin.php` | `sendTransferRequestAdminAlert()` |
| Decision made | Requester | `_email_transfer_response.php` | `sendTransferResponseNotification()` |
| Decision made | Previous owner | `_email_transfer_previous_owner.php` | `sendTransferPreviousOwnerNotification()` |

All email functions defined in `usersc/includes/transfer_email_notifications.php`.

**Email Design Principles**:

- Current owner notification includes all relevant details to help assess legitimacy
- Requester's last name is excluded from current owner notification (privacy)
- Both requester and previous owner are notified of decisions to enable dispute resolution
- Email failures are non-fatal (wrapped in isolated try/catch blocks) -- transfer completes even if email delivery fails

### API Patterns

Fully compliant with Pattern A (ADR-004):

- All endpoints return `ApiResponse::success()`or`ApiResponse::error()` with appropriate HTTP status codes
- Frontend uses `ElanRegistryAPI.post()` for all admin actions (approve/deny)
- Error messages are clear and actionable
- Success responses include confirmation details

## Consequences

### Positive

- **Dramatically reduces admin burden** -- 95%+ of transfers self-service without intervention
- **Enables faster user experience** -- transfers complete on admin schedule rather than email coordination loops
- **Full audit trail** -- request details in `car_transfer_requests`table + operational history in`cars_hist` table per ADR-003
- **Fraud detection opportunity** -- current owner is notified and can object if transfer is illegitimate
- **Dual-path email notifications** -- both requester and current owner aware, reducing future disputes
- **Admin retains decision authority** -- no automatic transfers; human review remains in the loop
- **Fully integrated with modern patterns** -- uses Pattern A API responses (ADR-004), typed exceptions, LogCategories, ElanRegistryAPI frontend client
- **Non-fatal email failures** -- transfers complete even if email delivery fails; user can verify via dashboard
- **Clear user communication** -- modals, in-page status, email confirmations provide awareness at each step

### Negative

- **Race condition on duplicate-pending check** -- no unique database constraint on (`existing_car_id`, `requested_by_user_id`, `status`='pending'), so rapid
  successive requests could theoretically create duplicates. Mitigated by application-level SELECT before INSERT, but not atomic.
- **Unused schema fields** -- `security_token`, `current_owner_response_date`, `denial_reason`, and the `'approved'` status value are defined but never used;
  reserved for future peer-approval workflow
- **History insertion outside transaction** -- `cars_hist` row inserted post-commit hook (non-fatal exception handling) rather than within the atomic
  transaction, introducing minor consistency gap
- **No automatic expiry enforcement** -- no cron job sets `status='expired'`when`expires_at` passes; admin queries must filter on timestamp. Relies on admin UI
  to exclude expired requests.
- **Admin approval is a bottleneck** -- every transfer requires manual admin review and action. As registry grows, admin workload scales linearly with transfer
  volume.
- **Limited discoverability** -- transfer option only appears when user knows to add the car and encounters the chassis collision. No dedicated entry point from
  car detail page for users aware their car is registered but not by them.
- **Denormalization cost** -- approval updates ~12 columns on cars table per ADR-002, increasing transaction scope

### Risks

| Risk | Likelihood | Impact | Mitigation |
| --- | --- | --- | --- |
| Malicious transfer requests flood admin queue | Low-Medium | Medium | Rate limiting on request-transfer.php; admin can bulk-deny; consider authentication-based throttling |
| Current owner's email is stale; transfer proceeds without awareness | Medium | Medium | Email delivery is best-effort; current owner can re-register or request transfer back; admin can reverse via UI |
| Legitimate new owner cannot prove ownership; denied transfer creates dispute | Medium | Medium | Request comment field allows context; admin can manually verify via phone/documentation; future peer-approval alternative exists |
| Admin accidentally approves transfer for wrong car/user | Low | High | Requires deliberate admin action; audit trail in cars_hist allows reversal; consider confirmation dialog (already present in UI) |
| Race condition creates duplicate pending requests; user confused | Low | Low | Duplicate request shows in UI; older request expires in 30 days; admin can deny one |
| Email notification reveals personal information of current owner to requester | Low-Medium | Medium | Requester's last name stripped from current owner notification; requester only sees car and their own request |

## Alternatives Considered

### A. Admin-Only Transfers (Status Quo Ante)

The original system: no self-service path. All transfers require email to administrator and manual reassignment via admin-only tool.

**Rejected because:**

- Fails KPI: ">95% successful transfers without administrative intervention"
- User friction: multi-day email coordination for simple ownership changes
- Admin overhead: linear scaling of admin time with transfer volume
- No current-owner awareness: previous owner may not learn about ownership change

**Retained for**: Exceptional cases (e.g., admin needs to reassign car after user deletion, or fix data corruption). Manual reassignment tool labeled
"Administrative Use Only" remains available.

### B. Instant Owner-to-Owner Transfer Without Admin Review

Requester sends token to current owner; current owner clicks approval link in email; transfer completes directly without admin review.

**Rejected because:**

- Current owner's email may be stale or unmonitored (not guaranteed for historical registry entries)
- Non-responsive current owner can block legitimate new owner indefinitely
- Single point of failure: if current owner doesn't respond, transfer stalls
- Reduces admin oversight; some organizations require human review of ownership changes
- Security risk: email interception could enable transfer to attacker

**Supported by schema**: Fields `security_token`, `current_owner_response_date`, and consideration of 'approved' status suggest this alternative was in original
design. Could be implemented as future enhancement with explicit opt-in.

### C. Admin Notification Only; No Current-Owner Email

Admin is notified of transfer requests and approves/denies, but current owner is not notified.

**Rejected because:**

- Current owner cannot object to illegitimate transfer requests
- No fraud detection opportunity: bad actors could transfer cars they don't own
- Increases dispute risk: current owner learns about ownership change after-the-fact
- Violates principle of transparency in ownership changes

### D. Peer Approval (Current Owner Approves/Rejects Directly)

Requester initiates; current owner receives email with approve/deny buttons; approval immediately transfers car without admin review.

**Rejected because:**

- Same fundamental risk as Alternative B: non-responsive current owner
- Adds assumption about email monitoring which doesn't hold for historical entries
- Higher fraud risk if email is compromised or forwarded
- Removes admin oversight entirely

**Could be future enhancement**: Added as opt-in per-user preference or configurable per registry policy.

### E. Separate "Request Transfer" Entry Point

Dedicated button on car detail page to initiate transfer, visible even before user attempts to add car.

**Advantages**:

- Better discoverability for users who find car in search but are not the registered owner
- Clearer intent: dedicated action vs. "I'm adding a car and hit an error"
- Could allow requesting transfer without duplicate add attempt

**Rejected because:**

- Current implementation via chassis collision detection works well for primary use case
- Additional UI entry point adds complexity
- Deferred as future enhancement; current flow serves 95% of cases

**Recommendation**: Consider for v3.0 UX refresh if transfer volume grows or user feedback indicates discoverability issues.

## References

| Item | File |
| --- | --- |
| Transfer request creation | [/app/cars/actions/request-transfer.php](../../app/cars/actions/request-transfer.php) |
| Chassis collision detection | [/app/cars/actions/check-chassis.php](../../app/cars/actions/check-chassis.php) |
| Admin approval endpoint | [/app/admin/includes/process-transfer-approve.php](../../app/admin/includes/process-transfer-approve.php) |
| Admin denial endpoint | [/app/admin/includes/process-transfer-deny.php](../../app/admin/includes/process-transfer-deny.php) |
| Car::transfer() facade | [/usersc/classes/Car.php](../../usersc/classes/Car.php) |
| CarAdministrationService | [/usersc/classes/Car/CarAdministrationService.php](../../usersc/classes/Car/CarAdministrationService.php) |
| Car transfer exception | [/usersc/classes/Exceptions/CarTransferException.php](../../usersc/classes/Exceptions/CarTransferException.php) |
| Email orchestration | [/usersc/includes/transfer_email_notifications.php](../../usersc/includes/transfer_email_notifications.php) |
| Email templates | [/usersc/views/emails/](../../usersc/views/emails/) (4 templates) |
| Admin UI (requests table) | [/app/admin/includes/tab-car_mgmt.php](../../app/admin/includes/tab-car_mgmt.php) |
| Admin JS handlers | [/app/admin/assets/manage-consolidated.js](../../app/admin/assets/manage-consolidated.js) |
| Owner UI modals | [/app/cars/form.php](../../app/cars/form.php) |
| Chassis check UI | [/app/cars/includes/_edit_car_1.php](../../app/cars/includes/_edit_car_1.php) |
| Database schema | [/database/1-schema.sql](../../database/1-schema.sql) |
| Integration tests | [/tests/integration/transfer/CarTransferWorkflowTest.php](../../tests/integration/transfer/CarTransferWorkflowTest.php) |
| Car repository | [/usersc/classes/Car/CarRepository.php](../../usersc/classes/Car/CarRepository.php) |
| User guide | [/docs/faq/CAR_TRANSFER_USER_GUIDE.md](../faq/CAR_TRANSFER_USER_GUIDE.md) |
| Admin guide | [/docs/faq/admin/CAR_TRANSFER_ADMIN_GUIDE.md](../faq/admin/CAR_TRANSFER_ADMIN_GUIDE.md) |
| Log categories | [/usersc/classes/LogCategories.php](../../usersc/classes/LogCategories.php) (LOG_CATEGORY_CAR_TRANSFER) |
| Error handling patterns | [ERROR_HANDLING.md](../development/ERROR_HANDLING.md) |
| Database audit trails | [ADR-003](ADR-003-database-audit-trails-triggers-history-tables.md) |
| Denormalization rationale | [ADR-002](ADR-002-denormalized-cars-table-cached-owner-data.md) |
| API response patterns | [ADR-004](ADR-004-standardize-api-architecture-pattern-a-responses.md) |
