# API / Route Reference

All routes are defined in `src/Config/routes.php`. The application uses a custom vanilla PHP router with support for URL parameter placeholders (`{id}`) and per-route middleware stacks.

## Middleware

| Key | Class | Description |
|-----|-------|-------------|
| `auth` | `AuthMiddleware` | Requires an active session; redirects to `/login` if unauthenticated |
| `csrf` | `CSRFMiddleware` | Validates the `_csrf_token` field in POST requests; aborts with 403 on mismatch |
| `admin` | `AdminMiddleware` | Requires `org_admin` or `superadmin` role; redirects to `/app/home` if unauthorised |
| `superadmin` | `SuperadminMiddleware` | Requires the `superadmin` role; redirects to `/app/home` if unauthorised |

Middleware is run in the order listed in the route definition.

---

## Route Table

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/` | `PricingController@index` | — | Landing / pricing page |
| GET | `/pricing` | `PricingController@index` | — | Pricing page (alias) |
| POST | `/checkout` | `CheckoutController@create` | csrf | Create Stripe Checkout session |
| POST | `/webhook/stripe` | `WebhookController@handle` | — | Stripe signed webhook receiver |
| GET | `/success` | `SuccessController@index` | — | Post-payment success page |
| GET | `/login` | `AuthController@showLogin` | — | Login page |
| POST | `/login` | `AuthController@login` | csrf | Login form submit |
| POST | `/logout` | `AuthController@logout` | csrf, auth | Logout and destroy session |
| GET | `/app/home` | `HomeController@index` | auth | Dashboard — project list |
| POST | `/app/projects` | `HomeController@createProject` | auth, csrf | Create a new project |
| GET | `/app/upload` | `UploadController@index` | auth | Document upload page |
| POST | `/app/upload` | `UploadController@store` | auth, csrf | Upload and process a document file |
| POST | `/app/upload/summarise` | `UploadController@generateSummary` | auth, csrf | Generate AI summary for a document |
| GET | `/app/diagram` | `DiagramController@index` | auth | Strategy diagram page |
| POST | `/app/diagram/generate` | `DiagramController@generate` | auth, csrf | Generate Mermaid diagram via AI |
| POST | `/app/diagram/save` | `DiagramController@save` | auth, csrf | Persist the current diagram |
| POST | `/app/diagram/save-okr` | `DiagramController@saveOkr` | auth, csrf | Save OKR data for a diagram node |
| GET | `/app/work-items` | `WorkItemController@index` | auth | Work items list page |
| POST | `/app/work-items/generate` | `WorkItemController@generate` | auth, csrf | AI-generate work items from diagram |
| POST | `/app/work-items/reorder` | `WorkItemController@reorder` | auth | AJAX: update priority order |
| GET | `/app/work-items/export` | `WorkItemController@export` | auth | Download CSV or JSON export |
| POST | `/app/work-items/{id}` | `WorkItemController@update` | auth, csrf | Update a work item's fields |
| POST | `/app/work-items/{id}/delete` | `WorkItemController@delete` | auth, csrf | Delete a work item |
| POST | `/app/work-items/{id}/generate-description` | `WorkItemController@generateDescription` | auth | AI-generate a detailed scope description |
| GET | `/app/prioritisation` | `PrioritisationController@index` | auth | Prioritisation screen |
| POST | `/app/prioritisation/framework` | `PrioritisationController@selectFramework` | auth, csrf | Select RICE or WSJF framework for the project |
| POST | `/app/prioritisation/scores` | `PrioritisationController@saveScores` | auth | AJAX: save individual item scores |
| POST | `/app/prioritisation/rerank` | `PrioritisationController@rerank` | auth, csrf | Re-rank all items by computed final score |
| POST | `/app/prioritisation/ai-baseline` | `PrioritisationController@aiBaseline` | auth | AJAX: AI suggest baseline scores for all items |
| GET | `/app/risks` | `RiskController@index` | auth | Risk management screen |
| POST | `/app/risks/generate` | `RiskController@generate` | auth, csrf | AI generate risks from work items |
| POST | `/app/risks` | `RiskController@store` | auth, csrf | Create a risk manually |
| POST | `/app/risks/{id}` | `RiskController@update` | auth, csrf | Update a risk's fields |
| POST | `/app/risks/{id}/delete` | `RiskController@delete` | auth, csrf | Delete a risk |
| POST | `/app/risks/{id}/mitigation` | `RiskController@generateMitigation` | auth | AJAX: AI generate mitigation strategy for a risk |
| GET | `/app/user-stories` | `UserStoryController@index` | auth | User stories screen |
| POST | `/app/user-stories/generate` | `UserStoryController@generate` | auth, csrf | AI decompose HL work items into user stories |
| POST | `/app/user-stories/store` | `UserStoryController@store` | auth, csrf | Create a user story manually |
| POST | `/app/user-stories/reorder` | `UserStoryController@reorder` | auth | AJAX: update story priority order |
| GET | `/app/user-stories/export` | `UserStoryController@export` | auth | Download CSV, JSON, or Jira-format export |
| POST | `/app/user-stories/{id}` | `UserStoryController@update` | auth, csrf | Update a user story's fields |
| POST | `/app/user-stories/{id}/delete` | `UserStoryController@delete` | auth, csrf | Delete a user story |
| POST | `/app/user-stories/{id}/suggest-size` | `UserStoryController@suggestSize` | auth | AJAX: AI suggest story point size |
| GET | `/app/sprints` | `SprintController@index` | auth | Sprint allocation screen |
| POST | `/app/sprints/store` | `SprintController@store` | auth, csrf | Create a sprint |
| POST | `/app/sprints/assign` | `SprintController@assignStory` | auth | AJAX: assign a user story to a sprint |
| POST | `/app/sprints/unassign` | `SprintController@unassignStory` | auth | AJAX: remove a user story from its sprint |
| POST | `/app/sprints/ai-allocate` | `SprintController@aiAllocate` | auth, csrf | AI auto-allocate unassigned stories across sprints |
| POST | `/app/sprints/{id}` | `SprintController@update` | auth, csrf | Update a sprint's fields |
| POST | `/app/sprints/{id}/delete` | `SprintController@delete` | auth, csrf | Delete a sprint |
| GET | `/app/admin` | `AdminController@index` | auth, admin | Admin dashboard — user count, team count, subscription status |
| GET | `/app/admin/users` | `AdminController@users` | auth, admin | List all users in the organisation |
| POST | `/app/admin/users` | `AdminController@createUser` | auth, admin, csrf | Create a new user (seat limit enforced) |
| POST | `/app/admin/users/{id}` | `AdminController@updateUser` | auth, admin, csrf | Update an existing user's name, email, role, or password |
| POST | `/app/admin/users/{id}/delete` | `AdminController@deleteUser` | auth, admin, csrf | Deactivate a user (soft delete; cannot self-delete) |
| GET | `/app/admin/teams` | `AdminController@teams` | auth, admin | List all teams with member counts and member details |
| POST | `/app/admin/teams` | `AdminController@createTeam` | auth, admin, csrf | Create a new team |
| POST | `/app/admin/teams/{id}` | `AdminController@updateTeam` | auth, admin, csrf | Update a team's name, description, or capacity |
| POST | `/app/admin/teams/{id}/delete` | `AdminController@deleteTeam` | auth, admin, csrf | Delete a team (CASCADE removes memberships) |
| POST | `/app/admin/teams/add-member` | `AdminController@addTeamMember` | auth, admin, csrf | Add a user to a team (both must belong to same org) |
| POST | `/app/admin/teams/remove-member` | `AdminController@removeTeamMember` | auth, admin, csrf | Remove a user from a team |
| GET | `/app/admin/settings` | `AdminController@settings` | auth, admin | Organisation settings page (personas, defaults, tripwires) |
| POST | `/app/admin/settings` | `AdminController@saveSettings` | auth, admin, csrf | Save organisation settings to `organisations.settings_json` |
| POST | `/app/sounding-board/evaluate` | `SoundingBoardController@evaluate` | auth | Run an AI evaluation; expects JSON body: `project_id`, `panel_type`, `evaluation_level`, `screen_context`, `screen_content`. Returns `{id, results}` |
| GET | `/app/sounding-board/results/{id}` | `SoundingBoardController@results` | auth | Load a single evaluation result by ID; returns decoded evaluation data |
| POST | `/app/sounding-board/results/{id}/respond` | `SoundingBoardController@respond` | auth | Accept or reject an individual persona response; expects JSON body: `member_index`, `action` (`accept`\|`reject`) |
| GET | `/app/sounding-board/history` | `SoundingBoardController@history` | auth | Return evaluation history for a project; expects query param `project_id` |
| GET | `/app/governance` | `DriftController@dashboard` | auth | Governance dashboard — active alerts, pending governance items, baseline history for a project (requires `project_id` query param) |
| POST | `/app/governance/baseline` | `DriftController@createBaseline` | auth, csrf | Create a new strategic baseline snapshot for the project; captures current work items and story metrics |
| POST | `/app/governance/detect` | `DriftController@runDetection` | auth, csrf | Run full drift detection against the latest baseline; raises DriftAlerts for capacity tripwires and dependency tripwires |
| POST | `/app/governance/alerts/{id}` | `DriftController@acknowledgeAlert` | auth, csrf | Acknowledge or resolve a drift alert; expects POST param `action` (`acknowledge` or `resolve`) |
| POST | `/app/governance/queue/{id}` | `DriftController@reviewChange` | auth, csrf | Approve or reject a governance queue item; expects POST param `action` (`approve` or `reject`); clears `requires_review` on the related work item when approved |
| GET | `/superadmin` | `SuperadminController@index` | auth, superadmin | Superadmin dashboard — org count, active user count, active subscription count |
| GET | `/superadmin/organisations` | `SuperadminController@organisations` | auth, superadmin | List all organisations with status, user count, and subscription info |
| POST | `/superadmin/organisations/{id}` | `SuperadminController@updateOrg` | auth, superadmin, csrf | Suspend, enable, or delete an organisation; expects POST param `action` |
| GET | `/superadmin/organisations/{id}/export` | `SuperadminController@exportOrg` | auth, superadmin | Download all organisation data as a JSON file |
| GET | `/superadmin/personas` | `SuperadminController@personas` | auth, superadmin | View and manage system-default persona panels; seeds defaults if none exist |
| POST | `/superadmin/personas` | `SuperadminController@savePersona` | auth, superadmin, csrf | Save updated prompt descriptions for default persona members |
| POST | `/superadmin/assign-superadmin` | `SuperadminController@assignSuperadmin` | auth, superadmin, csrf | Promote a user to the superadmin role; expects POST param `user_id` |

---

## Notes

### Stripe Webhook

`POST /webhook/stripe` deliberately has **no CSRF middleware**. Stripe sends a raw signed HTTP POST, not a browser form submission. The controller verifies the request authenticity using `STRIPE_WEBHOOK_SECRET` via Stripe's SDK signature verification instead.

### Static-before-dynamic route ordering

Work item routes are registered with static paths (`/generate`, `/reorder`, `/export`) before the dynamic `{id}` pattern. The router matches routes in registration order, so this prevents `/generate` from being caught as an `{id}` value.

### URL parameters

Placeholders like `{id}` in route patterns are converted to named regex capture groups (`(?P<id>[^/]+)`) by the router. The matched value is available to the controller via the `Request` object.

### Response formats

- Standard pages return HTML views rendered from `templates/`
- AJAX endpoints (`/reorder`, `/generate-description`, `/scores`, `/ai-baseline`, `/mitigation`, `/suggest-size`, `/assign`, `/unassign`) return JSON
- Export endpoints (`/app/work-items/export`, `/app/user-stories/export`) return a file download (`text/csv` or `application/json`)

### CSRF-exempt AJAX endpoints

Several AJAX routes omit the `csrf` middleware. These endpoints receive programmatic requests from JavaScript on the same page (no HTML form involved), so the CSRF token is passed in-band via the request body or header if needed. The `auth` middleware still applies, ensuring only authenticated users can call them. Affected routes: `/prioritisation/scores`, `/prioritisation/ai-baseline`, `/work-items/reorder`, `/work-items/{id}/generate-description`, `/risks/{id}/mitigation`, `/user-stories/reorder`, `/user-stories/{id}/suggest-size`, `/sprints/assign`, `/sprints/unassign`.
