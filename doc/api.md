# Shuffle REST API v1 Reference

**Base URL:** `https://boards.example.com/v1`

All requests and responses use JSON (`Content-Type: application/json`) unless noted
otherwise (file upload uses raw binary body).

---

## Authentication

Shuffle uses server-side sessions. Log in via `POST /auth/login` to obtain a session
cookie, which must be sent with every subsequent request.

State-changing requests (POST, PUT, DELETE) also require a **CSRF token** supplied in
the `X-CSRF-Token` header. Obtain the token from the `<meta name="csrf-token">` tag on
any HTML page, or from `GET /auth/session`.

### Errors

All error responses follow this shape:

```json
{ "error": "Human-readable message" }
```

Common HTTP status codes:

| Code | Meaning |
|------|---------|
| 400 | Bad Request — invalid or missing field |
| 403 | Forbidden — invalid CSRF token or insufficient role |
| 404 | Not Found |
| 409 | Conflict (e.g. delete a lane that has cards) |
| 500 | Internal Server Error |

---

## Auth

### POST /auth/login

Log in with username and password. No CSRF token required.

**Request body:**
```json
{ "username": "alice", "password": "secret" }
```

**Response 200:**
```json
{ "user": { "id": 1, "username": "alice", "name": "Alice Smith", "role": "admin" } }
```

**Response 401:**
```json
{ "error": "Invalid username or password." }
```

---

### POST /auth/logout

End the current session.

**Response 204:** No body.

---

### GET /auth/session

Returns the currently authenticated user and a fresh CSRF token.

**Response 200:**
```json
{
  "user": { "id": 1, "username": "alice", "name": "Alice Smith", "role": "admin" },
  "csrf_token": "abc123"
}
```

**Response 401:** Not authenticated.

---

## Users

### GET /users

List all users. **Role required:** admin.

**Response 200:**
```json
{
  "users": [
    { "id": 1, "username": "alice", "name": "Alice Smith", "email": "alice@example.com",
      "role": "admin", "status": "active", "created_at": "2025-01-01T00:00:00Z" }
  ]
}
```

---

### GET /users/{id}

Get a single user. **Role required:** admin or self.

**Response 200:**
```json
{ "user": { "id": 1, "username": "alice", "name": "Alice Smith", "email": "alice@example.com",
            "role": "admin", "status": "active" } }
```

---

### POST /users/invite

Invite a new user by email. Sends an invitation email with an activation link.
**Role required:** admin.

**Request body:**
```json
{ "email": "bob@example.com", "role": "member" }
```

**Response 200:**
```json
{ "message": "Invitation sent successfully." }
```

---

### POST /users/activate

Activate an invited account using the token from the invitation email.
No CSRF token required.

**Request body:**
```json
{
  "token": "invitation-token-from-email",
  "username": "bob",
  "name": "Bob Jones",
  "password": "secret123"
}
```

**Response 200:**
```json
{ "message": "Your account has been activated. You can now log in." }
```

---

### PUT /users/{id}

Update user fields. **Role required:** admin or self (limited fields).

**Request body (admin):**
```json
{ "name": "Bob Jones", "email": "bob@example.com", "role": "member", "status": "active" }
```

**Request body (self — password change):**
```json
{ "current_password": "old", "password": "new-password" }
```

**Response 200:**
```json
{ "user": { "id": 2, "username": "bob", "name": "Bob Jones" } }
```

---

### DELETE /users/{id}

Delete a user. **Role required:** admin.

**Response 204:** No body.

---

## Organizations

### GET /organizations

List all organizations. **Role required:** admin.

**Response 200:**
```json
{
  "organizations": [
    { "id": 1, "name": "Acme Corp", "member_count": 5, "created_at": "2025-01-01T00:00:00Z" }
  ]
}
```

---

### GET /organizations/{id}

Get a single organization. **Role required:** admin.

**Response 200:**
```json
{ "organization": { "id": 1, "name": "Acme Corp", "created_at": "2025-01-01T00:00:00Z" } }
```

---

### POST /organizations

Create an organization. **Role required:** admin.

**Request body:**
```json
{ "name": "Acme Corp" }
```

**Response 201:**
```json
{ "organization": { "id": 2, "name": "Acme Corp" } }
```

---

### PUT /organizations/{id}

Update an organization name. **Role required:** admin.

**Request body:**
```json
{ "name": "Acme Corporation" }
```

**Response 200:**
```json
{ "organization": { "id": 1, "name": "Acme Corporation" } }
```

---

### DELETE /organizations/{id}

Delete an organization. Fails if the organization has active members.
**Role required:** admin.

**Response 204:** No body.
**Response 409:** Organization has active members.

---

### GET /organizations/{id}/members

List members of an organization. **Role required:** admin.

**Response 200:**
```json
{
  "members": [
    { "id": 1, "username": "alice", "name": "Alice Smith", "role": "admin" }
  ]
}
```

---

## Boards

### GET /boards

List boards accessible to the current user.

Query parameters:
- `archived=1` — include archived boards

**Response 200:**
```json
{
  "boards": [
    { "id": 1, "title": "My Board", "description": "...", "is_archived": false,
      "visibility": "organization", "version": 42, "created_at": "2025-01-01T00:00:00Z" }
  ]
}
```

---

### GET /boards/{id}

Get a board with its lanes and card summaries.

**Response 200:**
```json
{
  "board": {
    "id": 1,
    "title": "My Board",
    "version": 42,
    "lanes": [
      {
        "id": 10,
        "title": "To Do",
        "position": 1000,
        "cards": [
          { "id": 100, "title": "Task 1", "position": 1000,
            "due_date": "2025-06-01", "comment_count": 2,
            "attachment_count": 1,
            "checklist_progress": { "done": 1, "total": 3 },
            "assigned_users": [{ "id": 1, "name": "Alice Smith" }] }
        ]
      }
    ]
  }
}
```

---

### POST /boards

Create a board. **Role required:** member.

**Request body:**
```json
{ "title": "My Board", "description": "Optional description", "visibility": "organization" }
```

`visibility`: `"private"` (owner only) or `"organization"` (all org members).

**Response 201:**
```json
{ "board": { "id": 2, "title": "My Board" } }
```

---

### PUT /boards/{id}

Update board metadata. **Role required:** member.

**Request body (all fields optional):**
```json
{ "title": "Renamed Board", "description": "New desc", "visibility": "private" }
```

**Response 200:**
```json
{ "board": { "id": 1, "title": "Renamed Board" } }
```

---

### POST /boards/{id}/archive

Archive a board. **Role required:** admin.

**Response 200:**
```json
{ "board": { "id": 1, "is_archived": true } }
```

---

### POST /boards/{id}/restore

Restore an archived board. **Role required:** admin.

**Response 200:**
```json
{ "board": { "id": 1, "is_archived": false } }
```

---

### DELETE /boards/{id}

Permanently delete a board and all its content. **Role required:** admin.

**Response 204:** No body.

---

### GET /boards/{id}/version

Poll for board updates. Supports `If-None-Match` header with the current version as ETag
(e.g. `"42"`). Returns **304 Not Modified** if unchanged.

**Response 200:**
```json
{ "version": 43 }
```

**Response 304:** No body (board unchanged).

---

## Lanes

### GET /boards/{boardId}/lanes

List lanes for a board.

**Response 200:**
```json
{
  "lanes": [
    { "id": 10, "title": "To Do", "position": 1000, "board_id": 1 }
  ]
}
```

---

### POST /boards/{boardId}/lanes

Create a lane. **Role required:** member.

**Request body:**
```json
{ "title": "In Progress" }
```

**Response 201:**
```json
{ "lane": { "id": 11, "title": "In Progress", "position": 2000 } }
```

---

### PUT /lanes/{id}

Rename a lane. **Role required:** member.

**Request body:**
```json
{ "title": "Doing" }
```

**Response 200:**
```json
{ "lane": { "id": 11, "title": "Doing" } }
```

---

### PUT /lanes/{id}/position

Reorder a lane within its board. **Role required:** member.

**Request body:**
```json
{ "after_lane_id": 10 }
```

`after_lane_id`: ID of the lane to place this lane after. `null` moves it to first position.

**Response 200:**
```json
{ "lane": { "id": 11, "position": 500 } }
```

---

### DELETE /lanes/{id}

Delete a lane. Fails if the lane contains cards. **Role required:** member.

**Response 204:** No body.
**Response 409:** Lane contains cards.

---

## Cards

### GET /cards/{id}

Get a single card with full detail: comments, checklists, attachments, assigned users.

**Response 200:**
```json
{
  "card": {
    "id": 100,
    "title": "Task 1",
    "description": "Markdown content",
    "due_date": "2025-06-01",
    "is_archived": false,
    "position": 1000,
    "lane_id": 10,
    "board_id": 1,
    "created_at": "2025-01-01T00:00:00Z",
    "assigned_users": [{ "id": 1, "name": "Alice Smith", "username": "alice" }],
    "checklists": [
      {
        "id": 50,
        "title": "Subtasks",
        "items": [
          { "id": 200, "title": "Step 1", "is_checked": true, "position": 1000 }
        ]
      }
    ],
    "comments": [
      { "id": 300, "body": "Looks good", "user_name": "Alice Smith",
        "created_at": "2025-01-02T10:00:00Z" }
    ],
    "attachments": [
      { "id": 400, "file_name": "spec.pdf", "file_size": 204800,
        "mime_type": "application/pdf", "created_at": "2025-01-03T09:00:00Z" }
    ]
  }
}
```

---

### POST /boards/{boardId}/lanes/{laneId}/cards

Create a card. **Role required:** member.

**Request body:**
```json
{ "title": "New Task", "description": "Optional markdown", "due_date": "2025-07-01" }
```

**Response 201:**
```json
{ "card": { "id": 101, "title": "New Task", "position": 2000, "lane_id": 10 } }
```

---

### PUT /cards/{id}

Update card fields. **Role required:** member.

**Request body (all fields optional):**
```json
{
  "title": "Renamed Task",
  "description": "Updated content",
  "due_date": "2025-08-01",
  "assigned_user_ids": [1, 2]
}
```

`assigned_user_ids` replaces the full assignment list. Pass `[]` to clear all assignments.

**Response 200:**
```json
{ "card": { "id": 100, "title": "Renamed Task" } }
```

---

### PUT /cards/{id}/move

Move a card to a different lane or position. **Role required:** member.

**Request body:**
```json
{ "lane_id": 11, "after_card_id": 99 }
```

`after_card_id`: ID of the card to place this card after within the lane.
`null` moves it to the top of the lane.

**Response 200:**
```json
{ "card": { "id": 100, "lane_id": 11, "position": 500 } }
```

---

### POST /cards/{id}/archive

Archive a card. **Role required:** member.

**Response 200:**
```json
{ "card": { "id": 100, "is_archived": true } }
```

---

### POST /cards/{id}/restore

Restore an archived card. **Role required:** member.

**Response 200:**
```json
{ "card": { "id": 100, "is_archived": false } }
```

---

### DELETE /cards/{id}

Permanently delete a card. **Role required:** admin.

**Response 204:** No body.

---

## Comments

### POST /cards/{cardId}/comments

Add a comment. **Role required:** member.

**Request body:**
```json
{ "body": "This is a comment. Markdown is supported." }
```

**Response 201:**
```json
{
  "comment": {
    "id": 301, "body": "This is a comment.", "card_id": 100,
    "user_id": 1, "user_name": "Alice Smith", "created_at": "2025-01-05T12:00:00Z"
  }
}
```

---

### PUT /comments/{id}

Edit a comment. **Role required:** comment author or admin.

**Request body:**
```json
{ "body": "Updated comment text." }
```

**Response 200:**
```json
{ "comment": { "id": 301, "body": "Updated comment text." } }
```

---

### DELETE /comments/{id}

Delete a comment. **Role required:** comment author or admin.

**Response 204:** No body.

---

## Checklists

### POST /cards/{cardId}/checklists

Create a checklist. **Role required:** member.

**Request body:**
```json
{ "title": "Definition of Done" }
```

**Response 201:**
```json
{ "checklist": { "id": 51, "title": "Definition of Done", "card_id": 100, "items": [] } }
```

---

### PUT /checklists/{id}

Rename a checklist. **Role required:** member.

**Request body:**
```json
{ "title": "Acceptance Criteria" }
```

**Response 200:**
```json
{ "checklist": { "id": 51, "title": "Acceptance Criteria" } }
```

---

### DELETE /checklists/{id}

Delete a checklist and all its items. **Role required:** member.

**Response 204:** No body.

---

### POST /checklists/{checklistId}/items

Add an item to a checklist. **Role required:** member.

**Request body:**
```json
{ "title": "Write unit tests" }
```

**Response 201:**
```json
{ "item": { "id": 201, "title": "Write unit tests", "is_checked": false, "position": 2000 } }
```

---

### PUT /checklist-items/{id}

Update a checklist item (title or checked state). **Role required:** member.

**Request body (all fields optional):**
```json
{ "title": "Write and run unit tests", "is_checked": true }
```

**Response 200:**
```json
{ "item": { "id": 201, "title": "Write and run unit tests", "is_checked": true } }
```

---

### PUT /checklist-items/{id}/position

Reorder a checklist item within its checklist. **Role required:** member.

**Request body:**
```json
{ "after_item_id": 200 }
```

`after_item_id`: ID of the item to place this item after. `null` moves it to first position.

**Response 200:**
```json
{ "item": { "id": 201, "position": 500 } }
```

---

### DELETE /checklist-items/{id}

Delete a checklist item. **Role required:** member.

**Response 204:** No body.

---

## Notifications

### GET /notifications

List notifications for the current user, newest first.

**Response 200:**
```json
{
  "notifications": [
    {
      "id": 1,
      "type": "assigned",
      "message": "You were assigned to 'Task 1'",
      "card_id": 100,
      "is_read": false,
      "created_at": "2025-01-05T12:00:00Z"
    }
  ]
}
```

---

### GET /notifications/count

Get the count of unread notifications.

**Response 200:**
```json
{ "count": 3 }
```

---

### PUT /notifications/{id}/read

Mark a notification as read.

**Response 200:**
```json
{ "notification": { "id": 1, "is_read": true } }
```

---

### POST /notifications/read-all

Mark all notifications for the current user as read.

**Response 204:** No body.

---

### DELETE /notifications/{id}

Delete a notification.

**Response 204:** No body.

---

## Search

### GET /search

Search cards by title and description. Results are ordered by relevance.

Query parameters:
- `q` (required) — search query, minimum 3 characters

**Example:** `GET /v1/search?q=login+bug`

**Response 200:**
```json
{
  "results": [
    {
      "id": 100,
      "title": "Fix login bug",
      "board_id": 1,
      "board_title": "Product Board",
      "lane_title": "In Progress",
      "is_archived": false,
      "board_archived": false
    }
  ],
  "count": 1,
  "query": "login bug"
}
```

**Response 400:** Query too short.

---

## Attachments

### POST /cards/{cardId}/attachments

Upload a file attachment to a card. **Role required:** member.

The request body is the **raw binary file content** (not multipart/form-data).
File metadata is passed via headers:

| Header | Description |
|--------|-------------|
| `Content-Type` | MIME type of the file (e.g. `image/png`) |
| `X-File-Name` | URL-encoded file name (e.g. `screenshot%201.png`) |
| `X-File-Size` | File size in bytes as a decimal string |
| `X-CSRF-Token` | CSRF token |

**Allowed MIME types:** `image/*`, `application/pdf`, `text/*`,
`application/msword`, `application/vnd.openxmlformats-officedocument.*`,
`application/vnd.ms-excel`, `application/vnd.ms-powerpoint`,
`application/zip`, `application/x-zip-compressed`

**Example (curl):**
```bash
curl -X POST https://boards.example.com/v1/cards/100/attachments \
  -H "Content-Type: image/png" \
  -H "X-File-Name: screenshot.png" \
  -H "X-File-Size: $(stat -c%s screenshot.png)" \
  -H "X-CSRF-Token: your-csrf-token" \
  --data-binary @screenshot.png \
  --cookie "shuffle_session=your-session-cookie"
```

**Response 201:**
```json
{
  "attachment": {
    "id": 401,
    "file_name": "screenshot.png",
    "file_size": 204800,
    "mime_type": "image/png",
    "card_id": 100,
    "user_id": 1,
    "created_at": "2025-01-06T09:00:00Z"
  }
}
```

---

### GET /cards/{cardId}/attachments

List attachments for a card.

**Response 200:**
```json
{
  "attachments": [
    { "id": 401, "file_name": "screenshot.png", "file_size": 204800,
      "mime_type": "image/png", "created_at": "2025-01-06T09:00:00Z" }
  ]
}
```

---

### GET /attachments/{id}/download

Download an attachment. The server streams the file content directly from S3.

**Response 200:** Binary file content with appropriate `Content-Type` and
`Content-Disposition: attachment; filename="..."` headers.

---

### DELETE /attachments/{id}

Delete an attachment. Removes the record from the database and the file from S3.
**Role required:** uploader or admin.

**Response 204:** No body.
