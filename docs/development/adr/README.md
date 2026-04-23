# Architecture Decision Records

This directory contains Architecture Decision Records (ADRs) for the Lotus Elan
Registry. ADRs document significant architectural decisions, their context, and
consequences.

We follow the [Michael Nygard ADR template](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions).

## Index

| ADR | Title | Status | Priority |
| --- | --- | --- | --- |
| [ADR-001](ADR-001-userspice-authentication-framework.md) | Adopt UserSpice as Authentication and Authorization Framework | Accepted | High |
| [ADR-002](ADR-002-denormalized-cars-table-cached-owner-data.md) | Use a Denormalized Cars Table with Cached Owner Data | Accepted | High |
| [ADR-003](ADR-003-database-audit-trails-triggers-history-tables.md) | Implement Database Audit Trails via Triggers and History Tables | Accepted | High |
| [ADR-004](ADR-004-standardize-api-architecture-pattern-a-responses.md) | Standardize API Architecture: Pattern A Responses | In Review | High |
| [ADR-005](ADR-005-use-encrypted-environment-variables-via-secure-env-php.md) | Use Encrypted Environment Variables via SecureEnvPHP | Superseded | High |
| [ADR-006](ADR-006-use-database-stored-cdn-urls-for-frontend-dependencies.md) | Use Database-Stored CDN URLs for Frontend Dependencies | In Review | Medium |
| [ADR-007](ADR-007-implement-content-security-policy-and-security-headers.md) | Implement Content Security Policy and Security Headers | In Review | High |
| [ADR-008](ADR-008-implement-self-service-car-ownership-transfer-workflow.md) | Implement Self-Service Car Ownership Transfer Workflow | In Review | Medium |
| [ADR-009](ADR-009-use-lightweight-custom-schema-migration-runner.md) | Use Lightweight Custom Schema Migration Runner | In Review | Medium |
| [ADR-010](ADR-010-use-noowner-system-account-for-gdpr-compliant-user-deletion.md) | Use noowner System Account for GDPR-Compliant User Deletion | In Review | Medium |
| [ADR-011](ADR-011-adopt-datatables-with-server-side-processing.md) | Adopt DataTables with Server-Side Processing | In Review | Low |
| [ADR-012](ADR-012-adopt-brevo-for-transactional-email-delivery.md) | Adopt Brevo (Sendinblue) for Transactional Email Delivery | In Review | Low |
| [ADR-013](ADR-013-pdf-reference-library-storage.md) | Store PDF Reference Library on A2 Hosting with Database-Driven Metadata | In Review | Medium |
| [ADR-014](ADR-014-replace-secure-env-php-with-phpdotenv.md) | Replace johnathanmiller/secure-env-php with vlucas/phpdotenv | Accepted | High |

## Statuses

- **Accepted** -- decision has been documented and is in effect
- **In Review** -- ADR written, pending review and commit
- **Planned** -- decision identified but ADR not yet written
- **Superseded** -- replaced by a newer ADR
- **Deprecated** -- no longer applicable

## Priority Guide

- **High** -- foundational decisions every contributor must understand
- **Medium** -- important decisions affecting specific domains or workflows
- **Low** -- supporting conventions and infrastructure choices
