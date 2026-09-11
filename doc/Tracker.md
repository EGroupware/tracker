# Tracker REST API

A **CRUD REST API** for the EGroupware **Tracker** app (bug/issue tracker), exposed via the
GroupDAV endpoint alongside Addressbook, Calendar, Infolog, and Timesheet.

Implemented by `EGroupware\Tracker\ApiHandler` (`tracker/src/ApiHandler.php`), with the JSON
mapping in `EGroupware\Tracker\JsTracker` (`tracker/src/JsTracker.php`). Field names follow the
JSCalendar Task (JsTask) vocabulary where an equivalent attribute exists.

---

## 1. Base URL & Authentication

```
https://example.egroupware.org/egroupware/groupdav.php/{user}/tracker/
```

| Part | Description |
|------|-------------|
| `{user}` | EGroupware username (e.g. `admin`, `sysop`). Scopes the collection listing to that user, but has no effect on direct access to a single ticket by id. |

**Tickets are addressed by their numeric `id` only.** Unlike Calendar/InfoLog/Addressbook,
Tracker has no client-supplied stable UID — the server always assigns the id, and a `PUT` to
a path that doesn't already resolve to an existing ticket **creates a new one** with a
server-assigned id (the id segment in the request path is not used). Always read the real
address back from the `Location` header (POST) or from the ticket's `id` field.

**Required headers for JSON:**

| Request type | Header |
|---|---|
| All reads | `Accept: application/json` |
| POST / PUT / PATCH | `Content-Type: application/json` |

---

## 2. Endpoints Overview

| Method | URL | Description |
|--------|-----|-------------|
| `GET` | `.../tracker/` | List accessible tickets (up to 500 per page), optionally filtered |
| `GET` | `.../tracker/{id}` | Fetch a single ticket, including its replies |
| `POST` | `.../tracker/` | Create a new ticket |
| `PATCH` | `.../tracker/{id}` | Partial update (only supplied fields) |
| `PUT` | `.../tracker/{id}` | Update — see [§11](#11-put) for why this behaves like PATCH, not a full replace |
| `DELETE` | `.../tracker/{id}` | Delete a ticket |
| `GET` | `.../tracker/{id}/replies` | List all replies/comments visible to the caller |
| `GET` | `.../tracker/{id}/replies/{reply_id}` | Fetch a single reply |
| `POST` | `.../tracker/{id}/replies/` | Add a reply |
| `PUT` / `PATCH` | `.../tracker/{id}/replies/{reply_id}` | Update a reply (`message`, `restricted` only) |
| `DELETE` | `.../tracker/{id}/replies/{reply_id}` | Delete a reply |

> Attachments are accessible via the standard Links/Attachments facility described in
> [Links-and-attachments.md](Links-and-attachments.md).

---

## 3. Ticket JSON Object

This is the canonical shape returned by GET and accepted by POST / PUT / PATCH.

```json
{
  "@type":          "Ticket",
  "id":              42,
  "title":           "Login page crashes on mobile",
  "description":     "Steps to reproduce: ...",
  "tracker":          1,
  "status":          "Open",
  "priority":         5,
  "percentComplete":  0,
  "start":            null,
  "due":              null,
  "closed":           null,
  "privacy":         "public",
  "category":        "Bug",
  "version":         "2.1",
  "resolution":       null,
  "creator":         "demo@example.org",
  "created":         "2026-05-25T10:00:00Z",
  "updated":         "2026-05-25T11:30:00Z",
  "modifier":        "demo@example.org",
  "participants":    { "5": { "@type": "Participant", "name": "Demo User", "email": "demo@example.org", "kind": "individual", "roles": { "owner": true } } },
  "group":            null,
  "egroupware.org:customfields": {},
  "etag":            "42:1748167200"
}
```

### Field Reference

| Field | Type | Writable | Description |
|-------|------|----------|-------------|
| `@type` | `"Ticket"` | No | Always `"Ticket"`. Ignored on write. |
| `id` | integer | No | Ticket id. Server-assigned on POST/PUT-create. |
| `title` | string | Yes | **Required on POST and non-PATCH writes.** One-line summary. |
| `description` | string | Yes | Full description. Omitted from the response when empty. |
| `tracker` | integer | Yes | The queue (tracker) id the ticket belongs to. Determines which `category`/`version`/`status`/`resolution` labels are valid — see [§7](#7-category-version-status-and-resolution). Defaults to the caller's default/first accessible queue if omitted on create. |
| `status` | string | Yes | See [§6](#6-status-values). |
| `priority` | integer (1–9) | Yes | See [§8](#8-priority-values). |
| `percentComplete` | integer (0–100) | Yes | Completion percentage. |
| `start` | ISO 8601 datetime | Yes | Omitted when not set. |
| `due` | ISO 8601 datetime | Yes | Omitted when not set. |
| `closed` | ISO 8601 datetime | No | Auto-set when status transitions to a closed status. Omitted when not set. |
| `privacy` | `"private"` \| `"public"` | Yes | `"private"` = visible only to creator, assignees and tracker admins. |
| `category` | string | Yes | See [§7](#7-category-version-status-and-resolution). Omitted when not set. |
| `version` | string | Yes | See [§7](#7-category-version-status-and-resolution). Omitted when not set. |
| `resolution` | string | Yes | See [§7](#7-category-version-status-and-resolution). Omitted when not set. |
| `creator` | string | Yes (POST only) | Account email (falls back to account name / id). |
| `created` | ISO 8601 datetime | No | Auto-set on creation. |
| `updated` | ISO 8601 datetime | No | Auto-set on every save. Omitted when not available. |
| `modifier` | string | No | Account email of whoever last saved the ticket. Omitted when not available. |
| `participants` | object | Yes | JSCalendar-style map keyed by account id / e-mail. Creator = role `owner`; assigned accounts = role `attendee`; CC addresses = role `informational`. See [§9](#9-participants). |
| `group` | string | Yes | Account email of the responsible group. Omitted when not set. |
| `egroupware.org:customfields` | object | Yes | See [§9](#9-participants) → custom fields below. |
| `etag` | string | No | `"{id}:{updated-timestamp}"`. Use with `If-Match` for optimistic locking. |
| `replies` | object | No | Only present on a single-ticket `GET`. Map keyed by reply id — see [§12](#12-replies). |

---

## 4. GET — List Tickets

```
GET /egroupware/groupdav.php/{user}/tracker/
Accept: application/json
```

Returns a JSON object keyed by the ticket's path. Each value is a ticket object (without
`replies`). The collection is paged in batches of up to 500.

**Response `200 OK`:**

```json
{
  "responses": {
    "/admin/tracker/2": {
      "@type":    "Ticket",
      "id":        2,
      "title":    "Fix login crash",
      "status":   "Open",
      "priority":  5,
      "privacy":  "public",
      "created":  "2026-05-20T15:55:24Z",
      "updated":  "2026-05-20T16:15:18Z"
    },
    "/admin/tracker/3": { "..." : "..." }
  }
}
```

### Filter query parameters

| Parameter | Effect |
|---|---|
| `search` | Full-text search |
| `status` | Ticket status label (see [§6](#6-status-values)); combine with `tracker` to match a queue-specific custom status |
| `priority` | Numeric priority |
| `tracker` | Restrict to one queue (id) |
| `assigned` | Account id or login name |
| `linked` | `"<app>:<id>"` — tickets linked to another EGroupware entry |
| `#<customfield>` | Custom field value |

---

## 5. GET — Single Ticket

```
GET /egroupware/groupdav.php/{user}/tracker/{id}
Accept: application/json
```

Returns the full ticket object directly (not wrapped in `responses`), including a `replies` map
if the ticket has any.

**Response headers:**

```
ETag: "42:1748167200"
Content-Type: application/json
```

**Access control:** Private tickets (`"privacy": "private"`) are only visible to the creator,
assignees, and tracker admins.

---

## 6. Status Values

`status` is a **label string**, not the internal integer code. There are four built-in,
global statuses plus any number of custom, per-queue statuses an admin can define.

| String value | Internal code | Description |
|---|---|---|
| `"Open"` | `-100` | Active, unresolved ticket |
| `"Closed"` | `-101` | Resolved/completed ticket |
| `"Deleted"` | `-102` | Soft-deleted (not shown in normal lists) |
| `"Pending"` | `-103` | Waiting for external input |
| *(custom label)* | positive `cat_id` | Admin-defined per queue — see [§7](#7-category-version-status-and-resolution) |

Custom statuses are scoped to a `tracker` (queue) exactly like category/version/resolution:
a custom status created for queue 2 is only a valid value for tickets whose `tracker` is 2
(or for a global custom status not restricted to any particular queue).

---

## 7. Category, Version, Status and Resolution

`category`, `version`, `status` and `resolution` are all internally stored as an integer
`cat_id` (EGroupware category id) — but **unlike ordinary categories, these are not arbitrary
values a client can invent.** They are admin-managed lists, scoped to the ticket's `tracker`
(queue), configured under *Admin → Tracker*:

- Each value must **already exist** as a label of the matching type for that queue (or be a
  global label not restricted to a queue) — the server never auto-creates one for you.
- They are **single-valued** — send a plain string, not a JSCalendar-style
  `{ "name": true }` category map.
- `tracker` matters: send it in the same request (or rely on the ticket's current queue for
  PATCH/PUT) *before* relying on a queue-specific `category`/`version`/`status`/`resolution`
  label, since the label is resolved against that queue.

**Unknown or wrong-type label → `422` error**, e.g.:

```json
{ "error": 422, "message": "Error parsing JsTracker attribute 'category': Invalid category 'Typo' for this tracker/queue" }
```

```json
{
  "tracker":    2,
  "category":  "Bug",
  "version":   "2.1",
  "status":    "Open",
  "resolution": null
}
```

---

## 8. Priority Values

Priority is an **integer from 1 (lowest) to 9 (highest)**. Default stock labels:

| Value | Label |
|-------|-------|
| `1` | 1 - lowest |
| `2` | 2 |
| `3` | 3 |
| `4` | 4 |
| `5` | 5 - medium |
| `6` | 6 |
| `7` | 7 |
| `8` | 8 |
| `9` | 9 - highest |

> Queue admins can customize priority labels per queue. The API always accepts and returns the **integer value** regardless of custom label configuration.

---

## 9. Participants

`assigned` accounts and `cc` e-mail addresses are merged into a single JSCalendar-style
`participants` map keyed by account id (assigned) or e-mail (cc):

```json
"participants": {
  "5":                  { "@type": "Participant", "name": "Demo User",   "email": "demo@example.org", "kind": "individual", "roles": { "owner": true } },
  "8":                  { "@type": "Participant", "name": "Jane Tech",   "email": "jane@example.org",  "kind": "individual", "roles": { "attendee": true } },
  "someone@else.org":   { "@type": "Participant", "email": "someone@else.org", "roles": { "informational": true } }
}
```

- The creator always carries the `owner` role.
- Assigned accounts carry the `attendee` role.
- CC addresses carry the `informational` role.

**PATCH** individual participants without replacing the whole map:

```json
{ "participants": { "8": { "roles": { "attendee": true } } } }
```

```json
{ "participants": { "8": null } }
```
removes that assignee (the creator/owner entry cannot be removed this way).

### Custom fields

`egroupware.org:customfields` is an object keyed by custom field name. On read, each value is
`{ "value": ..., "type": ..., "label": ..., "values": ... }` (only for fields that have a
value); on write, either the plain scalar or `{ "value": ... }` is accepted:

```json
{ "egroupware.org:customfields": { "browser": "Firefox 128" } }
```

---

## 10. PATCH — Partial Update

```
PATCH /egroupware/groupdav.php/{user}/tracker/{id}
Content-Type: application/json
```

Only the fields present in the request body are updated — see [§11](#11-put) for why `PUT`
behaves the same way here.

**Request body:**

```json
{
  "title":    "Updated title",
  "status":   "Closed",
  "priority": 3
}
```

**Response `200 OK` or `204 No Content`.**

**Behaviour notes:**
- Any writable field from the [field reference](#field-reference) is accepted.
- Fields the authenticated user cannot modify (based on their role in the queue) are **silently skipped**.

---

## 11. PUT

```
PUT /egroupware/groupdav.php/{user}/tracker/{id}
Content-Type: application/json
```

**Despite the verb, this is not a full replace.** The server merges the supplied body onto the
existing record exactly like PATCH does — fields you omit keep their current value, they are
**not** reset to defaults. The only functional differences from PATCH are: `title` is required,
and an empty body is rejected. If you want to clear a field, send it explicitly (`null` where
the field accepts it, e.g. `"description": null`).

**Request body:**

```json
{
  "title":       "Replaced title",
  "description": "Full replacement description",
  "status":      "Open",
  "priority":    5
}
```

**Response `204 No Content`** — no body (unless `Prefer: return=representation` is sent).

**ETag precondition (optimistic locking):**

```
If-Match: "42:1748167200"
```

If the ticket was modified since the ETag was fetched, the server returns `412 Precondition Failed`.
The same `If-Match` precondition is honored on **PATCH** and **DELETE** as well.

---

## 12. Replies

```
GET /egroupware/groupdav.php/{user}/tracker/{id}/replies
```

Returns a map keyed by reply id:

```json
{
  "17": {
    "@type":     "Reply",
    "id":         17,
    "message":   "Confirmed, working on a fix.",
    "creator":   "jane@example.org",
    "created":   "2026-05-25T12:00:00Z",
    "restricted": false
  }
}
```

`POST` the same shape (only `message` and `restricted` are accepted) to
`.../tracker/{id}/replies/` to add a reply — the server returns `201 Created` with a `Location`
header. `PUT`/`PATCH` and `DELETE` on `.../tracker/{id}/replies/{reply_id}` update or remove a
single reply; only the author, admins, or technicians may modify/delete a reply.

---

## 13. DELETE — Delete Ticket

```
DELETE /egroupware/groupdav.php/{user}/tracker/{id}
```

**Response `204 No Content`** — ticket deleted, no body.

Only tracker admins (full EGroupware admin or tracker `admin` ACL right) may delete tickets.

---

## 14. curl Examples

### List all tickets

```bash
curl -sk \
  -u "admin:YOUR_APP_PASSWORD" \
  "https://example.egroupware.org/egroupware/groupdav.php/admin/tracker/" \
  -H "Accept: application/json" | python3 -m json.tool
```

### Create a ticket

```bash
curl -sk \
  -u "admin:YOUR_APP_PASSWORD" \
  "https://example.egroupware.org/egroupware/groupdav.php/admin/tracker/" \
  -X POST \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "title":    "Button not working in Firefox",
    "priority": 7,
    "status":   "Open"
  }' -i | grep -E "HTTP|Location"
```

The ticket's numeric id is the last path segment of the returned `Location` header.

### Fetch a single ticket

```bash
curl -sk \
  -u "admin:YOUR_APP_PASSWORD" \
  "https://example.egroupware.org/egroupware/groupdav.php/admin/tracker/42" \
  -H "Accept: application/json" | python3 -m json.tool
```

### Update status and title (PATCH)

```bash
curl -sk \
  -u "admin:YOUR_APP_PASSWORD" \
  "https://example.egroupware.org/egroupware/groupdav.php/admin/tracker/42" \
  -X PATCH \
  -H "Content-Type: application/json" \
  -d '{"status": "Closed", "title": "Fixed: Button not working in Firefox"}' \
  -w "HTTP %{http_code}\n"
```

### Delete a ticket

```bash
curl -sk \
  -u "admin:YOUR_APP_PASSWORD" \
  "https://example.egroupware.org/egroupware/groupdav.php/admin/tracker/42" \
  -X DELETE \
  -w "HTTP %{http_code}\n"
```

### Full-cycle example (create → update → delete)

```bash
BASE="https://example.egroupware.org/egroupware/groupdav.php/admin/tracker"
AUTH="admin:YOUR_APP_PASSWORD"

# Create
LOC=$(curl -si -u "$AUTH" "$BASE/" -X POST \
  -H "Content-Type: application/json" \
  -d '{"title":"Test ticket","priority":3}' \
  | grep -i "^location:" | tr -d '\r\n')
ID=$(echo "$LOC" | sed 's|.*tracker/||' | tr -d '/ \r\n')
echo "Created ticket ID=$ID"

# Read
curl -sk -u "$AUTH" "$BASE/$ID" -H "Accept: application/json" | python3 -m json.tool

# Update
curl -sk -u "$AUTH" "$BASE/$ID" -X PATCH \
  -H "Content-Type: application/json" \
  -d '{"status":"Closed"}' -w "PATCH: HTTP %{http_code}\n"

# Delete
curl -sk -u "$AUTH" "$BASE/$ID" -X DELETE -w "DELETE: HTTP %{http_code}\n"
```

---

## 15. Attachments

Ticket attachments are accessible through EGroupware's **Links and Attachments** facility.
See [Links-and-attachments.md](Links-and-attachments.md) for the complete reference.

The links sub-collection for a ticket is at:

```
/egroupware/groupdav.php/{user}/tracker/{id}/links/
```

### List attachments

```bash
curl -sk -u "admin:PASSWORD" \
  "https://example.egroupware.org/egroupware/groupdav.php/admin/tracker/42/links/" \
  -H "Accept: application/json" | python3 -m json.tool
```

### Upload an attachment (POST multipart)

```bash
curl -sk -u "admin:PASSWORD" \
  "https://example.egroupware.org/egroupware/groupdav.php/admin/tracker/42/links/" \
  -X POST \
  -F "file=@/path/to/screenshot.png;type=image/png" \
  -i | grep -E "HTTP|Location"
```

### Upload an attachment as raw bytes (PUT)

```bash
curl -sk -u "admin:PASSWORD" \
  "https://example.egroupware.org/egroupware/groupdav.php/admin/tracker/42/links/screenshot.png" \
  -X PUT \
  -H "Content-Type: image/png" \
  --data-binary "@/path/to/screenshot.png" \
  -w "HTTP %{http_code}\n"
```

### Delete an attachment

```bash
curl -sk -u "admin:PASSWORD" \
  "https://example.egroupware.org/egroupware/groupdav.php/admin/tracker/42/links/{link_id}" \
  -X DELETE -w "HTTP %{http_code}\n"
```
