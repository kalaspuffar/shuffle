# Project Specification: KanBoard

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

---

## 1. Executive Summary

### Project Overview

KanBoard is a self-hosted, open-source Kanban task management system designed as a Trello replacement. It provides multiple Kanban boards with configurable lanes, rich Markdown cards, comments, file attachments, checklists, user assignments, due dates, and full-text search. The system runs on a single Debian Trixie server with PHP 8.4, MySQL, S3-compatible storage, and SMTP.

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
                          |  include/KanBoard |
                          +---------+---------+
                           /        |         \
                  +-------+    +----+----+    +-------+
                  | MySQL |    |   S3    |    | SMTP  |
                  |  DB   |    | (MinIO) |    | Server|
                  +-------+    +---------+    +-------+
```

### 2.2 Directory Structure

```
kanboard/
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
│   ├── KanBoard/                   # Application namespace root
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

**Class:** `KanBoard\Core\Autoloader`
**File:** `include/KanBoard/Core/Autoloader.php`

PSR-4-style autoloader mapping the `KanBoard\` namespace prefix to `include/KanBoard/`.

**Behavior:**
- Registered via `spl_autoload_register()`
- Converts namespace separators (`\`) to directory separators (`/`)
- Appends `.php` extension
- Example: `KanBoard\Model\Board` → `include/KanBoard/Model/Board.php`
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
        'name'     => 'KanBoard',
        'url'      => 'https://kanboard.example.com',
        'locale'   => 'en',
        'timezone' => 'UTC',
    ],
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'kanboard',
        'user'     => 'kanboard',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],
    's3' => [
        'endpoint'   => 'http://127.0.0.1:9000',
        'bucket'     => 'kanboard',
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
        'from_email' => 'noreply@kanboard.example.com',
        'from_name'  => 'KanBoard',
    ],
    'session' => [
        'lifetime'    => 86400,     // 24 hours
        'cookie_name' => 'kanboard_session',
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

**Class:** `KanBoard\Core\Database`
**File:** `include/KanBoard/Core/Database.php`

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

**Class:** `KanBoard\Core\Session`
**File:** `include/KanBoard/Core/Session.php`

Implements `SessionHandlerInterface` for MySQL-backed sessions.

**Session table:** `sessions` (see Section 4)

**Behavior:**
- Registered via `session_set_save_handler()` before `session_start()`
- Cookie settings: `HttpOnly`, `Secure` (when HTTPS), `SameSite=Lax`
- Cookie name from config: `kanboard_session`
- Session data serialized by PHP's native handler
- Garbage collection deletes sessions older than `session.lifetime` config
- Provides `destroyByUserId(int $userId)` for admin session revocation

### 3.6 Router

**Class:** `KanBoard\Core\Router`
**File:** `include/KanBoard/Core/Router.php`

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

**Class:** `KanBoard\Core\Request`
**File:** `include/KanBoard/Core/Request.php`

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

**Class:** `KanBoard\Core\Response`
**File:** `include/KanBoard/Core/Response.php`

| Method | Purpose |
|---|---|
| `json(array $data, int $status = 200): void` | Sends JSON response with Content-Type header |
| `error(string $message, int $status): void` | Sends JSON error response: `{"error": "..."}` |
| `noContent(): void` | Sends 204 No Content |
| `notModified(): void` | Sends 304 Not Modified (for polling) |
| `stream(resource $stream, string $contentType, int $size, string $filename): void` | Streams file download |

### 3.8 i18n

**Class:** `KanBoard\Core\Lang`
**File:** `include/KanBoard/Core/Lang.php`

Loads and serves translatable strings from JSON files.

**File format:** `include/lang/{locale}.json`

```json
{
    "app.name": "KanBoard",
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

**Class:** `KanBoard\Core\S3Client`
**File:** `include/KanBoard/Core/S3Client.php`

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

**Class:** `KanBoard\Core\Mailer`
**File:** `include/KanBoard/Core/Mailer.php`

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

**Class:** `KanBoard\Core\Csrf`
**File:** `include/KanBoard/Core/Csrf.php`

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

**Class:** `KanBoard\Core\Auth`
**File:** `include/KanBoard/Core/Auth.php`

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
namespace KanBoard\Model;

class Board {
    public function __construct(private \KanBoard\Core\Database $db) {}

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
- `importTrelloBoard()` — Parses Trello JSON, maps to KanBoard entities, creates placeholder users

### 3.16 API Controllers

Controllers handle HTTP concerns: extract parameters from the request, call the appropriate service method, and return a JSON response.

**Pattern:**

```php
namespace KanBoard\Controller;

class CardController {
    public function __construct(
        private \KanBoard\Service\CardService $cardService,
        private \KanBoard\Core\Auth $auth
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

### 3.19 CLI Scripts

**`bin/trello-import.php`** — Trello JSON import tool.

```
Usage: php bin/trello-import.php <trello-export.json> [--org=<org_id>]
```

**Behavior:**
1. Parses Trello JSON export file
2. Creates a KanBoard board with matching title/description
3. Maps Trello lists → KanBoard lanes (preserving order)
4. Maps Trello cards → KanBoard cards (title, description, position, due dates)
5. Maps Trello comments → KanBoard comments (preserving author + timestamp)
6. Maps Trello checklists → KanBoard checklists + items (preserving state)
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

### 4.2 Position Management Strategy

Entities with user-controlled ordering (lanes, cards, checklist items) use an integer `position` column with a gap-based numbering scheme.

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

**Authentication:** Session cookie (`kanboard_session`). All endpoints except `POST /v1/auth/login` require an active session.

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
            "created_at": "2026-02-01T10:00:00Z"
        }
    ]
}
```

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

### 5.6 Lanes

#### `GET /v1/boards/{boardId}/lanes`

Returns lanes for a board, ordered by position.

**Response (200):**
```json
{
    "lanes": [
        { "id": 1, "title": "To Do", "position": 1000 },
        { "id": 2, "title": "In Progress", "position": 2000 }
    ]
}
```

#### `POST /v1/boards/{boardId}/lanes`

**Required role:** Admin or Member.

**Request:**
```json
{
    "title": "New Lane"
}
```

Position is automatically assigned (appended to the end).

**Response (201):**
```json
{
    "lane": { "id": 3, "title": "New Lane", "position": 3000 }
}
```

#### `PUT /v1/lanes/{id}`

**Required role:** Admin or Member.

**Request:**
```json
{
    "title": "Renamed Lane"
}
```

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

### 5.13 Labels (Post-MVP)

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
| S3 storage | MinIO or any S3-compatible service |
| SMTP | Any SMTP server (Postfix, external service, etc.) |

### 7.2 Apache Configuration

```apache
<VirtualHost *:443>
    ServerName kanboard.example.com
    DocumentRoot /opt/kanboard/www

    <Directory /opt/kanboard/www>
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
    server_name kanboard.example.com;
    root /opt/kanboard/www;
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
/opt/kanboard/
├── bin/        → 750 (owner: kanboard, group: kanboard)
├── doc/        → 644
├── etc/        → 750 (config.php contains secrets)
├── include/    → 644
└── www/        → 644 (readable by web server)
```

The web server user (e.g., `www-data`) needs read access to `www/`, `include/`, and `etc/`. It should NOT have write access to any directory.

### 7.6 Database Setup

```sql
CREATE DATABASE kanboard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'kanboard'@'localhost' IDENTIFIED BY 'secure_password';
GRANT ALL PRIVILEGES ON kanboard.* TO 'kanboard'@'localhost';
FLUSH PRIVILEGES;
```

The schema DDL (all `CREATE TABLE` statements) is provided as `doc/schema.sql` and run once during initial setup.

### 7.7 Installation Steps

1. Clone the repository to `/opt/kanboard/`
2. Copy `etc/config.example.php` to `etc/config.php` and edit settings
3. Create MySQL database and user
4. Run `mysql kanboard < doc/schema.sql`
5. Configure the web server (Apache or Nginx) pointing DocumentRoot to `www/`
6. Set file permissions
7. Access the application and log in with the initial admin account (created by a setup script: `php bin/setup.php`)

---

## 8. Integration Points

### 8.1 S3-Compatible Storage (MinIO)

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
- Subject: i18n key `email.invite_subject` — e.g., "You're invited to KanBoard"
- Body: Welcome message + activation link with token
- Activation URL: `{app.url}/activate.php?token={invite_token}`

### 8.3 Trello JSON Import

| Aspect | Detail |
|---|---|
| **Tool** | `bin/trello-import.php` (CLI script) |
| **Input** | Trello JSON export file (path as argument) |
| **Network** | Downloads attachments from Trello CDN during import |
| **Idempotency** | Uses `trello_id` columns to detect previously imported entities |

**Trello → KanBoard mapping:**

| Trello Entity | KanBoard Entity | Notes |
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
- `bin/setup.php` — creates initial admin user

**Acceptance criteria:**
- Autoloader resolves all `KanBoard\` classes
- Database wrapper connects and executes parameterized queries
- Session persists across requests in MySQL
- Setup script creates a working admin account

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

| Trello JSON Path | KanBoard Field | Notes |
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
| LANE-01 through LANE-06 | 3.14, 4.1 (lanes), 4.2, 5.6 |
| CARD-01 through CARD-09 | 3.14, 3.15, 4.1 (cards), 4.2, 5.7, 5.13 |
| COMMENT-01 through COMMENT-05 | 3.14, 4.1 (comments), 5.8 |
| CHECK-01 through CHECK-06 | 3.14, 4.1 (checklists, checklist_items), 5.9 |
| FILE-01 through FILE-07 | 3.9, 3.15, 4.1 (attachments), 5.10, 8.1 |
| NOTIF-01 through NOTIF-06 | 3.15, 4.1 (notifications), 5.11 |
| SEARCH-01 through SEARCH-05 | 3.15, 4.1 (cards FULLTEXT), 5.12 |
| IMPORT-01 through IMPORT-10 | 3.15, 3.19, 8.3, 12.B |
| RT-01 through RT-03 | 3.18, 4.3, 5.5 (version endpoint) |
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

### E. CSS Custom Properties (Design Tokens)

The `app.css` stylesheet defines CSS custom properties on `:root` for consistent theming:

```css
:root {
    /* Colors */
    --color-primary: #0079bf;
    --color-primary-hover: #026aa7;
    --color-secondary: #5ba4cf;
    --color-background: #f4f5f7;
    --color-surface: #ffffff;
    --color-text: #172b4d;
    --color-text-secondary: #5e6c84;
    --color-border: #dfe1e6;
    --color-error: #eb5a46;
    --color-success: #61bd4f;
    --color-warning: #f2d600;

    /* Spacing */
    --space-xs: 4px;
    --space-sm: 8px;
    --space-md: 16px;
    --space-lg: 24px;
    --space-xl: 32px;

    /* Typography */
    --font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
    --font-size-sm: 0.875rem;
    --font-size-md: 1rem;
    --font-size-lg: 1.25rem;
    --font-size-xl: 1.5rem;

    /* Layout */
    --header-height: 48px;
    --lane-width: 272px;
    --card-border-radius: 4px;
    --border-radius: 4px;

    /* Shadows */
    --shadow-card: 0 1px 2px rgba(0, 0, 0, 0.1);
    --shadow-elevated: 0 4px 12px rgba(0, 0, 0, 0.15);

    /* Z-index layers */
    --z-dropdown: 100;
    --z-modal: 200;
    --z-notification: 300;
    --z-drag: 400;
}
```

These tokens enable future theming (dark mode, custom branding) by overriding custom properties without changing component CSS.

---

*This specification is complete and ready for implementation. It traces back to all requirements in REQUIREMENTS.md v1.2 and provides sufficient detail for a developer to build KanBoard without ambiguity.*
