# Shuffle

A self-hosted, open-source Kanban board. Own your tasks, own your data.

Shuffle is a Trello alternative you run on your own server. It gives you configurable boards and lanes, Markdown-rich cards with comments, file attachments, checklists, assignments, due dates, and full-text search — without depending on a third-party service.

## Project Status

Shuffle is **functional and self-hostable**. Core features are implemented:

- Authentication, invite-only registration, and account activation
- Boards, lanes, and drag-and-drop card management
- Cards with Markdown descriptions, comments, checklists, assignments, and due dates
- File attachments stored in S3-compatible object storage
- Full-text search across cards
- Notifications
- Admin panel for user and organization management
- REST API (v1) for external integrations and mobile clients
- Trello board import via CLI

The project has complete [requirements](REQUIREMENTS.md) and a detailed [technical specification](SPECIFICATION.md) guiding ongoing development. Contributions and feedback are welcome.

## Tech Stack

| Component | Technology |
|-----------|------------|
| Backend | PHP 8.4 (no framework, no Composer) |
| Frontend | Vanilla HTML, CSS, JavaScript (server-rendered) |
| Database | MySQL 8.0+ or MariaDB 10.6+ |
| File Storage | S3-compatible (tested with MinIO) |
| Markdown | Parsedown (bundled, MIT license) |
| Target OS | Debian Trixie (13) |

## Getting Started

See [doc/setup.md](doc/setup.md) for the full installation guide, including web server configuration and MinIO setup.

### Prerequisites

- PHP 8.4 with extensions: `pdo_mysql`, `mbstring`, `json`, `openssl`, `filter`
- MySQL 8.0+ or MariaDB 10.6+
- An S3-compatible object store (e.g., [MinIO](https://min.io))
- An SMTP server for email invitations
- Apache 2.4+ or Nginx 1.24+

### Install

1. Clone the repository:

   ```bash
   git clone https://github.com/kalaspuffar/shuffle.git
   cd shuffle
   ```

2. Copy the example configuration and edit it with your database, S3, and SMTP settings:

   ```bash
   cp etc/config.example.php etc/config.php
   ```

3. Create the database and load the schema:

   ```bash
   mysql -u root -p -e "CREATE DATABASE shuffle CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p shuffle < doc/schema.sql
   ```

4. Run the setup script to create the first admin account and default organization:

   ```bash
   php bin/setup.php
   ```

5. Point your web server's document root to the `www/` directory and open the site in a browser.

## REST API

Shuffle exposes a REST API at `/v1/`. See [doc/api.md](doc/api.md) for the full reference.

## Repository Layout

```
bin/                  CLI tools (setup script, Trello import)
doc/                  Database schema, setup guide, API reference
etc/                  Configuration files
include/Shuffle/      Application code (Core, Models, Services, Controllers)
include/lang/         Translation files
include/templates/    Shared HTML templates
include/vendor/       Bundled third-party libraries (Parsedown)
www/                  Web root (server-rendered pages, static assets, API)
personas/             Role-specific prompts used during development
REQUIREMENTS.md       Functional and non-functional requirements
SPECIFICATION.md      Technical specification
```

## Contributing

Shuffle is open to contributions. Here's how to get involved:

1. **Read the spec first.** [SPECIFICATION.md](SPECIFICATION.md) describes the architecture, API design, and implementation plan. Changes should align with it.

2. **Open an issue before writing code.** Describe what you want to change and why. This avoids duplicate work and ensures the change fits the project direction.

3. **Branch from `main`.** Create a feature branch with a descriptive name:

   ```bash
   git checkout -b feature/your-feature-name
   ```

4. **Follow the existing patterns.** No Composer, no npm, no frameworks. All text must be externalizable (no hardcoded user-facing strings). WCAG 2.1 AA compliance is required.

5. **Submit a pull request.** Include a clear description of what changed and reference any related issues.

### Reporting Issues

Open a [GitHub issue](https://github.com/kalaspuffar/shuffle/issues) with:

- A clear title describing the problem or suggestion
- Steps to reproduce (for bugs)
- Expected vs. actual behavior
- Your environment (PHP version, OS, browser)

## Design Goals

- **No vendor lock-in** — runs on your hardware, stores your data
- **Minimal dependencies** — no package managers, no build steps
- **Accessible** — WCAG 2.1 AA compliance, Lighthouse score >= 95%
- **Invite-only** — admins control who joins; no self-registration
- **Importable** — CLI tool for migrating from Trello (`bin/trello-import.php`)

## License

[MIT](LICENSE) — Copyright (c) 2026 Daniel Persson
