# Project Specification: Shuffle

**Version:** 1.0
**Date:** 2026-02-12
**Author:** Solution Architect
**Status:** Draft
**Based on:** REQUIREMENTS.md v1.2
**License:** MIT

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Architecture Overview](#2-architecture-overview)
3. [System Components](#3-system-components)
4. [Data Architecture](#4-data-architecture)
5. [API Specifications](#5-api-specifications)
6. [Security Architecture](#6-security-architecture)
7. [Infrastructure and Deployment](#7-infrastructure-and-deployment)
8. [Integration Points](#8-integration-points)
9. [Testing Strategy](#9-testing-strategy)
10. [Implementation Plan](#10-implementation-plan)
11. [Risks and Mitigations](#11-risks-and-mitigations)
12. [Appendices](#12-appendices)
13. [Appendix F: Accessibility Requirements](#appendix-f-accessibility-requirements)

---

## 1. Executive Summary

### Project Overview

Shuffle is a self-hosted, open-source Kanban task management system designed as a Trello replacement. It provides multiple Kanban boards with configurable lanes, rich Markdown cards, comments, file attachments, checklists, user assignments, due dates, and full-text search. The system runs on a single Debian Trixie server with PHP 8.4, MySQL, S3-compatible storage, and SMTP.

### Key Objectives

1. Deliver a fully functional web-based Kanban board that replaces Trello for day-to-day task management
2. Self-hosted with minimal infrastructure requirements and no external package managers
3. Open-source under MIT license with clean, maintainable code
4. Migrate existing Trello data via a CLI import tool

### Success Criteria

| Metric | Target |
|---|---|
| Google Lighthouse score | >= 95% at MVP |
| Accessibility compliance | WCAG 2.1 AA |
| Trello data migration | Cards, comments, checklists, attachments, board structure preserved |
| Deployment complexity | Single Debian server + MySQL + S3 + SMTP |

---

## 2. Architecture Overview

### 2.1 High-Level Architecture

```
                              +-----------+
                              |  Browser  |
                              +-----+-----+
                                    |
                              HTTPS (TLS terminated by reverse proxy)
                                    |
                          +---------+---------+
                          | Apache/Nginx      |
                          | DocumentRoot:www/ |
                          +---------+---------+
                                    |
               +--------------------+--------------------+
               |                                         |
      Server-Rendered Pages                     REST API v1
      (file-based routing)                  (front-controller)
       www/*.php                             www/v1/index.php
               |                                         |
               +--------------------+--------------------+
                                    |
                          +---------+---------+
                          |  PHP 8.4 Runtime  |
                          |  include/Shuffle |
                          +---------+---------+
                           /        |         \
                  +-------+    +----+----+    +-------+
                  | MySQL |    |    S3    |    | SMTP  |
                  |  DB   |    |  (Ceph)  |    | Server|
                  +-------+    +----------+    +-------+
```

### 2.2 Directory Structure

```
shuffle/
├── bin/                            # CLI scripts
│   └── trello-import.php           # Trello JSON import tool
│
├── doc/                            # Documentation
│   ├── setup.md                    # Installation guide
│   ├── apache.md                   # Apache configuration
│   ├── nginx.md                    # Nginx configuration
│   └── api.md                      # REST API reference
│
├── etc/                            # Configuration
│   ├── config.php                  # Active configuration (gitignored)
│   └── config.example.php          # Configuration template
│
├── include/                        # Shared PHP code (outside web root)
│   ├── bootstrap.php               # Autoloader registration + config loading
│   ├── Shuffle/                   # Application namespace root
│   │   ├── Core/                   # Framework-level classes
│   │   │   ├── Autoloader.php      # PSR-4-style autoloader
│   │   │   ├── Database.php        # Thin PDO wrapper
│   │   │   ├── Session.php         # Custom DB session handler
│   │   │   ├── Router.php          # REST API router
│   │   │   ├── Request.php         # HTTP request abstraction
│   │   │   ├── Response.php        # HTTP response abstraction
│   │   │   ├── Lang.php            # i18n string loader
│   │   │   ├── S3Client.php        # S3-compatible client (Signature V4)
│   │   │   ├── Mailer.php          # SMTP email sender
│   │   │   ├── Csrf.php            # CSRF token management
│   │   │   └── Auth.php            # Authentication + authorization
│   │   │
│   │   ├── Model/                  # Data models (one per entity)
│   │   │   ├── User.php
│   │   │   ├── Organization.php
│   │   │   ├── Board.php
│   │   │   ├── Lane.php
│   │   │   ├── Card.php
│   │   │   ├── Comment.php
│   │   │   ├── Checklist.php
│   │   │   ├── ChecklistItem.php
│   │   │   ├── Attachment.php
│   │   │   ├── Notification.php
│   │   │   └── Label.php
│   │   │
│   │   ├── Service/                # Business logic layer
│   │   │   ├── UserService.php
│   │   │   ├── OrganizationService.php
│   │   │   ├── BoardService.php
│   │   │   ├── LaneService.php
│   │   │   ├── CardService.php
│   │   │   ├── CommentService.php
│   │   │   ├── ChecklistService.php
│   │   │   ├── AttachmentService.php
│   │   │   ├── NotificationService.php
│   │   │   ├── SearchService.php
│   │   │   ├── LabelService.php
│   │   │   └── ImportService.php
│   │   │
│   │   └── Controller/             # REST API controllers
│   │       ├── AuthController.php
│   │       ├── UserController.php
│   │       ├── OrganizationController.php
│   │       ├── BoardController.php
│   │       ├── LaneController.php
│   │       ├── CardController.php
│   │       ├── CommentController.php
│   │       ├── ChecklistController.php
│   │       ├── AttachmentController.php
│   │       ├── NotificationController.php
│   │       ├── SearchController.php
│   │       └── LabelController.php
│   │
│   ├── lang/                       # i18n translation files
│   │   └── en.json                 # English (default locale)
│   │
│   └── vendor/                     # Vendored single-file libraries
│       └── Parsedown.php           # Markdown parser (MIT)
│
├── www/                            # Web root (DocumentRoot)
│   ├── index.php                   # Dashboard / landing page
│   ├── login.php                   # Login page
│   ├── logout.php                  # Logout handler
│   ├── board.php                   # Board view (lanes + cards)
│   ├── card.php                    # Card detail view
│   ├── boards.php                  # Board listing
│   ├── profile.php                 # User profile / settings
│   ├── admin/                      # Admin pages
│   │   ├── users.php               # User management
│   │   ├── invite.php              # Invite user
│   │   └── organizations.php       # Organization management
│   │
│   ├── v1/                         # REST API v1
│   │   ├── index.php               # Front-controller (all API requests)
│   │   └── .htaccess               # Rewrite rules for API routing
│   │
│   ├── css/                        # Stylesheets
│   │   └── app.css                 # Main stylesheet
│   │
│   ├── js/                         # Client-side JavaScript
│   │   ├── app.js                  # Common initialization
│   │   ├── board.js                # Board interactions (drag-and-drop, polling)
│   │   ├── card.js                 # Card detail interactions
│   │   ├── upload.js               # File upload with progress
│   │   └── notifications.js        # Notification polling + display
│   │
│   ├── img/                        # Static images and icons
│   │
│   └── .htaccess                   # Root rewrite rules (if Apache)
│
├── CLAUDE.md
├── REQUIREMENTS.md
├── SPECIFICATION.md
├── LICENSE
└── README.md
```

### 2.3 Key Architectural Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Project structure | `bin/`, `doc/`, `etc/`, `include/`, `www/` | Keeps config, CLI tools, and shared code outside the web root for security |
| Web page routing | File-based in `www/` | Simple, no framework overhead; each page is a self-contained PHP file |
| REST API routing | Front-controller at `www/v1/index.php` | Clean URLs, centralized middleware (auth, CSRF, CORS), versioned endpoint |
| S3 uploads | Proxy through PHP with streaming | Keeps S3 endpoint hidden from clients; PHP streams chunks to avoid memory issues |
| Rendering | Server-rendered HTML + JS sprinkles | Best Lighthouse scores, minimal JS payload, progressive enhancement |
| Polling | Board-level version counter with ETag | Simple, efficient; 304 responses minimize bandwidth when nothing changed |
| Sessions | Custom DB sessions in MySQL | Enables session management (admin revocation, listing active sessions) |
| i18n | JSON files per locale | Simple, no tooling overhead, POEditor-compatible for future translation workflows |
| CSS | Single stylesheet + CSS custom properties | No build step, easy theming, good browser support |
| PHP architecture | OOP with namespaces + autoloader | Clean separation, testable, scales well for a solo developer |
| DB access | Thin PDO wrapper | Centralizes connection management and parameterized queries without ORM overhead |
| Password hashing | Argon2id | PHP 8.4 native, strongest available algorithm |
| Markdown | Parsedown (vendored) | Single-file inclusion, MIT, battle-tested, no Composer |

### 2.4 Request Lifecycle

#### Server-Rendered Pages

```
Browser → GET /board.php?id=42
  → Apache serves www/board.php directly
    → include bootstrap.php (autoloader, config, session)
    → Auth check (redirect to login if needed)
    → Board access check (user's org has access)
    → Query MySQL for board data
    → Render HTML with PHP templates
  ← Full HTML page response
```

#### REST API

```
Browser → POST /v1/cards
  → Apache rewrites to www/v1/index.php
    → include bootstrap.php
    → Router parses method + path → CardController::create()
    → Auth check (session cookie)
    → CSRF check (token header)
    → Access control check
    → Service layer processes business logic
    → Model layer persists to MySQL
    → Bump board version counter
  ← JSON response
```

---

## 3. System Components

### 3.1 Autoloader

**Class:** `Shuffle\Core\Autoloader`
**File:** `include/Shuffle/Core/Autoloader.php`

PSR-4-style autoloader mapping the `Shuffle\` namespace prefix to `include/Shuffle/`.

**Behavior:**
- Registered via `spl_autoload_register()`
- Converts namespace separators (`\`) to directory separators (`/`)
- Appends `.php` extension
- Example: `Shuffle\Model\Board` → `include/Shuffle/Model/Board.php`
- Silently returns if file not found (allows other autoloaders to try)

### 3.2 Bootstrap

**File:** `include/bootstrap.php`

Entry point included by every web page and the API front-controller. Responsibilities:

1. Define `ROOT_DIR` constant pointing to the project root
2. Require and register the autoloader
3. Load configuration from `etc/config.php`
4. Initialize the `Database` singleton
5. Start the custom session handler
6. Initialize the `Lang` instance with the configured locale
7. Initialize the `Auth` instance from the current session

### 3.3 Configuration

**File:** `etc/config.php` (returns a PHP associative array)
**Template:** `etc/config.example.php`

```php
<?php
return [
    'app' => [
        'name'     => 'Shuffle',
        'url'      => 'https://shuffle.example.com',
        'locale'   => 'en',
        'timezone' => 'UTC',
    ],
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'shuffle',
        'user'     => 'shuffle',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],
    's3' => [
        'endpoint'   => 'http://127.0.0.1:9000',
        'bucket'     => 'shuffle',
        'access_key' => '',
        'secret_key' => '',
        'region'     => 'us-east-1',
        'path_style' => true,
    ],
    'smtp' => [
        'host'       => '127.0.0.1',
        'port'       => 587,
        'encryption' => 'tls',
        'username'   => '',
        'password'   => '',
        'from_email' => 'noreply@shuffle.example.com',
        'from_name'  => 'Shuffle',
    ],
    'session' => [
        'lifetime'    => 86400,     // 24 hours
        'cookie_name' => 'shuffle_session',
    ],
    'upload' => [
        'chunk_size'  => 5242880,   // 5 MB chunks for S3 multipart
    ],
    'polling' => [
        'interval' => 15,           // seconds between polls
    ],
];
```

### 3.4 Database Layer

**Class:** `Shuffle\Core\Database`
**File:** `include/Shuffle/Core/Database.php`

Thin PDO wrapper providing:

| Method | Purpose |
|---|---|
| `__construct(array $config)` | Creates PDO connection with `ERRMODE_EXCEPTION`, `utf8mb4` charset |
| `query(string $sql, array $params = []): PDOStatement` | Prepares and executes a parameterized query; returns the statement |
| `fetch(string $sql, array $params = []): ?array` | Returns a single row as an associative array, or `null` |
| `fetchAll(string $sql, array $params = []): array` | Returns all rows as an array of associative arrays |
| `execute(string $sql, array $params = []): int` | Executes a statement; returns affected row count |
| `lastInsertId(): string` | Returns the last auto-increment ID |
| `beginTransaction(): void` | Starts a transaction |
| `commit(): void` | Commits the current transaction |
| `rollBack(): void` | Rolls back the current transaction |
| `getPdo(): PDO` | Returns the underlying PDO instance (for edge cases) |

All queries MUST use parameterized placeholders. No string interpolation in SQL.

### 3.5 Session Manager

**Class:** `Shuffle\Core\Session`
**File:** `include/Shuffle/Core/Session.php`

Implements `SessionHandlerInterface` for MySQL-backed sessions.

**Session table:** `sessions` (see Section 4)

**Behavior:**
- Registered via `session_set_save_handler()` before `session_start()`
- Cookie settings: `HttpOnly`, `Secure` (when HTTPS), `SameSite=Lax`
- Cookie name from config: `shuffle_session`
- Session data serialized by PHP's native handler
- Garbage collection deletes sessions older than `session.lifetime` config
- Provides `destroyByUserId(int $userId)` for admin session revocation

### 3.6 Router

**Class:** `Shuffle\Core\Router`
**File:** `include/Shuffle/Core/Router.php`

Lightweight REST API router used exclusively by the `www/v1/index.php` front-controller.

**Interface:**

```php
$router = new Router();
$router->get('/boards', [BoardController::class, 'index']);
$router->post('/boards', [BoardController::class, 'create']);
$router->get('/boards/{id}', [BoardController::class, 'show']);
$router->put('/boards/{id}', [BoardController::class, 'update']);
$router->delete('/boards/{id}', [BoardController::class, 'delete']);
$router->dispatch($request);
```

**Behavior:**
- Matches HTTP method + path pattern against registered routes
- Extracts path parameters (e.g., `{id}`) as named arguments
- Instantiates the controller class and calls the method
- Returns 404 JSON response if no route matches
- Returns 405 JSON response if the path matches but the method does not

### 3.7 Request and Response

**Class:** `Shuffle\Core\Request`
**File:** `include/Shuffle/Core/Request.php`

Wraps PHP superglobals into a clean interface:

| Method | Purpose |
|---|---|
| `getMethod(): string` | HTTP method (GET, POST, PUT, DELETE) |
| `getPath(): string` | Request path relative to API root |
| `getQuery(string $key, $default = null)` | Query string parameter |
| `getBody(): array` | Parsed JSON request body |
| `getHeader(string $name): ?string` | Request header value |
| `getCookie(string $name): ?string` | Cookie value |
| `getInputStream(): resource` | Raw input stream for file uploads |

**Class:** `Shuffle\Core\Response`
**File:** `include/Shuffle/Core/Response.php`

| Method | Purpose |
|---|---|
| `json(array $data, int $status = 200): void` | Sends JSON response with Content-Type header |
| `error(string $message, int $status): void` | Sends JSON error response: `{"error": "..."}` |
| `noContent(): void` | Sends 204 No Content |
| `notModified(): void` | Sends 304 Not Modified (for polling) |
| `stream(resource $stream, string $contentType, int $size, string $filename): void` | Streams file download |

### 3.8 i18n

**Class:** `Shuffle\Core\Lang`
**File:** `include/Shuffle/Core/Lang.php`

Loads and serves translatable strings from JSON files.

**File format:** `include/lang/{locale}.json`

```json
{
    "app.name": "Shuffle",
    "auth.login": "Log In",
    "auth.logout": "Log Out",
    "auth.username": "Username",
    "auth.password": "Password",
    "board.create": "Create Board",
    "board.title": "Board Title",
    "board.description": "Description",
    "card.due_date": "Due Date",
    "card.assign": "Assign",
    "error.not_found": "Not found",
    "error.forbidden": "Access denied"
}
```

**Interface:**

| Method | Purpose |
|---|---|
| `__construct(string $locale, string $langDir)` | Loads `{langDir}/{locale}.json` |
| `get(string $key, array $params = []): string` | Returns translated string; supports `{0}`, `{1}` placeholders |
| `has(string $key): bool` | Checks if a key exists |

Usage in PHP templates:

```php
<h1><?= $lang->get('board.create') ?></h1>
<p><?= $lang->get('checklist.progress', [$done, $total]) ?></p>
```

String key convention: `{domain}.{action_or_label}` — e.g., `board.create`, `card.due_date`, `error.forbidden`.

### 3.9 S3 Client

**Class:** `Shuffle\Core\S3Client`
**File:** `include/Shuffle/Core/S3Client.php`

Custom S3-compatible client implementing AWS Signature V4 with path-based URLs. No AWS SDK dependency.

**Interface:**

| Method | Purpose |
|---|---|
| `__construct(array $config)` | Accepts `endpoint`, `bucket`, `access_key`, `secret_key`, `region`, `path_style` |
| `putObject(string $key, resource $stream, int $size, string $contentType): void` | Uploads a file using single PUT (for files <= chunk_size) |
| `createMultipartUpload(string $key, string $contentType): string` | Initiates multipart upload; returns upload ID |
| `uploadPart(string $key, string $uploadId, int $partNumber, string $body): string` | Uploads a part; returns ETag |
| `completeMultipartUpload(string $key, string $uploadId, array $parts): void` | Completes multipart upload with list of part ETags |
| `abortMultipartUpload(string $key, string $uploadId): void` | Aborts a failed multipart upload |
| `getObject(string $key): resource` | Returns a readable stream for the object |
| `deleteObject(string $key): void` | Deletes an object |
| `objectExists(string $key): bool` | Checks if an object exists (HEAD request) |

**Signature V4 implementation:**
- Canonical request construction with path-based URLs
- String-to-sign with AWS4-HMAC-SHA256
- Signing key derived from secret + date + region + service
- Authorization header format: `AWS4-HMAC-SHA256 Credential=.../aws4_request, SignedHeaders=..., Signature=...`

**S3 key naming convention:** `{board_id}/{card_id}/{uuid}_{original_filename}`

This prevents collisions and organizes files by board and card for easy bulk operations (e.g., cascading board deletion).

### 3.10 SMTP Mailer

**Class:** `Shuffle\Core\Mailer`
**File:** `include/Shuffle/Core/Mailer.php`

Minimal SMTP client for sending invitation emails. Implements SMTP protocol directly (EHLO, AUTH, MAIL FROM, RCPT TO, DATA) over a socket with TLS/STARTTLS support.

**Interface:**

| Method | Purpose |
|---|---|
| `__construct(array $config)` | Accepts SMTP config (host, port, encryption, username, password, from_email, from_name) |
| `send(string $to, string $subject, string $htmlBody, string $textBody): void` | Sends an email with both HTML and plain-text parts |

**Usage:** Invitation emails only (MVP). The email contains a secure link with a single-use token for the invited user to set their password.

### 3.11 Parsedown Integration

**File:** `include/vendor/Parsedown.php`

The Parsedown library (MIT, single file) is vendored directly into the project. No modifications.

**Usage wrapper function** (in bootstrap or a helper):

```php
function renderMarkdown(string $text): string {
    static $parsedown = null;
    if ($parsedown === null) {
        $parsedown = new Parsedown();
        $parsedown->setSafeMode(true);  // Escapes HTML in input
    }
    return $parsedown->text($text);
}
```

`setSafeMode(true)` is critical — it escapes any raw HTML in user input, preventing XSS through Markdown content.

### 3.12 CSRF Protection

**Class:** `Shuffle\Core\Csrf`
**File:** `include/Shuffle/Core/Csrf.php`

**Strategy:** Per-session CSRF token.

| Method | Purpose |
|---|---|
| `generate(): string` | Generates and stores a token in the session; returns it |
| `validate(string $token): bool` | Validates a token against the session-stored value |
| `getTokenField(): string` | Returns an HTML hidden input field for forms |

**Enforcement:**
- Server-rendered forms include the token as a hidden field
- REST API requests send the token via the `X-CSRF-Token` header
- All state-changing requests (POST, PUT, DELETE) are validated
- GET requests are exempt
- Invalid token returns 403 Forbidden

### 3.13 Authentication and Authorization

**Class:** `Shuffle\Core\Auth`
**File:** `include/Shuffle/Core/Auth.php`

**Interface:**

| Method | Purpose |
|---|---|
| `currentUser(): ?array` | Returns the authenticated user from session, or `null` |
| `login(string $username, string $password): ?array` | Validates credentials; creates session; returns user or `null` |
| `logout(): void` | Destroys current session |
| `requireAuth(): array` | Returns current user or sends 401/redirects to login |
| `requireRole(string $role): array` | Returns current user if role matches or sends 403 |
| `canAccessBoard(int $boardId): bool` | Checks board visibility (private owner or org membership) |
| `isAdmin(): bool` | Shorthand role check |
| `isMember(): bool` | Shorthand: Admin or Member |

**Role hierarchy:**
- **Admin** — Full system access. Can manage users, organizations, all boards.
- **Member** — Can create/edit boards, lanes, cards, comments, checklists, attachments.
- **Viewer** — Read-only access to boards visible to their organization.

**Board access logic:**
```
User can access board IF:
  - Board visibility = 'private' AND board.created_by = user.id
  OR
  - Board visibility = 'organization' AND EXISTS (
      board_organizations record WHERE board_id = board.id
      AND organization_id = user.organization_id
    )
```

**Strict board isolation** (BOARD-04b): All board queries MUST include the access check. No board metadata (title, existence) is ever leaked to unauthorized users. Unauthorized access returns the same 404 response as a non-existent board.

### 3.14 Models

Each model class encapsulates database operations for its entity. Models are data-access objects, not active records — they receive a `Database` instance and return associative arrays.

**Common pattern:**

```php
namespace Shuffle\Model;

class Board {
    public function __construct(private \Shuffle\Core\Database $db) {}

    public function findById(int $id): ?array { ... }
    public function findByUser(int $userId, int $orgId): array { ... }
    public function create(array $data): int { ... }
    public function update(int $id, array $data): void { ... }
    public function delete(int $id): void { ... }
    public function archive(int $id): void { ... }
    public function incrementVersion(int $id): void { ... }
    public function getVersion(int $id): int { ... }
}
```

**Model list:**

| Model | Key Methods Beyond CRUD |
|---|---|
| `User` | `findByUsername()`, `findByInviteToken()`, `findPlaceholders()`, `activate()`, `deactivate()` |
| `Organization` | `findMembers()`, `addMember()`, `removeMember()` |
| `Board` | `findByUser()`, `archive()`, `incrementVersion()`, `getVersion()` |
| `Lane` | `findByBoard()`, `reorder()` |
| `Card` | `findByLane()`, `findByBoard()`, `move()`, `reorder()`, `archive()`, `search()` |
| `Comment` | `findByCard()` |
| `Checklist` | `findByCard()` |
| `ChecklistItem` | `findByChecklist()`, `toggleCheck()`, `reorder()` |
| `Attachment` | `findByCard()` |
| `Notification` | `findByUser()`, `countUnread()`, `markRead()`, `dismiss()` |
| `Label` | `findByBoard()`, `attachToCard()`, `detachFromCard()` |
| `UserPrio` | `findByUser()`, `add()`, `remove()`, `reposition()`, `reorderByCardIds()` |

### 3.15 Services

Services contain business logic, orchestrate model calls, enforce business rules, and trigger side effects (notifications, version bumps).

**Key services:**

**BoardService:**
- `createBoard()` — Creates board, sets creator, assigns to org if requested
- `deleteBoard()` — Cascades: deletes all lanes, cards, comments, checklists, S3 attachments
- `archiveBoard()` — Sets `is_archived = 1`, board remains searchable
- `checkVersion()` — Compares client version with DB version; returns changed/unchanged

**CardService:**
- `createCard()` — Creates card at the bottom of a lane (highest position + gap)
- `moveCard()` — Moves card between lanes, recalculates position
- `reorderCard()` — Updates position within a lane; triggers renumbering if gap < 1

**AttachmentService:**
- `upload()` — Streams file from PHP input to S3 via multipart upload; stores metadata in DB
- `download()` — Streams file from S3 to the client via PHP
- `delete()` — Deletes from S3 and removes DB record

**NotificationService:**
- `notifyAssignment()` — Creates notification when user is assigned to a card
- `notifyComment()` — Creates notifications for all assigned users when a comment is added

**ImportService:**
- `importTrelloBoard()` — Parses Trello JSON, maps to Shuffle entities, creates placeholder users

**PriorityService** (personal priority list, PRIO-01..11):
- `getList()` — Returns the current user's `{inbox, prioritized}` in one pass. Inbox is computed (not stored): cards assigned to the user on accessible boards, non-archived, not in a Done lane, not in the user's `user_prio`; tiered per PRIO-04 (In Progress → Inbox lane → other) and merged stably in board-creation order within tier. Prioritized = `user_prio` joined live to card/lane/board, dropping rows whose card or board is no longer accessible.
- `prioritize(cardId)` — Adds the card to the user's prioritized section (position = max + 1000). Idempotent (already-prioritized card is a no-op success). 404-equivalent if the card is inaccessible or on a Done lane.
- `deprioritize(cardId)` — Removes the membership; the card reappears in the inbox (if it still qualifies). No-op if not a member.
- `reorder(cardId, afterCardId|null)` — Moves a prioritized card relative to another (null = to top) using §4.2 gap logic; renumbers the user's container on a missing gap.
- Every read of a card is board-access-checked for the requesting user; a stale `user_prio` row pointing at an inaccessible/deleted card is surfaced as absent, never as an error page.

### 3.16 API Controllers

Controllers handle HTTP concerns: extract parameters from the request, call the appropriate service method, and return a JSON response.

**Pattern:**

```php
namespace Shuffle\Controller;

class CardController {
    public function __construct(
        private \Shuffle\Service\CardService $cardService,
        private \Shuffle\Core\Auth $auth
    ) {}

    public function create(Request $request, Response $response, array $params): void {
        $user = $this->auth->requireRole('member');
        $body = $request->getBody();
        // Validate input...
        $card = $this->cardService->createCard($body, $user);
        $response->json($card, 201);
    }
}
```

Controllers validate input and return appropriate HTTP status codes. Business rule enforcement is in the service layer.

### 3.17 Web Pages

Server-rendered PHP pages in `www/`. Each page follows this structure:

```php
<?php
// www/board.php
require_once __DIR__ . '/../include/bootstrap.php';

$user = $auth->requireAuth();
$boardId = (int)($_GET['id'] ?? 0);
if (!$auth->canAccessBoard($boardId)) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

// Load data
$board = $boardService->getBoard($boardId);
$lanes = $laneService->getLanesByBoard($boardId);
// ... etc.

// Render
$pageTitle = $board['title'];
require __DIR__ . '/../include/templates/header.php';
?>
<!-- Page-specific HTML here -->
<?php require __DIR__ . '/../include/templates/footer.php'; ?>
```

**Shared templates** (in `include/templates/`):
- `header.php` — HTML doctype, `<head>`, navigation bar, notification bell
- `footer.php` — Footer content, common JS includes, closing tags

### 3.18 JavaScript Modules

All JS files are vanilla JavaScript (ES6+), no build step, loaded with `<script>` tags.

**`js/app.js`** — Common utilities:
- CSRF token management (reads from meta tag, attaches to fetch requests)
- `api()` helper function wrapping `fetch()` with auth headers and error handling
- Flash message display

**`js/board.js`** — Board interactivity:
- Drag-and-drop for cards using the HTML5 Drag and Drop API
- Card reordering within lanes
- Card moving between lanes
- API calls to persist position changes
- Polling loop: fetches board version from API every N seconds; full refresh if changed

**`js/card.js`** — Card detail interactions:
- Markdown preview toggle for description editing
- Comment submission
- Checklist item toggling
- User assignment autocomplete
- Due date picker

**`js/upload.js`** — File upload:
- `XMLHttpRequest` with `upload.onprogress` for progress tracking
- Progress bar UI
- Sends file to PHP upload endpoint (which streams to S3)

**`js/notifications.js`** — Notification system:
- Polls notification count on a timer
- Updates the notification bell badge
- Dropdown panel showing recent notifications
- Mark-as-read and dismiss actions

**`js/priority.js`** — Personal priority list interactions (PRIO-05/06/10):
- Add inbox card to prioritized (`POST /v1/priority/inbox/{id}`) with optimistic move + revert on error
- Remove prioritized card (`DELETE /v1/priority/inbox/{id}`) with optimistic move + revert on error
- Reorder prioritized section: drag-and-drop with a **keyboard alternative** (up/down action buttons on each item, visible focus, ARIA live-region announcements of moves) persisted via `PUT /v1/priority/position`
- Re-fetches `GET /v1/priority` after every successful mutation so the canonical server state re-renders
- Flash messages on success/failure using the shared `api()` helper and i18n strings

### 3.19 CLI Scripts

**`bin/trello-import.php`** — Trello JSON import tool.

```
Usage: php bin/trello-import.php <trello-export.json> [--org=<org_id>]
```

**Behavior:**
1. Parses Trello JSON export file
2. Creates a Shuffle board with matching title/description
3. Maps Trello lists → Shuffle lanes (preserving order)
4. Maps Trello cards → Shuffle cards (title, description, position, due dates)
5. Maps Trello comments → Shuffle comments (preserving author + timestamp)
6. Maps Trello checklists → Shuffle checklists + items (preserving state)
7. Downloads Trello attachments from CDN and re-uploads to S3
8. Creates placeholder users for Trello members not yet in the system
9. Assigns board to the specified organization (or prompts)
10. Supports multiple runs — checks for duplicate boards by Trello board ID stored in metadata

---

## 4. Data Architecture

### 4.1 Database Schema

All tables use InnoDB engine, `utf8mb4` charset, `utf8mb4_unicode_ci` collation.

#### `users`

| Column | Type | Constraints |
|---|---|---|
| `id` | INT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT |
| `username` | VARCHAR(64) | NOT NULL, UNIQUE |
| `password_hash` | VARCHAR(255) | NOT NULL |
| `name` | VARCHAR(128) | NOT NULL |
| `email` | VARCHAR(255) | NOT NULL, UNIQUE |
| `role` | ENUM('admin', 'member', 'viewer') | NOT NULL, DEFAULT 'member' |
| `organization_id` | INT UNSIGNED | NULL, FK → organizations.id |
| `is_placeholder` | TINYINT(1) | NOT NULL, DEFAULT 0 |
| `status` | ENUM('active', 'inactive') | NOT NULL, DEFAULT 'active' |
| `invite_token` | VARCHAR(128) | NULL, UNIQUE |
| `invite_token_expires_at` | DATETIME | NULL |
| `trello_id` | VARCHAR(64) | NULL (used by import) |
| `created_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP |
| `updated_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP |

#### `organizations`

| Column | Type | Constraints |
|---|---|---|
| `id` | INT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT |
| `name` | VARCHAR(128) | NOT NULL |
| `created_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP |
| `updated_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP |

#### `boards`

| Column | Type | Constraints |
|---|---|---|
| `id` | INT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT |
| `title` | VARCHAR(255) | NOT NULL |
| `description` | TEXT | NULL |
| `visibility` | ENUM('private', 'organization') | NOT NULL, DEFAULT 'private' |
| `is_archived` | TINYINT(1) | NOT NULL, DEFAULT 0 |
| `version` | INT UNSIGNED | NOT NULL, DEFAULT 1 |
| `created_by` | INT UNSIGNED | NOT NULL, FK → users.id |
| `trello_id` | VARCHAR(64) | NULL (used by import, UNIQUE when set) |
| `created_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP |
| `updated_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP |

#### `board_organizations`

| Column | Type | Constraints |
|---|---|---|
| `board_id` | INT UNSIGNED | NOT NULL, FK → boards.id ON DELETE CASCADE |
| `organization_id` | INT UNSIGNED | NOT NULL, FK → organizations.id ON DELETE CASCADE |

PRIMARY KEY (`board_id`, `organization_id`)

#### `lanes`

| Column | Type | Constraints |
|---|---|---|
| `id` | INT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT |
| `board_id` | INT UNSIGNED | NOT NULL, FK → boards.id ON DELETE CASCADE |
| `title` | VARCHAR(255) | NOT NULL |
| `icon` | VARCHAR(16) | NULL (single emoji, LANE-07/08) |
| `position` | INT UNSIGNED | NOT NULL |
| `created_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP |
| `updated_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP |

INDEX (`board_id`, `position`)

#### `cards`

| Column | Type | Constraints |
|---|---|---|
| `id` | INT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT |
| `lane_id` | INT UNSIGNED | NOT NULL, FK → lanes.id ON DELETE CASCADE |
| `title` | VARCHAR(255) | NOT NULL |
| `description` | TEXT | NULL |
| `due_date` | DATE | NULL |
| `position` | INT UNSIGNED | NOT NULL |
| `is_archived` | TINYINT(1) | NOT NULL, DEFAULT 0 |
| `created_by` | INT UNSIGNED | NOT NULL, FK → users.id |
| `trello_id` | VARCHAR(64) | NULL |
| `created_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP |
| `updated_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP |

INDEX (`lane_id`, `position`)
FULLTEXT INDEX (`title`, `description`)

#### `card_assignments`

| Column | Type | Constraints |
|---|---|---|
| `card_id` | INT UNSIGNED | NOT NULL, FK → cards.id ON DELETE CASCADE |
| `user_id` | INT UNSIGNED | NOT NULL, FK → users.id ON DELETE CASCADE |

PRIMARY KEY (`card_id`, `user_id`)

#### `comments`

| Column | Type | Constraints |
|---|---|---|
| `id` | INT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT |
| `card_id` | INT UNSIGNED | NOT NULL, FK → cards.id ON DELETE CASCADE |
| `user_id` | INT UNSIGNED | NOT NULL, FK → users.id |
| `body` | TEXT | NOT NULL |
| `created_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP |
| `updated_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP |

INDEX (`card_id`, `created_at`)

#### `checklists`

| Column | Type | Constraints |
|---|---|---|
| `id` | INT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT |
| `card_id` | INT UNSIGNED | NOT NULL, FK → cards.id ON DELETE CASCADE |
| `title` | VARCHAR(255) | NOT NULL |
| `position` | INT UNSIGNED | NOT NULL |
| `created_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP |
| `updated_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP |

INDEX (`card_id`, `position`)

#### `checklist_items`

| Column | Type | Constraints |
|---|---|---|
| `id` | INT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT |
| `checklist_id` | INT UNSIGNED | NOT NULL, FK → checklists.id ON DELETE CASCADE |
| `title` | VARCHAR(255) | NOT NULL |
| `is_checked` | TINYINT(1) | NOT NULL, DEFAULT 0 |
| `assigned_user_id` | INT UNSIGNED | NULL, FK → users.id ON DELETE SET NULL |
| `position` | INT UNSIGNED | NOT NULL |
| `created_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP |
| `updated_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP |

INDEX (`checklist_id`, `position`)

#### `attachments`

| Column | Type | Constraints |
|---|---|---|
| `id` | INT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT |
| `card_id` | INT UNSIGNED | NOT NULL, FK → cards.id ON DELETE CASCADE |
| `user_id` | INT UNSIGNED | NOT NULL, FK → users.id |
| `file_name` | VARCHAR(255) | NOT NULL |
| `file_size` | BIGINT UNSIGNED | NOT NULL |
| `s3_key` | VARCHAR(512) | NOT NULL |
| `mime_type` | VARCHAR(128) | NOT NULL |
| `uploaded_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP |

INDEX (`card_id`)

#### `notifications`

| Column | Type | Constraints |
|---|---|---|
| `id` | INT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT |
| `user_id` | INT UNSIGNED | NOT NULL, FK → users.id ON DELETE CASCADE |
| `type` | ENUM('assignment', 'comment') | NOT NULL |
| `reference_id` | INT UNSIGNED | NOT NULL |
| `message` | VARCHAR(512) | NOT NULL |
| `is_read` | TINYINT(1) | NOT NULL, DEFAULT 0 |
| `created_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP |

INDEX (`user_id`, `is_read`, `created_at`)

#### `labels`

| Column | Type | Constraints |
|---|---|---|
| `id` | INT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT |
| `board_id` | INT UNSIGNED | NOT NULL, FK → boards.id ON DELETE CASCADE |
| `name` | VARCHAR(64) | NOT NULL |
| `color` | VARCHAR(7) | NOT NULL (hex, e.g., `#FF5733`) |
| `created_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP |

INDEX (`board_id`)

#### `card_labels`

| Column | Type | Constraints |
|---|---|---|
| `card_id` | INT UNSIGNED | NOT NULL, FK → cards.id ON DELETE CASCADE |
| `label_id` | INT UNSIGNED | NOT NULL, FK → labels.id ON DELETE CASCADE |

PRIMARY KEY (`card_id`, `label_id`)

#### `sessions`

| Column | Type | Constraints |
|---|---|---|
| `id` | VARCHAR(128) | PRIMARY KEY |
| `user_id` | INT UNSIGNED | NULL, FK → users.id ON DELETE CASCADE |
| `data` | TEXT | NOT NULL |
| `last_activity` | DATETIME | NOT NULL |
| `created_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP |

INDEX (`user_id`)
INDEX (`last_activity`)

#### `user_prio`

| Column | Type | Constraints |
|---|---|---|
| `id` | INT UNSIGNED | PRIMARY KEY, AUTO_INCREMENT |
| `user_id` | INT UNSIGNED | NOT NULL, FK → users.id ON DELETE CASCADE |
| `card_id` | INT UNSIGNED | NOT NULL, FK → cards.id ON DELETE CASCADE |
| `position` | INT UNSIGNED | NOT NULL |
| `added_at` | DATETIME | NOT NULL, DEFAULT CURRENT_TIMESTAMP |

UNIQUE KEY (`user_id`, `card_id`)
INDEX (`user_id`, `position`)

Per-user priority list membership (PRIO-01..11). Stores **only** (user, card) pairs and the user's custom order — no other card data is duplicated here. `card_id` cascade-deletes with the card, `user_id` cascade-deletes with the user. The `position` column uses the same gap-based scheme as §4.2 (gap 1000) within the per-user container.

### 4.2 Position Management Strategy

Entities with user-controlled ordering (lanes, cards, checklist items, and **user priority entries**) use an integer `position` column with a gap-based numbering scheme.

**Initial assignment:** New items get `position = (max_position_in_container + 1000)`, or `1000` if the container is empty.

**Reordering (insert between):** When moving an item between two existing items with positions A and B, the new position is `floor((A + B) / 2)`.

**Renumbering trigger:** If a calculated position would equal an adjacent position (gap < 1), all items in the container are renumbered with a gap of 1000 in a single transaction.

**Drag-and-drop move between containers (cards between lanes):** The card's `lane_id` is updated and it receives a new position in the target lane using the same gap logic.

### 4.3 Board Versioning for Polling

The `boards.version` column is an incrementing integer. Every mutation that changes the visible state of a board (card CRUD, lane CRUD, comment add, checklist change, attachment change) increments this counter via `UPDATE boards SET version = version + 1 WHERE id = ?`.

The client stores the last-known version and polls `GET /v1/boards/{id}/version`. If the server version matches, the response is `304 Not Modified`. If it differs, the client fetches the full board state.

---

## 5. API Specifications

### 5.1 General Conventions

**Base URL:** `/v1`

**Content-Type:** `application/json` for all request and response bodies (except file upload/download).

**Authentication:** Session cookie (`shuffle_session`). All endpoints except `POST /v1/auth/login` require an active session.

**CSRF:** All state-changing requests (POST, PUT, DELETE) must include the `X-CSRF-Token` header.

**Error response format:**

```json
{
    "error": "Human-readable error message"
}
```

**Standard HTTP status codes:**

| Code | Usage |
|---|---|
| 200 | Success (with body) |
| 201 | Created (with body of created resource) |
| 204 | Success (no body) |
| 304 | Not Modified (polling) |
| 400 | Bad Request (validation error) |
| 401 | Unauthorized (not logged in) |
| 403 | Forbidden (insufficient role or CSRF failure) |
| 404 | Not Found (or access denied for board isolation) |
| 405 | Method Not Allowed |
| 409 | Conflict (e.g., duplicate) |
| 500 | Internal Server Error |

### 5.2 Authentication

#### `POST /v1/auth/login`

Authenticates a user and creates a session.

**Request:**
```json
{
    "username": "johndoe",
    "password": "secret"
}
```

**Response (200):**
```json
{
    "user": {
        "id": 1,
        "username": "johndoe",
        "name": "John Doe",
        "email": "john@example.com",
        "role": "admin",
        "organization_id": 1
    },
    "csrf_token": "abc123..."
}
```

Sets the session cookie. Returns CSRF token for subsequent requests.

**Response (401):** `{"error": "Invalid credentials"}`

#### `POST /v1/auth/logout`

Destroys the current session.

**Response (204):** No content. Session cookie cleared.

#### `GET /v1/auth/session`

Returns the current session state. Used by the client on page load.

**Response (200):**
```json
{
    "user": { ... },
    "csrf_token": "abc123..."
}
```

**Response (401):** `{"error": "Not authenticated"}`

### 5.3 Users

**Required role:** Admin for all user management operations.

#### `GET /v1/users`

Lists all users in the system.

**Query parameters:**
- `status` — Filter by `active`, `inactive`, or `placeholder` (optional)
- `organization_id` — Filter by organization (optional)

**Response (200):**
```json
{
    "users": [
        {
            "id": 1,
            "username": "johndoe",
            "name": "John Doe",
            "email": "john@example.com",
            "role": "admin",
            "organization_id": 1,
            "is_placeholder": false,
            "status": "active"
        }
    ]
}
```

#### `GET /v1/users/{id}`

Returns a single user.

**Response (200):**
```json
{
    "user": { ... }
}
```

#### `POST /v1/users/invite`

Invites a new user via email.

**Request:**
```json
{
    "email": "newuser@example.com",
    "name": "New User",
    "role": "member",
    "organization_id": 1
}
```

**Behavior:**
1. Creates a user record with `status = 'inactive'` and a random `invite_token`
2. Sends an invitation email via SMTP with a link: `{app.url}/activate.php?token={invite_token}`
3. Token expires after 72 hours (`invite_token_expires_at`)

**Response (201):**
```json
{
    "user": { ... },
    "message": "Invitation sent"
}
```

#### `POST /v1/users/activate`

Activates an invited user (sets username and password). No session required — uses invite token.

**Request:**
```json
{
    "token": "abc123...",
    "username": "newuser",
    "password": "securepassword"
}
```

**Response (200):**
```json
{
    "user": { ... }
}
```

#### `PUT /v1/users/{id}`

Updates a user. Admins can update any user. Non-admins can only update their own `name` and `email`.

**Request:**
```json
{
    "name": "Updated Name",
    "email": "newemail@example.com",
    "role": "viewer",
    "organization_id": 2,
    "status": "inactive"
}
```

Only provided fields are updated. `role`, `organization_id`, and `status` require Admin role.

**Response (200):**
```json
{
    "user": { ... }
}
```

#### `DELETE /v1/users/{id}`

Removes a user from the system. Admin only.

**Response (204):** No content.

### 5.4 Organizations

**Required role:** Admin for all operations.

#### `GET /v1/organizations`

**Response (200):**
```json
{
    "organizations": [
        { "id": 1, "name": "Engineering", "member_count": 5 }
    ]
}
```

#### `POST /v1/organizations`

**Request:**
```json
{
    "name": "Engineering"
}
```

**Response (201):**
```json
{
    "organization": { "id": 1, "name": "Engineering" }
}
```

#### `PUT /v1/organizations/{id}`

**Request:**
```json
{
    "name": "Updated Name"
}
```

**Response (200):**
```json
{
    "organization": { ... }
}
```

#### `DELETE /v1/organizations/{id}`

Deletes an organization. Fails if users are still assigned to it.

**Response (204):** No content.
**Response (409):** `{"error": "Organization has active members"}`

#### `GET /v1/organizations/{id}/members`

Returns all users in the organization.

**Response (200):**
```json
{
    "members": [ { ... } ]
}
```

### 5.5 Boards

#### `GET /v1/boards`

Lists boards accessible to the current user.

**Query parameters:**
- `include_archived` — `true` to include archived boards (default: `false`)

**Required role:** Any authenticated user.

**Response (200):**
```json
{
    "boards": [
        {
            "id": 1,
            "title": "Sprint Board",
            "description": "Current sprint tasks",
            "visibility": "organization",
            "is_archived": false,
            "version": 42,
            "created_by": 1,
            "organizations": [1, 2],
            "card_count": 23,
            "created_at": "2026-02-01T10:00:00Z"
        }
    ]
}
```

**`card_count`** (BOARD-06b): total cards in the board across all lanes, archived cards included — the number the board-delete confirmation displays. Board rows are batch-annotated with one grouped query (no N+1).

#### `GET /v1/boards/{id}`

Returns a full board with all lanes and cards.

**Required role:** Any authenticated user with access.

**Response (200):**
```json
{
    "board": {
        "id": 1,
        "title": "Sprint Board",
        "description": "Current sprint tasks",
        "visibility": "organization",
        "is_archived": false,
        "version": 42,
        "created_by": 1,
        "organizations": [1, 2],
        "lanes": [
            {
                "id": 1,
                "title": "To Do",
                "position": 1000,
                "cards": [
                    {
                        "id": 1,
                        "title": "Implement login",
                        "due_date": "2026-03-01",
                        "position": 1000,
                        "is_archived": false,
                        "assigned_users": [1, 3],
                        "comment_count": 5,
                        "checklist_progress": { "done": 3, "total": 5 },
                        "attachment_count": 2,
                        "labels": [
                            { "id": 1, "name": "Bug", "color": "#FF0000" }
                        ]
                    }
                ]
            }
        ]
    }
}
```

#### `GET /v1/boards/{id}/version`

Lightweight polling endpoint. Returns the board's current version.

**Response headers:** `ETag: "42"` (the version number)

**Client sends:** `If-None-Match: "42"`

**Response (304):** Not Modified (no body, if ETag matches)

**Response (200):**
```json
{
    "version": 43
}
```

#### `POST /v1/boards`

**Required role:** Admin or Member.

**Request:**
```json
{
    "title": "New Board",
    "description": "Optional description",
    "visibility": "organization",
    "organization_ids": [1]
}
```

If `visibility = "private"`, `organization_ids` is ignored.

The new board is automatically seeded with the standard 11-lane set (LANE-09):

| # | Icon | Lane |
|---|------|------|
| 1 | 📥 | Inbox |
| 2 | 🔖 | Resources |
| 3 | ⏳ | Backlog |
| 4 | 🚦 | Up Next |
| 5 | 🔨 | In Progress |
| 6 | ⛔ | Blocked |
| 7 | 👀 | In Review |
| 8 | 📦 | Waiting for release |
| 9 | 🧪 | QA |
| 10 | ✅ | Done |
| 11 | 🚫 | Won't fix |

They are created in this order (positions 1000, 2000, …) and carry these icons. The caller can rename or delete any of them afterwards via the lane endpoints above.

**Response (201):**
```json
{
    "board": { ... }
}
```

#### `PUT /v1/boards/{id}`

**Required role:** Admin or Member with access.

**Request:**
```json
{
    "title": "Updated Title",
    "description": "Updated description",
    "visibility": "organization",
    "organization_ids": [1, 2]
}
```

**Response (200):**
```json
{
    "board": { ... }
}
```

#### `POST /v1/boards/{id}/archive`

Archives a board (soft delete).

**Required role:** Admin.

**Response (204):** No content.

#### `POST /v1/boards/{id}/restore`

Restores an archived board.

**Required role:** Admin.

**Response (204):** No content.

#### `DELETE /v1/boards/{id}`

Permanently deletes a board and all its contents. Cascades to lanes, cards, comments, checklists, checklist items, attachments (including S3 objects), card assignments, card labels.

**Required role:** Admin.

**Response (204):** No content.

**UI surface (BOARD-06a):** the board listing page (`boards.php`) renders a Delete button (admin only) next to each board's Edit action. It opens an in-page confirmation modal showing the board title and its `card_count`; boards with cards carry a stronger warning ("X cards will also be deleted permanently"). Confirmation calls this endpoint; success (204) removes the board card from the grid, error (404/403/500) shows a flash and keeps the page.

### 5.6 Lanes

#### `GET /v1/boards/{boardId}/lanes`

Returns lanes for a board, ordered by position.

**Response (200):**
```json
{
    "lanes": [
        { "id": 1, "title": "To Do", "icon": null, "position": 1000 },
        { "id": 2, "title": "In Progress", "icon": "🔨", "position": 2000 }
    ]
}
```

#### `POST /v1/boards/{boardId}/lanes`

**Required role:** Admin or Member.

**Request:**
```json
{
    "title": "New Lane",
    "icon": "📥"
}
```

`icon` is optional (LANE-07/08); when present it must be a single emoji. Omit it or set it to `null`/`""` for no icon.

Position is automatically assigned (appended to the end).

**Response (201):**
```json
{
    "lane": { "id": 3, "title": "New Lane", "icon": "📥", "position": 3000 }
}
```

#### `PUT /v1/lanes/{id}`

Renames a lane and/or updates its icon. At least one of `title` or `icon` must be present.

**Required role:** Admin or Member.

**Request:**
```json
{
    "title": "Renamed Lane",
    "icon": "🚦"
}
```

`icon` may be set to `null` (or `""`) to remove the icon (LANE-07).

**Response (200):**
```json
{
    "lane": { ... }
}
```

#### `PUT /v1/lanes/{id}/position`

Reorders a lane within its board. This is a deliberate action (not drag-and-drop per LANE-05).

**Required role:** Admin or Member.

**Request:**
```json
{
    "after_lane_id": 2
}
```

`after_lane_id` is the lane this lane should be placed after. Use `null` or `0` to move to the first position.

**Response (200):**
```json
{
    "lane": { ... }
}
```

Bumps board version.

#### Add-lane form (web UI) — LANE-10, LANE-11

The board's "Add Lane" ghost form contains, in order:

1. **Template dropdown** — a `<select>` with a "Custom lane" option (default) followed by the standard lane set (Inbox, Resources, Backlog, Up Next, In Progress, Blocked, In Review, Waiting for release, QA, Done, Won't fix). Data is served server-side in the board script tag's `data-lane-templates` JSON (single source of truth: `BoardService::DEFAULT_LANES`); each entry is `{title, icon}`.
   - Selecting a template **prepopulates the title and icon inputs** from that entry; both fields remain freely editable.
   - Selecting "Custom lane" blanks both inputs (or leaves them untouched if the user has already typed).
2. **Icon input** — a short text field for the single-emoji icon, plus:
3. **Emoji picker** — a toggle button showing a curated grid of common emojis (which always includes the 11 template emojis). Clicking an emoji fills the icon input and closes the picker. The input remains editable afterwards.

The picker and dropdown are pure front-end conveniences: they submit through the existing `POST /v1/boards/{boardId}/lanes` body (`{title, icon}`), so the API contract is unchanged and the server-side single-emoji validation (LANE-08) still applies.

#### `DELETE /v1/lanes/{id}`

Deletes a lane. **Refuses if the lane contains cards** — the client must move or delete all cards first.

**Required role:** Admin or Member.

**Response (204):** No content.
**Response (409):** `{"error": "Lane contains cards. Move or delete cards before deleting the lane."}`

Bumps board version.

### 5.7 Cards

#### `GET /v1/cards/{id}`

Returns full card detail including comments, checklists, and attachments.

**Response (200):**
```json
{
    "card": {
        "id": 1,
        "lane_id": 1,
        "title": "Implement login",
        "description": "Full Markdown content here...",
        "description_html": "<p>Rendered HTML here...</p>",
        "due_date": "2026-03-01",
        "position": 1000,
        "is_archived": false,
        "created_by": 1,
        "assigned_users": [
            { "id": 1, "name": "John Doe", "username": "johndoe" }
        ],
        "labels": [
            { "id": 1, "name": "Bug", "color": "#FF0000" }
        ],
        "comments": [
            {
                "id": 1,
                "user_id": 2,
                "user_name": "Jane Smith",
                "body": "Markdown comment text",
                "body_html": "<p>Rendered comment</p>",
                "created_at": "2026-02-10T14:30:00Z",
                "updated_at": "2026-02-10T14:30:00Z"
            }
        ],
        "checklists": [
            {
                "id": 1,
                "title": "Implementation tasks",
                "position": 1000,
                "items": [
                    {
                        "id": 1,
                        "title": "Create login form",
                        "is_checked": true,
                        "assigned_user_id": 1,
                        "assigned_user_name": "John Doe",
                        "position": 1000
                    }
                ]
            }
        ],
        "attachments": [
            {
                "id": 1,
                "file_name": "screenshot.png",
                "file_size": 245760,
                "mime_type": "image/png",
                "uploaded_at": "2026-02-10T15:00:00Z",
                "user_name": "John Doe"
            }
        ],
        "created_at": "2026-02-01T10:00:00Z",
        "updated_at": "2026-02-10T15:00:00Z"
    }
}
```

#### `POST /v1/boards/{boardId}/lanes/{laneId}/cards`

Creates a new card at the bottom of the specified lane.

**Required role:** Admin or Member.

**Request:**
```json
{
    "title": "New Card",
    "description": "Optional Markdown description",
    "due_date": "2026-03-15",
    "assigned_user_ids": [1, 3]
}
```

**Response (201):**
```json
{
    "card": { ... }
}
```

Bumps board version. Creates assignment notifications if `assigned_user_ids` is provided.

#### `PUT /v1/cards/{id}`

Updates card fields.

**Required role:** Admin or Member.

**Request:**
```json
{
    "title": "Updated Title",
    "description": "Updated description",
    "due_date": "2026-04-01",
    "assigned_user_ids": [1, 2]
}
```

Only provided fields are updated. If `assigned_user_ids` is provided, it replaces the full assignment list. Newly assigned users receive notifications.

**Response (200):**
```json
{
    "card": { ... }
}
```

Bumps board version.

#### `PUT /v1/cards/{id}/move`

Moves a card to a different lane and/or reorders within a lane.

**Required role:** Admin or Member.

**Request:**
```json
{
    "lane_id": 2,
    "after_card_id": 5
}
```

`lane_id` is the target lane. `after_card_id` is the card this card should be placed after (`null` or `0` for the top position).

**Response (200):**
```json
{
    "card": { ... }
}
```

Bumps board version.

#### `POST /v1/cards/{id}/archive`

Archives a card.

**Required role:** Admin or Member.

**Response (204):** No content. Bumps board version.

#### `POST /v1/cards/{id}/restore`

Restores an archived card.

**Required role:** Admin or Member.

**Response (204):** No content. Bumps board version.

#### `DELETE /v1/cards/{id}`

Permanently deletes a card and all its contents (comments, checklists, attachments including S3 objects).

**Required role:** Admin or Member.

**Response (204):** No content. Bumps board version.

### 5.8 Comments

#### `POST /v1/cards/{cardId}/comments`

**Required role:** Admin or Member.

**Request:**
```json
{
    "body": "Markdown comment text"
}
```

**Response (201):**
```json
{
    "comment": {
        "id": 1,
        "card_id": 5,
        "user_id": 2,
        "body": "Markdown comment text",
        "body_html": "<p>Rendered HTML</p>",
        "created_at": "2026-02-12T10:00:00Z"
    }
}
```

Bumps board version. Creates notifications for all users assigned to the card (except the comment author).

#### `PUT /v1/comments/{id}`

**Required role:** Comment author only (or Admin).

**Request:**
```json
{
    "body": "Updated comment text"
}
```

**Response (200):**
```json
{
    "comment": { ... }
}
```

Bumps board version.

#### `DELETE /v1/comments/{id}`

**Required role:** Comment author or Admin.

**Response (204):** No content. Bumps board version.

### 5.9 Checklists

#### `POST /v1/cards/{cardId}/checklists`

**Required role:** Admin or Member.

**Request:**
```json
{
    "title": "Checklist Name"
}
```

**Response (201):**
```json
{
    "checklist": { "id": 1, "title": "Checklist Name", "position": 1000, "items": [] }
}
```

Bumps board version.

#### `PUT /v1/checklists/{id}`

**Required role:** Admin or Member.

**Request:**
```json
{
    "title": "Updated Name"
}
```

**Response (200):**
```json
{
    "checklist": { ... }
}
```

#### `DELETE /v1/checklists/{id}`

**Required role:** Admin or Member.

**Response (204):** No content. Bumps board version.

#### `POST /v1/checklists/{checklistId}/items`

**Required role:** Admin or Member.

**Request:**
```json
{
    "title": "Checklist item text",
    "assigned_user_id": 3
}
```

**Response (201):**
```json
{
    "item": { "id": 1, "title": "Checklist item text", "is_checked": false, "assigned_user_id": 3, "position": 1000 }
}
```

Bumps board version.

#### `PUT /v1/checklist-items/{id}`

Updates a checklist item (title, checked state, assignment, position).

**Required role:** Admin or Member.

**Request:**
```json
{
    "title": "Updated text",
    "is_checked": true,
    "assigned_user_id": 2
}
```

Only provided fields are updated.

**Response (200):**
```json
{
    "item": { ... }
}
```

Bumps board version.

#### `PUT /v1/checklist-items/{id}/position`

**Request:**
```json
{
    "after_item_id": 3
}
```

**Response (200):**
```json
{
    "item": { ... }
}
```

#### `DELETE /v1/checklist-items/{id}`

**Required role:** Admin or Member.

**Response (204):** No content. Bumps board version.

### 5.10 Attachments

#### `POST /v1/cards/{cardId}/attachments`

Uploads a file attachment. The request body is the raw file stream (not JSON).

**Required role:** Admin or Member.

**Request headers:**
- `Content-Type`: The file's MIME type (e.g., `image/png`)
- `X-File-Name`: Original filename (URL-encoded)
- `X-File-Size`: File size in bytes
- `X-CSRF-Token`: CSRF token

**Request body:** Raw binary file data.

**Server behavior:**
1. Validates MIME type and file name
2. Generates S3 key: `{board_id}/{card_id}/{uuid}_{filename}`
3. Opens `php://input` as a stream
4. If file size <= chunk size: single `PUT` to S3
5. If file size > chunk size: initiates multipart upload, streams chunks, completes
6. Stores metadata in `attachments` table
7. Bumps board version

**Response (201):**
```json
{
    "attachment": {
        "id": 1,
        "file_name": "screenshot.png",
        "file_size": 245760,
        "mime_type": "image/png",
        "uploaded_at": "2026-02-12T10:00:00Z"
    }
}
```

#### `GET /v1/attachments/{id}/download`

Streams the file from S3 through PHP to the client.

**Required role:** Any authenticated user with board access.

**Response headers:**
- `Content-Type`: Original MIME type
- `Content-Length`: File size
- `Content-Disposition`: `attachment; filename="original_name.ext"`

**Response body:** Raw binary file data (streamed).

#### `DELETE /v1/attachments/{id}`

Deletes the attachment from S3 and the database.

**Required role:** Attachment owner or Admin.

**Response (204):** No content. Bumps board version.

### 5.11 Notifications

#### `GET /v1/notifications`

Returns notifications for the current user.

**Query parameters:**
- `unread_only` — `true` to filter to unread only (default: `false`)
- `limit` — Number of notifications to return (default: 50)

**Response (200):**
```json
{
    "notifications": [
        {
            "id": 1,
            "type": "assignment",
            "reference_id": 42,
            "message": "You were assigned to 'Implement login'",
            "is_read": false,
            "created_at": "2026-02-12T10:00:00Z"
        }
    ],
    "unread_count": 3
}
```

#### `GET /v1/notifications/count`

Lightweight endpoint for the notification badge.

**Response (200):**
```json
{
    "unread_count": 3
}
```

#### `PUT /v1/notifications/{id}/read`

Marks a notification as read.

**Response (204):** No content.

#### `POST /v1/notifications/read-all`

Marks all notifications as read for the current user.

**Response (204):** No content.

#### `DELETE /v1/notifications/{id}`

Dismisses (deletes) a notification.

**Response (204):** No content.

### 5.12 Search

#### `GET /v1/search`

Searches cards using MySQL full-text search.

**Query parameters:**
- `q` — Search query (required, minimum 3 characters)
- `board_id` — Restrict to a specific board (optional)
- `include_archived` — `true` to include archived cards/boards (default: `true` per SEARCH-04)

**Response (200):**
```json
{
    "results": [
        {
            "card_id": 42,
            "card_title": "Implement login",
            "card_description_excerpt": "...matching text around the search term...",
            "board_id": 1,
            "board_title": "Sprint Board",
            "lane_id": 2,
            "lane_title": "In Progress",
            "is_archived": false,
            "board_is_archived": false
        }
    ],
    "total": 15
}
```

**Access control:** Results are filtered to only include cards on boards the user has access to. The search query never returns results from boards the user cannot see.

**MySQL full-text search:**
```sql
SELECT c.*, MATCH(c.title, c.description) AGAINST(? IN BOOLEAN MODE) AS relevance
FROM cards c
JOIN lanes l ON c.lane_id = l.id
JOIN boards b ON l.board_id = b.id
JOIN board_organizations bo ON b.id = bo.board_id
WHERE bo.organization_id = ?
  AND MATCH(c.title, c.description) AGAINST(? IN BOOLEAN MODE)
ORDER BY relevance DESC
LIMIT 50
```

For private boards, an additional `OR b.created_by = ?` condition is included.

### 5.13 Personal Priority List (PRIO-01..11)

All endpoints are for the **authenticated user only** — there is no `user_id` parameter anywhere on these routes; the service resolves the acting user from the session and never acts on another user's list (PRIO-02).

**Common item shape** (`inbox[]` and `prioritized[]` entries):

```json
{
    "card_id": 42,
    "card_title": "Implement login",
    "board_id": 1,
    "board_title": "Sprint Board",
    "lane_id": 2,
    "lane_title": "In Progress",
    "lane_icon": "🔨",
    "state_marker": "🔨",
    "due_date": "2026-09-01",
    "card_html": "/card.php?id=42",
    "tier": 1
}
```

- `card_html` is the deep link to the card on its board (PRIO-07); the card page enforces board access itself.
- `state_marker` is derived from the live lane (PRIO-07): In Progress → 🔨, Inbox → 📥, Done → ✅, anything else → the lane's own icon when it has one, or a neutral marker when it does not.
- `tier` is present only on inbox items (1/2/3, per PRIO-04).

#### `GET /v1/priority`

Returns the acting user's full list in one pass (PRIO-01, PRIO-03, PRIO-06, PRIO-09).

**Response (200):**
```json
{
    "inbox": [ { ...item with tier... } ],
    "prioritized": [ { ...item... } ]
}
```

Semantics:
- `inbox` — computed live: cards assigned to the user, on boards the user can access, non-archived, **not in a Done lane** (lane title case-insensitive match), and not already in `user_prio`. Order: tier 1 (In Progress lane) → tier 2 (Inbox lane) → tier 3 (everything else); within a tier, lane position, then card position, with boards merged in board-creation order (PRIO-04).
- `prioritized` — the user's `user_prio` rows in position order, joined live to card/lane/board. Rows whose card was deleted, archived, moved to Done, or whose board access was lost are still returned with their live state (Done marking included, per PRIO-09) unless the card was deleted or the board is fully inaccessible — those rows are omitted.

#### `POST /v1/priority/inbox/{cardId}`

Adds the card to the user's prioritized section (PRIO-05). Body: none.

- **200** — added, returns the new `position`.
- Already-prioritized — **200 no-op** (idempotent), same `position`.
- Card not assignable to the user / inaccessible board / card on a Done lane — **409** for Done-lane cards, **404** otherwise.

#### `DELETE /v1/priority/inbox/{cardId}`

Removes the card from the user's prioritized section (PRIO-05). It reappears in the inbox on the next `GET /v1/priority` if it still qualifies.

- **204** — removed, or no-op if not a member.

#### `PUT /v1/priority/position`

Reorders a prioritized card relative to another card in the same user's list (PRIO-06).

**Request:**
```json
{ "card_id": 42, "after_card_id": 7 }
```

`after_card_id: null` moves the card to the top. §4.2 gap logic applies (midpoint insert, full renumber when the gap collapses).

- **200** — returns the new `position`.
- Card not in the user's prioritized section — **404**.

---

### 5.14 Labels (Post-MVP)

#### `GET /v1/boards/{boardId}/labels`

Returns all labels for a board.

**Response (200):**
```json
{
    "labels": [
        { "id": 1, "name": "Bug", "color": "#FF0000" },
        { "id": 2, "name": "Feature", "color": "#00FF00" }
    ]
}
```

#### `POST /v1/boards/{boardId}/labels`

**Required role:** Admin or Member.

**Request:**
```json
{
    "name": "Bug",
    "color": "#FF0000"
}
```

**Response (201):**
```json
{
    "label": { "id": 1, "name": "Bug", "color": "#FF0000" }
}
```

#### `PUT /v1/labels/{id}`

**Request:**
```json
{
    "name": "Critical Bug",
    "color": "#CC0000"
}
```

**Response (200):**
```json
{
    "label": { ... }
}
```

#### `DELETE /v1/labels/{id}`

**Response (204):** No content.

#### `POST /v1/cards/{cardId}/labels/{labelId}`

Attaches a label to a card.

**Response (204):** No content. Bumps board version.

#### `DELETE /v1/cards/{cardId}/labels/{labelId}`

Detaches a label from a card.

**Response (204):** No content. Bumps board version.

---

## 6. Security Architecture

### 6.1 Password Hashing

**Algorithm:** Argon2id via PHP's `password_hash()` with `PASSWORD_ARGON2ID`.

```php
$hash = password_hash($password, PASSWORD_ARGON2ID);
$valid = password_verify($password, $hash);
```

PHP's defaults for Argon2id are used (memory cost 65536 KB, time cost 4, threads 1). These can be tuned via `password_hash()` options if needed.

### 6.2 Session Security

- Sessions stored in MySQL `sessions` table (not filesystem)
- Session ID regenerated on login (`session_regenerate_id(true)`)
- Cookie flags: `HttpOnly`, `Secure` (when HTTPS), `SameSite=Lax`, `Path=/`
- Session lifetime: 24 hours (configurable)
- Garbage collection: Sessions older than lifetime are deleted
- Admin can revoke all sessions for a user via `Session::destroyByUserId()`

### 6.3 CSRF Protection

- A random token is generated per session and stored in `$_SESSION['csrf_token']`
- Server-rendered forms include the token as a hidden field: `<input type="hidden" name="_csrf" value="...">`
- The token is also provided in the login response and stored by JavaScript
- REST API requests send the token via the `X-CSRF-Token` header
- All POST, PUT, DELETE requests are validated against the session token
- Invalid or missing CSRF token returns `403 Forbidden`

### 6.4 XSS Prevention

**Markdown rendering:** Parsedown with `setSafeMode(true)` — escapes all raw HTML in user input.

**HTML output:** All dynamic values in server-rendered pages use `htmlspecialchars()` with `ENT_QUOTES`:

```php
<?= htmlspecialchars($board['title'], ENT_QUOTES, 'UTF-8') ?>
```

**Content-Type headers:** All JSON API responses set `Content-Type: application/json` to prevent browser interpretation as HTML.

**Content Security Policy (CSP):** The following header is set on all HTML pages:

```
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; frame-ancestors 'none'
```

### 6.5 SQL Injection Prevention

All database queries use PDO prepared statements with parameterized placeholders. The `Database` class enforces this by design — there is no method that accepts raw SQL with interpolated values.

```php
// Correct — always use this pattern
$db->fetch("SELECT * FROM users WHERE username = ?", [$username]);

// Never — this pattern must not exist in the codebase
$db->fetch("SELECT * FROM users WHERE username = '$username'");
```

### 6.6 File Upload Security

- MIME type is validated server-side (not trusted from client headers alone)
- File extension is checked against an allowlist of safe types
- Files are never served directly from the web server; always streamed through PHP with controlled headers
- S3 keys include a UUID to prevent path traversal or overwrite attacks
- `Content-Disposition: attachment` on downloads prevents browser execution

### 6.7 Access Control Enforcement

Every endpoint and every page enforces access control:

1. **Authentication check** — Is the user logged in? (401 if not)
2. **Role check** — Does the user have the required role? (403 if not)
3. **Board access check** — Can the user access this board? (404 if not — strict isolation)

Board access checks use a reusable query pattern:

```sql
-- Check if user can access a board
SELECT b.id FROM boards b
LEFT JOIN board_organizations bo ON b.id = bo.board_id
WHERE b.id = ?
  AND (
    (b.visibility = 'private' AND b.created_by = ?)
    OR (b.visibility = 'organization' AND bo.organization_id = ?)
  )
```

**Strict isolation (BOARD-04b):** An unauthorized user accessing a board receives the exact same `404 Not Found` response as a non-existent board. No information is leaked about whether the board exists.

### 6.8 Rate Limiting (Recommendation)

While not a hard MVP requirement, the specification recommends basic rate limiting on the login endpoint to prevent brute-force attacks:

- Maximum 10 failed login attempts per IP per 15-minute window
- Implemented via a simple `login_attempts` table: `(ip_address VARCHAR(45), attempted_at DATETIME)`
- After exceeding the limit, return `429 Too Many Requests`

### 6.9 Security Headers

All responses include:

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 0
Referrer-Policy: strict-origin-when-cross-origin
Content-Security-Policy: (see 6.4)
```

---

## 7. Infrastructure and Deployment

### 7.1 Server Requirements

| Component | Requirement |
|---|---|
| OS | Debian Trixie (13) |
| PHP | 8.4 with extensions: `pdo_mysql`, `mbstring`, `json`, `openssl`, `curl` |
| Web server | Apache 2.4 with `mod_rewrite` or Nginx |
| MySQL | 8.0+ with InnoDB and FULLTEXT support |
| S3 storage | Ceph RGW or any S3-compatible service |
| SMTP | Any SMTP server (Postfix, external service, etc.) |

### 7.2 Apache Configuration

```apache
<VirtualHost *:443>
    ServerName shuffle.example.com
    DocumentRoot /opt/shuffle/www

    <Directory /opt/shuffle/www>
        AllowOverride All
        Require all granted
    </Directory>

    # PHP settings for large file uploads
    php_value upload_max_filesize 0
    php_value post_max_size 0
    php_value max_execution_time 3600
    php_value max_input_time 3600
    php_value memory_limit 64M

    # TLS configuration handled by reverse proxy or certbot
</VirtualHost>
```

**`www/v1/.htaccess`:**

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]
```

### 7.3 Nginx Configuration

```nginx
server {
    listen 443 ssl;
    server_name shuffle.example.com;
    root /opt/shuffle/www;
    index index.php;

    # Disable upload size limit (handled by PHP)
    client_max_body_size 0;

    # API front-controller
    location /v1/ {
        try_files $uri /v1/index.php?$query_string;
    }

    # PHP processing
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;

        # Large file upload settings
        fastcgi_read_timeout 3600;
        fastcgi_send_timeout 3600;
    }

    # Deny access to hidden files
    location ~ /\. {
        deny all;
    }

    # Static file caching
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }
}
```

### 7.4 PHP Configuration Notes

For streaming large file uploads through PHP (S3 proxy), these settings are critical:

| Setting | Value | Reason |
|---|---|---|
| `upload_max_filesize` | `0` (unlimited) | No file size limit per requirements |
| `post_max_size` | `0` (unlimited) | Allow large request bodies |
| `max_execution_time` | `3600` | Allow long-running uploads (1 hour) |
| `max_input_time` | `3600` | Allow slow upload streams |
| `memory_limit` | `64M` | Low — uploads stream through, not buffered |

The upload handler reads from `php://input` in chunks (5 MB default) and forwards each chunk to S3 via multipart upload, keeping memory usage constant regardless of file size.

### 7.5 File Permissions

```
/opt/shuffle/
├── bin/        → 750 (owner: shuffle, group: shuffle)
├── doc/        → 644
├── etc/        → 750 (config.php contains secrets)
├── include/    → 644
└── www/        → 644 (readable by web server)
```

The web server user (e.g., `www-data`) needs read access to `www/`, `include/`, and `etc/`. It should NOT have write access to any directory.

### 7.6 Database Setup

```sql
CREATE DATABASE shuffle CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'shuffle'@'localhost' IDENTIFIED BY 'secure_password';
GRANT ALL PRIVILEGES ON shuffle.* TO 'shuffle'@'localhost';
FLUSH PRIVILEGES;
```

The schema DDL (all `CREATE TABLE` statements) is provided as `doc/schema.sql` and run once during initial setup.

### 7.7 Installation Steps

1. Clone the repository to `/opt/shuffle/`
2. Copy `etc/config.example.php` to `etc/config.php` and edit settings
3. Create MySQL database and user
4. Run `mysql shuffle < doc/schema.sql`
5. Configure the web server (Apache or Nginx) pointing DocumentRoot to `www/`
6. Set file permissions
7. Navigate to `https://your-domain/setup.php` and complete the web setup wizard to create the initial admin account and configure application settings.

---

## 8. Integration Points

### 8.1 S3-Compatible Storage (Ceph RGW reference)

| Aspect | Detail |
|---|---|
| **Purpose** | File attachment storage |
| **Protocol** | S3 API via custom `S3Client` class |
| **URL style** | Path-based: `{endpoint}/{bucket}/{key}` |
| **Authentication** | AWS Signature V4 (access key + secret key) |
| **Operations** | PUT (upload), GET (download), DELETE, HEAD (exists check), multipart upload |
| **Bucket** | Single bucket, configured in `etc/config.php` |
| **Key format** | `{board_id}/{card_id}/{uuid}_{filename}` |

**Failure handling:**
- Upload failure: abort multipart upload, return error to client, no DB record created
- Download failure: return 500 with generic error
- Delete failure: log error, still delete DB record (orphaned S3 objects cleaned up by maintenance)

### 8.2 SMTP (Email)

| Aspect | Detail |
|---|---|
| **Purpose** | Invitation emails only (MVP) |
| **Protocol** | SMTP with TLS/STARTTLS |
| **Email format** | Multipart (HTML + plain text) |
| **From address** | Configurable in `etc/config.php` |

**Invitation email content:**
- Subject: i18n key `email.invite_subject` — e.g., "You're invited to Shuffle"
- Body: Welcome message + activation link with token
- Activation URL: `{app.url}/activate.php?token={invite_token}`

### 8.3 Trello JSON Import

| Aspect | Detail |
|---|---|
| **Tool** | `bin/trello-import.php` (CLI script) |
| **Input** | Trello JSON export file (path as argument) |
| **Network** | Downloads attachments from Trello CDN during import |
| **Idempotency** | Uses `trello_id` columns to detect previously imported entities |

**Trello → Shuffle mapping:**

| Trello Entity | Shuffle Entity | Notes |
|---|---|---|
| Board | Board | `trello_id` stored for dedup |
| List | Lane | Position preserved |
| Card | Card | Title, description, due date, position |
| Comment (Action) | Comment | Author mapped to user by Trello member ID |
| Checklist | Checklist | Title, position |
| Checklist Item | ChecklistItem | Title, checked state |
| Attachment | Attachment | Downloaded from Trello CDN, re-uploaded to S3 |
| Member | User (placeholder) | Created with `is_placeholder = 1`, `trello_id` stored |

**Placeholder user flow:**
1. During import, unknown Trello members become placeholder users (`is_placeholder = 1`, random password hash, inactive status)
2. Imported data (comments, assignments) references the placeholder user
3. Admin later invites the real person; on activation, the admin can merge/link the placeholder to the new account
4. The `trello_id` field helps match placeholder users to their Trello identity

---

## 9. Testing Strategy

### 9.1 Unit Testing

**Framework:** PHPUnit (single PHAR file, no Composer)

**Scope:** Models, services, and core classes.

**Focus areas:**
- `Database` wrapper (mocked PDO)
- `Auth` role checks and board access logic
- `S3Client` request signing (verifiable against AWS test vectors)
- `Csrf` token generation and validation
- `Lang` string loading and placeholder replacement
- Position calculation logic (gap management, renumbering)
- Service layer business rules

### 9.2 Integration Testing

**Scope:** API endpoints end-to-end against a test MySQL database.

**Approach:**
- Test database seeded with known fixtures before each test suite
- HTTP requests sent to the API front-controller
- Validates response status codes, JSON structure, and database state
- Tests access control: verify that unauthorized users get 404 (not 403) for board isolation

### 9.3 Accessibility Testing

- Automated: axe-core browser extension or pa11y CLI for WCAG 2.1 AA violations
- Manual: keyboard-only navigation testing for all interactive elements
- Screen reader testing: NVDA or VoiceOver for critical flows (login, board view, card management)
- Drag-and-drop: verify keyboard alternative exists for all drag-and-drop operations

### 9.4 Performance Testing

- Lighthouse CI in automated pipeline: fail build if score drops below 95%
- Manual load testing with realistic data volumes (100 boards, 1000 cards)
- Polling efficiency: measure server load with N concurrent polling clients

### 9.5 Security Testing

- OWASP ZAP scan against the running application
- Manual testing: XSS via Markdown injection, SQL injection attempts, CSRF bypass attempts
- File upload: test with various MIME types, path traversal filenames, oversized files
- Board isolation: verify no information leakage across organizations

---

## 10. Implementation Plan

### Phase 1: Foundation

**Components:** Directory structure, autoloader, config, database wrapper, session manager, bootstrap.

**Deliverables:**
- Project directory skeleton
- `Autoloader` class with namespace mapping
- `Database` class with PDO wrapper
- `Session` class with MySQL handler
- `etc/config.example.php` template
- `include/bootstrap.php`
- MySQL schema DDL (`doc/schema.sql`)
- `www/setup.php` — web setup wizard that creates the initial admin user, configures application settings (app name/URL/locale/timezone), SMTP, and S3 storage in a single atomic transaction

**Acceptance criteria:**
- Autoloader resolves all `Shuffle\` classes
- Database wrapper connects and executes parameterized queries
- Session persists across requests in MySQL
- Web setup wizard (`www/setup.php`) creates a working admin account, configures app settings, SMTP, and S3 in a single atomic transaction

### Phase 2: Authentication and User Management

**Components:** Auth, CSRF, Mailer, User model/service/controller, login page, admin user pages.

**Deliverables:**
- `Auth` class (login, logout, role checks)
- `Csrf` class (token generation/validation)
- `Mailer` class (SMTP client)
- Login/logout pages and API endpoints
- User invite flow (admin page + email + activation page)
- User management admin pages
- `Lang` class + `en.json` with auth-related strings

**Acceptance criteria:**
- User can log in with username/password
- Admin can invite a user via email
- Invited user can activate account via emailed link
- Session persists and is validated on each request
- CSRF protection blocks forged requests
- Role-based access control enforces Admin/Member/Viewer boundaries

### Phase 3: Organizations and Boards

**Components:** Organization model/service/controller, Board model/service/controller, board listing page, board creation.

**Deliverables:**
- Organization CRUD (admin only)
- Board CRUD with visibility settings
- Board listing page (filtered by access)
- Board creation UI
- Board archive/restore
- Board access control (org membership check)
- `Response`, `Request`, and `Router` classes for the API

**Acceptance criteria:**
- Admin can create organizations and assign users
- Users can create boards (private or org-assigned)
- Board listing only shows accessible boards
- Inaccessible boards return 404 (strict isolation)
- Boards can be archived and restored

### Phase 4: Lanes and Cards

**Components:** Lane model/service/controller, Card model/service/controller, board view page with lanes and cards, drag-and-drop JS.

**Deliverables:**
- Lane CRUD with position management
- Lane reorder (deliberate action, not drag)
- Card CRUD with position management
- Card drag-and-drop (within lane and between lanes)
- Board view page (full Kanban UI)
- `board.js` with HTML5 Drag and Drop API
- Card detail page (basic: title, description, due date)
- Parsedown integration for Markdown rendering

**Acceptance criteria:**
- Lanes can be created, renamed, reordered, and deleted (empty only)
- Cards can be created, edited, and deleted
- Cards can be dragged between lanes and reordered within lanes
- Position changes persist correctly in the database
- Markdown descriptions render safely (no XSS)
- Board version increments on all mutations

### Phase 5: Card Features

**Components:** Comments, checklists, attachments, card assignments, card detail UI.

**Deliverables:**
- Comment CRUD with Markdown rendering
- Checklist CRUD with item management
- Checklist item check/uncheck, assignment, reorder
- File attachment upload (streaming to S3)
- File attachment download (streaming from S3)
- `S3Client` with Signature V4
- `upload.js` with progress bar
- Card assignment UI
- Full card detail page

**Acceptance criteria:**
- Comments render Markdown safely
- Checklists show progress (e.g., "3/5 completed")
- File uploads stream to S3 without exhausting PHP memory
- Large files (100+ MB) upload successfully with progress indicator
- Files can be downloaded
- Multiple users can be assigned to a card

### Phase 6: Notifications, Search, and Polling

**Components:** Notification system, search, polling, notification UI.

**Deliverables:**
- Notification model/service/controller
- Notification creation triggers (assignment, comment)
- Notification bell in header with unread count
- Notification dropdown panel
- `notifications.js` polling
- Search endpoint with MySQL FULLTEXT
- Search UI (search bar, results page)
- Board polling (`board.js` version check loop)

**Acceptance criteria:**
- Assigning a user to a card creates a notification
- Commenting on a card notifies assigned users
- Notification bell shows unread count, updates via polling
- Search returns relevant cards across accessible boards
- Archived cards appear in search results, marked as archived
- Board view auto-refreshes when another user makes changes

### Phase 7: Trello Import, CSS, and Polish

**Components:** Import CLI tool, final CSS styling, accessibility audit, Lighthouse optimization.

**Deliverables:**
- `bin/trello-import.php` CLI tool
- Full CSS stylesheet (`app.css`) with custom properties
- Responsive design (desktop + tablet)
- Keyboard navigation for all interactive elements
- ARIA attributes for drag-and-drop
- Lighthouse audit and optimization
- `doc/setup.md` installation guide
- `doc/api.md` API reference
- `README.md`

**Acceptance criteria:**
- Trello JSON export imports correctly (boards, lanes, cards, comments, checklists, attachments)
- Placeholder users created for unknown Trello members
- Multiple import runs do not duplicate data
- WCAG 2.1 AA compliance verified
- Lighthouse score >= 95%
- Clean, consistent visual design
- Setup documentation is complete and accurate

---

## 11. Risks and Mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| **S3 Signature V4 complexity** — Implementing AWS auth signing in plain PHP is non-trivial (~200-300 lines) | Medium | High | Reference AWS documentation and test vectors; implement incrementally with unit tests against known signatures |
| **Large file streaming** — PHP proxying gigabyte files to S3 could hit timeouts or memory issues | Medium | Medium | Stream in chunks via `php://input`; set generous `max_execution_time`; use S3 multipart upload; test with large files early |
| **Drag-and-drop accessibility** — HTML5 DnD API has poor accessibility support | High | Medium | Provide keyboard alternative for all drag-and-drop operations (arrow keys + modifier, or menu-based move); ARIA live regions announce changes |
| **Concurrent editing conflicts** — Two users editing the same board simultaneously | Medium | Low | Board version counter detects changes; client refreshes on version mismatch; no real-time locking needed for MVP |
| **SMTP implementation** — Writing a raw SMTP client is error-prone | Medium | Medium | Implement only the minimum required SMTP commands; test against a local Postfix and a well-known provider; consider vendoring a single-file SMTP library if available under MIT |
| **MySQL FULLTEXT limitations** — Minimum word length (default 4 chars), stop words | Low | Low | Document the limitation; configurable via MySQL's `ft_min_word_len`; acceptable for MVP |
| **Trello JSON format changes** — Breaking the import tool | Low | Medium | Pin to a known Trello export version; isolate import code; provide clear error messages on unexpected format |
| **Single developer bottleneck** — All system knowledge in one person | High | Medium | Clean code, comprehensive documentation, MIT license, this specification |

---

## 12. Appendices

### A. Configuration File Reference

See Section 3.3 for the complete `etc/config.php` structure with all keys, types, and default values.

### B. Trello JSON Mapping Reference

| Trello JSON Path | Shuffle Field | Notes |
|---|---|---|
| `.id` | `boards.trello_id` | |
| `.name` | `boards.title` | |
| `.desc` | `boards.description` | |
| `.lists[].id` | (used for card mapping) | |
| `.lists[].name` | `lanes.title` | |
| `.lists[].pos` | `lanes.position` | Normalized to gap scheme |
| `.cards[].id` | `cards.trello_id` | |
| `.cards[].name` | `cards.title` | |
| `.cards[].desc` | `cards.description` | |
| `.cards[].due` | `cards.due_date` | ISO 8601 → DATE |
| `.cards[].pos` | `cards.position` | Normalized |
| `.cards[].idList` | `cards.lane_id` | Mapped via list → lane |
| `.cards[].idMembers[]` | `card_assignments` | Mapped via member → user |
| `.cards[].checklists[]` | `checklists` + `checklist_items` | |
| `.actions[]` (type=commentCard) | `comments` | Filtered by action type |
| `.actions[].memberCreator` | `comments.user_id` | Mapped to user/placeholder |
| `.members[].id` | `users.trello_id` | |
| `.members[].fullName` | `users.name` | |
| `.members[].username` | `users.username` | Prefixed with `trello_` if collision |

### C. Requirement Traceability Matrix

| Requirement | Specification Section |
|---|---|
| AUTH-01 through AUTH-06 | 3.13, 5.2, 5.3, 6.1, 6.2 |
| ORG-01 through ORG-04 | 3.14, 4.1 (organizations), 5.4 |
| RBAC-01 through RBAC-05 | 3.13, 6.7 |
| BOARD-01 through BOARD-06 | 3.14, 3.15, 4.1 (boards, board_organizations), 5.5 |
| BOARD-06a / BOARD-06b | 5.5 (DELETE /v1/boards UI surface; `card_count` in GET /v1/boards), 3.15 (BoardService::listBoards), www/boards.php + www/js/boards.js |
| LANE-01 through LANE-06 | 3.14, 4.1 (lanes), 4.2, 5.6 |
| CARD-01 through CARD-09 | 3.14, 3.15, 4.1 (cards), 4.2, 5.7, 5.14 |
| COMMENT-01 through COMMENT-05 | 3.14, 4.1 (comments), 5.8 |
| CHECK-01 through CHECK-06 | 3.14, 4.1 (checklists, checklist_items), 5.9 |
| FILE-01 through FILE-07 | 3.9, 3.15, 4.1 (attachments), 5.10, 8.1 |
| NOTIF-01 through NOTIF-06 | 3.15, 4.1 (notifications), 5.11 |
| SEARCH-01 through SEARCH-05 | 3.15, 4.1 (cards FULLTEXT), 5.12 |
| IMPORT-01 through IMPORT-10 | 3.15, 3.19, 8.3, 12.B |
| RT-01 through RT-03 | 3.18, 4.3, 5.5 (version endpoint) |
| PRIO-01 through PRIO-11 | 3.14 (user_prio), 3.15 (PriorityService), 3.18 (js/priority.js), 4.1 (user_prio), 5.13 |
| ONBOARD-01 through ONBOARD-11 | Future (Nice-to-have) |
| PERF-01 through PERF-04 | 9.4, 10 (Phase 7) |
| SEC-01 through SEC-08 | 6.1 through 6.8 |
| UX-01 through UX-05 | 9.3, 10 (Phase 7) |
| MAINT-01 through MAINT-05 | 2.2, 2.3, 3.8, 7.7 |

### D. Glossary

| Term | Definition |
|---|---|
| **Front-controller** | A single entry point (index.php) that handles all incoming requests for a subsystem (the REST API) |
| **Gap-based positioning** | A numbering scheme where items have large gaps between position values, allowing insertions without renumbering |
| **Multipart upload** | An S3 API feature that splits large files into parts, uploads them independently, and combines them server-side |
| **Placeholder user** | A user account created during Trello import representing a person who hasn't been invited yet |
| **Signature V4** | AWS's request signing algorithm authenticating S3 API calls using HMAC-SHA256 |
| **Strict board isolation** | The principle that unauthorized users receive no information about a board's existence (404, not 403) |

### E. Visual Design System — CSS Custom Properties (Design Tokens)

The `app.css` stylesheet defines CSS custom properties for consistent theming. Shuffle uses a **dark-first design** with deep purple accents and soft muted colors. Both dark (default) and light themes are defined. Theme switching is achieved by overriding custom properties on a `[data-theme="light"]` selector.

#### E.1 Design Philosophy

- **Dark-first**: The default and primary theme is dark, with a near-black base and cool purple undertone
- **Deep purple accent**: `#6D28D9` is the brand color, used for primary actions, buttons, and active states
- **Soft/muted semantics**: Status colors (success, warning, error) use warm pastels that are gentle on dark backgrounds
- **3-level surface hierarchy**: Base → Raised → Elevated, creating depth through progressive lightening
- **WCAG AA compliant**: All text/background combinations meet 4.5:1 contrast ratio minimum; large text meets 3:1

#### E.2 Dark Theme (Default)

```css
:root {
    /* ========================================
       BACKGROUNDS — 3-level surface hierarchy
       ======================================== */
    --color-base: #0D0D12;              /* Deepest background: page body, board area */
    --color-raised: #161625;            /* Mid-level surface: lanes, cards, sidebars */
    --color-elevated: #1E1E32;          /* Top-level surface: modals, dropdowns, popovers */

    /* ========================================
       PRIMARY — Deep indigo-purple accent
       ======================================== */
    --color-primary: #6D28D9;           /* Filled buttons, badges, active indicators */
    --color-primary-hover: #7C3AED;     /* Hover state for primary buttons/elements */
    --color-primary-active: #5B21B6;    /* Active/pressed state for primary buttons */
    --color-primary-text: #A78BFA;      /* Links, inline text on dark backgrounds (AA: ~7.5:1 on base) */
    --color-primary-subtle: rgba(109, 40, 217, 0.15); /* Subtle primary background tint (selected rows, active nav) */

    /* ========================================
       TEXT — Off-white with cool tint
       ======================================== */
    --color-text: #E2E2EC;              /* Primary body text, headings (AA: ~14:1 on base) */
    --color-text-secondary: #9898AC;    /* Secondary text, timestamps, metadata (AA: ~6.5:1 on base) */
    --color-text-disabled: #5A5A6E;     /* Disabled controls only — NOT for placeholder text (AA: 3.2:1 — large text only) */
    --color-placeholder: #7A7A90;       /* Placeholder text in inputs (AA: ~4.5:1 on base) — see Appendix F.7.1 */
    --color-text-inverse: #0D0D12;      /* Text on light/primary-filled backgrounds (e.g. white button text on purple) */
    --color-text-on-primary: #FFFFFF;   /* Text on primary-colored fills (buttons, badges) */

    /* ========================================
       SEMANTIC — Warm muted pastels
       ======================================== */
    --color-success: #86EFAC;           /* Success states: save confirmed, task complete, checklist done */
    --color-success-subtle: rgba(134, 239, 172, 0.12); /* Success background tint */
    --color-warning: #FDBA74;           /* Warning states: due soon, approaching limit */
    --color-warning-subtle: rgba(253, 186, 116, 0.12); /* Warning background tint */
    --color-error: #F87171;             /* Error states: delete, validation fail, overdue */
    --color-error-subtle: rgba(248, 113, 113, 0.12);   /* Error background tint */
    --color-info: #A78BFA;              /* Info states: notifications, tips, highlights */
    --color-info-subtle: rgba(167, 139, 250, 0.12);    /* Info background tint */

    /* ========================================
       BORDERS
       ======================================== */
    --color-border: #2A2A3C;            /* Default borders: cards, inputs, dividers */
    --color-border-subtle: #1E1E30;     /* Subtle separators: between list items, lanes */
    --color-border-focus: #A78BFA;      /* Focus ring color (keyboard navigation) — 2px solid */

    /* ========================================
       INTERACTIVE STATES
       ======================================== */
    --color-hover-overlay: rgba(255, 255, 255, 0.05);  /* Generic hover: cards, list items, nav items */
    --color-active-overlay: rgba(255, 255, 255, 0.08); /* Generic active/pressed overlay */
    --color-selected-bg: rgba(109, 40, 217, 0.20);     /* Selected item background (e.g. current nav item) */

    /* ========================================
       OVERLAYS & UTILITY
       ======================================== */
    --color-overlay: rgba(0, 0, 0, 0.60);    /* Modal/dialog backdrop */
    --color-scrollbar: #2A2A3C;               /* Scrollbar track/thumb resting */
    --color-scrollbar-hover: #3A3A4C;         /* Scrollbar thumb on hover */
    --color-drag-shadow: rgba(109, 40, 217, 0.30); /* Shadow on dragged cards */

    /* ========================================
       SPACING
       ======================================== */
    --space-2xs: 2px;
    --space-xs: 4px;
    --space-sm: 8px;
    --space-md: 16px;
    --space-lg: 24px;
    --space-xl: 32px;
    --space-2xl: 48px;
    --space-3xl: 64px;

    /* ========================================
       TYPOGRAPHY — Font Families
       ======================================== */
    --font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
    --font-family-mono: 'SF Mono', 'Fira Mono', Menlo, Consolas, monospace;

    /* ========================================
       TYPOGRAPHY — Font Sizes
       ======================================== */
    --font-size-xs: 0.75rem;    /* 12px — badges, timestamps */
    --font-size-sm: 0.875rem;   /* 14px — secondary text, metadata */
    --font-size-md: 1rem;       /* 16px — body text, inputs */
    --font-size-lg: 1.25rem;    /* 20px — section headings, card titles in detail view */
    --font-size-xl: 1.5rem;     /* 24px — page titles */
    --font-size-2xl: 2rem;      /* 32px — hero/onboarding headings */

    /* ========================================
       TYPOGRAPHY — Font Weights
       ======================================== */
    --font-weight-regular: 400;  /* Body text, descriptions, form inputs */
    --font-weight-medium: 500;   /* UI labels, button text, nav items, H5–H6 */
    --font-weight-semibold: 600; /* Card titles, H3–H4, emphasized metadata */
    --font-weight-bold: 700;     /* Page headings (H1–H2), strong emphasis */

    /* ========================================
       TYPOGRAPHY — Line Heights
       ======================================== */
    --line-height-tight: 1.2;    /* Headings (H1–H3), display text */
    --line-height-ui: 1.25;      /* Buttons, badges, form labels, table cells */
    --line-height-body: 1.5;     /* Body text, paragraphs, descriptions (WCAG ≥1.5) */

    /* ========================================
       TYPOGRAPHY — Letter Spacing
       ======================================== */
    --letter-spacing-tight: -0.01em;   /* H1, H2 — large headings feel tighter */
    --letter-spacing-normal: 0;        /* Body text, most UI elements */
    --letter-spacing-wide: 0.01em;     /* xs/sm text (12–14px) for legibility */

    /* ========================================
       SPACING — 4px base unit
       ======================================== */
    --space-1: 4px;     /* Micro: icon-to-text inline gap, tight dividers */
    --space-2: 8px;     /* XS: card gap, badge padding, checkbox spacing */
    --space-3: 12px;    /* SM: card internal padding (h), lane gap, list item spacing */
    --space-4: 16px;    /* MD: section padding, input padding, mobile page margin */
    --space-5: 20px;    /* Between form fields, small component groups */
    --space-6: 24px;    /* LG: desktop page padding, modal padding, card detail sections */
    --space-8: 32px;    /* XL: page section gaps, large separations */
    --space-10: 40px;   /* 2XL: major page sections, hero spacing */
    --space-12: 48px;   /* 3XL: top-level page vertical padding */
    --space-16: 64px;   /* 4XL: large hero/onboarding spacing */

    /* ========================================
       LAYOUT — Structural Dimensions
       ======================================== */
    --header-height: 48px;
    --sidebar-width: 240px;
    --sidebar-collapsed-width: 48px;
    --lane-width: 272px;
    --lane-gap: 12px;
    --content-max-width: 800px;
    --card-padding-x: 12px;
    --card-padding-y: 8px;
    --card-gap: 8px;

    /* ========================================
       LAYOUT — Modal Sizes
       ======================================== */
    --modal-width-sm: 440px;   /* Confirmations, simple forms */
    --modal-width-lg: 640px;   /* Card detail, settings, import, complex forms */
    --modal-margin: 16px;      /* Min margin from viewport edges (mobile) */

    /* ========================================
       LAYOUT — Border Radius
       ======================================== */
    --border-radius-sm: 4px;
    --border-radius-md: 6px;
    --border-radius-lg: 8px;
    --border-radius-xl: 12px;
    --border-radius-full: 9999px;

    /* ========================================
       LAYOUT — Breakpoints (reference, not tokens)
       Mobile:  < 768px
       Tablet:  768px – 1023px
       Desktop: ≥ 1024px
       ======================================== */

    /* ========================================
       SHADOWS — adjusted for dark theme
       ======================================== */
    --shadow-card: 0 1px 3px rgba(0, 0, 0, 0.3), 0 1px 2px rgba(0, 0, 0, 0.2);
    --shadow-elevated: 0 4px 16px rgba(0, 0, 0, 0.4);
    --shadow-drag: 0 8px 24px rgba(109, 40, 217, 0.3), 0 2px 8px rgba(0, 0, 0, 0.4);

    /* ========================================
       Z-INDEX LAYERS
       ======================================== */
    --z-dropdown: 100;
    --z-sticky: 150;
    --z-modal-backdrop: 200;
    --z-modal: 250;
    --z-notification: 300;
    --z-toast: 350;
    --z-drag: 400;
    --z-tooltip: 500;
}
```

#### E.3 Light Theme

Applied via `[data-theme="light"]` on the `<html>` element. Only color-related tokens are overridden; spacing, typography, layout, and z-index remain unchanged.

```css
[data-theme="light"] {
    /* Backgrounds */
    --color-base: #F5F3FF;             /* Faint purple-tinted white */
    --color-raised: #FFFFFF;
    --color-elevated: #FFFFFF;

    /* Primary */
    --color-primary: #6D28D9;           /* Same brand purple — works on light backgrounds */
    --color-primary-hover: #5B21B6;     /* Darker on hover (inverted from dark theme) */
    --color-primary-active: #4C1D95;
    --color-primary-text: #6D28D9;      /* Links — full purple works on light (AA: ~7:1 on base) */
    --color-primary-subtle: rgba(109, 40, 217, 0.08);

    /* Text */
    --color-text: #1A1A2E;             /* Near-black with purple undertone */
    --color-text-secondary: #5A5A72;
    --color-text-disabled: #9898AC;
    --color-placeholder: #7A7A8E;      /* Placeholder text (AA: ~4.5:1 on #F5F3FF base) */
    --color-text-inverse: #FFFFFF;
    --color-text-on-primary: #FFFFFF;

    /* Semantic — deeper/saturated versions for light backgrounds */
    --color-success: #16A34A;
    --color-success-subtle: rgba(22, 163, 74, 0.08);
    --color-warning: #CA8A04;
    --color-warning-subtle: rgba(202, 138, 4, 0.08);
    --color-error: #DC2626;
    --color-error-subtle: rgba(220, 38, 38, 0.08);
    --color-info: #6D28D9;
    --color-info-subtle: rgba(109, 40, 217, 0.08);

    /* Borders */
    --color-border: #E2E0EC;
    --color-border-subtle: #EEECF5;
    --color-border-focus: #6D28D9;

    /* Interactive states */
    --color-hover-overlay: rgba(0, 0, 0, 0.04);
    --color-active-overlay: rgba(0, 0, 0, 0.07);
    --color-selected-bg: rgba(109, 40, 217, 0.10);

    /* Overlays & utility */
    --color-overlay: rgba(0, 0, 0, 0.40);
    --color-scrollbar: #D0CEE0;
    --color-scrollbar-hover: #B8B6C8;
    --color-drag-shadow: rgba(109, 40, 217, 0.20);

    /* Shadows — lighter for light theme */
    --shadow-card: 0 1px 3px rgba(0, 0, 0, 0.08), 0 1px 2px rgba(0, 0, 0, 0.06);
    --shadow-elevated: 0 4px 16px rgba(0, 0, 0, 0.12);
    --shadow-drag: 0 8px 24px rgba(109, 40, 217, 0.15), 0 2px 8px rgba(0, 0, 0, 0.1);
}
```

#### E.4 Board Background Tint Presets

Users can select a board background tint from 8 presets. The tint applies as a subtle hue shift on the base background color. The default is neutral (no tint). These are applied via a `data-board-tint` attribute on the board container.

**Dark theme tints** (subtle shifts on `#0D0D12`):

| Name | Value | Description |
|---|---|---|
| `neutral` | `#0D0D12` | Default — no tint |
| `purple` | `#0F0D18` | Faint purple warmth |
| `blue` | `#0D0F18` | Cool blue undertone |
| `teal` | `#0D1214` | Cool teal/green |
| `green` | `#0D120E` | Subtle forest |
| `amber` | `#12110D` | Warm amber glow |
| `rose` | `#120D10` | Soft pink warmth |
| `slate` | `#0F0F14` | Cool neutral gray |

**Light theme tints** (subtle shifts on `#F5F3FF`):

| Name | Value | Description |
|---|---|---|
| `neutral` | `#F5F3FF` | Default — faint purple base |
| `purple` | `#F0EBFF` | Deeper lavender |
| `blue` | `#EBF0FF` | Soft sky |
| `teal` | `#E8F5F5` | Cool mint |
| `green` | `#EBF5EB` | Gentle sage |
| `amber` | `#F5F2EB` | Warm cream |
| `rose` | `#F5EBF0` | Soft blush |
| `slate` | `#EFEFF3` | Cool neutral |

#### E.5 WCAG AA Contrast Verification

All text/background combinations have been verified for WCAG 2.1 AA compliance:

**Dark theme (on `--color-base: #0D0D12`):**

| Token | Color | Contrast Ratio | Passes AA |
|---|---|---|---|
| `--color-text` | `#E2E2EC` | ~14.2:1 | ✅ Normal + large text |
| `--color-text-secondary` | `#9898AC` | ~6.5:1 | ✅ Normal + large text |
| `--color-text-disabled` | `#5A5A6E` | ~3.2:1 | ⚠️ Large text only (18px+) |
| `--color-primary-text` (links) | `#A78BFA` | ~7.5:1 | ✅ Normal + large text |
| `--color-success` | `#86EFAC` | ~12.0:1 | ✅ Normal + large text |
| `--color-warning` | `#FDBA74` | ~10.5:1 | ✅ Normal + large text |
| `--color-error` | `#F87171` | ~6.8:1 | ✅ Normal + large text |
| `--color-info` | `#A78BFA` | ~7.5:1 | ✅ Normal + large text |
| `--color-text-on-primary` (on `#6D28D9`) | `#FFFFFF` | ~7.2:1 | ✅ Normal + large text |

**Dark theme (on `--color-raised: #161625`):**

| Token | Color | Contrast Ratio | Passes AA |
|---|---|---|---|
| `--color-text` | `#E2E2EC` | ~11.8:1 | ✅ Normal + large text |
| `--color-text-secondary` | `#9898AC` | ~5.5:1 | ✅ Normal + large text |
| `--color-primary-text` (links) | `#A78BFA` | ~6.3:1 | ✅ Normal + large text |

**Light theme (on `--color-base: #F5F3FF`):**

| Token | Color | Contrast Ratio | Passes AA |
|---|---|---|---|
| `--color-text` | `#1A1A2E` | ~14.5:1 | ✅ Normal + large text |
| `--color-text-secondary` | `#5A5A72` | ~5.8:1 | ✅ Normal + large text |
| `--color-primary-text` (links) | `#6D28D9` | ~7.0:1 | ✅ Normal + large text |

---

#### E.6 Typography System

##### Font Loading Strategy

The font stack uses **system fonts only** — no web fonts to download. This guarantees zero font-loading latency, no FOIT/FOUT, and optimal performance. The system stack adapts to each OS:

| OS | Primary Resolved Font |
|---|---|
| macOS / iOS | SF Pro (via `-apple-system`) |
| Windows | Segoe UI |
| Android | Roboto |
| Linux | Ubuntu / system sans-serif |

Monospace follows the same strategy: SF Mono → Fira Mono → Menlo → Consolas → fallback. No ligature fonts (e.g., Fira Code) are included to ensure predictable code rendering in card descriptions.

##### Heading Hierarchy

All headings use `--line-height-tight: 1.2`. H1 and H2 apply `--letter-spacing-tight: -0.01em`; H3–H6 use `--letter-spacing-normal: 0`.

| Level | Font Size Token | Computed Size | Weight Token | Weight | Use Cases |
|---|---|---|---|---|---|
| H1 | `--font-size-2xl` | 32px | `--font-weight-bold` | 700 | Hero/onboarding headings (one per page max) |
| H2 | `--font-size-xl` | 24px | `--font-weight-bold` | 700 | Page titles (board name, settings page) |
| H3 | `--font-size-lg` | 20px | `--font-weight-semibold` | 600 | Section headings, card detail title |
| H4 | `--font-size-md` | 16px | `--font-weight-semibold` | 600 | Sub-section headings, modal titles |
| H5 | `--font-size-sm` | 14px | `--font-weight-medium` | 500 | Group labels, sidebar section headers |
| H6 | `--font-size-xs` | 12px | `--font-weight-medium` | 500 | Overline labels, fine-grained grouping |

Heading margins: `margin-top: 1.5em; margin-bottom: 0.5em` (collapsed when heading is the first child of its container).

##### Body Text Variants

| Variant | Size Token | Computed | Weight | Line Height | Letter Spacing | Use Cases |
|---|---|---|---|---|---|---|
| Body (default) | `--font-size-md` | 16px | 400 | `--line-height-body` (1.5) | `normal` | Card descriptions, comments, form helper text |
| Body small | `--font-size-sm` | 14px | 400 | `--line-height-body` (1.5) | `--letter-spacing-wide` (0.01em) | Metadata, secondary descriptions |
| Caption | `--font-size-xs` | 12px | 400 | `--line-height-body` (1.5) | `--letter-spacing-wide` (0.01em) | Timestamps, file sizes, badge text |
| Strong | inherit | inherit | 700 | inherit | inherit | Inline emphasis (`<strong>`, `**markdown**`) |
| Code (inline) | `--font-size-sm` | 14px | 400 | `--line-height-ui` (1.25) | `normal` | Inline code snippets; uses `--font-family-mono` |

##### UI Text Variants

These are used for interactive and structural UI elements, all using `--line-height-ui: 1.25`:

| Variant | Size Token | Computed | Weight | Letter Spacing | Use Cases |
|---|---|---|---|---|---|
| Button text | `--font-size-sm` | 14px | 500 | `normal` | All button labels |
| Button text (large) | `--font-size-md` | 16px | 500 | `normal` | Large/primary CTA buttons |
| Form label | `--font-size-sm` | 14px | 500 | `normal` | Input labels, select labels |
| Form input | `--font-size-md` | 16px | 400 | `normal` | Text inputs, textareas, selects |
| Placeholder | `--font-size-md` | 16px | 400 | `normal` | Input placeholders; color: `--color-placeholder` (AA-compliant) |
| Nav item | `--font-size-sm` | 14px | 500 | `normal` | Sidebar nav, top nav links |
| Nav item (active) | `--font-size-sm` | 14px | 600 | `normal` | Currently selected nav item |
| Badge | `--font-size-xs` | 12px | 500 | `--letter-spacing-wide` (0.01em) | Status badges, count indicators |
| Tooltip | `--font-size-xs` | 12px | 400 | `--letter-spacing-wide` (0.01em) | Tooltip text content |

##### Link Styling

Links within body text (not navigation) use the following treatment:

```css
a {
    color: var(--color-primary-text);        /* #A78BFA dark / #6D28D9 light */
    text-decoration: underline;              /* Always visible — not color-only */
    text-decoration-color: currentColor;
    text-underline-offset: 2px;              /* Prevents underline from touching descenders */
    text-decoration-thickness: 1px;
}
a:hover {
    text-decoration-thickness: 2px;          /* Thicker underline on hover for feedback */
}
a:focus-visible {
    outline: 2px solid var(--color-border-focus);
    outline-offset: 2px;
    border-radius: 2px;
}
```

Navigation links (sidebar, top bar) do **not** use underlines — they rely on background color changes and weight shifts for active/hover states (defined in the component design section).

##### Card Title Truncation

Card titles in the board (column) view use a **2-line clamp** to maintain consistent card height:

```css
.card-title {
    font-size: var(--font-size-sm);          /* 14px */
    font-weight: var(--font-weight-semibold); /* 600 */
    line-height: var(--line-height-body);     /* 1.5 */
    letter-spacing: var(--letter-spacing-normal);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;
    /* Max visible height: 14px × 1.5 × 2 = 42px */
}
```

In card detail view (modal/panel), titles are **not** truncated and display at `--font-size-lg` (20px) with `--font-weight-semibold` (600).

##### Markdown Rendered Content

Card descriptions and comments render Markdown via Parsedown. The `.markdown-body` container applies refined typography:

```css
.markdown-body {
    font-size: var(--font-size-md);
    line-height: var(--line-height-body);
    color: var(--color-text);
}

/* Paragraphs — tighter spacing than standard */
.markdown-body p {
    margin: 0 0 0.75em 0;
}
.markdown-body p:last-child {
    margin-bottom: 0;
}

/* Headings within markdown — scale down by one level visually */
.markdown-body h1 { font-size: var(--font-size-xl); font-weight: var(--font-weight-bold); }
.markdown-body h2 { font-size: var(--font-size-lg); font-weight: var(--font-weight-bold); }
.markdown-body h3 { font-size: var(--font-size-md); font-weight: var(--font-weight-semibold); }
.markdown-body h4 { font-size: var(--font-size-sm); font-weight: var(--font-weight-semibold); }
.markdown-body h1, .markdown-body h2, .markdown-body h3, .markdown-body h4 {
    line-height: var(--line-height-tight);
    margin: 1.25em 0 0.5em 0;
}

/* Blockquotes */
.markdown-body blockquote {
    border-left: 3px solid var(--color-primary);
    background: var(--color-primary-subtle);
    padding: 0.5em 1em;
    margin: 0.75em 0;
    border-radius: 0 var(--border-radius-sm) var(--border-radius-sm) 0;
    color: var(--color-text-secondary);
}

/* Code blocks */
.markdown-body pre {
    background: var(--color-raised);
    border: 1px solid var(--color-border-subtle);
    border-radius: var(--border-radius-md);
    padding: 0.75em 1em;
    margin: 0.75em 0;
    overflow-x: auto;
    font-family: var(--font-family-mono);
    font-size: var(--font-size-sm);
    line-height: 1.6;
}

/* Inline code */
.markdown-body code:not(pre code) {
    background: var(--color-primary-subtle);
    padding: 0.15em 0.4em;
    border-radius: var(--border-radius-sm);
    font-family: var(--font-family-mono);
    font-size: 0.875em;  /* Relative to parent — slightly smaller than surrounding text */
}

/* Tables */
.markdown-body table {
    width: 100%;
    border-collapse: collapse;
    margin: 0.75em 0;
    font-size: var(--font-size-sm);
}
.markdown-body th {
    font-weight: var(--font-weight-semibold);
    text-align: left;
    padding: 0.5em 0.75em;
    border-bottom: 2px solid var(--color-border);
    color: var(--color-text);
}
.markdown-body td {
    padding: 0.5em 0.75em;
    border-bottom: 1px solid var(--color-border-subtle);
}
.markdown-body tr:nth-child(even) td {
    background: var(--color-hover-overlay);
}

/* Task lists (Parsedown renders as <ul> with <input type="checkbox">) */
.markdown-body input[type="checkbox"] {
    appearance: none;
    width: 16px;
    height: 16px;
    border: 2px solid var(--color-border);
    border-radius: var(--border-radius-sm);
    vertical-align: middle;
    margin-right: 0.5em;
    position: relative;
    cursor: default;
}
.markdown-body input[type="checkbox"]:checked {
    background: var(--color-primary);
    border-color: var(--color-primary);
}
.markdown-body input[type="checkbox"]:checked::after {
    content: '';
    position: absolute;
    left: 3px;
    top: 0px;
    width: 6px;
    height: 10px;
    border: solid var(--color-text-on-primary);
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}

/* Horizontal rules */
.markdown-body hr {
    border: none;
    height: 1px;
    background: var(--color-border);
    margin: 1.5em 0;
}

/* Lists */
.markdown-body ul, .markdown-body ol {
    padding-left: 1.5em;
    margin: 0.5em 0;
}
.markdown-body li {
    margin: 0.25em 0;
}
.markdown-body li > p {
    margin: 0.25em 0;
}

/* Images in markdown */
.markdown-body img {
    max-width: 100%;
    height: auto;
    border-radius: var(--border-radius-md);
    margin: 0.75em 0;
}
```

##### Responsive Typography

The font system uses `rem` units anchored to the `<html>` element's `font-size`. No font scaling adjustments are applied at different breakpoints — the system font stack and `1rem = 16px` base provide consistent readability across devices. Body text at 16px meets WCAG minimum requirements at all viewport sizes.

If future testing reveals readability issues on very small screens, a single adjustment can be applied:

```css
/* Reserved — not applied at MVP */
@media (max-width: 359px) {
    html { font-size: 14px; }
}
```

##### Text Color Application Summary

| Element | Color Token | Theme-Agnostic? |
|---|---|---|
| Body text, headings | `--color-text` | ✅ |
| Secondary text, metadata, timestamps | `--color-text-secondary` | ✅ |
| Disabled text, placeholder | `--color-text-disabled` | ✅ |
| Links | `--color-primary-text` | ✅ |
| Text on primary-colored backgrounds | `--color-text-on-primary` | ✅ |
| Text on inverted backgrounds | `--color-text-inverse` | ✅ |
| Error messages | `--color-error` | ✅ |
| Success messages | `--color-success` | ✅ |

---

#### E.7 Spacing & Layout System

##### Spacing Scale

All spacing in the system is based on a **4px base unit**. Named spacing tokens cover the full range of UI needs:

| Token | Value | Use Cases |
|---|---|---|
| `--space-1` | 4px | Icon-to-text inline gaps, tight dividers, micro adjustments |
| `--space-2` | 8px | Card gap, badge inline padding, checkbox-to-label, small component gaps |
| `--space-3` | 12px | Card horizontal padding, lane gap, list item vertical spacing |
| `--space-4` | 16px | Input padding, section internal padding, mobile page margins |
| `--space-5` | 20px | Gap between form fields, small component group spacing |
| `--space-6` | 24px | Desktop page padding, modal padding, card detail section gaps |
| `--space-8` | 32px | Page section gaps, large vertical separations |
| `--space-10` | 40px | Major page sections, hero area padding |
| `--space-12` | 48px | Top-level page vertical padding, equals header height |
| `--space-16` | 64px | Large hero/onboarding vertical spacing |

**Rule**: Every padding, margin, and gap value in the application **must** use one of these tokens. No arbitrary pixel values.

##### Breakpoints

Three responsive tiers with mobile-first media queries:

| Tier | Range | Sidebar | Board Layout | Page Padding |
|---|---|---|---|---|
| **Mobile** | < 768px | Hidden (hamburger toggle) | Horizontal scroll, lanes at 272px with peek | 16px (`--space-4`) |
| **Tablet** | 768–1023px | Collapsible (starts collapsed) | Horizontal scroll, sidebar overlay when open | 24px (`--space-6`) |
| **Desktop** | ≥ 1024px | Visible, collapsible (starts open) | Horizontal scroll, sidebar pushes content | 24px (`--space-6`) |

```css
/* Breakpoint media queries */
@media (max-width: 767px)  { /* Mobile styles */ }
@media (min-width: 768px)  { /* Tablet+ styles */ }
@media (min-width: 1024px) { /* Desktop styles */ }
```

##### Page Layout Structure

The application uses a fixed-header layout with a collapsible sidebar:

```
┌──────────────────────────────────────────────────────┐
│  Top Header Bar (48px, full width, fixed)             │
├────────────┬─────────────────────────────────────────┤
│            │                                         │
│  Sidebar   │  Main Content Area                      │
│  (240px)   │  (flex: 1, scrollable)                  │
│            │                                         │
│  - Logo    │  Board view: lanes scroll horizontally  │
│  - Nav     │  Other pages: centered, max 800px       │
│  - Boards  │                                         │
│            │                                         │
│  Collapse  │                                         │
│  toggle ▶  │                                         │
│            │                                         │
└────────────┴─────────────────────────────────────────┘
```

```css
/* Core layout structure */
.app-layout {
    display: flex;
    flex-direction: column;
    height: 100vh;
    overflow: hidden;
}

.app-header {
    height: var(--header-height);          /* 48px */
    flex-shrink: 0;
    position: sticky;
    top: 0;
    z-index: var(--z-sticky);
}

.app-body {
    display: flex;
    flex: 1;
    overflow: hidden;
}

.app-sidebar {
    width: var(--sidebar-width);           /* 240px */
    flex-shrink: 0;
    overflow-y: auto;
    transition: width 0.2s ease-in-out;
}

.app-sidebar[data-collapsed="true"] {
    width: var(--sidebar-collapsed-width); /* 48px */
}

.app-main {
    flex: 1;
    overflow: auto;
}
```

##### Sidebar

| Property | Value | Notes |
|---|---|---|
| Width (open) | 240px (`--sidebar-width`) | Fixed, not resizable |
| Width (collapsed) | 48px (`--sidebar-collapsed-width`) | Shows icons only, tooltips on hover |
| Collapse transition | `width 0.2s ease-in-out` | Smooth collapse/expand |
| Internal padding | 12px (`--space-3`) | Left and right |
| Section gap | 24px (`--space-6`) | Between nav groups (e.g., navigation vs board list) |
| Item height | 36px | Nav items, board list entries — meets 44px touch target via padding |
| Item padding | 8px vertical, 12px horizontal | Within each nav/board item |
| Item border-radius | `--border-radius-md` (6px) | Rounded hover/active backgrounds |
| Collapse toggle | Bottom of sidebar | Icon button, 48×36px, keyboard accessible |
| Persistence | `localStorage` key: `sidebar-collapsed` | Remembers user preference |

**Mobile behavior (< 768px):** Sidebar is hidden by default. Hamburger button in the header opens it as a **full-height overlay** with backdrop (`--color-overlay`). Closes on backdrop click, Escape key, or navigation.

**Tablet behavior (768–1023px):** Sidebar starts collapsed (48px icons). Can expand to full 240px. When expanded, overlays the content area (does not push content).

**Desktop behavior (≥ 1024px):** Sidebar starts open at 240px. Can be collapsed to 48px. Sidebar **pushes** the main content area (no overlay).

##### Board View Layout

The board is a **horizontally scrolling flex container** of lanes:

```css
.board-canvas {
    display: flex;
    gap: var(--lane-gap);                  /* 12px */
    padding: var(--space-3) var(--space-6); /* 12px top/bottom, 24px left/right (desktop) */
    overflow-x: auto;
    overflow-y: hidden;
    height: 100%;                           /* Fill below header */
    align-items: flex-start;
}

/* Mobile padding */
@media (max-width: 767px) {
    .board-canvas {
        padding: var(--space-2) var(--space-4); /* 8px top/bottom, 16px left/right */
    }
}
```

**Lane structure:**

```css
.lane {
    width: var(--lane-width);              /* 272px */
    min-width: var(--lane-width);          /* Prevent shrinking */
    max-height: 100%;                      /* Constrained to board canvas */
    display: flex;
    flex-direction: column;
    background: var(--color-raised);
    border-radius: var(--border-radius-lg); /* 8px */
    overflow: hidden;
}

.lane-header {
    padding: var(--space-3) var(--space-3); /* 12px all sides */
    flex-shrink: 0;
}

.lane-body {
    flex: 1;
    overflow-y: auto;
    padding: 0 var(--space-2) var(--space-2); /* 0 top, 8px sides and bottom */
}

.lane-footer {
    padding: var(--space-2) var(--space-3); /* 8px top/bottom, 12px sides */
    flex-shrink: 0;
}
```

**Lane header:** Displays lane title (sentence case, `--font-weight-semibold`, `--font-size-sm`) and card count badge. Editable inline on click.

**Lane footer:** Contains the "+ Add Card" button. Always visible at the bottom of the lane.

##### Ghost Lane (Add Lane)

The "Add Lane" placeholder appears as the last column in the board:

```css
.lane-ghost {
    width: var(--lane-width);              /* 272px — same as regular lanes */
    min-width: var(--lane-width);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding-top: var(--space-3);           /* 12px — aligns with lane headers */
    flex-shrink: 0;
}

.lane-ghost-button {
    width: 100%;
    padding: var(--space-3);              /* 12px */
    border: 2px dashed var(--color-border-subtle);
    border-radius: var(--border-radius-lg); /* 8px */
    background: transparent;
    color: var(--color-text-secondary);
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-medium);
    cursor: pointer;
    transition: border-color 0.15s ease, color 0.15s ease, background 0.15s ease;
}

.lane-ghost-button:hover {
    border-color: var(--color-primary);
    color: var(--color-primary-text);
    background: var(--color-primary-subtle);
}
```

##### Card Layout (Board View)

Individual cards within a lane:

```css
.card {
    background: var(--color-elevated);
    border-radius: var(--border-radius-md); /* 6px */
    padding: var(--card-padding-y) var(--card-padding-x); /* 8px 12px */
    box-shadow: var(--shadow-card);
    cursor: pointer;
    transition: box-shadow 0.15s ease, transform 0.15s ease;
}

.card + .card {
    margin-top: var(--card-gap);           /* 8px */
}

.card:hover {
    box-shadow: var(--shadow-elevated);
}

.card[data-dragging="true"] {
    box-shadow: var(--shadow-drag);
    transform: rotate(2deg);
    opacity: 0.9;
    z-index: var(--z-drag);
}
```

**Card content stack** (top to bottom within card):
1. **Labels** — Colored pills, top of card, `margin-bottom: --space-1` (4px)
2. **Title** — 2-line clamp (see Typography § E.6)
3. **Metadata row** — Due date, comment count, checklist progress, attachment indicator; `margin-top: --space-2` (8px); uses `--font-size-xs`, `--color-text-secondary`
4. **Assignees** — Avatar stack, bottom right, `margin-top: --space-2` (8px)

##### Mobile Board Behavior (< 768px)

Lanes remain at 272px width and scroll horizontally. The viewport shows approximately one lane at a time with a **peek** of the next lane (~24px visible) to signal scrollability:

```css
@media (max-width: 767px) {
    .board-canvas {
        scroll-snap-type: x mandatory;
        -webkit-overflow-scrolling: touch;
    }

    .lane {
        scroll-snap-align: start;
    }
}
```

A **lane indicator** (dot navigation) appears below the board header on mobile to show which lane is in view. This is purely visual — swiping is the primary interaction.

##### Modal Layout

Modals center vertically and horizontally with a backdrop overlay:

```css
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: var(--color-overlay);
    z-index: var(--z-modal-backdrop);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--modal-margin);          /* 16px from viewport edges */
}

.modal {
    background: var(--color-elevated);
    border-radius: var(--border-radius-xl); /* 12px */
    box-shadow: var(--shadow-elevated);
    z-index: var(--z-modal);
    width: 100%;
    max-height: calc(100vh - var(--modal-margin) * 2); /* Respect viewport */
    overflow-y: auto;
    padding: var(--space-6);               /* 24px */
}

.modal--sm {
    max-width: var(--modal-width-sm);      /* 440px */
}

.modal--lg {
    max-width: var(--modal-width-lg);      /* 640px */
}
```

**Modal header:** Title (`--font-size-lg`, `--font-weight-semibold`) + close button (top right, icon-only, 36×36px).

**Modal footer (if actions):** Right-aligned buttons with `--space-3` (12px) gap between them. `padding-top: --space-6` (24px) to separate from content.

**Card detail modal** uses `--lg` size (640px). Internal sections separated by `--space-6` (24px) gaps. Sections: title, description, checklists, attachments, comments, sidebar metadata.

##### Non-Board Page Layout

Settings, user management, search results, and other non-board pages use a centered, max-width container:

```css
.page-content {
    max-width: var(--content-max-width);   /* 800px */
    margin: 0 auto;
    padding: var(--space-6);               /* 24px */
}

@media (max-width: 767px) {
    .page-content {
        padding: var(--space-4);           /* 16px */
    }
}
```

##### Component Spacing Quick Reference

| Context | Property | Token | Value |
|---|---|---|---|
| Card internal padding | padding | `--card-padding-y` / `--card-padding-x` | 8px / 12px |
| Card-to-card gap | margin-top | `--card-gap` | 8px |
| Lane-to-lane gap | gap | `--lane-gap` | 12px |
| Lane internal padding (header) | padding | `--space-3` | 12px |
| Lane internal padding (body) | padding | `0 --space-2 --space-2` | 0 8px 8px |
| Form field gap | gap / margin | `--space-5` | 20px |
| Input padding | padding | `--space-2 --space-3` | 8px 12px |
| Button padding (default) | padding | `--space-2 --space-4` | 8px 16px |
| Modal internal padding | padding | `--space-6` | 24px |
| Desktop page margin | padding | `--space-6` | 24px |
| Mobile page margin | padding | `--space-4` | 16px |
| Section separators | margin/padding | `--space-8` | 32px |
| Header height | height | `--header-height` | 48px |
| Sidebar width | width | `--sidebar-width` | 240px |

---

#### E.8 Component Design System

##### Buttons

Four variants, three sizes, with consistent border-radius `--border-radius-lg` (8px).

**Variants:**

| Variant | Background | Text Color | Border | Use Cases |
|---|---|---|---|---|
| **Primary** | `--color-primary` | `--color-text-on-primary` | none | Main actions: Save, Create, Confirm |
| **Secondary** | transparent | `--color-text` | 1px solid `--color-border` | Cancel, secondary actions, filters |
| **Ghost** | transparent | `--color-text-secondary` | none | Toolbar actions, inline actions, close |
| **Danger** | `--color-error` | `#FFFFFF` | none | Delete, remove, destructive actions |

**Sizes:**

| Size | Height | Font Size | Padding | Icon Size | Icon-Only Width |
|---|---|---|---|---|---|
| **Small** | 28px | `--font-size-xs` (12px) | 4px 12px | 14px | 28px |
| **Default** | 36px | `--font-size-sm` (14px) | 8px 16px | 16px | 36px |
| **Large** | 44px | `--font-size-md` (16px) | 10px 24px | 20px | 44px |

All buttons use `--font-weight-medium` (500) and `--line-height-ui` (1.25).

**Interactive States:**

```css
/* Primary button — full specification */
.btn-primary {
    background: var(--color-primary);
    color: var(--color-text-on-primary);
    border: none;
    border-radius: var(--border-radius-lg);  /* 8px */
    font-weight: var(--font-weight-medium);
    cursor: pointer;
    transition: background 0.15s ease, box-shadow 0.15s ease, transform 0.1s ease;
}
.btn-primary:hover {
    background: var(--color-primary-hover);
}
.btn-primary:active {
    background: var(--color-primary-active);
    transform: scale(0.98);
}
.btn-primary:focus-visible {
    outline: 2px solid var(--color-border-focus);
    outline-offset: 2px;
}
.btn-primary:disabled {
    background: var(--color-text-disabled);
    color: var(--color-base);
    cursor: not-allowed;
    opacity: 0.6;
}

/* Secondary button */
.btn-secondary {
    background: transparent;
    color: var(--color-text);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-lg);
}
.btn-secondary:hover {
    background: var(--color-hover-overlay);
    border-color: var(--color-text-secondary);
}
.btn-secondary:active {
    background: var(--color-active-overlay);
}

/* Ghost button */
.btn-ghost {
    background: transparent;
    color: var(--color-text-secondary);
    border: none;
    border-radius: var(--border-radius-lg);
}
.btn-ghost:hover {
    background: var(--color-hover-overlay);
    color: var(--color-text);
}
.btn-ghost:active {
    background: var(--color-active-overlay);
}

/* Danger button */
.btn-danger {
    background: var(--color-error);
    color: #FFFFFF;
    border: none;
    border-radius: var(--border-radius-lg);
}
.btn-danger:hover {
    filter: brightness(1.1);
}
.btn-danger:active {
    filter: brightness(0.95);
    transform: scale(0.98);
}
```

**Icon-Only Buttons:**

Square with matching height/width and same border-radius as text buttons. Always require `aria-label` and display a tooltip on hover (500ms delay).

```css
.btn-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    /* width = height, determined by size class */
    padding: 0;
    border-radius: var(--border-radius-lg);  /* 8px */
}
/* Size: .btn-icon.btn-sm = 28×28, .btn-icon = 36×36, .btn-icon.btn-lg = 44×44 */
```

**Button with Icon + Text:**

Icon placed left of text by default. Gap between icon and text: `--space-2` (8px). Icon size matches the size tier (see sizes table).

**Loading State:**

When a button action is in progress, the button text is replaced with a spinner (16px for default, scaled per size). The button is disabled and maintains its width to prevent layout shift.

##### Form Elements

**Text Inputs & Textareas:**

Filled style — subtle background, no border at rest, border appears on focus.

```css
.input {
    height: 36px;
    width: 100%;
    padding: var(--space-2) var(--space-3);   /* 8px 12px */
    background: var(--color-raised);
    border: 1px solid transparent;             /* No visible border at rest */
    border-radius: var(--border-radius-md);    /* 6px */
    font-family: var(--font-family);
    font-size: var(--font-size-md);            /* 16px */
    font-weight: var(--font-weight-regular);
    line-height: var(--line-height-ui);
    color: var(--color-text);
    transition: border-color 0.15s ease, background 0.15s ease;
}
.input::placeholder {
    color: var(--color-placeholder);    /* Meets AA 4.5:1 — NOT --color-text-disabled */
}
.input:hover {
    background: var(--color-elevated);
}
.input:focus {
    outline: none;
    border-color: var(--color-border-focus);    /* Purple focus ring */
    background: var(--color-elevated);
}
.input:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Textarea — same styles, auto-height */
.textarea {
    /* Inherits .input styles */
    height: auto;
    min-height: 80px;
    resize: vertical;
    line-height: var(--line-height-body);       /* 1.5 for multi-line */
}
```

**Form Labels:**

Positioned **above** the input with a 4px gap:

```css
.form-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);                        /* 4px label-to-input gap */
}
.form-group + .form-group {
    margin-top: var(--space-5);                 /* 20px between field groups */
}
.form-label {
    font-size: var(--font-size-sm);            /* 14px */
    font-weight: var(--font-weight-medium);    /* 500 */
    line-height: var(--line-height-ui);
    color: var(--color-text);
}
.form-label--required::after {
    content: ' *';
    color: var(--color-error);
}
```

**Validation States:**

Inline error below the input with an icon indicator inside the input:

```css
/* Error state */
.input--error {
    border-color: var(--color-error);
    background: var(--color-error-subtle);
}
.input-error-icon {
    position: absolute;
    right: var(--space-3);                     /* 12px from right edge */
    top: 50%;
    transform: translateY(-50%);
    color: var(--color-error);
    width: 16px;
    height: 16px;
    /* ⚠ triangle icon or ✕ circle icon */
}
.form-error-message {
    font-size: var(--font-size-xs);            /* 12px */
    color: var(--color-error);
    margin-top: var(--space-1);                /* 4px below input */
    line-height: var(--line-height-body);
    display: flex;
    align-items: center;
    gap: var(--space-1);                       /* 4px icon-to-text */
}

/* Success state (post-validation) */
.input--success {
    border-color: var(--color-success);
}
```

**Select / Custom Dropdown:**

Native `<select>` with custom styling for consistency. Falls back gracefully to native mobile pickers:

```css
.select {
    /* Same as .input */
    appearance: none;
    padding-right: var(--space-8);             /* 32px — room for chevron icon */
    background-image: url('...chevron-down.svg');
    background-repeat: no-repeat;
    background-position: right var(--space-3) center;
    background-size: 16px;
}
```

**Checkboxes:**

Custom-styled checkboxes replacing native appearance:

```css
.checkbox {
    appearance: none;
    width: 18px;
    height: 18px;
    border: 2px solid var(--color-border);
    border-radius: var(--border-radius-sm);    /* 4px */
    background: transparent;
    cursor: pointer;
    transition: background 0.15s ease, border-color 0.15s ease;
    flex-shrink: 0;
}
.checkbox:hover {
    border-color: var(--color-primary);
}
.checkbox:checked {
    background: var(--color-primary);
    border-color: var(--color-primary);
    /* Checkmark via pseudo-element or inline SVG */
}
.checkbox:focus-visible {
    outline: 2px solid var(--color-border-focus);
    outline-offset: 2px;
}
.checkbox:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
```

Checkbox-to-label gap: `--space-2` (8px). Labels are clickable (associated via `for` attribute).

##### Header Bar

The top header is 48px tall, full-width, fixed to the top:

```
┌─────────────────────────────────────────────────────────────────┐
│ [☰] Logo/Name          [ 🔍 Search input... ]    [🔔] [Avatar] │
└─────────────────────────────────────────────────────────────────┘
```

| Zone | Content | Alignment |
|---|---|---|
| **Left** | Hamburger menu (mobile/tablet), app logo or name | flex-start |
| **Center** | Search input (expandable, `max-width: 480px`) | centered |
| **Right** | Notification bell, user avatar → dropdown | flex-end |

```css
.app-header {
    height: var(--header-height);              /* 48px */
    background: var(--color-raised);
    border-bottom: 1px solid var(--color-border-subtle);
    display: flex;
    align-items: center;
    padding: 0 var(--space-4);                 /* 0 16px */
    gap: var(--space-4);                       /* 16px between zones */
    z-index: var(--z-sticky);
}

.header-search {
    flex: 1;
    max-width: 480px;
    margin: 0 auto;
}

.header-search .input {
    height: 32px;                              /* Compact in header context */
    font-size: var(--font-size-sm);
    border-radius: var(--border-radius-full);  /* Pill-shaped search bar */
}
```

**Mobile (< 768px):** Search input collapses to an icon button. Tapping it expands the search to fill the header (animated). Hamburger icon replaces the logo for sidebar toggle.

##### Avatars

Rounded square, three sizes. Initials fallback when no image uploaded:

| Size | Dimensions | Border Radius | Font Size | Use Cases |
|---|---|---|---|---|
| Small | 24×24px | `--border-radius-sm` (4px) | 10px | Card assignees, inline mentions |
| Medium | 32×32px | `--border-radius-md` (6px) | 13px | Header, comment authors, member lists |
| Large | 48×48px | `--border-radius-lg` (8px) | 18px | Profile/settings page, user management |

```css
.avatar {
    border-radius: var(--border-radius-md);
    object-fit: cover;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

/* Initials fallback — colored background from user's name hash */
.avatar--initials {
    background: var(--avatar-bg);              /* Computed per user */
    color: #FFFFFF;
    font-weight: var(--font-weight-semibold);
    text-transform: uppercase;
}
```

**Avatar stack** (card assignees): Overlapping, right-to-left, with `-6px` margin-left. If > 3 assignees, show first 3 + "+N" count circle.

**Initials color generation:** Hash the user's display name to select from 8 preset background colors: `#6D28D9` (purple), `#2563EB` (blue), `#0891B2` (teal), `#16A34A` (green), `#CA8A04` (amber), `#DC2626` (red), `#9333EA` (violet), `#4F46E5` (indigo). These are chosen to meet AA contrast on white text.

##### Notification Bell & Dropdown

**Bell indicator:** Small red dot (8px diameter) appears at the top-right corner of the bell icon when there are unread notifications. No count displayed.

```css
.notification-dot {
    position: absolute;
    top: 2px;
    right: 2px;
    width: 8px;
    height: 8px;
    border-radius: var(--border-radius-full);
    background: var(--color-error);
    border: 2px solid var(--color-raised);     /* Creates visual separation from icon */
}
```

**Notification dropdown panel:** Opens below the bell icon. Width: 360px. Max height: 480px with scroll. Shows latest notifications grouped by time (Today, Yesterday, Older).

Each notification item:
- Height: auto (min 48px)
- Padding: `--space-3` (12px)
- Unread: left border 3px `--color-primary`, background `--color-primary-subtle`
- Read: no left border, transparent background
- Hover: `--color-hover-overlay`
- Content: Avatar (24px) + text + timestamp, single or two lines

##### Tooltips

Dark pill tooltip, appears on hover after 500ms delay:

```css
.tooltip {
    background: #1A1A2E;                       /* Near-black, works in both themes */
    color: #FFFFFF;
    font-size: var(--font-size-xs);            /* 12px */
    font-weight: var(--font-weight-regular);
    line-height: var(--line-height-ui);
    padding: var(--space-1) var(--space-2);    /* 4px 8px */
    border-radius: var(--border-radius-sm);    /* 4px */
    z-index: var(--z-tooltip);
    pointer-events: none;
    max-width: 200px;
    text-align: center;
    white-space: nowrap;
}

/* Arrow/caret — CSS triangle pointing toward trigger */
.tooltip::after {
    content: '';
    position: absolute;
    border: 5px solid transparent;
    /* Direction set per placement: top, bottom, left, right */
}
.tooltip[data-placement="bottom"]::after {
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    border-bottom-color: #1A1A2E;
}
```

Tooltip positioning: Prefer **bottom**. If insufficient viewport space, flip to top, then left/right. Implemented via simple JS positioning (no library).

##### Dropdown Menus

Elevated card style for user menus, context menus, and custom selects:

```css
.dropdown {
    background: var(--color-elevated);
    border: 1px solid var(--color-border-subtle);
    border-radius: var(--border-radius-lg);    /* 8px */
    box-shadow: var(--shadow-elevated);
    z-index: var(--z-dropdown);
    min-width: 180px;
    max-width: 280px;
    padding: var(--space-1) 0;                 /* 4px top/bottom */
    overflow-y: auto;
    max-height: 320px;
}

.dropdown-item {
    height: 36px;
    display: flex;
    align-items: center;
    padding: 0 var(--space-3);                 /* 0 12px */
    gap: var(--space-2);                       /* 8px icon-to-text */
    font-size: var(--font-size-sm);
    color: var(--color-text);
    cursor: pointer;
    transition: background 0.1s ease;
}
.dropdown-item:hover {
    background: var(--color-hover-overlay);
}
.dropdown-item:active {
    background: var(--color-active-overlay);
}
.dropdown-item--danger {
    color: var(--color-error);
}

.dropdown-divider {
    height: 1px;
    background: var(--color-border-subtle);
    margin: var(--space-1) 0;                  /* 4px vertical margin */
}
```

Dropdown opens below the trigger by default. If insufficient space below, opens above. Closes on: outside click, Escape key, item selection.

##### Card Labels (Color Bars)

In board view, labels appear as small color bars at the top of the card. In card detail view, they expand to show text.

**Board view (compact):**

```css
.card-labels {
    display: flex;
    gap: var(--space-1);                       /* 4px between bars */
    flex-wrap: wrap;
    margin-bottom: var(--space-1);             /* 4px below labels */
}

.card-label-bar {
    width: 40px;
    height: 8px;
    border-radius: var(--border-radius-sm);    /* 4px */
    /* background set per label color */
}
.card-label-bar:hover {
    height: 16px;                              /* Expand slightly on hover */
    transition: height 0.1s ease;
}
```

**Card detail view (expanded):**

```css
.card-label-pill {
    display: inline-flex;
    align-items: center;
    height: 24px;
    padding: 0 var(--space-2);                 /* 0 8px */
    border-radius: var(--border-radius-full);  /* Pill shape */
    font-size: var(--font-size-xs);
    font-weight: var(--font-weight-medium);
    /* background and text color set per label */
}
```

**Label color presets** (8 colors, selected by user when creating a label):

| Name | Background | Text Color |
|---|---|---|
| Red | `#EF4444` | `#FFFFFF` |
| Orange | `#F97316` | `#FFFFFF` |
| Amber | `#EAB308` | `#1A1A2E` |
| Green | `#22C55E` | `#FFFFFF` |
| Teal | `#14B8A6` | `#FFFFFF` |
| Blue | `#3B82F6` | `#FFFFFF` |
| Purple | `#8B5CF6` | `#FFFFFF` |
| Pink | `#EC4899` | `#FFFFFF` |

##### Checklist Progress Indicator (Board View)

Mini progress bar displayed in the card metadata row:

```css
.checklist-progress {
    display: flex;
    align-items: center;
    gap: var(--space-1);                       /* 4px */
}

.checklist-progress-bar {
    width: 48px;
    height: 4px;
    background: var(--color-border-subtle);
    border-radius: var(--border-radius-full);
    overflow: hidden;
}

.checklist-progress-fill {
    height: 100%;
    border-radius: var(--border-radius-full);
    background: var(--color-primary);
    transition: width 0.2s ease;
}

/* Complete state */
.checklist-progress-fill--complete {
    background: var(--color-success);
}
```

The progress bar is accompanied by a small checkbox icon (14px) to the left, colored `--color-text-secondary`.

##### Confirmation Dialogs

Use small modal (`--modal-width-sm`, 440px) with warning treatment:

```
┌────────────────────────────────────────┐
│                  [✕]                   │
│          ⚠  Warning Icon               │
│                                        │
│     Are you sure you want to           │
│     delete "Board Name"?               │
│                                        │
│     This action cannot be undone.      │
│     All lanes, cards, and comments     │
│     will be permanently removed.       │
│                                        │
│           [Cancel]  [Delete]           │
└────────────────────────────────────────┘
```

- **Warning icon**: 40×40px, centered, `--color-warning` for warnings, `--color-error` for deletions
- **Title**: `--font-size-lg` (20px), `--font-weight-semibold`, centered
- **Body text**: `--font-size-sm` (14px), `--color-text-secondary`, centered
- **Actions**: Right-aligned. Cancel = secondary button. Confirm = danger button (for destructive) or primary (for non-destructive confirmations)
- **Spacing**: `--space-6` (24px) padding. `--space-4` (16px) between icon and title. `--space-2` (8px) between title and body. `--space-6` (24px) before action buttons.

##### Focus Management & Keyboard Accessibility

All interactive components follow consistent focus behavior:

```css
/* Global focus-visible style */
:focus-visible {
    outline: 2px solid var(--color-border-focus);  /* Purple */
    outline-offset: 2px;
}

/* Remove outline for mouse clicks */
:focus:not(:focus-visible) {
    outline: none;
}
```

| Component | Focus Behavior |
|---|---|
| Buttons | Visible outline on Tab focus. Not on click. |
| Inputs | Border changes to `--color-border-focus`. No outline (border is sufficient). |
| Links | Outline + rounded 2px radius |
| Cards | Outline on focus. Enter/Space opens card detail. |
| Dropdown items | Background highlight tracks keyboard position (arrow keys). Enter selects. |
| Modal | Focus trapped inside. Tab cycles through focusable elements. Escape closes. |
| Sidebar nav | Arrow keys navigate items. Enter activates. |

**Tab order:** Follows visual layout. Skip links provided for: "Skip to main content", "Skip to board".

---

#### E.9 Interactive States (Consolidated)

This section consolidates all interactive state definitions. Component-specific states (buttons, inputs, checkboxes, cards, dropdowns) are defined in §E.8; this section covers remaining components and cross-cutting patterns.

##### Sidebar Navigation Items

```css
.nav-item {
    height: 36px;
    display: flex;
    align-items: center;
    gap: var(--space-2);                       /* 8px icon-to-text */
    padding: 0 var(--space-3);                 /* 0 12px */
    border-radius: var(--border-radius-md);    /* 6px */
    font-size: var(--font-size-sm);            /* 14px */
    font-weight: var(--font-weight-regular);   /* 400 */
    color: var(--color-text-secondary);
    cursor: pointer;
    transition: background 0.1s ease, color 0.1s ease;
}

.nav-item:hover {
    background: var(--color-hover-overlay);
    color: var(--color-text);
}

.nav-item:active {
    background: var(--color-active-overlay);
}

.nav-item[aria-current="page"],
.nav-item--active {
    background: var(--color-primary-subtle);   /* rgba(109, 40, 217, 0.15) */
    color: var(--color-primary-text);          /* Purple text */
    font-weight: var(--font-weight-semibold);  /* 600 */
}

.nav-item:focus-visible {
    outline: 2px solid var(--color-border-focus);
    outline-offset: -2px;                      /* Inset to fit within sidebar padding */
}

.nav-item .nav-icon {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    color: inherit;
}
```

**Collapsed sidebar:** When sidebar is collapsed (48px), nav items show only the icon centered. Tooltip appears on hover (500ms delay) showing the label text.

##### Board List Items (Sidebar)

```css
.board-list-item {
    /* Inherits .nav-item base styles */
    padding-left: var(--space-4);              /* 16px — indented under section */
}

.board-list-item .board-color-dot {
    width: 8px;
    height: 8px;
    border-radius: var(--border-radius-full);
    flex-shrink: 0;
    /* background set per board color */
}

.board-list-item--active {
    background: var(--color-primary-subtle);
    font-weight: var(--font-weight-medium);    /* 500 — slightly less bold than nav */
}
```

##### Header Icon Buttons (Notification Bell, User Avatar)

```css
.header-icon-btn {
    width: 36px;
    height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--border-radius-md);    /* 6px */
    background: transparent;
    border: none;
    color: var(--color-text-secondary);
    cursor: pointer;
    position: relative;
    transition: background 0.1s ease, color 0.1s ease;
}

.header-icon-btn:hover {
    background: var(--color-hover-overlay);
    color: var(--color-text);
}

.header-icon-btn:active {
    background: var(--color-active-overlay);
}

.header-icon-btn:focus-visible {
    outline: 2px solid var(--color-border-focus);
    outline-offset: 2px;
}

.header-icon-btn[aria-expanded="true"] {
    background: var(--color-active-overlay);
    color: var(--color-text);
}
```

##### Avatar Hover (Interactive Contexts)

Avatars are non-interactive by default (display-only). In interactive contexts (clickable user profiles, assignee pickers), they gain hover states:

```css
.avatar--interactive {
    cursor: pointer;
    transition: opacity 0.1s ease, box-shadow 0.1s ease;
}

.avatar--interactive:hover {
    opacity: 0.85;
    box-shadow: 0 0 0 2px var(--color-primary-subtle);
}

.avatar--interactive:focus-visible {
    outline: 2px solid var(--color-border-focus);
    outline-offset: 2px;
}
```

##### Drag-and-Drop States

**Dragged card** (already defined in §E.8, restated for reference):

```css
.card[data-dragging="true"] {
    box-shadow: var(--shadow-drag);
    transform: rotate(2deg);
    opacity: 0.9;
    z-index: var(--z-drag);
    cursor: grabbing;
}
```

**Drop placeholder** — appears at the target position when dragging:

```css
.card-drop-placeholder {
    background: var(--color-primary-subtle);   /* Solid tinted fill, ~15% purple */
    border-radius: var(--border-radius-md);    /* 6px — matches card */
    /* Height matches the dragged card's height (set via JS) */
    min-height: 40px;
    margin-top: var(--card-gap);               /* 8px — matches card spacing */
    transition: height 0.15s ease;
}
```

**Drop target lane** — the lane receiving a card from another lane gets a subtle highlight:

```css
.lane[data-drop-target="true"] {
    outline: 2px solid var(--color-primary);
    outline-offset: -2px;
    background: color-mix(in srgb, var(--color-raised) 95%, var(--color-primary) 5%);
}
```

**Lane drag handle** (for reordering lanes):

```css
.lane-drag-handle {
    cursor: grab;
    color: var(--color-text-disabled);
    transition: color 0.1s ease;
}

.lane-drag-handle:hover {
    color: var(--color-text-secondary);
}

.lane[data-dragging="true"] {
    box-shadow: var(--shadow-drag);
    opacity: 0.9;
    z-index: var(--z-drag);
}
```

##### Link States

```css
a {
    color: var(--color-link);
    text-decoration: underline;
    text-decoration-thickness: 1px;
    text-underline-offset: 2px;
    transition: color 0.1s ease, text-decoration-thickness 0.1s ease;
}

a:hover {
    color: var(--color-link-hover);
    text-decoration-thickness: 2px;
}

a:active {
    color: var(--color-primary-active);
}

a:focus-visible {
    outline: 2px solid var(--color-border-focus);
    outline-offset: 2px;
    border-radius: 2px;
}

a:visited {
    color: var(--color-link);                  /* No visited color change — keeps UI clean */
}
```

##### Inline Editable Fields (Lane Titles, Card Titles)

Fields that switch from display to edit on click:

```css
/* Display state — looks like plain text */
.editable-field {
    padding: var(--space-1) var(--space-2);    /* 4px 8px */
    border: 1px solid transparent;
    border-radius: var(--border-radius-sm);    /* 4px */
    cursor: text;
    transition: background 0.1s ease, border-color 0.1s ease;
}

.editable-field:hover {
    background: var(--color-hover-overlay);
    border-color: var(--color-border-subtle);
}

/* Edit state — becomes a real input */
.editable-field--editing {
    background: var(--color-elevated);
    border-color: var(--color-border-focus);
    outline: none;
    cursor: text;
}
```

**Behavior:** Click or Enter to start editing. Enter or blur to save. Escape to cancel (revert to previous value).

##### Toggle Switch (Settings Pages)

```css
.toggle {
    width: 40px;
    height: 22px;
    border-radius: var(--border-radius-full);
    background: var(--color-border);
    border: none;
    cursor: pointer;
    position: relative;
    transition: background 0.15s ease;
    flex-shrink: 0;
}

.toggle::after {
    content: '';
    position: absolute;
    top: 2px;
    left: 2px;
    width: 18px;
    height: 18px;
    border-radius: var(--border-radius-full);
    background: #FFFFFF;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
    transition: transform 0.15s ease;
}

.toggle[aria-checked="true"] {
    background: var(--color-primary);
}

.toggle[aria-checked="true"]::after {
    transform: translateX(18px);
}

.toggle:hover {
    filter: brightness(1.1);
}

.toggle:focus-visible {
    outline: 2px solid var(--color-border-focus);
    outline-offset: 2px;
}

.toggle:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
```

---

#### E.10 Motion & Animation System

##### Motion Design Principles

KanBoard uses a **snappy, minimal** motion style. Animations exist to provide spatial context and user feedback, not for decoration. Every animation must:
1. Have a functional purpose (feedback, orientation, or continuity)
2. Complete quickly (≤ 200ms for micro-interactions, ≤ 300ms for entrances/exits)
3. Respect `prefers-reduced-motion` (see §E.10.8)

##### E.10.1 Duration Tokens

```css
:root {
    --duration-instant: 0ms;           /* State changes with no animation (color swaps on active) */
    --duration-fast: 100ms;            /* Micro-interactions: hover overlays, active presses, color changes */
    --duration-normal: 150ms;          /* Standard transitions: button states, input focus, dropdowns */
    --duration-moderate: 200ms;        /* Sidebar collapse, modal/dropdown entrance, search expand */
    --duration-slow: 300ms;            /* Complex entrance animations: toast slide-in, modal backdrop fade */
    --duration-skeleton: 1500ms;       /* Skeleton shimmer pulse cycle */
}
```

##### E.10.2 Easing Tokens

```css
:root {
    --ease-default: ease;              /* General transitions (CSS default, suitable for most cases) */
    --ease-in: ease-in;               /* Elements leaving the screen (accelerate out) */
    --ease-out: ease-out;             /* Elements entering the screen (decelerate in) */
    --ease-in-out: ease-in-out;       /* Elements moving position (sidebar, expand/collapse) */
    --ease-spring: cubic-bezier(0.34, 1.56, 0.64, 1);  /* Subtle overshoot for playful feedback (toast arrival) */
}
```

##### E.10.3 Component Transitions Summary

All transitions gathered in one reference table. Components already shown in §E.8 are included for completeness.

| Component | Property | Duration | Easing | Trigger |
|---|---|---|---|---|
| **Button (primary)** | `background, box-shadow` | `--duration-normal` (150ms) | `ease` | hover, focus |
| **Button (primary)** | `transform` | `--duration-fast` (100ms) | `ease` | active (scale 0.98) |
| **Button (secondary)** | `background, border-color` | `--duration-normal` (150ms) | `ease` | hover |
| **Button (ghost)** | `background, color` | `--duration-fast` (100ms) | `ease` | hover |
| **Button (danger)** | `filter` | `--duration-normal` (150ms) | `ease` | hover |
| **Input** | `border-color, background` | `--duration-normal` (150ms) | `ease` | hover, focus |
| **Checkbox** | `background, border-color` | `--duration-normal` (150ms) | `ease` | hover, checked |
| **Card** | `box-shadow, transform` | `--duration-normal` (150ms) | `ease` | hover, drag |
| **Dropdown item** | `background` | `--duration-fast` (100ms) | `ease` | hover |
| **Nav item** | `background, color` | `--duration-fast` (100ms) | `ease` | hover |
| **Link** | `color, text-decoration-thickness` | `--duration-fast` (100ms) | `ease` | hover |
| **Ghost lane button** | `border-color, color, background` | `--duration-normal` (150ms) | `ease` | hover |
| **Card label bar** | `height` | `--duration-fast` (100ms) | `ease` | hover (8px→16px) |
| **Sidebar** | `width` | `--duration-moderate` (200ms) | `ease-in-out` | collapse toggle |
| **Editable field** | `background, border-color` | `--duration-fast` (100ms) | `ease` | hover, edit |
| **Toggle switch** | `background` | `--duration-normal` (150ms) | `ease` | toggle |
| **Toggle switch knob** | `transform` | `--duration-normal` (150ms) | `ease` | toggle |
| **Avatar (interactive)** | `opacity, box-shadow` | `--duration-fast` (100ms) | `ease` | hover |

##### E.10.4 Entrance & Exit Animations

**Modal:**

```css
/* Backdrop — fade in */
@keyframes modal-backdrop-in {
    from { opacity: 0; }
    to   { opacity: 1; }
}

/* Modal panel — scale up + fade in */
@keyframes modal-in {
    from {
        opacity: 0;
        transform: scale(0.95);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

/* Exit — reverse */
@keyframes modal-out {
    from {
        opacity: 1;
        transform: scale(1);
    }
    to {
        opacity: 0;
        transform: scale(0.95);
    }
}

.modal-backdrop {
    animation: modal-backdrop-in var(--duration-moderate) var(--ease-out);
}

.modal {
    animation: modal-in var(--duration-moderate) var(--ease-out);
}

.modal-backdrop--closing {
    animation: modal-backdrop-in var(--duration-normal) var(--ease-in) reverse forwards;
}

.modal--closing {
    animation: modal-out var(--duration-normal) var(--ease-in) forwards;
}
```

**Dropdown Menu:**

```css
@keyframes dropdown-in {
    from {
        opacity: 0;
        transform: translateY(-4px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.dropdown {
    animation: dropdown-in var(--duration-normal) var(--ease-out);
}

.dropdown--closing {
    animation: dropdown-in var(--duration-fast) var(--ease-in) reverse forwards;
}
```

**Notification Panel (Header Dropdown):**

Same animation as dropdown menu, applied to `.notification-panel`.

**Mobile Search Expand:**

```css
@keyframes search-expand {
    from {
        width: 36px;
        opacity: 0.5;
    }
    to {
        width: 100%;
        opacity: 1;
    }
}

.header-search--expanding {
    animation: search-expand var(--duration-moderate) var(--ease-in-out);
}

.header-search--collapsing {
    animation: search-expand var(--duration-normal) var(--ease-in) reverse forwards;
}
```

**Mobile Sidebar Overlay:**

```css
/* Sidebar slides in from left */
@keyframes sidebar-slide-in {
    from { transform: translateX(-100%); }
    to   { transform: translateX(0); }
}

.app-sidebar--mobile-open {
    animation: sidebar-slide-in var(--duration-moderate) var(--ease-out);
}

.app-sidebar--mobile-closing {
    animation: sidebar-slide-in var(--duration-normal) var(--ease-in) reverse forwards;
}

/* Backdrop fades in */
.sidebar-backdrop {
    animation: modal-backdrop-in var(--duration-moderate) var(--ease-out);
}
```

##### E.10.5 Toast Notifications

**Position:** Fixed bottom-right corner. Offset: `--space-4` (16px) from viewport edges.

**Stack behavior:** Maximum 3 toasts visible simultaneously. New toasts appear at the bottom; older toasts slide up to make room. When a 4th toast arrives, the oldest auto-dismisses. Toasts auto-dismiss after 5 seconds (configurable per-toast for errors: 8 seconds). Manual dismiss via close button (ghost icon button).

**Variants:**

| Variant | Left Border Color | Icon | Use Cases |
|---|---|---|---|
| **Success** | `--color-success` (#16A34A) | ✓ checkmark circle | Card saved, action completed |
| **Error** | `--color-error` (#DC2626) | ✕ error circle | Save failed, validation error |
| **Warning** | `--color-warning` (#CA8A04) | ⚠ triangle | Approaching limits, degraded state |
| **Info** | `--color-info` (#2563EB) | ℹ info circle | General information, tips |

**Toast layout:**

```css
.toast-container {
    position: fixed;
    bottom: var(--space-4);                    /* 16px from bottom */
    right: var(--space-4);                     /* 16px from right */
    z-index: var(--z-toast);
    display: flex;
    flex-direction: column;
    gap: var(--space-2);                       /* 8px between stacked toasts */
    pointer-events: none;
    max-width: 380px;
    width: calc(100vw - var(--space-8));       /* Responsive: max 100vw - 32px */
}

.toast {
    display: flex;
    align-items: flex-start;
    gap: var(--space-3);                       /* 12px */
    padding: var(--space-3) var(--space-4);    /* 12px 16px */
    background: var(--color-elevated);
    border-radius: var(--border-radius-lg);    /* 8px */
    border-left: 3px solid;                    /* Color per variant */
    box-shadow: var(--shadow-elevated);
    pointer-events: auto;
    min-height: 48px;
}

.toast-icon {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
    margin-top: 1px;                           /* Optical alignment with text */
}

.toast-content {
    flex: 1;
}

.toast-title {
    font-size: var(--font-size-sm);            /* 14px */
    font-weight: var(--font-weight-medium);    /* 500 */
    color: var(--color-text);
    line-height: var(--line-height-ui);
}

.toast-message {
    font-size: var(--font-size-xs);            /* 12px */
    color: var(--color-text-secondary);
    line-height: var(--line-height-body);
    margin-top: var(--space-1);                /* 4px below title, if present */
}

.toast-close {
    /* Ghost icon button, 24×24px */
    width: 24px;
    height: 24px;
    flex-shrink: 0;
}
```

**Toast animations:**

```css
@keyframes toast-in {
    from {
        opacity: 0;
        transform: translateX(100%);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes toast-out {
    from {
        opacity: 1;
        transform: translateX(0);
        max-height: 120px;
        margin-bottom: var(--space-2);
    }
    to {
        opacity: 0;
        transform: translateX(100%);
        max-height: 0;
        margin-bottom: 0;
        padding-top: 0;
        padding-bottom: 0;
    }
}

.toast {
    animation: toast-in var(--duration-slow) var(--ease-spring);
}

.toast--dismissing {
    animation: toast-out var(--duration-moderate) var(--ease-in) forwards;
}
```

**Auto-dismiss progress bar** (optional visual):

```css
.toast-progress {
    position: absolute;
    bottom: 0;
    left: 3px;                                 /* Inset past left border */
    right: 0;
    height: 2px;
    background: var(--color-text-disabled);
    border-radius: 0 0 var(--border-radius-lg) 0;
    transform-origin: left;
    animation: toast-progress-shrink 5s linear forwards;
}

@keyframes toast-progress-shrink {
    from { transform: scaleX(1); }
    to   { transform: scaleX(0); }
}
```

**Mobile (< 768px):** Toasts span full width, positioned at bottom. `max-width: 100%; right: 0; left: 0; bottom: 0;` with `--space-2` (8px) side padding.

##### E.10.6 Loading States

**Skeleton Screens:**

Used for initial page loads and section loads (board view, card list, settings pages). Skeletons mirror the layout of the content they replace.

```css
@keyframes skeleton-pulse {
    0%   { background-color: var(--color-raised); }
    50%  { background-color: var(--color-elevated); }
    100% { background-color: var(--color-raised); }
}

.skeleton {
    animation: skeleton-pulse var(--duration-skeleton) ease-in-out infinite;
    border-radius: var(--border-radius-sm);    /* 4px default */
}

/* Shape variants */
.skeleton--text {
    height: 14px;                              /* Matches body text */
    width: 100%;
    margin-bottom: var(--space-2);
}

.skeleton--text-short {
    width: 60%;
}

.skeleton--heading {
    height: 20px;
    width: 40%;
    margin-bottom: var(--space-3);
}

.skeleton--avatar {
    width: 32px;
    height: 32px;
    border-radius: var(--border-radius-md);    /* Matches avatar shape */
}

.skeleton--card {
    height: 80px;
    border-radius: var(--border-radius-md);    /* Matches card radius */
    margin-bottom: var(--card-gap);            /* 8px */
}

.skeleton--button {
    height: 36px;
    width: 100px;
    border-radius: var(--border-radius-lg);    /* Matches button radius */
}
```

**Board view skeleton:** Shows 3 lane skeletons side-by-side, each containing 3–4 card skeletons of varying height (60px–100px) to simulate realistic content.

**Card detail modal skeleton:** Header skeleton (title bar + close button), body with 2 text-block skeletons, sidebar with 3 short label skeletons.

**Inline spinners (button loading):**

Already defined in §E.8 — spinner replaces button text, button stays disabled and maintains width. Spinner sizes: 14px (small button), 16px (default), 20px (large).

```css
@keyframes spin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}

.spinner {
    display: inline-block;
    border: 2px solid transparent;
    border-top-color: currentColor;
    border-radius: var(--border-radius-full);
    animation: spin 0.6s linear infinite;
}

.spinner--sm  { width: 14px; height: 14px; }
.spinner--md  { width: 16px; height: 16px; }
.spinner--lg  { width: 20px; height: 20px; }

/* Page-level centered spinner (fallback for non-skeleton contexts) */
.spinner--page {
    width: 32px;
    height: 32px;
    border-width: 3px;
    margin: var(--space-8) auto;
    color: var(--color-primary);
}
```

##### E.10.7 Drag-and-Drop Animation

**Card pickup:** Card lifts with `box-shadow: var(--shadow-drag)`, rotates `2deg`, and fades to `opacity: 0.9`. Transition: `--duration-normal` (150ms) `ease-out`.

**Card movement:** The card follows the cursor/touch with no transition delay (JS-driven `transform: translate()`). The placeholder (solid tinted fill) smoothly expands/collapses at the target position using `height` transition at `--duration-normal` (150ms) `ease`.

**Card drop:** On release, the card animates from its current position to the placeholder slot. Duration: `--duration-moderate` (200ms), easing: `ease-out`. Rotation returns to `0deg`, shadow returns to `var(--shadow-card)`, opacity returns to `1`.

**Lane reordering:** Same pickup and drop animation as cards, but no rotation (lanes stay upright).

**Other cards shift:** Adjacent cards above/below the placeholder smoothly shift position using `transform: translateY()` with `--duration-normal` (150ms) `ease`.

##### E.10.8 Reduced Motion

All animations respect `prefers-reduced-motion: reduce`. The strategy is to **replace motion with instant state changes** rather than removing feedback entirely:

```css
@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
        scroll-behavior: auto !important;
    }

    /* Skeleton still pulses but more gently */
    .skeleton {
        animation: none !important;
        background-color: var(--color-elevated);
    }

    /* Drag-and-drop — no rotation, instant pickup */
    .card[data-dragging="true"] {
        transform: none;
        transition: none;
    }

    /* Spinner still spins — essential for indicating loading */
    .spinner {
        animation-duration: 0.8s !important;
        animation-iteration-count: infinite !important;
    }
}
```

**Exceptions to reduced motion:**
- **Spinners** always animate (essential loading feedback; no motion-free alternative)
- **Focus outlines** remain visible (non-motion accessibility cue)
- **Color/opacity state changes** still apply instantly (they are not motion)

##### E.10.9 Scroll Behavior

```css
html {
    scroll-behavior: smooth;                   /* Smooth scrolling for anchor links */
}

/* Board horizontal scrolling — native touch + mouse wheel */
.board-canvas {
    scroll-behavior: auto;                     /* No smooth scroll on board — too laggy with many lanes */
    -webkit-overflow-scrolling: touch;         /* iOS momentum scrolling */
}

/* Mobile snap scrolling (repeated from §E.8 for completeness) */
@media (max-width: 767px) {
    .board-canvas {
        scroll-snap-type: x mandatory;
    }
    .lane {
        scroll-snap-align: start;
    }
}
```

**Custom scrollbar styling** (Webkit + Firefox):

Already defined via `--color-scrollbar-track`, `--color-scrollbar-thumb`, `--color-scrollbar-hover` tokens (see §E.3). Width: 8px desktop, hidden on mobile (overlay scrollbars).

---

#### E.11 Interactive State Cross-Reference

Summary of every visual state per component type for implementation verification:

| Component | Default | Hover | Active | Focus | Disabled | Loading | Error | Selected/Active |
|---|---|---|---|---|---|---|---|---|
| Button (primary) | ✅ §E.8 | ✅ §E.8 | ✅ §E.8 | ✅ §E.8 | ✅ §E.8 | ✅ §E.8 | — | — |
| Button (secondary) | ✅ §E.8 | ✅ §E.8 | ✅ §E.8 | ✅ §E.8 | ✅ §E.8 | ✅ §E.8 | — | — |
| Button (ghost) | ✅ §E.8 | ✅ §E.8 | ✅ §E.8 | ✅ §E.8 | ✅ §E.8 | ✅ §E.8 | — | — |
| Button (danger) | ✅ §E.8 | ✅ §E.8 | ✅ §E.8 | ✅ §E.8 | ✅ §E.8 | ✅ §E.8 | — | — |
| Text input | ✅ §E.8 | ✅ §E.8 | — | ✅ §E.8 | ✅ §E.8 | — | ✅ §E.8 | — |
| Checkbox | ✅ §E.8 | ✅ §E.8 | — | ✅ §E.8 | ✅ §E.8 | — | — | ✅ §E.8 (checked) |
| Toggle switch | ✅ §E.9 | ✅ §E.9 | — | ✅ §E.9 | ✅ §E.9 | — | — | ✅ §E.9 (on) |
| Card | ✅ §E.8 | ✅ §E.8 | — | ✅ §E.8 | — | — | — | ✅ §E.8 (dragging) |
| Dropdown item | ✅ §E.8 | ✅ §E.8 | ✅ §E.8 | ✅ §E.8 | — | — | — | — |
| Nav item | ✅ §E.9 | ✅ §E.9 | ✅ §E.9 | ✅ §E.9 | — | — | — | ✅ §E.9 (current page) |
| Board list item | ✅ §E.9 | ✅ §E.9 | ✅ §E.9 | ✅ §E.9 | — | — | — | ✅ §E.9 (active board) |
| Header icon button | ✅ §E.9 | ✅ §E.9 | ✅ §E.9 | ✅ §E.9 | — | — | — | ✅ §E.9 (expanded) |
| Link | ✅ §E.9 | ✅ §E.9 | ✅ §E.9 | ✅ §E.9 | — | — | — | — |
| Editable field | ✅ §E.9 | ✅ §E.9 | — | — | — | — | — | ✅ §E.9 (editing) |
| Toast | ✅ §E.10 | — | — | — | — | — | — | — |
| Skeleton | ✅ §E.10 | — | — | — | — | — | — | — |

---

*This specification is a work in progress. The visual design system is being developed iteratively — color tokens, typography, spacing/layout, component design, interactive states, and motion/animation are complete. Accessibility review has been completed; requirements are documented in Appendix F.*

---

## Appendix F: Accessibility Requirements

**Standard:** WCAG 2.1 Level AA (mandatory), Level AAA (stretch goal)
**Review Date:** 2026-02-13

This appendix specifies accessibility requirements for the Shuffle application. These requirements are mandatory for MVP unless noted otherwise. Each requirement references the WCAG success criteria it satisfies and the review issue that prompted it.

---

### F.1 Semantic HTML and Landmarks

**WCAG:** 1.3.1 Info and Relationships (A), 2.4.1 Bypass Blocks (A)
**Ref:** ISSUE-17

All pages MUST use HTML5 semantic landmark elements for the page structure:

```html
<body>
  <a href="#main-content" class="skip-link">Skip to main content</a>
  <a href="#board-canvas" class="skip-link">Skip to board</a> <!-- Board view only -->

  <header class="app-header" role="banner">
    <!-- Logo, search, notifications, user menu -->
  </header>

  <nav class="app-sidebar" aria-label="Main navigation">
    <!-- Sidebar navigation -->
  </nav>

  <main id="main-content" class="app-main">
    <!-- Page content -->
  </main>
</body>
```

**Requirements:**
- Every page MUST have exactly one `<main>` element
- Header MUST use `<header>` with `role="banner"`
- Sidebar MUST use `<nav>` with `aria-label="Main navigation"`
- If multiple `<nav>` elements exist, each MUST have a unique `aria-label`
- Page title (`<title>`) MUST reflect the current page context (e.g., "Sprint Board - Shuffle", "Settings - Shuffle")

---

### F.2 Skip Links

**WCAG:** 2.4.1 Bypass Blocks (A)
**Ref:** ISSUE-16

Skip links MUST be the first focusable elements in the DOM:

```css
.skip-link {
    position: absolute;
    top: -100%;
    left: var(--space-4);
    background: var(--color-primary);
    color: var(--color-text-on-primary);
    padding: var(--space-2) var(--space-4);
    border-radius: var(--border-radius-md);
    z-index: 9999;
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-medium);
    text-decoration: none;
}

.skip-link:focus {
    top: var(--space-2);
}
```

**Required skip links:**
- "Skip to main content" — on every page
- "Skip to board" — on board view pages (targets the `.board-canvas` element)

---

### F.3 Keyboard Navigation

**WCAG:** 2.1.1 Keyboard (A), 2.4.3 Focus Order (A), 2.4.7 Focus Visible (AA)
**Ref:** ISSUE-01, ISSUE-06

#### F.3.1 General Keyboard Requirements

- ALL interactive elements MUST be keyboard accessible
- Tab order MUST follow visual reading order (left-to-right, top-to-bottom)
- Focus indicators MUST be visible: 2px solid `--color-border-focus` with 2px offset (already specified in E.8)
- No keyboard traps — every focused element must allow Tab/Shift+Tab to move away (except modals, which trap focus intentionally with Escape exit)

#### F.3.2 Card Drag-and-Drop Keyboard Alternative

Since the HTML5 Drag and Drop API has poor keyboard accessibility, ALL drag-and-drop operations MUST have a keyboard-accessible alternative:

**Card operations (via context menu on each card):**

Each card MUST be focusable (`tabindex="0"`) and support:
- **Enter/Space**: Opens card detail modal
- **Context menu (Shift+F10 or dedicated button)**: Opens a card action menu with:
  - "Move to lane..." → submenu listing all lanes on the board
  - "Move up" (within current lane, disabled if first)
  - "Move down" (within current lane, disabled if last)
  - "Move to top" (within current lane)
  - "Move to bottom" (within current lane)
  - "Archive"
  - "Delete"

**Card action menu ARIA pattern:**

```html
<div class="card" tabindex="0" role="article" aria-label="Implement login, due March 1, In Progress lane">
  <button class="card-menu-btn btn-icon btn-ghost btn-sm"
          aria-label="Card actions"
          aria-haspopup="true"
          aria-expanded="false">
    <!-- ⋯ icon -->
  </button>
</div>
```

The menu follows the WAI-ARIA Menu Button pattern (see F.8).

**Move confirmation announcement (ARIA live region):**

```html
<div aria-live="polite" class="sr-only" id="board-announcer">
  <!-- JS populates: "Card 'Implement login' moved to 'Done' lane, position 2 of 5" -->
</div>
```

**Lane reordering keyboard alternative:**

Lane reordering is already specified as "deliberate action (not drag-and-drop)" per LANE-05 and uses the `PUT /v1/lanes/{id}/position` API. The lane header menu MUST include "Move lane left" and "Move lane right" options. These operations update the lane position via the API and announce the change.

#### F.3.3 Checklist Item Reordering

Checklist items MUST support keyboard reordering:
- Focus on a checklist item, then Ctrl+Arrow Up / Ctrl+Arrow Down to reorder
- Announce: "Item moved to position 3 of 5"

---

### F.4 Screen Reader Support

**WCAG:** 4.1.2 Name, Role, Value (A), 1.1.1 Non-text Content (A)
**Ref:** ISSUE-04, ISSUE-05, ISSUE-07, ISSUE-08, ISSUE-10, ISSUE-18

#### F.4.1 Card Accessible Labels

Each card in board view MUST have an accessible description that conveys its full context:

```html
<div class="card"
     tabindex="0"
     role="article"
     aria-label="Implement login"
     aria-describedby="card-42-meta">
  <div class="card-labels">
    <span class="card-label-bar" style="background:#EF4444" aria-label="Bug" title="Bug" role="img"></span>
    <span class="card-label-bar" style="background:#3B82F6" aria-label="Feature" title="Feature" role="img"></span>
  </div>
  <span class="card-title">Implement login</span>
  <div class="card-meta" id="card-42-meta">
    <span>Due: March 1, 2026</span>
    <span>3 comments</span>
    <span>Checklist: 3 of 5 complete</span>
    <span>2 attachments</span>
  </div>
</div>
```

**Requirements:**
- Card labels (color bars) MUST have `aria-label` and `role="img"` (ISSUE-04)
- Card titles MUST expose the full title even when visually truncated (ISSUE-10). Use `aria-label` on the card element with the full title
- Checklist progress MUST include a text description: "Checklist: 3 of 5 complete" (ISSUE-18)
- Due date MUST use `<time datetime="2026-03-01">` for machine-readability

#### F.4.2 Notification Bell

**Ref:** ISSUE-05

```html
<button class="header-icon-btn"
        aria-label="Notifications, 3 unread"
        aria-haspopup="true"
        aria-expanded="false">
  <svg aria-hidden="true"><!-- bell icon --></svg>
  <span class="notification-dot" aria-hidden="true"></span>
</button>
```

- The `aria-label` MUST be dynamically updated by `notifications.js` when the unread count changes
- When count is 0: `aria-label="Notifications, no unread"`
- The red dot is `aria-hidden="true"` (decorative — the label carries the information)

#### F.4.3 Inline Editable Fields

**Ref:** ISSUE-07

```html
<!-- Display mode -->
<span class="editable-field"
      role="button"
      tabindex="0"
      aria-label="Lane title: To Do. Press Enter to edit.">
  To Do
</span>

<!-- Edit mode (JS swaps the element) -->
<input class="editable-field editable-field--editing"
       type="text"
       value="To Do"
       aria-label="Lane title"
       autofocus />
```

- Mode changes MUST be announced via an ARIA live region: "Editing lane title" / "Lane title saved"
- Escape cancels editing and announces "Edit cancelled"

#### F.4.4 Toggle Switch

**Ref:** ISSUE-08

```html
<button class="toggle"
        role="switch"
        aria-checked="true"
        aria-labelledby="theme-label">
</button>
<label id="theme-label">Dark theme</label>
```

- MUST use `role="switch"` (not just `aria-checked`)
- MUST be a `<button>` element (keyboard-operable by default)
- MUST have an associated label via `aria-labelledby` or `aria-label`

#### F.4.5 Avatars and User References

```html
<!-- Avatar with image -->
<img class="avatar" src="..." alt="John Doe" />

<!-- Avatar with initials -->
<span class="avatar avatar--initials" aria-label="John Doe" role="img">JD</span>

<!-- Avatar stack on cards -->
<div class="avatar-stack" aria-label="Assigned to John Doe, Jane Smith, and 2 others">
  <!-- avatars -->
</div>
```

#### F.4.6 Due Date States

**Ref:** ISSUE-13

Due dates MUST communicate their state via text, not color alone:

```html
<!-- Normal -->
<time datetime="2026-03-15" class="due-date">Due Mar 15</time>

<!-- Due soon (within 48 hours) -->
<time datetime="2026-02-14" class="due-date due-date--warning">
  <span aria-hidden="true">⚠</span> Due tomorrow
</time>

<!-- Overdue -->
<time datetime="2026-02-10" class="due-date due-date--overdue">
  <span aria-hidden="true">⚠</span> Overdue by 3 days
</time>
```

- Color changes (`--color-warning`, `--color-error`) MUST be accompanied by text indicators ("Due tomorrow", "Overdue")
- Screen readers receive the text naturally; the icon is decorative (`aria-hidden`)

---

### F.5 ARIA Live Regions and Dynamic Content

**WCAG:** 4.1.3 Status Messages (AA)
**Ref:** ISSUE-02

#### F.5.1 Toast Notifications

```html
<!-- Toast container — polite by default -->
<div class="toast-container" role="status" aria-live="polite" aria-atomic="false">
  <!-- Individual toasts inserted by JS -->
</div>

<!-- Error toasts use role="alert" for immediate announcement -->
<div class="toast toast--error" role="alert">
  <span class="toast-icon" aria-hidden="true"><!-- error icon --></span>
  <div class="toast-content">
    <span class="toast-title">Save failed</span>
    <span class="toast-message">Could not save the card. Please try again.</span>
  </div>
  <button class="toast-close btn-icon btn-ghost" aria-label="Dismiss notification">
    <!-- close icon -->
  </button>
</div>
```

- Success/info toasts: `role="status"`, `aria-live="polite"`
- Error/warning toasts: `role="alert"` (assertive announcement)
- Toast close button MUST have `aria-label="Dismiss notification"`

#### F.5.2 Board Update Announcements

```html
<div id="board-announcer" class="sr-only" aria-live="polite">
  <!-- JS updates text when board polling detects changes -->
  <!-- Example: "Board has been updated by another user" -->
</div>
```

- When polling detects a version change and refreshes the board, announce it once
- Do NOT announce every poll check — only when actual changes are detected

#### F.5.3 File Upload Progress

**Ref:** ISSUE-09

```html
<div class="upload-progress" role="progressbar"
     aria-valuenow="45" aria-valuemin="0" aria-valuemax="100"
     aria-label="Uploading screenshot.png: 45%">
  <div class="upload-progress-bar" style="width: 45%"></div>
</div>
```

- Progress MUST be announced to screen readers via `aria-valuenow` updates
- Completion MUST be announced: "Upload complete: screenshot.png"
- Failure MUST be announced via `role="alert"`: "Upload failed: screenshot.png"

#### F.5.4 Search Status

**Ref:** ISSUE-11

```html
<div class="search-wrapper">
  <input type="search"
         class="input"
         placeholder="Search cards..."
         aria-label="Search cards"
         aria-describedby="search-help search-results-status"
         autocomplete="off" />
  <span id="search-help" class="sr-only">Enter at least 3 characters to search</span>
  <div id="search-results-status" class="sr-only" aria-live="polite">
    <!-- JS updates: "15 results found" or "No results found" -->
  </div>
</div>
```

#### F.5.5 Form Validation

```html
<div class="form-group">
  <label class="form-label form-label--required" for="board-title">Board Title</label>
  <input id="board-title"
         class="input input--error"
         type="text"
         aria-required="true"
         aria-invalid="true"
         aria-describedby="board-title-error" />
  <span id="board-title-error" class="form-error-message" role="alert">
    Board title is required
  </span>
</div>
```

- Required fields MUST have `aria-required="true"`
- Invalid fields MUST have `aria-invalid="true"` and `aria-describedby` pointing to the error message
- Error messages MUST use `role="alert"` for immediate announcement

---

### F.6 Modal Dialogs

**WCAG:** 2.4.3 Focus Order (A), 2.1.2 No Keyboard Trap (A)
**Ref:** ISSUE-06, ISSUE-14

#### F.6.1 Standard Modal Pattern

```html
<div class="modal-backdrop" aria-hidden="true"></div>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
  <div class="modal-header">
    <h2 id="modal-title">Create Board</h2>
    <button class="btn-icon btn-ghost" aria-label="Close dialog">
      <!-- close icon -->
    </button>
  </div>
  <div class="modal-body">
    <!-- Content -->
  </div>
  <div class="modal-footer">
    <button class="btn-secondary">Cancel</button>
    <button class="btn-primary">Create</button>
  </div>
</div>
```

**Focus management requirements:**
1. **On open:** Focus moves to the first focusable element in the modal (typically the first form input, or the close button if no inputs)
2. **Tab wrapping:** Tab from last focusable element moves to first; Shift+Tab from first moves to last
3. **On close (Escape or close button):** Focus returns to the element that triggered the modal
4. **Background:** All content behind the modal MUST receive `inert` attribute AND `aria-hidden="true"`
5. **Scrollable content:** If modal content scrolls, the modal container (not the backdrop) is scrollable

#### F.6.2 Destructive Confirmation Dialog

```html
<div class="modal modal--sm" role="alertdialog" aria-modal="true"
     aria-labelledby="confirm-title" aria-describedby="confirm-desc">
  <div class="modal-body" style="text-align: center;">
    <span class="confirm-icon" aria-hidden="true"><!-- warning icon --></span>
    <h2 id="confirm-title">Delete "Sprint Board"?</h2>
    <p id="confirm-desc">This action cannot be undone. All lanes, cards, and comments will be permanently removed.</p>
  </div>
  <div class="modal-footer">
    <button class="btn-secondary" autofocus>Cancel</button>
    <button class="btn-danger">Delete</button>
  </div>
</div>
```

- Use `role="alertdialog"` for destructive confirmations
- **Focus MUST start on the Cancel button** (not the destructive action) to prevent accidental confirmation
- `aria-describedby` MUST point to the warning explanation text

---

### F.7 Color and Contrast

**WCAG:** 1.4.1 Use of Color (A), 1.4.3 Contrast Minimum (AA), 1.4.11 Non-text Contrast (AA)
**Ref:** ISSUE-03, ISSUE-04, ISSUE-13

#### F.7.1 Placeholder Text Contrast Fix

The `--color-text-disabled` token (#5A5A6E, 3.2:1 ratio) MUST NOT be used for placeholder text on normal-sized inputs. Instead:

```css
:root {
    /* NEW TOKEN — placeholder text that meets AA for normal text */
    --color-placeholder: #7A7A90;      /* ~4.5:1 on #0D0D12 base, ~4.6:1 on #161625 raised */
}

.input::placeholder {
    color: var(--color-placeholder);    /* NOT --color-text-disabled */
}
```

`--color-text-disabled` remains valid for:
- Disabled button text (disabled state is conveyed non-visually via `disabled` attribute)
- Large text (18px+ bold or 24px+ regular) where 3:1 is sufficient

#### F.7.2 Non-Text Contrast Requirements

Interactive UI components and their states MUST meet 3:1 contrast ratio against adjacent colors (WCAG 1.4.11):

| Element | Requirement |
|---|---|
| Focus indicators | `--color-border-focus` (#A78BFA) against background — ✅ passes |
| Form input borders (focus state) | Must be visible against background — ✅ passes |
| Checkbox border | `--color-border` (#2A2A3C) against `--color-raised` (#161625) — verify ≥ 3:1 |
| Toggle switch track | `--color-border` against background — verify ≥ 3:1 |
| Progress bar | `--color-primary` against `--color-border-subtle` — ✅ passes |

#### F.7.3 Color-Independent Information

All information conveyed by color MUST also be conveyed by text, shape, or pattern:

| Element | Color Indicator | Required Non-Color Indicator |
|---|---|---|
| Card labels (board view) | Color bar | `aria-label` with label name; show name on hover/focus |
| Due date states | Warning/error color | Text: "Due tomorrow", "Overdue" |
| Notification dot | Red dot | `aria-label` on bell button with count |
| Form validation | Red border | Error message text + `aria-invalid` |
| Checklist progress | Green when complete | Text: "3 of 5 complete" |
| Toast variants | Colored left border | Icon + text title |

---

### F.8 WAI-ARIA Design Patterns

**WCAG:** 4.1.2 Name, Role, Value (A)
**Ref:** ISSUE-12, ISSUE-15

#### F.8.1 Menu Button Pattern (Card Actions, Lane Actions, User Menu)

Reference: [WAI-ARIA Menu Button](https://www.w3.org/WAI/ARIA/apg/patterns/menu-button/)

```html
<button aria-haspopup="true" aria-expanded="false" aria-label="Card actions">
  <!-- icon -->
</button>

<div role="menu" aria-label="Card actions">
  <button role="menuitem">Move to lane...</button>
  <button role="menuitem">Move up</button>
  <button role="menuitem">Move down</button>
  <div role="separator"></div>
  <button role="menuitem">Archive</button>
  <button role="menuitem" class="dropdown-item--danger">Delete</button>
</div>
```

**Keyboard interactions:**
- Enter/Space on trigger → opens menu, focuses first item
- Arrow Down/Up → navigates items
- Home/End → first/last item
- Escape → closes menu, returns focus to trigger
- Type-ahead → jumps to matching item

#### F.8.2 Combobox Pattern (User Assignment)

**Ref:** ISSUE-12
Reference: [WAI-ARIA Combobox](https://www.w3.org/WAI/ARIA/apg/patterns/combobox/)

```html
<div class="combobox-wrapper">
  <label for="assign-user">Assign to</label>
  <input id="assign-user"
         role="combobox"
         aria-expanded="false"
         aria-controls="assign-listbox"
         aria-autocomplete="list"
         aria-activedescendant=""
         autocomplete="off" />
  <ul id="assign-listbox" role="listbox" aria-label="Users">
    <li id="user-1" role="option" aria-selected="false">John Doe</li>
    <li id="user-2" role="option" aria-selected="true">Jane Smith</li>
  </ul>
</div>
```

**Keyboard interactions:**
- Typing filters the list
- Arrow Down/Up → navigates options
- Enter → selects highlighted option
- Escape → closes listbox

#### F.8.3 Tab Panel Pattern (Card Detail Sections, if applicable)

If card detail view uses tab-like navigation between sections (description, checklists, comments, attachments), implement the [WAI-ARIA Tabs pattern](https://www.w3.org/WAI/ARIA/apg/patterns/tabs/). Otherwise, use heading hierarchy with sections.

---

### F.9 Responsive Accessibility

**WCAG:** 1.4.4 Resize Text (AA), 1.4.10 Reflow (AA), 2.5.5 Target Size (AAA)
**Ref:** ISSUE-19, ISSUE-20

#### F.9.1 Zoom and Reflow

- The application MUST remain usable at 200% browser zoom
- Content MUST reflow to a single column at 320px CSS viewport width (equivalent to 1280px at 400% zoom)
- No horizontal scrolling for text content at 200% zoom (board view horizontal scrolling for lanes is acceptable as it is a 2D layout)
- Text MUST be resizable up to 200% without loss of functionality

#### F.9.2 Touch Targets

All interactive elements on touch devices MUST have a minimum touch target of 44×44px (WCAG 2.1) or 24×24px with adequate spacing (WCAG 2.2):

| Element | Current Size | Requirement |
|---|---|---|
| Buttons (default) | 36px height | ✅ OK — padding extends touch area |
| Buttons (large) | 44px height | ✅ Meets target |
| Buttons (small) | 28px height | ⚠️ Ensure adequate spacing (8px+ around) |
| Icon buttons | 36×36px | ✅ OK |
| Toast close | 24×24px | ⚠️ Increase to 36×36px on mobile |
| Sidebar nav items | 36px height | ✅ OK with full-width clickable area |
| Checklist checkboxes | 18×18px | ⚠️ Ensure clickable label area extends to 44px |
| Card label bars | 40×8px | Non-interactive (display only) — acceptable |

#### F.9.3 Mobile Sidebar Accessibility

When the mobile sidebar opens as an overlay:
- Focus MUST move to the sidebar (first nav item or close button)
- Backdrop MUST be clickable to close (and Escape key)
- Focus MUST be trapped within the sidebar while open
- On close, focus MUST return to the hamburger button
- The hamburger button MUST have `aria-label="Open navigation menu"` and `aria-expanded`

---

### F.10 Accessible Drag-and-Drop Implementation Guide

This section provides the complete implementation specification for accessible drag-and-drop, consolidating requirements from F.3.2.

#### F.10.1 Dual-Mode Interaction

Every drag-and-drop operation supports two modes:
1. **Mouse/touch drag** — HTML5 Drag and Drop API (visual users)
2. **Keyboard menu** — Context menu with move operations (keyboard/screen reader users)

Both modes call the same API endpoints and produce the same results.

#### F.10.2 ARIA Attributes for Draggable Cards

```html
<div class="card"
     tabindex="0"
     role="article"
     aria-roledescription="Draggable card"
     aria-label="Implement login"
     aria-describedby="card-42-meta"
     draggable="true">
  <!-- Card content -->
  <button class="card-menu-btn btn-icon btn-ghost btn-sm"
          aria-label="Card actions for Implement login"
          aria-haspopup="menu">
    <svg aria-hidden="true"><!-- ⋯ dots icon --></svg>
  </button>
</div>
```

#### F.10.3 Screen Reader Announcements

All move operations MUST produce announcements via the `#board-announcer` live region:

| Action | Announcement |
|---|---|
| Card moved between lanes | "Card 'Implement login' moved to 'Done' lane, position 2 of 5" |
| Card moved within lane | "Card 'Implement login' moved to position 3 of 5 in 'In Progress' lane" |
| Card moved to top | "Card 'Implement login' moved to top of 'To Do' lane" |
| Card moved to bottom | "Card 'Implement login' moved to bottom of 'To Do' lane" |
| Lane reordered | "Lane 'In Progress' moved to position 2 of 4" |

---

### F.11 Testing Requirements

#### F.11.1 Automated Testing

- **axe-core**: Run on every page. Zero critical or serious violations.
- **Lighthouse Accessibility**: Score >= 95% on all pages
- **pa11y**: CLI-based WCAG 2.1 AA scan as part of CI

#### F.11.2 Manual Keyboard Testing

Every interactive flow MUST be tested with keyboard only (no mouse):

| Flow | Test Steps |
|---|---|
| Login | Tab to username, Tab to password, Enter to submit |
| Navigate to board | Tab through sidebar, Enter to select board |
| Create card | Tab to "Add Card" button, Enter, type title, Enter to save |
| Move card (keyboard) | Focus card, open menu, select "Move to lane...", select lane |
| Open card detail | Focus card, Enter to open modal |
| Add comment | In card modal, Tab to comment textarea, type, Tab to submit, Enter |
| Check/uncheck item | Tab to checkbox, Space to toggle |
| Dismiss notification | Tab to bell, Enter to open, Tab to notification, Enter to navigate |
| Close modal | Escape to close, verify focus returns to trigger |

#### F.11.3 Screen Reader Testing

Test with at minimum:
- **NVDA** (Windows, free) — primary screen reader for testing
- **VoiceOver** (macOS/iOS) — secondary

Critical flows to test:
1. Login flow — form labels announced, error messages announced
2. Board view — cards announced with context (title, labels, metadata)
3. Card detail — all sections navigable, comments readable
4. Card move (keyboard) — announcements confirmed
5. Notifications — bell announces count, notification list navigable
6. Toast messages — automatically announced
7. File upload — progress announced

#### F.11.4 Color Contrast Validation

- Verify all text/background combinations with WebAIM Contrast Checker
- Verify non-text contrast for UI controls (borders, focus indicators, icons)
- Test with color vision deficiency simulators (protanopia, deuteranopia, tritanopia)
- Verify color is never the sole means of conveying information

---

### F.12 Accessibility Acceptance Criteria Summary

For MVP sign-off, ALL of the following MUST pass:

- [ ] All pages have correct landmark structure (`<header>`, `<nav>`, `<main>`)
- [ ] Skip links work ("Skip to main content", "Skip to board")
- [ ] Every interactive element is keyboard accessible (Tab, Enter/Space, Escape, Arrow keys)
- [ ] All forms have associated labels, required field indicators, and accessible error messages
- [ ] Cards can be moved between lanes and reordered using keyboard-only interaction
- [ ] Modal focus trap works correctly (trap on open, restore on close)
- [ ] Toast notifications are announced by screen readers
- [ ] Notification bell announces unread count
- [ ] File upload progress is announced
- [ ] All images and icons have appropriate alt text or `aria-hidden="true"`
- [ ] Color is never the sole means of conveying information
- [ ] All text meets 4.5:1 contrast (normal text) or 3:1 (large text)
- [ ] Placeholder text meets 4.5:1 contrast
- [ ] `prefers-reduced-motion` is respected (already specified in E.10.8)
- [ ] Application is usable at 200% browser zoom
- [ ] axe-core reports zero critical/serious violations
- [ ] Lighthouse Accessibility score >= 95%

---

### F.13 i18n Strings for Accessibility

The following i18n keys MUST be added to `include/lang/en.json` for accessibility-related strings:

```json
{
    "a11y.skip_to_content": "Skip to main content",
    "a11y.skip_to_board": "Skip to board",
    "a11y.open_nav": "Open navigation menu",
    "a11y.close_nav": "Close navigation menu",
    "a11y.close_dialog": "Close dialog",
    "a11y.card_actions": "Card actions for {0}",
    "a11y.move_to_lane": "Move to lane",
    "a11y.move_up": "Move up",
    "a11y.move_down": "Move down",
    "a11y.move_to_top": "Move to top",
    "a11y.move_to_bottom": "Move to bottom",
    "a11y.card_moved_to_lane": "Card '{0}' moved to '{1}' lane, position {2} of {3}",
    "a11y.card_moved_in_lane": "Card '{0}' moved to position {1} of {2} in '{3}' lane",
    "a11y.lane_moved": "Lane '{0}' moved to position {1} of {2}",
    "a11y.notifications_unread": "Notifications, {0} unread",
    "a11y.notifications_none": "Notifications, no unread",
    "a11y.dismiss_notification": "Dismiss notification",
    "a11y.upload_progress": "Uploading {0}: {1}%",
    "a11y.upload_complete": "Upload complete: {0}",
    "a11y.upload_failed": "Upload failed: {0}",
    "a11y.board_updated": "Board has been updated",
    "a11y.search_help": "Enter at least 3 characters to search",
    "a11y.search_results": "{0} results found",
    "a11y.search_no_results": "No results found",
    "a11y.edit_field": "Press Enter to edit",
    "a11y.editing": "Editing {0}",
    "a11y.saved": "{0} saved",
    "a11y.edit_cancelled": "Edit cancelled",
    "a11y.checklist_progress": "Checklist: {0} of {1} items complete",
    "a11y.due_today": "Due today",
    "a11y.due_tomorrow": "Due tomorrow",
    "a11y.overdue": "Overdue by {0} days",
    "a11y.draggable_card": "Draggable card",
    "a11y.required_field": "Required"
}
```
