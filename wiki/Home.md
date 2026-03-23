# Welcome to the Elan Registry Wiki

This wiki provides documentation for developers working with the Elan
Registry application, which uses the UserSpice authentication framework.

## What This Wiki Is For

This wiki helps you understand:

- How UserSpice integrates into Elan Registry
- Core concepts behind the architecture
- How to build features safely and securely
- Where to find answers to common problems

## For New Developers

Start here and follow the suggested reading order below. It's designed to take
you from "What is this?" through "How do I build features?"

## Recommended Reading Order

**Understand First**:

- **[UserSpice Services and Core Concepts](UserSpice-Services-and-Core-Concepts)** —
  What UserSpice is and what it provides
- **[Elan Registry Architecture and Database Design](Elan-Registry-Architecture-and-Database-Design)** —
  How the system is structured and how data flows
  - [Database Schema and Data Model](Database-Schema-and-Data-Model) |
    [PHP Architecture](PHP-Architecture-and-Class-Design) |
    [UserSpice Integration](UserSpice-Integration-and-Access-Control) |
    [File Storage](File-Storage-and-Image-Handling) |
    [External Integrations](External-Integrations-and-Infrastructure) |
    [User Flows](Key-User-Flows)

**Setup**:

- **[Registry-Installation](Registry-Installation)** — Installation,
  prerequisites, and initial configuration

**Learn Implementation**:

- **[Understanding the Page Framework](Understanding-the-Page-Framework)** —
  How pages initialize and how the standard pattern works
- **[Page Security and Access Control](Page-Security-and-Access-Control)** —
  Permission model and security best practices
- **[Customization and Integration Patterns](Customization-and-Integration-Patterns)** —
  How to extend the system safely
- **[Development-Patterns](Development-Patterns)** — Workflows, standards,
  and best practices

**Development Workflow**:

- **[Development Workflow](Development-Workflow)** — Milestone lifecycle,
  Claude Code commands, branching strategy, and step-by-step guide
- **[Developer-Tools](Developer-Tools)** — Static analysis and code quality
  tools

**Reference**:

- **[Quick-Reference](Quick-Reference)** — Code snippets and common patterns
- **[Troubleshooting-Guide](Troubleshooting-Guide)** — Solutions to common
  problems

## Documentation vs Repository Docs

This wiki focuses on **concepts and understanding**. For specific function
signatures and implementation details, see repository documentation:

| Need | Location | Use When |
| --- | --- | --- |
| Understanding "why" | This wiki | Learning concepts and architecture |
| Function signatures | [USERSPICE_FUNCTIONS.md](https://github.com/unibrain1/elanregistry/blob/master/docs/development/USERSPICE_FUNCTIONS.md) | Looking up how to call a function |
| Code patterns | [INTEGRATION.md](https://github.com/unibrain1/elanregistry/blob/master/docs/development/INTEGRATION.md) | Implementing features |
| Database schema | [DATABASE.md](https://github.com/unibrain1/elanregistry/blob/master/docs/development/DATABASE.md) | Understanding data structure |
| Debugging issues | [PAGE_LOADING_FLOW.md](https://github.com/unibrain1/elanregistry/blob/master/docs/development/PAGE_LOADING_FLOW.md) | Troubleshooting initialization |

## Quick Navigation

**Key Concepts**:

- [UserSpice Authentication and Permissions](UserSpice-Services-and-Core-Concepts#core-services-userspice-provides)
- [How Pages Initialize](Understanding-the-Page-Framework#the-standard-page-pattern)
- [Security Model](Page-Security-and-Access-Control#security-philosophy-defense-in-depth)
- [Where to Put Code](Customization-and-Integration-Patterns#the-philosophy-safe-customization)

**Common Tasks**:

- [Check if user is logged in](Quick-Reference#authentication-check)
- [Check user permissions](Quick-Reference#permission-check)
- [Get user data](Quick-Reference#get-user-data)
- [Protect a form with CSRF](Quick-Reference#form-with-csrf-protection)

**Troubleshooting**:

- [Page not registered error](Troubleshooting-Guide#page-not-registered-error)
- [Permission denied errors](Troubleshooting-Guide#permission-denied-on-page-you-should-have-access-to)
- [Server variables not working](Troubleshooting-Guide#server-variables-arent-working)

## Getting Help

- **Installation issues**: See [Registry-Installation](Registry-Installation)
- **How to understand UserSpice**: See
  [UserSpice Services and Core Concepts](UserSpice-Services-and-Core-Concepts)
- **How pages work**: See
  [Understanding the Page Framework](Understanding-the-Page-Framework)
- **How to write code**: See [Development-Patterns](Development-Patterns)
- **Development workflow**: See [Development Workflow](Development-Workflow)
- **Function reference**: See
  [USERSPICE_FUNCTIONS.md](https://github.com/unibrain1/elanregistry/blob/master/docs/development/USERSPICE_FUNCTIONS.md)
- **Official UserSpice docs**:
  [UserSpice Knowledge Base](https://userspice.com/kb/)
- **How to edit this wiki**: See [WIKI-WORKFLOW](WIKI-WORKFLOW) — Local and
  GitHub editing guide

---

**Last Updated**: 2026-03-23 | **UserSpice Version**: 6.x.x |
**Elan Registry**: v2.16.3+ | **Content-Review-Status**: Reviewed
