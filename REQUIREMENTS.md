# Requirements Document: Shuffle

**Version:** 1.4
**Date:** 2026-08-29
**Author:** Requirements Analyst
**Status:** Complete — Ready for Architect Review
**License:** MIT

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Business Context](#2-business-context)
3. [Goals and Objectives](#3-goals-and-objectives)
4. [Scope](#4-scope)
5. [Stakeholders](#5-stakeholders)
6. [User Personas / Actors](#6-user-personas--actors)
7. [Functional Requirements](#7-functional-requirements)
8. [Non-Functional Requirements](#8-non-functional-requirements)
9. [Data Requirements](#9-data-requirements)
10. [Integration Requirements](#10-integration-requirements)
11. [Constraints](#11-constraints)
12. [Assumptions](#12-assumptions)
13. [Dependencies](#13-dependencies)
14. [Risks](#14-risks)
15. [Success Criteria](#15-success-criteria)
16. [Open Questions](#16-open-questions)
17. [Appendices](#17-appendices)

---

## 1. Executive Summary

**Shuffle** is a self-hosted, open-source Kanban-style task management system designed as a reliable alternative to hosted SaaS solutions like Trello. The project is motivated by the need for full control over availability, data ownership, and infrastructure independence.

The system provides multiple Kanban boards with configurable lanes, rich cards with Markdown descriptions, comments, file attachments, checklists, assignments, and due dates. It supports organizations, role-based access control, and includes a Trello migration tool for importing existing data.

**Key success criteria:**
- A fully functional web-based Kanban board system that can replace Trello for day-to-day task management
- Self-hosted with minimal infrastructure requirements
- Open source under MIT license with minimal external dependencies

---

## 2. Business Context

### Background and Rationale

A Trello service outage exposed the risk of depending on a third-party hosted solution for task management. The inability to access task boards during the outage disrupted workflows and highlighted the need for a self-hosted alternative where uptime is within the team's control.

### Strategic Alignment

- **Data ownership**: All project data remains on infrastructure the team controls
- **Availability**: Uptime depends on owned infrastructure, not a third-party vendor
- **Cost**: No per-user SaaS fees; infrastructure costs only
- **Customization**: Open-source codebase can be tailored to specific workflow needs

### Current State vs. Desired State

| Aspect | Current (Trello) | Desired (Shuffle) |
|---|---|---|
| Hosting | Third-party SaaS | Self-hosted |
| Availability | Dependent on vendor | Controlled by team |
| Data ownership | Vendor-hosted | Fully owned |
| Cost model | Per-user subscription | Infrastructure cost only |
| Customization | Limited | Full source access |
| License | Proprietary | MIT open source |

---

## 3. Goals and Objectives

### Business Goals

1. Eliminate dependency on third-party hosted task management services
2. Maintain full control over data and system availability
3. Provide a capable open-source alternative that others can benefit from
4. Convince current stakeholders to adopt the solution as a Trello replacement

### User Goals

1. Manage tasks across multiple projects using Kanban boards
2. Collaborate with team members through assignments, comments, and checklists
3. Track work progress visually across lanes
4. Access task details including attachments and due dates
5. Migrate existing Trello data without losing critical information

### Measurable Success Criteria

| Metric | Target |
|---|---|
| Web Lighthouse score | >= 95% (MVP), iterating toward 100% |
| Accessibility compliance | WCAG 2.1 AA |
| Trello data migration | Cards, comments, checklists, attachments, board structure preserved |
| Deployment complexity | Single Debian Trixie server + MySQL + S3-compatible storage + SMTP |

---

## 4. Scope

### In Scope

**MVP / v1:**
- User authentication (username/password, invite-only)
- Organization management (admin-managed, single org per user)
- Role-based access control (Admin, Member, Viewer)
- Multiple Kanban boards (private and org-assigned)
- Configurable lanes (create, edit, delete, reorder)
- Rich cards (Markdown title/description, drag-and-drop ordering)
- Card assignments (one or multiple users)
- Comments (Markdown, editable, deletable)
- Checklists (multiple per card, individually assignable items)
- Due dates on cards
- File attachments (S3-compatible storage, path-based)
- In-app notifications (assignments and comments)
- Lightweight polling for update detection
- Trello JSON import tool
- Full-text search (MySQL-based)
- Server-rendered web interface (HTML/CSS/JS, minimal dependencies)
- REST API backend
- Setup documentation and README

**Post-MVP / Should-Have:**
- Personal priority list (per-user "work on next" view across all boards — see 7.15)
- Labels/tags on cards
- Due date notifications
- File previews (image thumbnails, PDF preview)

**Future / Nice-to-Have:**
- Web-based onboarding wizard for first-time setup
- Flutter mobile applications (Android and iOS)
- WebSocket-based real-time updates
- OAuth/SSO authentication
- Multi-language support (i18n)
- WCAG 2.1 AAA compliance
- Lighthouse score 100%
- Docker deployment option

### Out of Scope

- Backup and disaster recovery tooling (responsibility of infrastructure maintainer)
- Hosted/cloud offering
- Gantt charts, time tracking, or advanced project management features
- Email notifications
- Self-registration / public sign-up
- Import from non-Trello sources (Jira, Asana, etc.)

### Future Considerations

- The architecture must not hardcode strings, enabling future localization (i18n)
- Search infrastructure should be replaceable (MySQL full-text now, potentially Elasticsearch later)
- Authentication system should be extensible for OAuth/SSO
- Per-user board invitations and board-level roles (beyond org-based visibility)
- Users belonging to multiple organizations

---

## 5. Stakeholders

| Stakeholder | Role | Concerns |
|---|---|---|
| Project Owner / Developer | Solo developer, primary decision maker | Maintainability, minimal dependencies, code quality |
| End Users (Team Members) | Daily users of the Kanban boards | Usability, performance, feature parity with Trello |
| System Administrators | Deploy and maintain the instance | Easy setup, clear documentation, stable operation |
| Open Source Community | Potential contributors and users | Clear code, MIT license, good documentation |

---

## 6. User Personas / Actors

### System Admin

- **Role**: Manages the Shuffle instance
- **Responsibilities**: Invites users, manages organizations, assigns roles
- **Technical proficiency**: Comfortable with server administration
- **Access pattern**: Infrequent, configuration-focused

### Board Admin

- **Role**: Manages one or more boards within the system
- **Responsibilities**: Creates boards, configures lanes, manages board access
- **Technical proficiency**: General computer user
- **Access pattern**: Regular, organizational

### Team Member

- **Role**: Day-to-day task management user
- **Responsibilities**: Creates and manages cards, comments, checks off tasks, uploads files
- **Technical proficiency**: General computer user
- **Access pattern**: Frequent, daily use, typically picks first assigned card from a lane

### Viewer

- **Role**: Read-only stakeholder
- **Responsibilities**: Reviews board progress, reads card details
- **Technical proficiency**: Any level
- **Access pattern**: Periodic review

---

## 7. Functional Requirements

### 7.1 Authentication & User Management

| ID | Requirement | Priority |
|---|---|---|
| AUTH-01 | System shall support username/password authentication | Must-have |
| AUTH-02 | Only system admins can invite new users to the system | Must-have |
| AUTH-03 | Admin invites users via email; invited users receive an email with a link to set their password | Must-have |
| AUTH-03a | System requires SMTP configuration for sending invitation emails | Must-have |
| AUTH-04 | Users can update their own name and email | Must-have |
| AUTH-05 | System admin can deactivate or remove users | Must-have |
| AUTH-06 | OAuth/SSO authentication (Google, GitHub, SAML) | Future |

### 7.2 Organizations

| ID | Requirement | Priority |
|---|---|---|
| ORG-01 | System admins can create and manage organizations | Must-have |
| ORG-02 | System admins assign users to organizations | Must-have |
| ORG-03 | A user belongs to exactly one organization | Must-have |
| ORG-04 | Organizations serve as a grouping mechanism for boards and users | Must-have |

### 7.3 Role-Based Access Control

| ID | Requirement | Priority |
|---|---|---|
| RBAC-01 | Three roles: Admin, Member, Viewer | Must-have |
| RBAC-02 | **Admin**: Full access — manage boards, lanes, cards, users, organizations | Must-have |
| RBAC-03 | **Member**: Create and edit boards, lanes, cards, comments, attachments, checklists | Must-have |
| RBAC-04 | **Viewer**: Read-only access to boards and cards they have access to | Must-have |
| RBAC-05 | Viewers cannot modify lanes, cards, or any board content | Must-have |

### 7.4 Boards

| ID | Requirement | Priority |
|---|---|---|
| BOARD-01 | Users (Admin, Member) can create new boards | Must-have |
| BOARD-02 | Boards have a title and optional description | Must-have |
| BOARD-03 | Boards can be **private** (accessible only to the creator) | Must-have |
| BOARD-04 | Boards can be **assigned to one or more organizations** (accessible to all members of those organizations) | Must-have |
| BOARD-04a | Users can only see boards belonging to their organization or boards explicitly shared with their organization | Must-have |
| BOARD-04b | **Strict board isolation** — no information about inaccessible boards is leaked to unauthorized users | Must-have |
| BOARD-05 | No system limit on the number of boards | Must-have |
| BOARD-06 | Boards can be archived or deleted by Admins | Must-have |
| BOARD-06a | Board deletion is surfaced in the **board listing UI** (admin only). The destructive action requires an explicit confirmation that states the board title and the number of cards that will be deleted (including archived cards, which cascade). A board **without any cards** can be deleted in a single step; a board **with cards** shows a stronger, two-step confirmation (a warning about the card count) before the call proceeds | Must-have |
| BOARD-06b | Board listing responses (UI and `GET /v1/boards`) include a per-board **`card_count`** (all cards in the board, archived included) so the confirmation surface can warn about the blast radius without an extra round-trip | Must-have |
| BOARD-06c | Board archive/restore is surfaced in the **board listing UI**, in the **edit-board modal**, admin-only (mirrors the card-edit page: *Archive* soft action, left of the red *Delete*). Archiving is a reversible "off the rack" — it does **not** delete data and does **not** require a destructive confirmation. Archiving a board immediately **removes it from the user's default board list** (admin still sees it under the existing "Show archived" toggle) | Must-have |
| BOARD-06d | An **archived board's cards must not appear in any user's Personal Priority List** — neither the computed inbox (already true via PRIO-08) nor the prioritized lane, for any user. A member's already-prioritized cards on a now-archived board leave their priority list; if the board is later **restored**, those cards may re-enter the inbox (recomputed live) but are **not** auto-returned to the prioritized lane | Must-have |

### 7.5 Lanes (Columns)

| ID | Requirement | Priority |
|---|---|---|
| LANE-01 | Admins and Members can create lanes within a board | Must-have |
| LANE-02 | Lanes have a title | Must-have |
| LANE-03 | Lanes can be renamed by Admins and Members | Must-have |
| LANE-04 | Lanes can be deleted by Admins and Members | Must-have |
| LANE-05 | Lanes can be reordered, but the interaction must require deliberate action (e.g., menu/modal, not casual drag-and-drop) to prevent accidental reordering | Must-have |
| LANE-06 | Typical usage is 5-20 lanes per board; no hard limit enforced | Must-have |
| LANE-07 | A lane can have an **optional icon** (a single emoji) in addition to its title. The icon may be set at creation, updated later, or removed (null) | Must-have |
| LANE-08 | Lane icons are stored and returned by the API as a distinct field (`icon`), independent of the title. The icon must be validated to be a **single emoji** (base pictographic character plus standard modifiers — variation selectors, keycap, ZWJ, skin tone); anything else is rejected with a validation error | Must-have |
| LANE-09 | Every **newly created board** is seeded with the standard 11-lane set (Inbox, Resources, Backlog, Up Next, In Progress, Blocked, In Review, Waiting for release, QA, Done, Won't fix), each with its canonical icon, in that order | Must-have |
| LANE-10 | The add-lane UI offers a **template dropdown**: selecting a standard lane (Inbox, Resources, … Won't fix) **prepopulates title and icon** from that template; both remain freely editable. A "Custom lane" option and free icon input preserve arbitrary lane creation | Should-have |
| LANE-11 | The add-lane UI offers an **emoji picker** (curated grid) so an icon can be chosen by click instead of typed; picking one fills the icon input, which remains editable | Should-have |

### 7.6 Cards

| ID | Requirement | Priority |
|---|---|---|
| CARD-01 | Admins and Members can create cards within a lane | Must-have |
| CARD-02 | Cards have a **title** (plain text) | Must-have |
| CARD-03 | Cards have a **description** (Markdown with rich text rendering) | Must-have |
| CARD-04 | Cards can be **assigned to one or multiple users** | Must-have |
| CARD-05 | Cards have a **due date** field | Must-have |
| CARD-06 | Cards can be **drag-and-drop reordered** within a lane | Must-have |
| CARD-07 | Cards can be **drag-and-drop moved between lanes** | Must-have |
| CARD-08 | Cards can be archived or deleted | Must-have |
| CARD-09 | Cards support **labels/tags** with colors for categorization | Should-have |

### 7.7 Comments

| ID | Requirement | Priority |
|---|---|---|
| COMMENT-01 | Admins and Members can add comments to cards | Must-have |
| COMMENT-02 | Comments support **Markdown** formatting | Must-have |
| COMMENT-03 | Comment authors can **edit** their own comments | Must-have |
| COMMENT-04 | Comment authors and Admins can **delete** comments | Must-have |
| COMMENT-05 | Comments display author name and timestamp | Must-have |

### 7.8 Checklists

| ID | Requirement | Priority |
|---|---|---|
| CHECK-01 | A card can have **multiple checklists** | Must-have |
| CHECK-02 | Each checklist has a title | Must-have |
| CHECK-03 | Checklist items can be **checked/unchecked** to indicate completion | Must-have |
| CHECK-04 | Checklist items can be **individually assigned** to users | Must-have |
| CHECK-05 | Checklist items can be added, edited, deleted, and reordered | Must-have |
| CHECK-06 | Display progress indicator (e.g., "3/5 completed") | Must-have |

### 7.9 File Attachments

| ID | Requirement | Priority |
|---|---|---|
| FILE-01 | Admins and Members can attach files to cards | Must-have |
| FILE-02 | Files are stored in **S3-compatible storage** using path-based access (no region dependency) | Must-have |
| FILE-02a | **No hard limit on file size** — uploads use chunked/multipart upload to S3 to support files ranging from kilobytes to gigabytes | Must-have |
| FILE-02b | **Upload progress indicator** required for large file uploads | Must-have |
| FILE-03 | Users can download attached files | Must-have |
| FILE-04 | Users can delete their own attachments; Admins can delete any | Must-have |
| FILE-05 | Display file name, size, and upload date | Must-have |
| FILE-06 | **Image thumbnail previews** on cards | Nice-to-have |
| FILE-07 | **PDF preview** inline | Nice-to-have |

### 7.10 Notifications

| ID | Requirement | Priority |
|---|---|---|
| NOTIF-01 | In-app notifications when a user is **assigned to a card** | Must-have |
| NOTIF-02 | In-app notifications when someone **comments on a card** the user is assigned to | Must-have |
| NOTIF-03 | Users can view and dismiss notifications | Must-have |
| NOTIF-04 | Unread notification count visible in UI | Must-have |
| NOTIF-05 | **Due date reminder** notifications | Nice-to-have |
| NOTIF-06 | Email notifications | Future |

### 7.11 Search

| ID | Requirement | Priority |
|---|---|---|
| SEARCH-01 | Users can search across cards (title, description) within a board | Must-have |
| SEARCH-02 | Search uses MySQL full-text search | Must-have |
| SEARCH-03 | Search results link directly to the matching card | Must-have |
| SEARCH-04 | **Archived cards/boards are included** in search results, clearly marked as archived | Must-have |
| SEARCH-05 | Cross-board search | Should-have |

### 7.12 Trello Import

| ID | Requirement | Priority |
|---|---|---|
| IMPORT-01 | Import tool accepts **Trello JSON export** files | Must-have |
| IMPORT-02 | Import preserves **board structure** (boards and lanes) | Must-have |
| IMPORT-03 | Import preserves **cards** (title, description, position, due dates) | Must-have |
| IMPORT-04 | Import preserves **comments** with author attribution | Must-have |
| IMPORT-05 | Import preserves **checklists** and checklist item state | Must-have |
| IMPORT-06 | Import **downloads attachments** from Trello and re-uploads to S3 | Must-have |
| IMPORT-07 | Import creates **placeholder users** for Trello users not yet in the system | Must-have |
| IMPORT-08 | Admins can later invite and activate placeholder users, inheriting their imported data | Must-have |
| IMPORT-09 | Import tool is a **CLI script** designed as a one-time migration tool, supports multiple runs for additional boards | Must-have |
| IMPORT-10 | Import of activity history | Nice-to-have |

### 7.13 Real-Time Updates

| ID | Requirement | Priority |
|---|---|---|
| RT-01 | Web client uses **lightweight polling** to detect changes made by other users | Must-have |
| RT-02 | Polling should be efficient — check for update availability before fetching full data | Must-have |
| RT-03 | WebSocket-based real-time push updates | Future |

### 7.14 Onboarding Wizard

A web-based setup wizard displayed on first visit when the application detects no existing configuration. Designed to lower the barrier to adoption by guiding a new administrator through the complete initial setup without requiring manual file editing or CLI interaction.

| ID | Requirement | Priority |
|---|---|---|
| ONBOARD-01 | When no valid configuration exists, the application redirects all requests to the onboarding wizard | Nice-to-have |
| ONBOARD-02 | **Step 1 — MySQL connection**: Wizard prompts for host, port, database name, username, and password; validates the connection before proceeding | Nice-to-have |
| ONBOARD-03 | **Step 2 — Database initialization**: Wizard creates the required database schema (tables, indexes) automatically after successful MySQL connection | Nice-to-have |
| ONBOARD-04 | **Step 3 — S3 storage**: Wizard prompts for endpoint URL, bucket name, access key, and secret key; validates connectivity and write access before proceeding | Nice-to-have |
| ONBOARD-05 | **Step 4 — SMTP configuration**: Wizard prompts for SMTP host, port, encryption (TLS/STARTTLS), username, and password; optionally sends a test email to verify | Nice-to-have |
| ONBOARD-06 | **Step 5 — Admin account creation**: Wizard prompts for admin username, name, email, and password; creates the first system admin user | Nice-to-have |
| ONBOARD-07 | **Step 6 — First organization**: Wizard prompts for organization name; creates the initial organization and assigns the admin to it | Nice-to-have |
| ONBOARD-08 | Wizard writes validated configuration to the server config file upon completion | Nice-to-have |
| ONBOARD-09 | Wizard is only accessible when no configuration exists; once setup is complete, the wizard endpoint is disabled | Nice-to-have |
| ONBOARD-10 | Each step validates input and provides clear error messages on failure (e.g., "Cannot connect to MySQL: Access denied") | Nice-to-have |
| ONBOARD-11 | Wizard must be usable without JavaScript (progressive enhancement) to align with the server-rendered approach | Nice-to-have |

### 7.15 Personal Priority List

A per-user "what do I work on next" view, spanning all boards the user can access. The priority list is a **view and a personal ordering**, never a source of truth: cards live and change only in their own boards, and the list always reflects each card's live board state.

**Design decisions (locked):**

- **No mirroring, no copies.** The list stores only (user → card) membership and per-user order. Title, description, lane, assignees, comments — everything — is read live from the card's board.
- **Per-user.** Each user maintains their own list independently; there is no shared state between users, and no concept of a "team priority list."
- **Two sections:**
  - **Inbox** — cards **assigned to the user** (across all accessible boards), **not in a Done lane**, that the user has **not** yet prioritized. Sorted by priority tier, then by the card's in-board order (see PRIO-04).
  - **Prioritized** — cards the user has pulled from the inbox, in a **custom per-user order** that is persisted and reorderable.

| ID | Requirement | Priority |
|---|---|---|
| PRIO-01 | Every authenticated user (Admin, Member, Viewer) can view a **personal priority list** spanning all boards they can access. The entry point is a top-level navigation item, not per-board | Must-have |
| PRIO-02 | The priority list is **per-user**: each user's memberships and ordering are private to that user; one user's additions, removals, or reorderings never affect another user's list | Must-have |
| PRIO-03 | **Inbox section**: contains exactly the cards that are (a) assigned to the current user, (b) on a board the user can access, (c) **not in a Done lane**, (d) **not already in the user's prioritized section**. Cards already prioritized do not appear in the inbox | Must-have |
| PRIO-04 | Inbox ordering: **tier 1** = cards in an In Progress lane, **tier 2** = cards in an Inbox lane, **tier 3** = cards in any other non-Done lane. Within a tier, cards keep their **in-board position order** (lane order first, then position within lane); cards from different boards at the same tier/position are stable-merged in board-creation order. "In Progress" and "Inbox" are matched case-insensitively by lane title (lane icons are not used for matching; a lane named "In Progress (Web)" still counts) | Must-have |
| PRIO-05 | Users can **add** an inbox card to their prioritized section and **remove** a card from it (revert to inbox). The card's board state is never modified by these actions | Must-have |
| PRIO-06 | Users can **reorder** the prioritized section freely; the ordering is persisted per user and survives page reloads and logouts | Must-have |
| PRIO-07 | Every item links to its **card on its board** (clicking opens the card's board view, not a copy). The item also shows the card's **live lane** with its emoji icon + title, and a **state emoji marker** derived from the live lane (In Progress → 🔨, Inbox → 📥, Done → ✅, others → the lane's own icon or a neutral marker) so list state is visible at a glance | Must-have |
| PRIO-08 | **Read-only elsewhere**: the priority list provides no editing surface for card content (title, description, assignees, comments, checklists, attachments). All such edits happen on the board; the list re-reads live data on every load. Board-level mutations (card moved to Done, card deleted, board access removed) are reflected automatically: such cards vanish from both sections on next load, with no stale rows persisting in a way that breaks the page | Must-have |
| PRIO-09 | Cards in **Done lanes never appear** in the inbox (v1); a card the user already prioritized that is later moved to a Done lane remains visible in the prioritized section marked as Done (the user may remove it), but no *new* Done cards ever surface in the inbox | Must-have |
| PRIO-10 | The view is keyboard- and screen-reader-accessible (WCAG 2.1 AA): both sections announce their counts, all actions are reachable by keyboard with visible focus, reordering uses accessible controls (buttons or drag with a keyboard alternative) | Must-have |
| PRIO-11 | API: the priority list is available over REST — `GET /v1/priority` (inbox + prioritized in one payload), `POST /v1/priority/inbox/{cardId}` (add to prioritized), `DELETE /v1/priority/inbox/{cardId}` (remove from prioritized), `PUT /v1/priority/position` ({cardId, afterCardId|null}) (reorder). All mutations CSRF-protected, all reads board-access-checked per item | Must-have |

---

## 8. Non-Functional Requirements

### 8.1 Performance

| ID | Requirement | Target |
|---|---|---|
| PERF-01 | Google Lighthouse score (Performance, Accessibility, Best Practices, SEO) | >= 95% at MVP, iterating toward 100% |
| PERF-02 | Page load time for board view | Reasonable for the number of cards displayed |
| PERF-03 | Drag-and-drop interactions | Smooth, no perceptible lag |
| PERF-04 | Polling overhead | Minimal — should not degrade user experience or server performance |

### 8.2 Security

| ID | Requirement | Priority |
|---|---|---|
| SEC-01 | Passwords must be securely hashed (e.g., bcrypt, Argon2) | Must-have |
| SEC-02 | Session management with secure, HTTP-only cookies | Must-have |
| SEC-03 | CSRF protection on all state-changing operations | Must-have |
| SEC-04 | Input sanitization to prevent XSS (especially important with Markdown rendering); use **Parsedown** (single-file inclusion, no Composer) for Markdown parsing | Must-have |
| SEC-05 | SQL injection prevention via parameterized queries | Must-have |
| SEC-06 | File upload validation (type, size) | Must-have |
| SEC-07 | Access control enforcement — users can only access boards/cards they have permission for | Must-have |
| SEC-08 | S3 access credentials securely stored, not exposed to clients | Must-have |

### 8.3 Availability & Reliability

| ID | Requirement | Notes |
|---|---|---|
| AVAIL-01 | System should operate reliably on a single Debian server | Primary deployment target |
| AVAIL-02 | Graceful error handling — system should not crash on malformed input | Must-have |
| AVAIL-03 | Backup and disaster recovery are the responsibility of the infrastructure maintainer | Out of scope |

### 8.4 Usability

| ID | Requirement | Priority |
|---|---|---|
| UX-01 | **WCAG 2.1 AA** compliance | Must-have |
| UX-02 | WCAG 2.1 AAA compliance where feasible | Stretch goal |
| UX-03 | Intuitive Kanban board interaction (familiar to Trello users) | Must-have |
| UX-04 | Lane reordering requires deliberate action to prevent accidental changes | Must-have |
| UX-05 | Clean, uncluttered interface | Must-have |

### 8.5 Maintainability

| ID | Requirement | Notes |
|---|---|---|
| MAINT-01 | Codebase should favor in-project code over external dependencies | Core philosophy |
| MAINT-02 | Clear code structure suitable for a solo developer | Must-have |
| MAINT-03 | Comprehensive README with setup instructions | Must-have |
| MAINT-04 | No hardcoded strings — text must be externalizable for future i18n | Must-have |
| MAINT-05 | REST API documented (at minimum in code comments or a simple spec) | Must-have |

---

## 9. Data Requirements

### 9.1 Data Entities

| Entity | Key Attributes |
|---|---|
| **User** | id, username, password_hash, name, email, role, organization_id, is_placeholder, status (active/inactive) |
| **Organization** | id, name |
| **Board** | id, title, description, visibility (private/organization), created_by, created_at |
| **Board Organization** | board_id, organization_id |
| **Lane** | id, board_id, title, position |
| **Card** | id, lane_id, title, description (Markdown), due_date, position, created_by, created_at, updated_at |
| **Card Assignment** | card_id, user_id |
| **Comment** | id, card_id, user_id, body (Markdown), created_at, updated_at |
| **Checklist** | id, card_id, title, position |
| **Checklist Item** | id, checklist_id, title, is_checked, assigned_user_id, position |
| **Attachment** | id, card_id, user_id, file_name, file_size, s3_path, mime_type, uploaded_at |
| **Notification** | id, user_id, type, reference_id, message, is_read, created_at |
| **Label** | id, board_id, name, color |
| **Card Label** | card_id, label_id |

### 9.2 Key Relationships

- A **User** belongs to exactly one **Organization**
- A **Board** can be shared with one or more **Organizations** (many-to-many via Board Organization)
- A **Board** has many **Lanes**, ordered by position
- A **Lane** has many **Cards**, ordered by position
- A **Card** has many **Comments**, **Checklists**, **Attachments**, and **Assignments**
- A **Checklist** has many **Checklist Items**, ordered by position
- A **Card** can have many **Labels** (many-to-many via Card Label)

### 9.3 Data Volume

- No predefined upper limit on users, boards, or cards
- Typical board: 5-20 lanes
- File attachments stored externally in S3 (database stores metadata only)
- Growth is organic — hobby project scaling to team use

### 9.4 Data Quality

- Markdown content validated for rendering safety (XSS prevention)
- File uploads validated for type and size
- Referential integrity enforced at the database level

### 9.5 Data Migration

- Trello JSON import (see Section 7.12)
- Placeholder users created during import
- Attachments re-uploaded from Trello to local S3 storage
- Multiple import runs supported for incremental board migration

---

## 10. Integration Requirements

### 10.1 S3-Compatible Storage

| Aspect | Detail |
|---|---|
| **Purpose** | File attachment storage |
| **Protocol** | S3 API (path-based access, no virtual-hosted-style) |
| **Compatibility** | Must work with Ceph RGW, MinIO, Garage, or similar self-hosted S3 alternatives |
| **Authentication** | Access key / secret key |
| **Configuration** | Endpoint URL, bucket name, access credentials stored in server config |

### 10.2 Trello API (Import Only)

| Aspect | Detail |
|---|---|
| **Purpose** | One-time data migration |
| **Input** | Trello JSON export file (manually exported by user) |
| **Network access** | Required during import to download attachments from Trello CDN |
| **Authentication** | None required — works with exported JSON file |

### 10.3 REST API (Internal)

| Aspect | Detail |
|---|---|
| **Purpose** | Serve data to web frontend (where appropriate) and future mobile apps |
| **Format** | JSON |
| **Authentication** | Session-based (cookies) for web; token-based for future mobile |
| **Documentation** | In-code documentation minimum, formal API spec as a should-have |

---

## 11. Constraints

### 11.1 Technical Constraints

| Constraint | Detail |
|---|---|
| **Backend language** | PHP 8.4 (plain, no framework, no Composer) |
| **Frontend** | Vanilla HTML, CSS, JavaScript — minimal external dependencies |
| **Mobile** | Flutter (future, post-web) |
| **Database** | MySQL |
| **File storage** | S3-compatible, path-based |
| **Deployment target** | Bare metal Debian Trixie (13) server (primary) |
| **Rendering approach** | Server-rendered HTML where optimal for web; REST API for data consumption by other platforms |
| **Platform-specific code** | Preferred over generic abstractions — each platform served optimally |
| **Dependency philosophy** | Minimize external dependencies; prefer in-project code |

### 11.2 Business Constraints

| Constraint | Detail |
|---|---|
| **Team size** | Solo developer + Claude Code CLI |
| **Timeline** | No hard deadline; hobby project |
| **Budget** | Infrastructure costs only |
| **License** | MIT — all code and dependencies must be MIT-compatible |

### 11.3 Regulatory / Compliance Constraints

| Constraint | Detail |
|---|---|
| **Accessibility** | WCAG 2.1 AA (mandatory), AAA (stretch goal) |
| **Data privacy** | Self-hosted by design; data stays on owner's infrastructure |

---

## 12. Assumptions

1. The server environment runs Debian Trixie (13) with PHP 8.4 and MySQL available (or the README will document installation)
2. S3-compatible storage is provisioned and accessible from the server
3. SMTP server is available for sending invitation emails
4. Users have modern web browsers (latest 2 versions of major browsers)
5. Trello JSON export format remains stable for the import tool
6. The solo developer has sufficient PHP, HTML/CSS/JS, and Flutter knowledge
7. Network access is available during Trello import to download attachments
8. DNS and HTTPS termination are handled outside the application (e.g., via reverse proxy)

---

## 13. Dependencies

| Dependency | Type | Status |
|---|---|---|
| PHP 8.4 runtime | Technical | Required — part of server setup |
| SMTP server | Technical | Required — for invitation emails |
| MySQL server | Technical | Required — part of server setup |
| S3-compatible storage | Technical | Available |
| Debian server | Infrastructure | Required — part of setup |
| Trello JSON export | Data | User-provided for migration |
| Web browser (modern) | Client | Assumed |
| Claude Code CLI | Development tool | Available |

---

## 14. Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| **Scope creep** — feature requests expand beyond task management | Medium | High | Strict priority tiers; MVP-first approach |
| **Solo developer bottleneck** — all knowledge in one person | High | Medium | Clean code, good documentation, open-source community potential |
| **Trello export format changes** — breaking the import tool | Low | Medium | Document supported Trello export version; isolate import code |
| **Markdown XSS vulnerabilities** — rendering user-supplied Markdown | Medium | High | Use Parsedown (battle-tested, single-file, MIT) with output sanitization |
| **S3 compatibility issues** — path-based access varies between providers | Low | Medium | Test against the Ceph RGW bucket as reference implementation |
| **Performance at scale** — MySQL full-text search and polling under load | Low (initially) | Medium | Acceptable for current scale; architecture allows future replacement |
| **Stakeholder adoption** — users may resist moving from Trello | Medium | High | Full-featured web UI before pitch; smooth Trello import preserving all data |

---

## 15. Success Criteria

### MVP Success

- [ ] A system admin can invite users and manage organizations
- [ ] Users can create boards with configurable lanes
- [ ] Users can create, edit, move, and reorder cards with drag-and-drop
- [ ] Cards support Markdown descriptions, comments, checklists, assignments, due dates, and file attachments
- [ ] Trello boards can be imported with all critical data preserved
- [ ] The web interface achieves a Lighthouse score >= 95%
- [ ] The application meets WCAG 2.1 AA standards
- [ ] The system runs reliably on a single Debian Trixie server with MySQL, S3, and SMTP

### Adoption Success

- [ ] Stakeholders agree the system is a viable Trello replacement
- [ ] Team migrates daily workflow to Shuffle
- [ ] Mobile apps (Flutter) are available for on-the-go access

### Long-Term Success

- [ ] Open-source community contributes improvements
- [ ] System supports multiple languages
- [ ] Lighthouse score reaches 100%

---

## 16. Resolved Questions

All open questions have been resolved during requirements gathering.

| # | Question | Resolution |
|---|---|---|
| 1 | What is the maximum acceptable file upload size for attachments? | **No hard limit.** Files range from KB to GB. Use chunked/multipart upload to S3 with progress indicator. |
| 2 | Should there be a board-level role system? | **No.** Roles are system-wide (Admin, Member, Viewer). Board visibility is per-organization. Boards can be shared with multiple organizations. Per-user board roles may be added in the future. |
| 3 | How should the invite flow work? | **Email with set-password link.** Admin enters user email, system sends invite via SMTP. SMTP configuration is a setup requirement. |
| 4 | Should archived cards/boards be searchable? | **Yes.** Archived content appears in search results but is clearly marked as archived. |
| 5 | Markdown parsing library preference? | **Parsedown** — single-file inclusion, MIT licensed, no Composer. Balances security with minimal-dependency philosophy. |
| 6 | Trello import: CLI or web-based? | **CLI script.** One-time migration tool run from the server command line. |
| 7 | What PHP version is the minimum supported? | **PHP 8.4** on Debian Trixie (13). |
| 8 | Should the polling interval be configurable? | **No.** Hardcoded interval — implementation detail for the architect. |

---

## 17. Appendices

### A. Glossary

| Term | Definition |
|---|---|
| **Board** | A collection of lanes representing a project or workflow |
| **Lane** | A vertical column on a board representing a stage (e.g., "To Do", "In Progress", "Done") |
| **Card** | A task or work item that lives within a lane |
| **Checklist** | A list of sub-tasks within a card that can be individually checked off |
| **Placeholder user** | A user account created during Trello import, representing a Trello user not yet invited to the system |
| **S3-compatible storage** | Object storage that implements the Amazon S3 API (e.g., Ceph RGW, MinIO, Garage) |
| **Polling** | A technique where the client periodically checks the server for updates |

### B. Priority Legend

| Priority | Meaning |
|---|---|
| **Must-have** | Required for MVP — the system is incomplete without it |
| **Should-have** | Important but can follow shortly after MVP |
| **Nice-to-have** | Valuable but not time-sensitive |
| **Future** | Planned for later phases, not part of near-term development |

### C. Reference Documents

- [Trello JSON Export Format](https://developer.atlassian.com/cloud/trello/rest/)
- [WCAG 2.1 Guidelines](https://www.w3.org/TR/WCAG21/)
- [S3 API Reference](https://docs.aws.amazon.com/AmazonS3/latest/API/)

---

*This document is complete and ready for a Solutions Architect to use as the basis for technical design and implementation planning. All open questions have been resolved (Section 16).*
