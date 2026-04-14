# MCP Tool Contract — StratFlow

This document defines the contract for every tool exposed by the `stratflow-mcp` server.
The MCP server is implemented as a separate process that calls the StratFlow REST API
(authenticated via PAT) and wraps the JSON responses as tool outputs.

---

## Authentication

Every MCP session authenticates as a specific StratFlow user via a Personal Access Token
stored in the MCP server's environment (e.g. `STRATFLOW_API_TOKEN`). The token scopes the
user's view to their own organisation.

Boot sequence:
1. MCP server starts and reads `STRATFLOW_API_URL` + `STRATFLOW_API_TOKEN`
2. Calls `GET /api/v1/me` to verify the token is valid and fetch the user identity
3. Caches `user.id`, `user.org_id`, `user.team` for the session
4. If `/api/v1/me` returns non-200, the server logs the error and refuses to start

---

## Tools

### `get_story`

**Description:** Fetch a single user story with its full context (acceptance criteria,
HL parent, Jira key, quality score).

**Input schema:**
```json
{
  "type": "object",
  "required": ["id"],
  "properties": {
    "id": { "type": "integer", "description": "Story ID" }
  }
}
```

**API call:** `GET /api/v1/stories/{id}`

**Output contract:** Object conforming to the `Story` schema in `docs/openapi.yaml`.
Required fields: `id`, `title`, `status`, `project_id`.

**Error cases:**
- `404` — story does not exist or belongs to a different org → tool returns `{ "error": "not found" }`
- `401` — invalid PAT → tool logs and raises a connection error

---

### `list_my_stories`

**Description:** List stories assigned to the authenticated user, optionally filtered
by status or project.

**Input schema:**
```json
{
  "type": "object",
  "properties": {
    "status": {
      "type": "string",
      "enum": ["backlog", "in_progress", "in_review", "done"]
    },
    "project_id": { "type": "integer" },
    "limit":      { "type": "integer", "default": 50, "maximum": 200 }
  }
}
```

**API call:** `GET /api/v1/stories?mine=1[&status=...][&project_id=...][&limit=...]`

**Output contract:** `{ "stories": Story[] }` — array may be empty.

---

### `list_team_stories`

**Description:** List stories assigned to the authenticated user's team.

**Input schema:** `{}` (no parameters)

**API call:** `GET /api/v1/stories/team`

**Output contract:** `{ "stories": Story[] }`

---

### `start_story`

**Description:** Transition a story from `backlog` to `in_progress`.

**Input schema:**
```json
{
  "type": "object",
  "required": ["id"],
  "properties": {
    "id": { "type": "integer" }
  }
}
```

**API call:** `POST /api/v1/stories/{id}/status` with body `{ "status": "in_progress" }`

**Output contract:** `{ "ok": true, "status": "in_progress" }` on success.

**Error cases:**
- `400` — invalid status transition (already in that state is OK; truly invalid values return 400)
- `404` — story not found

---

### `complete_story`

**Description:** Transition a story to `done`.

**Input schema:**
```json
{
  "type": "object",
  "required": ["id"],
  "properties": {
    "id": { "type": "integer" }
  }
}
```

**API call:** `POST /api/v1/stories/{id}/status` with body `{ "status": "done" }`

**Output contract:** `{ "ok": true, "status": "done" }`

---

### `claim_story`

**Description:** Assign a story to the authenticated user.

**Input schema:**
```json
{
  "type": "object",
  "required": ["id"],
  "properties": {
    "id": { "type": "integer" }
  }
}
```

**API call:** `POST /api/v1/stories/{id}/assign`

**Output contract:** `{ "ok": true }`

---

### `clear_active_story`

**Description:** Unassign the authenticated user from a story they currently own.

**Input schema:**
```json
{
  "type": "object",
  "required": ["id"],
  "properties": {
    "id": { "type": "integer" }
  }
}
```

**API call:** `POST /api/v1/stories/{id}/status` with body `{ "status": "backlog" }`

**Output contract:** `{ "ok": true, "status": "backlog" }`

---

## Error Response Shape

All error responses from the API follow:

```json
{
  "error": "<human-readable message>"
}
```

HTTP status codes used:
- `400` Bad Request — validation failure (e.g. invalid status value)
- `401` Unauthorised — missing or invalid PAT
- `403` Forbidden — authenticated but not allowed
- `404` Not Found — resource missing or cross-org access denied

---

## Contract Test Stub

`tests/Mcp/` is reserved for MCP tool contract tests once `stratflow-mcp` is in this repo.
Each test class should:
1. Instantiate the tool class directly
2. Call its `execute(input)` method with a known payload
3. Assert the output JSON matches the shape above

Until `stratflow-mcp` is co-located here, the REST API integration tests in
`tests/Unit/Controllers/ApiStoriesControllerTest.php` serve as the proxy contract tests.
