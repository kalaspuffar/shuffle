# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Shuffle** is a self-hosted, open-source Kanban task management system (Trello alternative). The project is in the **design/specification phase** — no application code exists yet. The repository contains requirements, a detailed technical specification, role-specific system prompts, and OpenSpec workflow tooling to drive the project from specification through implementation.

## Tech Stack

- **Backend**: PHP 8.4 — plain, no framework, no Composer
- **Frontend**: Vanilla HTML, CSS, JavaScript — server-rendered, minimal dependencies
- **Database**: MySQL with full-text search
- **File Storage**: S3-compatible (path-based), tested against MinIO
- **Markdown**: Parsedown (single-file inclusion, MIT, no Composer)
- **Deployment**: Bare metal Debian Trixie (13)
- **Future Mobile**: Flutter (Android/iOS)
- **License**: MIT — all code and dependencies must be MIT-compatible

## Key Design Constraints

- **No Composer, no npm** — minimize external dependencies; prefer in-project code
- **No hardcoded strings** — all text must be externalizable for future i18n
- **Server-rendered HTML** for web; REST API for data consumption by other platforms
- **WCAG 2.1 AA** mandatory; AAA as stretch goal
- **Lighthouse score** >= 95% at MVP
- **Invite-only** — no self-registration; admin invites users via SMTP email
- **Single org per user** (future: multi-org)
- **System-wide roles** only (Admin, Member, Viewer) — no per-board roles yet

## Repository Structure

```
REQUIREMENTS.md        — Functional/non-functional requirements (v1.2, complete)
SPECIFICATION.md       — Technical specification (v1.0, draft — produced by Solution Architect, reviewed by Designer + Accessibility)
personas/              — Role-specific system prompts (load one per session)
  ANALYST.md           — Requirements Analyst → produces REQUIREMENTS.md
  SOLUTION_ARCHITECT.md — Solution Architect → reads requirements, produces SPECIFICATION.md
  GRAPHICAL_DESIGNER.md — Graphical Designer → reviews/enhances visual design in SPECIFICATION.md
  ACCESSIBILITY_REVIEWER.md — Accessibility Reviewer → WCAG 2.1 AA compliance review
  WEB_DEVELOPER.md     — Web Developer → implements features per SPECIFICATION.md on feature branches
  CODE_REVIEWER.md     — Code Reviewer → reviews feature branches, writes COMMENTS.md
openspec/              — OpenSpec workflow configuration and state
  config.yaml          — OpenSpec config (spec-driven schema)
  changes/             — Active and archived changes
  specs/               — Synced specs (currently empty)
.claude/commands/opsx/ — Slash command definitions for OpenSpec workflows
.claude/skills/        — OpenSpec skill implementations
```

## Role-Based Workflow

Different Claude Code sessions operate under different persona system prompts from `personas/`. The workflow progresses through phases:

1. **Analyst** → Produces `REQUIREMENTS.md`
2. **Solution Architect** → Reads requirements, produces `SPECIFICATION.md`
3. **Graphical Designer** → Reviews/enhances visual design specs in SPECIFICATION.md
4. **Accessibility Reviewer** → Reviews for WCAG 2.1 AA compliance, updates SPECIFICATION.md
5. **Web Developer** → Implements features on feature branches per SPECIFICATION.md
6. **Code Reviewer** → Reviews feature branches against spec, produces `COMMENTS.md`

## OpenSpec Commands

Implementation is managed through OpenSpec (`/opsx:*` commands):

| Command | Purpose |
|---------|---------|
| `/opsx:new` | Start a new change |
| `/opsx:ff` | Fast-forward: create all artifacts at once |
| `/opsx:continue` | Create the next artifact in a change |
| `/opsx:apply` | Implement tasks from a change |
| `/opsx:verify` | Verify implementation matches artifacts |
| `/opsx:archive` | Archive a completed change |
| `/opsx:explore` | Explore/investigate before or during a change |
| `/opsx:sync` | Sync delta specs to main specs |
| `/opsx:bulk-archive` | Archive multiple completed changes |
| `/opsx:onboard` | Guided walkthrough of the OpenSpec workflow |

## Development Process (When Implementation Begins)

Per `WEB_DEVELOPER.md`, the development process uses feature branches:
- Create branches like `feature/descriptive-name` for each logical unit of work
- The Web Developer reads `SPECIFICATION.md` (and `COMMENTS.md` if present) before coding
- The Code Reviewer uses `git diff main` to review changes, then writes `COMMENTS.md`
