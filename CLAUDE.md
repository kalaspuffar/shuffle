# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**KanBoard** is a self-hosted, open-source Kanban task management system (Trello alternative). The project is currently in the **design/specification phase** — no application code exists yet. The repository contains requirements, role-specific system prompts, and OpenSpec workflow tooling to drive the project from specification through implementation.

## Tech Stack (from REQUIREMENTS.md)

- **Backend**: PHP 8.4 — plain, no framework, no Composer
- **Frontend**: Vanilla HTML, CSS, JavaScript — server-rendered, minimal dependencies
- **Database**: MySQL with full-text search
- **File Storage**: S3-compatible (path-based), tested against MinIO
- **Markdown**: Parsedown (single-file inclusion, MIT, no Composer)
- **Deployment**: Bare metal Debian Trixie (13)
- **Future Mobile**: Flutter (Android/iOS)
- **License**: MIT — all code and dependencies must be MIT-compatible

## Repository Structure

- `REQUIREMENTS.md` — Complete functional/non-functional requirements (v1.2, ready for architect review)
- `SPECIFICATION.md` — Technical specification (to be produced by Solution Architect)
- `ANALYST.md` — System prompt: Requirements Analyst role
- `SOLUTION_ARCHITECT.md` — System prompt: Solution Architect role (reads REQUIREMENTS.md → produces SPECIFICATION.md)
- `GRAPHICAL_DESIGNER.md` — System prompt: Graphical Designer role (reviews specs for visual completeness)
- `ACCESSIBILITY_REVIEWER.md` — System prompt: Accessibility Reviewer role (WCAG 2.1 AA compliance review)
- `openspec/config.yaml` — OpenSpec configuration (spec-driven schema)
- `.claude/skills/` — OpenSpec workflow skills (new-change, continue, apply, verify, archive, etc.)
- `.claude/commands/opsx/` — Slash command definitions for OpenSpec workflows

## Workflow

This project uses a **role-based workflow** where different Claude Code sessions operate under different system prompts:

1. **Analyst** (`ANALYST.md`) → Produces `REQUIREMENTS.md`
2. **Solution Architect** (`SOLUTION_ARCHITECT.md`) → Reads requirements, produces `SPECIFICATION.md`
3. **Graphical Designer** (`GRAPHICAL_DESIGNER.md`) → Reviews/enhances visual design specs in SPECIFICATION.md
4. **Accessibility Reviewer** (`ACCESSIBILITY_REVIEWER.md`) → Reviews for WCAG 2.1 AA compliance, updates SPECIFICATION.md

Implementation is managed through **OpenSpec** (`/opsx:*` commands):
- `/opsx:new` — Start a new change
- `/opsx:ff` — Fast-forward: create all artifacts for a change at once
- `/opsx:continue` — Create the next artifact in a change
- `/opsx:apply` — Implement tasks from a change
- `/opsx:verify` — Verify implementation matches artifacts
- `/opsx:archive` — Archive a completed change
- `/opsx:explore` — Explore/investigate before or during a change

## Key Design Constraints

- **No Composer, no npm** — minimize external dependencies; prefer in-project code
- **No hardcoded strings** — all text must be externalizable for future i18n
- **Server-rendered HTML** for web; REST API for data consumption by other platforms
- **WCAG 2.1 AA** mandatory; AAA as stretch goal
- **Lighthouse score** >= 95% at MVP
- **Invite-only** — no self-registration; admin invites users via SMTP email
- **Single org per user** (future: multi-org)
- **System-wide roles** only (Admin, Member, Viewer) — no per-board roles yet
