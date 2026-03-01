# ADR-013: Store PDF Reference Library on A2 Hosting with Database-Driven Metadata

## Status

**In Review**

## Date

2026-02-25

## Context

The Lotus Elan Registry maintains a reference library of 18+ PDF documents: workshop manuals, technical specifications, parts catalogs, and historical materials. These documents are essential resources for car owners and researchers.

Currently, PDFs are stored in `docs/assets/` on the application server and served directly via the web server with companion `.txt` and `.png` files. The reference library page uses `scandir()` to list available documents and `docs/embed.php` to serve them directly.

This approach has limitations:

- **GitHub storage constraints**: Large binary files (workshop manuals up to ~15 MB) conflict with GitHub's recommended practices and storage costs. Commits containing large PDFs increase repository size and clone time.
- **No access control**: Direct web serving bypasses authentication. All documents are visible to all visitors regardless of intended audience.
- **No metadata management**: Document descriptions, categories, and visibility are managed in separate `.txt` files, not in a structured database. This is error-prone and doesn't scale.
- **No audit trail**: There is no record of who uploaded documents or when they were changed.
- **Page storage fragility**: The `scandir()` approach depends on file organization and companion file naming conventions that are invisible to the application layer.

A2 Hosting (the current production host) provides sufficient disk quota and SFTP access to store the PDFs outside the application source tree. The database provides a natural place to store metadata, access control rules, and audit history.

## Decision

Store PDF reference library documents on A2 Hosting with database-driven metadata management and access-control-enforcing PHP proxy serving.

### Storage and Serving Architecture

All PDF files are stored in a single controlled directory (`docs/pdfs/`) on A2 Hosting, protected from direct HTTP access via `.htaccess`. The directory is never served directly by the web server; instead, a PHP proxy (`docs/serve-document.php`) is the single point of access control enforcement for all PDF bytes.

The proxy enforces access control before sending any bytes:

- **public**: serve to all visitors (logged in or not)
- **members**: require `$user->isLoggedIn()`
- **admin**: require `isRegistryAdmin()` === true

On authorization success, the proxy streams the PDF with correct HTTP headers (`Content-Type: application/pdf`, `Content-Length`, `Content-Disposition`) and logs the access. On failure, it returns 403 (admin documents), 404 (not found), or redirects to login (members documents).

### Document Access Points

Multiple pages provide access to reference library documents. All must be
updated to route through the proxy instead of serving files directly from
`docs/assets/`:

| Page | Current Behavior | Migration |
|------|-----------------|-----------|
| `docs/reference-library.php` | Lists PDFs via `scandir('docs/assets/')`, links to `embed.php` and direct download | Query `reference_documents` table; link to proxy |
| `docs/embed.php` | Iframe viewer; loads PDF from `docs/assets/` via `?doc=<filename>` | Update to load from `docs/serve-document.php?id=<id>`; accept `?id=` parameter alongside legacy `?doc=` |
| `docs/faq/paint-colors.php` | Hardcoded link to `embed.php?doc=All%20Elan%20and%20Elan%20Plus%202%20Paint%20Codes.pdf` | Update to use `?id=<id>` after migration; or resolve by filename lookup |
| `docs/car-stories.php` | Hardcoded link to `embed.php?doc=Mag%20_issue_50_p12-15_Barry-Shapecraft.pdf` | Update to use `?id=<id>` after migration; or resolve by filename lookup |

#### Embed Page Migration

`docs/embed.php` is an iframe-based PDF viewer used by the reference library
listing and by pages that deep-link to specific documents. Currently it:

1. Accepts a `?doc=<filename>` parameter
2. Validates the filename (directory traversal guard, extension check)
3. Loads the PDF directly from `docs/assets/` into an iframe

After migration, `embed.php` will be updated to:

1. Accept `?id=<id>` (preferred) or `?doc=<filename>` (legacy/transition)
2. For `?id=`: set the iframe `src` to `docs/serve-document.php?id=<id>`
3. For `?doc=`: look up the document by filename in `reference_documents` and
   redirect to the `?id=` form, or fall back to the proxy with a filename-based
   lookup
4. Display the document title from database metadata instead of parsing the
   filename

This allows deep-linking pages (`paint-colors.php`, `car-stories.php`) to be
updated incrementally -- they can switch from `?doc=<filename>` to `?id=<id>`
at any point after migration without breaking.

### Replacing DocumentConfig with Database-Driven Metadata

The `reference_documents` table also replaces the hardcoded
`usersc/classes/DocumentConfig.php` class, which currently manages metadata,
access control, and navigation for markdown documentation served by
`docs/view.php`.

`DocumentConfig` provides four static methods with data hardcoded in PHP arrays:

- `getCategories()` — maps category slugs (`faq`, `admin`) to directory paths,
  document lists, and `requiresAdmin` flags
- `getDocumentInfo()` — maps filenames to title, icon, description, breadcrumb
  label, and category
- `validateDocument()` — looks up a filename, verifies it exists in the
  configured category, and returns the resolved filesystem path
- `hasAccess()` — checks `requiresAdmin` and calls `isRegistryAdmin()`
- `getBreadcrumb()` — builds breadcrumb navigation from category and document
  metadata

The `reference_documents` table subsumes all of this data. The schema includes
columns for every field currently hardcoded in `DocumentConfig`:

| DocumentConfig field | Table column | Notes |
|---------------------|-------------|-------|
| document filename | `filename` | Unique per section; supports both PDFs and `.md` files |
| title | `title` | Human-readable display title |
| description | `description` | Full description text |
| icon | `icon` | Font Awesome class (e.g., `fas fa-car`) |
| breadcrumb label | `breadcrumb` | Short label for breadcrumb navigation |
| category (faq/admin) | `section` | Page-level grouping: `howto`, `reference`, `stories`, `admin` |
| — (new) | `category` | Subsection within a page (e.g., `identify`, `paint`, `workshop`) |
| requiresAdmin | `access_level` | `ENUM('public','members','admin')` replaces boolean flag |
| directory path | `doc_type` | `ENUM('pdf','markdown')` determines serving path |

After migration, `docs/view.php` queries `reference_documents` by filename
instead of calling `DocumentConfig::validateDocument()`. Access control uses
the `access_level` column (mapping `admin` to `isRegistryAdmin()`, `members`
to `$user->isLoggedIn()`, `public` to unrestricted). Breadcrumb generation
uses the `breadcrumb` and `category` columns.

**Files affected by DocumentConfig removal:**

| File | Current DocumentConfig usage | Migration |
|------|------------------------------|-----------|
| `docs/view.php` | `validateDocument()`, `hasAccess()`, `getBreadcrumb()` | Query `reference_documents` table; use `ReferenceDocument` class |
| `usersc/classes/DocumentConfig.php` | Class definition | Remove after migration |
| `usersc/classes/Exceptions/DocumentationException.php` | Exception for DocumentConfig | Retain; used by MarkdownParser |
| `tests/unit/classes/DocumentConfigTest.php` | Unit tests for DocumentConfig | Replace with ReferenceDocument tests |
| `tests/integration/DocumentationViewerTest.php` | Integration tests referencing DocumentConfig | Update to test database-driven flow |
| `tests/unit/system/AutoloaderTest.php` | Verifies DocumentConfig autoloading | Remove DocumentConfig assertion |
| `tests/bootstrap-unit.php` | Mocks `isRegistryAdmin` for DocumentConfig | Retain mock (still needed) |
| `docs/development/CLASSES.md` | DocumentConfig class documentation | Update to document ReferenceDocument |
| `docs/development/PAGE_LOADING_FLOW.md` | Lists DocumentConfig in class inventory | Update reference |

### Database Schema and Metadata

The schema is delivered as FIX script 26, creating two tables following the
pattern established by `cars` and `cars_hist`:

| Column | Type | Purpose |
|--------|------|---------|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | Primary key |
| `title` | `VARCHAR(255)` | Human-readable document title |
| `description` | `TEXT` | Full description (migrated from `.txt` files) |
| `filename` | `VARCHAR(255) UNIQUE` | Physical filename in `docs/pdfs/` or `docs/faq/` |
| `file_size` | `BIGINT UNSIGNED` | File size in bytes (0 for markdown) |
| `doc_type` | `ENUM('pdf','markdown')` | Document type; determines serving path |
| `icon` | `VARCHAR(50)` | Font Awesome icon class for UI display |
| `breadcrumb` | `VARCHAR(100)` | Short label for breadcrumb navigation |
| `section` | `VARCHAR(50)` | Page grouping: `howto`, `reference`, `stories`, `admin` (see #559 alignment) |
| `category` | `VARCHAR(50)` | Subsection within page: `guides`, `faq`, `identify`, `paint`, `workshop`, `parts`, `articles`, `stories`, `magazine`, `admin-guides`, `admin-reference`, `other` |
| `access_level` | `ENUM('public','members','admin')` | Access control level |
| `sort_order` | `SMALLINT UNSIGNED` | Admin-controlled display ordering |
| `uploaded_by` | `INT` (foreign key, ON DELETE SET NULL) | Reference to `users.id` |
| `deleted_at` | `TIMESTAMP NULL` | Soft delete timestamp |
| `ctime` | `TIMESTAMP` | Creation time (automatic) |
| `mtime` | `TIMESTAMP` | Last modification time (automatic) |

A mirror `reference_documents_hist` table captures all INSERT, UPDATE, and DELETE operations via database triggers, providing a complete audit trail consistent with `cars_hist`.

### FIX Script DDL

The schema is created and deployed via FIX script `26-Create-Reference-Documents-Table.php`. The primary table DDL:

```sql
CREATE TABLE IF NOT EXISTS `reference_documents` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `ctime`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `mtime`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,
    `title`         VARCHAR(255)    NOT NULL,
    `description`   TEXT            NULL,
    `filename`      VARCHAR(255)    NOT NULL,
    `file_size`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `doc_type`      ENUM('pdf','markdown')
                                    NOT NULL DEFAULT 'pdf',
    `icon`          VARCHAR(50)     NULL DEFAULT NULL,
    `breadcrumb`    VARCHAR(100)    NULL DEFAULT NULL,
    `section`       VARCHAR(50)     NOT NULL DEFAULT 'reference',
    `category`      VARCHAR(50)     NOT NULL DEFAULT 'articles',
    `access_level`  ENUM('public','members','admin')
                                    NOT NULL DEFAULT 'members',
    `sort_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `uploaded_by`   INT             NULL,
    `deleted_at`    TIMESTAMP       NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_section_filename` (`section`, `filename`),
    INDEX `idx_doc_type` (`doc_type`),
    INDEX `idx_section` (`section`),
    INDEX `idx_access_level` (`access_level`),
    INDEX `idx_category` (`category`),
    INDEX `idx_sort_order` (`sort_order`),
    INDEX `idx_deleted_at` (`deleted_at`),
    CONSTRAINT `fk_ref_docs_uploaded_by`
        FOREIGN KEY (`uploaded_by`)
        REFERENCES `users` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
```

The history table mirrors all columns and is populated exclusively by database triggers on INSERT, UPDATE, and DELETE operations:

```sql
CREATE TRIGGER `ref_docs_insert`
AFTER INSERT ON `reference_documents`
FOR EACH ROW
BEGIN
    INSERT INTO `reference_documents_hist`
        (operation, doc_id, ctime, mtime, title, description,
         filename, file_size, doc_type, icon, breadcrumb,
         section, category, access_level,
         sort_order, uploaded_by, deleted_at)
    VALUES
        ('INSERT', NEW.id, NEW.ctime, NEW.mtime, NEW.title,
         NEW.description, NEW.filename, NEW.file_size,
         NEW.doc_type, NEW.icon, NEW.breadcrumb,
         NEW.section, NEW.category, NEW.access_level,
         NEW.sort_order, NEW.uploaded_by, NEW.deleted_at);
END
```

Similar triggers exist for UPDATE and DELETE operations. All triggers are created as part of the FIX script.

### File Upload and Registration

Two paths accommodate both direct uploads and large files:

**Path A — Direct PHP upload (files up to 10 MB):**

1. Admin submits form with CSRF token.
2. File is validated: PHP error check, `is_uploaded_file()`, size limit, MIME type (via `finfo`), magic bytes (`%PDF`), extension.
3. Filename is sanitised: unsafe characters removed, length capped at 200 chars, `.pdf` extension enforced.
4. File is moved to `docs/pdfs/` via `move_uploaded_file()`.
5. Database row inserted; INSERT trigger fires automatically.

**Path B — SFTP + metadata registration (files over 10 MB):**

1. Admin uploads PDF directly to `docs/pdfs/` via SFTP.
2. Admin opens "Register File" form in admin interface.
3. `ReferenceDocument::register()` verifies file exists on disk, reads size, and inserts metadata row.
4. File is served identically via the proxy.

This split avoids PHP `upload_max_filesize` constraints typical on shared hosting.

### Security Model

**Proxy-based enforcement**: All requests pass through `docs/serve-document.php`. No direct web server rule can bypass it. On successful authorization:

1. Verifies the resolved physical path is within `docs/pdfs/` (directory traversal guard using `realpath()`).
2. Sends `Content-Type: application/pdf` and `Content-Disposition: inline; filename="..."` (or `attachment` for downloads).
3. Sends `Content-Length` from the stored `file_size` value.
4. Streams the file with `readfile()`.
5. Logs the access via `logger()`.

On authorization failure: non-admin access to admin documents returns 403, unauthenticated access to member documents redirects to login, missing documents return 404, and missing files (despite DB record) return 500 and log the error.

**Directory protection**: `/docs/pdfs/.htaccess` contains:

```apache
Require all denied
```

This blocks direct HTTP access to the directory (Apache 2.4+). For Apache 2.2 compatibility: `Deny from all`.

**Upload validation**: All direct uploads pass six checks:
1. PHP error check (`UPLOAD_ERR_OK`)
2. `is_uploaded_file()` guard
3. Size limit (≤ 10 MB)
4. MIME type via `finfo` (must be `application/pdf`)
5. Magic bytes check (first 4 bytes must be `%PDF`)
6. Extension validation (`.pdf` only)

Filenames are sanitised by stripping unsafe characters, enforcing 200-char limit, rejecting `..` patterns, and ensuring `.pdf` extension.

**Cloudflare**: Cache Rule configured to bypass CDN cache for `docs/serve-document.php*` and `docs/pdfs/*` to ensure access control checks are never skipped.

### Class Design

The `ReferenceDocument` class (`usersc/classes/ReferenceDocument.php`) provides
CRUD operations and replaces `DocumentConfig` for document lookup and access
control:

**CRUD operations (PDF management):**

- `find(int $id): bool` — load document by ID (excludes soft-deleted)
- `findByFilename(string $filename): bool` — load by filename (for
  `docs/view.php` and legacy `?doc=` parameter support)
- `create(array $fields, int $userId): int` — validate upload, move file,
  insert row
- `register(array $fields, int $userId): int` — register pre-uploaded SFTP
  file
- `update(array $fields, int $userId): bool` — update metadata only
- `delete(int $userId): bool` — soft-delete

**Query and display (replaces DocumentConfig):**

- `getAll(string $accessContext): array` — fetch all visible documents
- `getBySection(string $section): array` — fetch documents for a page
  (e.g., `howto`, `reference`, `stories`, `admin`)
- `getByCategory(string $category): array` — fetch documents by subsection
- `getByDocType(string $docType): array` — fetch by type (`pdf` or `markdown`)
- `hasAccess(object $user): bool` — check access using `access_level` column
- `getBreadcrumb(string $usUrlRoot): array` — build breadcrumb navigation

**Utility:**

- `resolveStoragePath(): string` — resolve physical path with traversal guard;
  returns path in `docs/pdfs/` for PDFs or `docs/faq/` for markdown

A typed exception class `ReferenceDocumentException` extends the application's
`ElanRegistryException` base.

### Admin Interface and Integration

The admin interface integrates into the existing consolidated management page
(`app/admin/manage-consolidated.php`) as a new **Reference Library** tab,
following the established pattern of tab-based administration:

- **Tab content**: `app/admin/includes/tab-reference_library.php` — document
  list, upload form, metadata editor, SFTP registration form
- **AJAX endpoint**: `app/admin/actions/reference-library.php` — handles
  upload, register, update, and soft-delete actions

The consolidated page uses `?tab=<key>` routing with content loaded from
`includes/tab-<key>.php`. The new tab is added to the `$validTabs` array as
`'reference-library' => 'Reference Library'` and rendered as a nav tab with
a `fa-book` icon.

The tab content includes:
- A DataTable listing all documents (including soft-deleted, shown greyed out)
- An upload form for files up to 10 MB (direct PHP upload)
- A registration form for SFTP-uploaded files (filename lookup on disk)
- Inline edit for metadata (title, description, category, access level,
  sort order)
- Soft-delete with confirmation modal

The AJAX endpoint is protected by `securePage()` and `isRegistryAdmin()`, and
uses `ApiResponse` with Pattern A response format, compatible with the
`ElanRegistryAPI` frontend client.

The AJAX endpoint page is registered in UserSpice's page permissions system.
The directory `app/admin/actions/` is added to the `$path` array in
`/z_us_root.php` if not already present.

The public reference library page (`/docs/reference-library.php`) is updated to query `reference_documents` instead of `scandir()`, and to link to `docs/serve-document.php?id=<id>` instead of direct file paths.

### UserSpice Integration

The design follows existing UserSpice patterns:

- Uses the `securePage($php_self)` pattern for page access control.
- Uses `isRegistryAdmin()` bridge function for permission checks.
- Leverages the `Token` class for CSRF protection.
- Uses the `logger()` function with `LogCategories` constants for audit logging.
- Uses the `DB` singleton for all database operations.
- Exceptions extend the application's `ElanRegistryException` hierarchy.

## Implementation Plan

### Phase 1 — Foundation

1. Create `/docs/pdfs/` directory with mode `0750`.
2. Create `/docs/pdfs/.htaccess` with `Require all denied`.
3. Create and run FIX script 26 (DDL and triggers).
4. Create `ReferenceDocument.php` and `ReferenceDocumentException.php`.
5. Add `'app/admin/actions/'` to `$path` in `/z_us_root.php` if not already present.
6. Write and run unit tests for validation and sanitisation methods.

### Phase 2 — Serving Proxy

7. Create `/docs/serve-document.php` with access enforcement logic.
8. Register `/docs/serve-document.php` in UserSpice page permissions (level 1).
9. Configure Cloudflare Cache Rule to bypass for `docs/serve-document.php*`.
10. Test proxy manually: public serves to all, members redirects to login, admin returns 403 to non-admins.

### Phase 3 — Admin Interface

11. Add `'reference-library' => 'Reference Library'` to `$validTabs` in
    `app/admin/manage-consolidated.php`.
12. Create `app/admin/includes/tab-reference_library.php` with document list,
    upload form, and SFTP registration form.
13. Create `app/admin/actions/reference-library.php` AJAX endpoint.
14. Register the AJAX endpoint in UserSpice (admin level).
15. Write integration tests for upload and registration workflows.

### Phase 4 — Updated Public Pages

16. Update `/docs/reference-library.php` to query `reference_documents` instead of `scandir()`.
17. Replace direct `docs/assets/<file>` links with `docs/serve-document.php?id=<id>`.
18. Update `/docs/embed.php` to accept `?id=<id>` parameter and load PDFs via the proxy.
19. Maintain `?doc=<filename>` support in `embed.php` as a transition path (filename lookup in `reference_documents`).
20. Update `/docs/faq/paint-colors.php` deep-link to use `?id=<id>`.
21. Update `/docs/car-stories.php` deep-link to use `?id=<id>`.
22. Decide on `access_level = 'public'` documents and update UserSpice registration if needed.

### Phase 5 — DocumentConfig Migration

23. Seed `reference_documents` rows for all 13 markdown documents currently in
    `DocumentConfig` (6 faq + 7 admin), with `doc_type = 'markdown'`, correct
    `icon`, `breadcrumb`, `category`, and `access_level` values.
24. Update `docs/view.php` to query `reference_documents` via
    `ReferenceDocument::findByFilename()` instead of calling
    `DocumentConfig::validateDocument()`.
25. Update `docs/view.php` access control to use
    `ReferenceDocument::hasAccess()` instead of `DocumentConfig::hasAccess()`.
26. Update `docs/view.php` breadcrumb to use
    `ReferenceDocument::getBreadcrumb()` instead of
    `DocumentConfig::getBreadcrumb()`.
27. Remove `usersc/classes/DocumentConfig.php`.
28. Update tests: replace `DocumentConfigTest.php` with `ReferenceDocument`
    tests; update `DocumentationViewerTest.php` and `AutoloaderTest.php`.
29. Update `docs/development/CLASSES.md` and `docs/development/PAGE_LOADING_FLOW.md`.

### Phase 6 — PDF Migration

30. Migrate existing PDF files from `docs/assets/` to database and `docs/pdfs/`
    per migration plan.
31. Archive `docs/assets/` and remove from deployment once burn-in period
    completes.

## Migration Plan

### Pre-migration Audit

Create an inventory of all files in `docs/assets/`:

```bash
ls -la docs/assets/ | tee /tmp/reference-library-inventory.txt
```

### Migration Steps

For each PDF in `docs/assets/`:

1. Copy the PDF to `docs/pdfs/`.
2. Read companion `.txt` file for description.
3. Use admin registration form to create `reference_documents` row with:
   - `title`: human-readable title
   - `description`: full text from `.txt` file
   - `filename`: the PDF filename
   - `category`: technical, workshop, parts, history, magazine, or other
   - `access_level`: `members` by default (change to `public` only for documents freely accessible to unauthenticated visitors)

4. Verify document appears in listing and is served correctly via proxy.

### Companion File Handling

- `.txt` files: Copy content into `description` column; files themselves remain in `docs/assets/`.
- `.png` thumbnails: New listing does not display cover images. Thumbnails can be added later with a schema change if needed.

### Rollback Plan

During migration, both the old `scandir`-based listing and new `reference_documents`-based listing can coexist. If regression occurs:

1. Revert `/docs/reference-library.php` to previous version.
2. Old page uses `docs/assets/` files (still in place).
3. `reference_documents` rows and `docs/pdfs/` files are non-destructive; they can remain while issue is diagnosed.

### Post-migration Cleanup

- [ ] All 18 PDFs have corresponding `reference_documents` rows with correct metadata.
- [ ] All documents load correctly via `docs/serve-document.php`.
- [ ] `docs/embed.php` updated to accept `?id=` and route through proxy.
- [ ] `docs/faq/paint-colors.php` deep-link updated to use `?id=`.
- [ ] `docs/car-stories.php` deep-link updated to use `?id=`.
- [ ] No remaining references to `docs/assets/*.pdf` in any PHP page.
- [ ] `docs/assets/.htaccess` updated to deny all access.
- [ ] Cloudflare cache purged after listing page update.
- [ ] `docs/assets/` marked for removal in next maintenance window.

## Consequences

### Positive

- **Access control enforcement**. Documents can be restricted to members or admins. The proxy enforces this uniformly; no web server misconfiguration can bypass it.
- **Complete audit trail**. All document changes (create, update, soft-delete) are logged in the history table, including who made the change and when.
- **GitHub-friendly**. PDFs are stored on A2 Hosting, not in the repository. This eliminates large binary files from Git history and reduces clone time and storage cost.
- **Scalable metadata**. Documents are now queryable by category, access level, and sort order. Future features (full-text search, filtering, advanced sorting) are straightforward.
- **Clean admin interface**. Document management is centralized in the registry admin panel instead of scattered across file system operations and separate `.txt` files.
- **Consistent with existing patterns**. The design reuses UserSpice integration patterns, database conventions (soft deletes, audit trails, foreign keys), and exception handling already established in the application.
- **Simple serving model**. The proxy is a thin PHP wrapper that enforces access rules and logs requests. No need for hybrid strategies, separate serving paths, or complex web server configuration.
- **Supports intent-based reorganization**. The `section` and `category`
  columns enable #559's reorganization from format-based to intent-based
  navigation without code changes — documents can be reassigned to different
  sections and categories via the admin interface or database updates.

### Negative

- **Requires SFTP access for large files**. Files over 10 MB must be uploaded manually via SFTP and then registered through the admin form. This adds a step compared to direct upload, but is necessary to avoid PHP post/upload size limits on typical shared hosting.
- **One-way proxy serving**. All file requests must pass through the PHP proxy. This adds minimal overhead on a fast server but is not zero-cost compared to direct web server serving. The security and control benefits justify this trade-off.
- **No file replacement**. The system does not support in-place file replacement. If a document needs to be updated, the admin soft-deletes the old record and uploads a new one. This preserves the audit trail but requires two operations.
- **Storage directory outside application source**. The `docs/pdfs/` directory lives in the deployed application but is not part of the source repository. Deployment procedures must ensure the directory exists and has correct permissions on the target server.

### Risks

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|-----------|
| Web server misconfiguration exposes `docs/pdfs/` directly | Low | High | `.htaccess` protection is primary; test permissions after deployment; monitor web server error logs for directory-listing attempts |
| A2 Hosting disk quota exceeded | Low | Medium | Set max upload limits in form validation; document file_size in database; monitor quota via A2 control panel; alert if > 70% used |
| Cloudflare cache serves restricted document | Low-Medium | High | Cache Rule configured to bypass for `docs/serve-document.php*`; cache purge documented in migration; periodic spot-check of access control via private browsing |
| Soft-deleted file remains on disk | Low | Low | Soft-delete does not remove the physical file; this is intentional (preserves audit trail and allows recovery). Final cleanup is a separate maintenance task after a release cycle. |
| File size in database vs disk becomes inconsistent | Very Low | Low | File size is read fresh from disk and stored on upload/registration; proxy compares against stored value before sending; mismatch triggers 500 error and log alert |
| SFTP file registered but then deleted from disk | Low | Medium | Register form checks `file_exists()` before inserting; proxy checks again at serve time; missing file returns 500 error logged as FILE_ERROR; admin must restore file or soft-delete the record |

## Alternatives Considered

### Git LFS (Free Tier)

Use Git Large File Storage to store PDFs in the repository with pointer files checked into Git.

**Rejected because:**

- Free tier supports only 1 GB/month bandwidth; a registry with hundreds of users downloading large workshop manuals would exceed this quickly.
- Pointer file approach adds overhead and complexity; requires LFS client on all developer machines and CI/CD pipeline.
- Still requires external dependency (GitHub LFS) for a document management feature that belongs in application code and database.

### Git LFS (Private Backend)

Use Git LFS with a self-hosted backend server to avoid bandwidth costs.

**Rejected because:**

- Adds operational overhead: running and maintaining a separate LFS server.
- A2 Hosting does not provide LFS backend services; would require a third VM or external service.
- The application already has disk space on A2 Hosting and a database for metadata; LFS adds no value over direct storage plus database tracking.

### Store PDFs Directly in Git

Commit PDF files directly to the repository.

**Rejected because:**

- Repository size becomes unmanageable. A single 15 MB workshop manual bloats the `.git` directory; 18+ PDFs with history creates a ~500 MB+ repository.
- Clone and fetch operations become slow for all developers.
- GitHub charges for storage beyond the free tier.
- This is the exact problem Git LFS was designed to solve.

### External Storage Services (Google Drive, Dropbox, AWS S3)

Host PDFs on a third-party service and proxy requests to it.

**Rejected because:**

- Introduces external dependency for core application functionality. If the service is down, documents are unavailable.
- Recurring subscription cost (AWS S3 or similar managed service).
- Data sovereignty concerns: user data stored on third-party infrastructure.
- Network latency added to every document request.
- No audit trail of access in the application database.
- Configuration and credential management adds complexity.

The application already has disk space on A2 Hosting; using it is simpler and keeps all data under local control.

## Coordination with Related Issues

This ADR intersects with three open issues in the v2.18.0 milestone. The
recommendations below should be implemented when this ADR is approved.

### Issue #576: Git LFS — Superseded by This ADR

Issue #576 proposes implementing Git LFS for PDF file management. This ADR
explicitly evaluates and rejects Git LFS (both free tier and private backend)
in the Alternatives Considered section. The two approaches are fundamentally
contradictory: #576 keeps PDFs in Git (via LFS pointers), while this ADR
moves PDFs out of Git entirely to A2 Hosting disk with database metadata.

**Action on approval:** Close #576 as superseded by ADR-013, with a comment
linking to this ADR and explaining the rationale.

### Issue #350: DocumentPortalTemplate — Complementary

Issue #350 creates a `DocumentPortalTemplate` class to render document portal
pages (FAQ index, admin index) with a consistent card-based UI. This ADR
replaces `DocumentConfig` (the data source) with the `reference_documents`
table. These are complementary: #350 handles rendering, this ADR handles data.

**Actions on approval:**

- Update #350 to note that `DocumentPortalTemplate::render()` will receive its
  `documents` array from `ReferenceDocument::getByCategory()` or
  `ReferenceDocument::getBySection()` database queries, not from hardcoded
  PHP arrays.
- Move `DocumentPortalTemplate` to `usersc/classes/` (consistent with project
  conventions for classes).
- Add a cross-reference from #350 to this ADR.

### Issue #559: Documentation Reorganization — Schema Alignment Needed

Issue #559 reorganizes documentation from format-based (FAQ, Reference Library)
to intent-based navigation:

- **How-To Guides** — website usage: Add Car, Transfer, FAQ
- **Technical Reference** — car knowledge: Identification, Paint, Workshop,
  Parts, Articles
- **Car Stories** — individual car histories
- **Admin Docs** — administrative guides (admin-only access)

This reorganization requires the `reference_documents` schema to support two
levels of grouping:

1. **Section** — which page/portal displays the document (e.g., `howto`,
   `reference`, `stories`, `admin`)
2. **Category** — subsection within that page (e.g., `identify`,
   `paint`, `workshop`)

The original schema used a single `category` column for both purposes, which
is insufficient. A document like the Identification Guide may need to appear
in both How-To Guides (as a "how to identify your car" link) and Technical
Reference (as a reference document). A single column cannot express this.

**Schema change:** Add a `section` column to `reference_documents`:

```sql
`section` VARCHAR(50) NOT NULL DEFAULT 'reference'
```

The `section` column determines which page renders the document. The `category`
column determines the subsection heading within that page. This maps to #559's
structure as follows:

| #559 page | `section` value | `category` values |
|-----------|----------------|-------------------|
| How-To Guides | `howto` | `guides`, `faq` |
| Technical Reference | `reference` | `identify`, `paint`, `workshop`, `parts`, `articles` |
| Car Stories | `stories` | `stories`, `magazine` |
| Admin Docs | `admin` | `admin-guides`, `admin-reference` |

Each page queries: `SELECT * FROM reference_documents WHERE section = ?
AND deleted_at IS NULL ORDER BY category, sort_order`.

For documents that should appear in multiple sections (e.g., Identification
Guide in both How-To and Technical Reference), insert two rows with the same
`filename` but different `section` values. The `UNIQUE KEY uk_filename`
constraint must be changed to `UNIQUE KEY uk_section_filename (section,
filename)` to allow this.

**Actions on approval:**

- Update #559 dependencies: add "ADR-013 Phases 1-3" as a prerequisite;
  remove #576 from the blocked list.
- Add cross-reference from #559 to this ADR.
- Note that #559's FIX/23 (menu restructure) should run after
  `reference_documents` is populated.
- Resolve the Privacy Policy placement question raised in #559 comments
  (footer vs. How-To Guides vs. both).

### Dependency Chain After Approval

```text
ADR-013 Phase 1-3    #350 (DocumentPortalTemplate)
  (schema + proxy       (rendering)
   + admin tab)              |
       |                     |
       +----------+----------+
                  |
           #559 (Reorganize by Intent)
                  |
            #576 (CLOSED — superseded)
```

## References

- **DocumentConfig (replaced)**: [usersc/classes/DocumentConfig.php](../../usersc/classes/DocumentConfig.php)
  — hardcoded document metadata; replaced by `reference_documents` table.
- **Documentation Viewer**: [docs/view.php](../view.php) — markdown viewer;
  migrated from DocumentConfig to ReferenceDocument queries.
- **Consolidated Admin Page**:
  [app/admin/manage-consolidated.php](../../app/admin/manage-consolidated.php)
  — tabbed admin interface; Reference Library tab integrates here.
- **Reference Library Listing**: [docs/reference-library.php](../reference-library.php) — current `scandir()`-based PDF listing (to be migrated).
- **Document Embed Viewer**: [docs/embed.php](../embed.php) — iframe-based PDF viewer used by listing and deep-link pages.
- **Paint Colors Page**: [docs/faq/paint-colors.php](../faq/paint-colors.php) — deep-links to Paint Codes PDF via `embed.php`.
- **Car Stories Page**: [docs/car-stories.php](../car-stories.php) — deep-links to Shapecraft story PDF via `embed.php`.
- **FIX Scripts**: [docs/development/FIX_SCRIPTS.md](../development/FIX_SCRIPTS.md) — database maintenance scripts pattern.
- **Error Handling**: [docs/development/ERROR_HANDLING.md](../development/ERROR_HANDLING.md) — exception handling and ApiResponse pattern.
- **Coding Standards**: [docs/development/CODING_STANDARDS.md](../development/CODING_STANDARDS.md) — file organization, security standards, and type hints.
- **UserSpice Integration**: [GitHub Wiki: UserSpice Integration Guide](https://github.com/jimboone/elan-registry/wiki/Integration).
- **Custom Functions**: [usersc/includes/custom_functions.php](../../usersc/includes/custom_functions.php) — `isRegistryAdmin()`, `dbInt()`, and other bridge functions.
- **Page Loading Flow**: [docs/development/PAGE_LOADING_FLOW.md](../development/PAGE_LOADING_FLOW.md) — initialization sequence and globals.
- **Security Headers**: [usersc/includes/security_headers.php](../../usersc/includes/security_headers.php) — CSP and other headers.
- **Issue #350**: [Consolidate Documentation Portal Templates](https://github.com/unibrain1/elanregistry/issues/350)
  — complementary; provides rendering for database-driven metadata.
- **Issue #559**: [Reorganize documentation by user intent](https://github.com/unibrain1/elanregistry/issues/559)
  — requires `section` and `category` columns for intent-based grouping.
- **Issue #576**: [Git LFS for PDF management](https://github.com/unibrain1/elanregistry/issues/576)
  — superseded by this ADR; to be closed on approval.
