# API / Route Reference

All routes are defined in `src/Config/routes.php`. The application uses a custom vanilla PHP router with support for URL parameter placeholders (`{id}`) and per-route middleware stacks.

## Middleware

| Key | Class | Description |
|-----|-------|-------------|
| `auth` | `AuthMiddleware` | Requires an active session; redirects to `/login` if unauthenticated |
| `csrf` | `CSRFMiddleware` | Validates the `_csrf_token` field in POST requests; aborts with 403 on mismatch |
| `admin` | `AdminMiddleware` | Requires `org_admin` or `superadmin` role; redirects to `/app/home` if unauthorised |
| `superadmin` | `SuperadminMiddleware` | Requires the `superadmin` role; redirects to `/app/home` if unauthorised |
| `workflow_write` | `WorkflowWriteMiddleware` | Requires the user has write access to the project (not viewer-only); redirects to `/app/home` if unauthorised |
| `billing` | `BillingMiddleware` | Requires `has_billing_access` flag or `org_admin`/`superadmin` role |
| `executive` | `ExecutiveMiddleware` | Requires `has_executive_access` flag or `superadmin` role |
| `project_create` | `ProjectCreateMiddleware` | Requires `is_project_admin`, `org_admin`, or `superadmin` role |
| `project_manage` | `ProjectManageMiddleware` | Requires `is_project_admin`, `org_admin`, or `superadmin` role, AND project membership |
| `api_auth` | `ApiAuthMiddleware` | Validates `Authorization: Bearer <token>` against `personal_access_tokens` table |

Middleware is run in the order listed in the route definition.

---

## Route Table

### Public / Auth

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/healthz` | `HealthController@index` | — | Health check endpoint; returns HTTP 200 |
| GET | `/` | `PricingController@index` | — | Landing / pricing page |
| GET | `/pricing` | `PricingController@index` | — | Pricing page (alias) |
| POST | `/checkout` | `CheckoutController@create` | csrf | Create Stripe Checkout session |
| POST | `/webhook/stripe` | `WebhookController@handle` | — | Stripe signed webhook receiver |
| GET | `/success` | `SuccessController@index` | — | Post-payment success page |
| GET | `/login` | `AuthController@showLogin` | — | Login page |
| POST | `/login` | `AuthController@login` | csrf | Login form submit |
| GET | `/login/mfa` | `AuthController@showMfaChallenge` | — | TOTP MFA challenge page |
| POST | `/login/mfa` | `AuthController@verifyMfaChallenge` | csrf | Verify TOTP code |
| GET | `/forgot-password` | `AuthController@showForgotPassword` | — | Forgot password page |
| POST | `/forgot-password` | `AuthController@sendResetEmail` | csrf | Send password reset email |
| GET | `/set-password/{token}` | `AuthController@showSetPassword` | — | Set new password via reset token |
| POST | `/set-password/{token}` | `AuthController@setPassword` | csrf | Submit new password |
| POST | `/logout` | `AuthController@logout` | csrf, auth | Logout and destroy session |

### Dashboard & Projects

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/app/home` | `HomeController@index` | auth | Dashboard — project list |
| POST | `/app/projects` | `HomeController@createProject` | auth, project_create, csrf | Create a new project |
| POST | `/app/projects/{id}/edit` | `HomeController@editProject` | auth, project_manage, csrf | Edit project name and status |
| POST | `/app/projects/{id}/rename` | `HomeController@renameProject` | auth, project_manage, csrf | Rename a project |
| POST | `/app/projects/{id}/delete` | `HomeController@deleteProject` | auth, project_manage, csrf | Delete a project |
| POST | `/app/projects/{id}/jira-link` | `HomeController@linkJira` | auth, project_manage, csrf | Link project to a Jira project key |
| GET | `/app/projects/{id}/github/edit` | `ProjectGitHubController@edit` | auth | View project GitHub repository settings |
| POST | `/app/projects/{id}/github/save` | `ProjectGitHubController@save` | auth, project_manage, csrf | Save project GitHub repository link |
| GET | `/app/projects/{id}/executive` | `ExecutiveController@projectDashboard` | auth, executive | Per-project executive summary |

### Upload & Diagram

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/app/upload` | `UploadController@index` | auth | Document upload page |
| POST | `/app/upload` | `UploadController@store` | auth, workflow_write, csrf | Upload and process a document file |
| POST | `/app/upload/summarise` | `UploadController@generateSummary` | auth, workflow_write, csrf | Generate AI summary for a document |
| GET | `/app/diagram` | `DiagramController@index` | auth | Strategy diagram page |
| POST | `/app/diagram/generate` | `DiagramController@generate` | auth, workflow_write, csrf | Generate Mermaid diagram via AI |
| POST | `/app/diagram/save` | `DiagramController@save` | auth, workflow_write, csrf | Persist the current diagram code |
| POST | `/app/diagram/save-okr` | `DiagramController@saveOkr` | auth, workflow_write, csrf | Save OKR data for a single diagram node |
| POST | `/app/diagram/save-all-okrs` | `DiagramController@saveAllOkrs` | auth, workflow_write, csrf | Save OKRs for all nodes at once |
| POST | `/app/diagram/generate-okrs` | `DiagramController@generateOkrs` | auth, workflow_write, csrf | AI-generate OKRs for all diagram nodes |
| POST | `/app/diagram/add-okr` | `DiagramController@addOkr` | auth, workflow_write, csrf | Add an OKR to a specific node |
| POST | `/app/diagram/delete-okr` | `DiagramController@deleteOkr` | auth, workflow_write, csrf | Delete an OKR from a node |

### Key Results

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| POST | `/app/key-results` | `KrController@store` | auth, workflow_write, csrf | Create a key result linked to a work item |
| POST | `/app/key-results/{id}` | `KrController@update` | auth, workflow_write, csrf | Update a key result |
| POST | `/app/key-results/{id}/delete` | `KrController@delete` | auth, workflow_write, csrf | Delete a key result |

### Work Items

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/app/work-items` | `WorkItemController@index` | auth | Work items list page |
| POST | `/app/work-items/generate` | `WorkItemController@generate` | auth, workflow_write, csrf | AI-generate work items from diagram |
| POST | `/app/work-items/store` | `WorkItemController@store` | auth, workflow_write, csrf | Create a work item manually |
| POST | `/app/work-items/reorder` | `WorkItemController@reorder` | auth, workflow_write, csrf | AJAX: update priority order |
| POST | `/app/work-items/regenerate-sizing` | `WorkItemController@regenerateSizing` | auth, workflow_write, csrf | Bulk re-run AI sprint sizing estimates |
| POST | `/app/work-items/refine-all` | `WorkItemController@refineAll` | auth, workflow_write, csrf | AI-improve all low-scoring work items |
| GET | `/app/work-items/export` | `WorkItemController@export` | auth | Download CSV or JSON export |
| POST | `/app/work-items/{id}` | `WorkItemController@update` | auth, workflow_write, csrf | Update a work item's fields |
| POST | `/app/work-items/{id}/delete` | `WorkItemController@delete` | auth, workflow_write, csrf | Delete a work item |
| POST | `/app/work-items/{id}/close` | `WorkItemController@close` | auth, workflow_write, csrf | Mark a work item as closed |
| POST | `/app/work-items/{id}/generate-description` | `WorkItemController@generateDescription` | auth, workflow_write, csrf | AI-generate a detailed scope description |
| POST | `/app/work-items/{id}/improve` | `WorkItemController@improve` | auth, workflow_write, csrf | AI-improve all low-scoring fields for this item |
| POST | `/app/work-items/{id}/refine-quality` | `WorkItemController@refineQuality` | auth, workflow_write, csrf | AI-rewrite specific quality dimensions |
| POST | `/app/work-items/{id}/score` | `WorkItemController@score` | auth, workflow_write, csrf | AJAX: run quality scoring for this item |

### Prioritisation

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/app/prioritisation` | `PrioritisationController@index` | auth | Prioritisation screen |
| POST | `/app/prioritisation/framework` | `PrioritisationController@selectFramework` | auth, workflow_write, csrf | Select RICE or WSJF framework for the project |
| POST | `/app/prioritisation/scores` | `PrioritisationController@saveScores` | auth, workflow_write, csrf | AJAX: save individual item scores |
| POST | `/app/prioritisation/rerank` | `PrioritisationController@rerank` | auth, workflow_write, csrf | Re-rank all items by computed final score |
| POST | `/app/prioritisation/ai-baseline` | `PrioritisationController@aiBaseline` | auth, workflow_write, csrf | AI suggest baseline scores for all items |

### Risks

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/app/risks` | `RiskController@index` | auth | Risk management screen |
| POST | `/app/risks/generate` | `RiskController@generate` | auth, workflow_write, csrf | AI generate risks from work items |
| POST | `/app/risks` | `RiskController@store` | auth, workflow_write, csrf | Create a risk manually |
| POST | `/app/risks/{id}` | `RiskController@update` | auth, workflow_write, csrf | Update a risk's fields |
| POST | `/app/risks/{id}/delete` | `RiskController@delete` | auth, workflow_write, csrf | Delete a risk |
| POST | `/app/risks/{id}/close` | `RiskController@close` | auth, workflow_write, csrf | Mark a risk as closed |
| POST | `/app/risks/{id}/roam` | `RiskController@setRoam` | auth, workflow_write, csrf | Set ROAM status (Resolved/Owned/Accepted/Mitigated) |
| POST | `/app/risks/{id}/mitigation` | `RiskController@generateMitigation` | auth, workflow_write, csrf | AJAX: AI generate mitigation strategy |

### User Stories

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/app/user-stories` | `UserStoryController@index` | auth | User stories screen |
| POST | `/app/user-stories/generate` | `UserStoryController@generate` | auth, workflow_write, csrf | AI decompose High Level work items into stories |
| POST | `/app/user-stories/store` | `UserStoryController@store` | auth, workflow_write, csrf | Create a user story manually |
| POST | `/app/user-stories/reorder` | `UserStoryController@reorder` | auth, workflow_write, csrf | AJAX: update story priority order |
| POST | `/app/user-stories/regenerate-sizing` | `UserStoryController@regenerateSizing` | auth, workflow_write, csrf | Bulk re-run AI story point sizing |
| POST | `/app/user-stories/refine-all` | `UserStoryController@refineAll` | auth, workflow_write, csrf | AI-improve all low-scoring stories |
| POST | `/app/user-stories/delete-all` | `UserStoryController@deleteAll` | auth, workflow_write, csrf | Delete all stories for a project |
| GET | `/app/user-stories/export` | `UserStoryController@export` | auth | Download CSV, JSON, or Jira-format export |
| POST | `/app/user-stories/{id}` | `UserStoryController@update` | auth, workflow_write, csrf | Update a user story's fields |
| POST | `/app/user-stories/{id}/delete` | `UserStoryController@delete` | auth, workflow_write, csrf | Delete a user story |
| POST | `/app/user-stories/{id}/close` | `UserStoryController@close` | auth, workflow_write, csrf | Mark a story as closed |
| POST | `/app/user-stories/{id}/suggest-size` | `UserStoryController@suggestSize` | auth, workflow_write, csrf | AJAX: AI suggest story point size |
| POST | `/app/user-stories/{id}/improve` | `UserStoryController@improve` | auth, workflow_write, csrf | AI-improve all low-scoring fields |
| POST | `/app/user-stories/{id}/refine-quality` | `UserStoryController@refineQuality` | auth, workflow_write, csrf | AI-rewrite specific quality dimensions |
| POST | `/app/user-stories/{id}/score` | `UserStoryController@score` | auth, workflow_write, csrf | AJAX: run quality scoring for this story |

### Sprints

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/app/sprints` | `SprintController@index` | auth | Sprint allocation screen |
| GET | `/app/sprints/jira-defaults` | `SprintController@jiraDefaults` | auth | AJAX: fetch Jira sprint defaults for the project |
| POST | `/app/sprints/store` | `SprintController@store` | auth, workflow_write, csrf | Create a sprint |
| POST | `/app/sprints/assign` | `SprintController@assignStory` | auth, workflow_write, csrf | AJAX: assign a user story to a sprint |
| POST | `/app/sprints/unassign` | `SprintController@unassignStory` | auth, workflow_write, csrf | AJAX: remove a user story from its sprint |
| POST | `/app/sprints/ai-allocate` | `SprintController@aiAllocate` | auth, workflow_write, csrf | AI auto-allocate unassigned stories across sprints |
| POST | `/app/sprints/auto-generate` | `SprintController@autoGenerate` | auth, workflow_write, csrf | Auto-generate sprints based on project timeline |
| POST | `/app/sprints/auto-fill` | `SprintController@autoFill` | auth, workflow_write, csrf | Auto-fill sprints with unassigned stories |
| POST | `/app/sprints/{id}` | `SprintController@update` | auth, workflow_write, csrf | Update a sprint's fields |
| POST | `/app/sprints/{id}/delete` | `SprintController@delete` | auth, workflow_write, csrf | Delete a sprint |

### Sounding Board (AI Evaluation)

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| POST | `/app/sounding-board/evaluate` | `SoundingBoardController@evaluate` | auth, workflow_write, csrf | Run an AI panel evaluation; expects JSON body: `project_id`, `panel_type`, `evaluation_level`, `screen_context`, `screen_content`; returns `{id, results}` |
| GET | `/app/sounding-board/results/{id}` | `SoundingBoardController@results` | auth | Load a single evaluation result by ID |
| POST | `/app/sounding-board/results/{id}/respond` | `SoundingBoardController@respond` | auth, workflow_write, csrf | Accept or reject an individual persona response; expects JSON body: `member_index`, `action` (`accept`\|`reject`) |
| GET | `/app/sounding-board/history` | `SoundingBoardController@history` | auth | Return evaluation history for a project; expects query param `project_id` |

### Board Review

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| POST | `/app/board-review/evaluate` | `BoardReviewController@evaluate` | auth, workflow_write, csrf | Run a virtual boardroom review; expects JSON body: `project_id`, `evaluation_level`, `screen_context`, `screen_content`; returns `{id, conversation, recommendation}` |
| GET | `/app/board-review/results/{id}` | `BoardReviewController@results` | auth | Fetch a stored board review by ID; returns decoded conversation, recommendation, proposed_changes |
| POST | `/app/board-review/{id}/accept` | `BoardReviewController@accept` | auth, workflow_write, csrf | Accept a board review — apply `proposed_changes` transactionally to the underlying data (documents, diagrams, work items, or stories) |
| POST | `/app/board-review/{id}/reject` | `BoardReviewController@reject` | auth, workflow_write, csrf | Reject a board review — record the outcome without applying any changes |
| GET | `/app/board-review/history` | `BoardReviewController@history` | auth | Return board review history for a project; expects query param `project_id`; viewable by all project members |

### Governance & Drift Detection

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/app/governance` | `DriftController@dashboard` | auth | Governance dashboard — alerts, queue, baseline history (requires `project_id` query param) |
| POST | `/app/governance/baseline` | `DriftController@createBaseline` | auth, workflow_write, csrf | Create a strategic baseline snapshot |
| POST | `/app/governance/detect` | `DriftController@runDetection` | auth, workflow_write, csrf | Run full drift detection against the latest baseline |
| POST | `/app/governance/alerts/{id}` | `DriftController@acknowledgeAlert` | auth, workflow_write, csrf | Acknowledge or resolve a drift alert; expects `action` (`acknowledge`\|`resolve`) |
| POST | `/app/governance/queue/{id}` | `DriftController@reviewChange` | auth, workflow_write, csrf | Approve or reject a governance queue item; expects `action` (`approve`\|`reject`) |

### Traceability

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/app/traceability` | `TraceabilityController@index` | auth | Traceability matrix — story-to-OKR linkage view |

### Executive Dashboard

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/app/executive` | `ExecutiveController@dashboard` | auth, executive | Org-wide executive rollup — portfolio status, backlog health, sprint velocity, risk register |
| GET | `/app/projects/{id}/executive` | `ExecutiveController@projectDashboard` | auth, executive | Per-project executive summary |

### Git Integration

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/app/git-links` | `GitLinkController@index` | auth | Git link management page |
| POST | `/app/git-links` | `GitLinkController@create` | auth, workflow_write, csrf | Link a story to a git commit/branch/PR |
| POST | `/app/git-links/{id}/delete` | `GitLinkController@delete` | auth, workflow_write, csrf | Remove a git link |
| POST | `/webhook/git/github` | `GitWebhookController@receiveGitHub` | — | Receive GitHub push/PR webhook events |
| POST | `/webhook/git/gitlab` | `GitWebhookController@receiveGitLab` | — | Receive GitLab push/MR webhook events |

### Jira Integration (Admin)

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| POST | `/app/jira/sync` | `IntegrationController@contextualSync` | auth, workflow_write, csrf | Sync current project page with Jira |
| POST | `/app/jira/sync/preview` | `IntegrationController@syncPreview` | auth, workflow_write, csrf | Preview Jira sync changes without committing |
| GET | `/app/admin/integrations` | `IntegrationController@index` | auth, admin | Integrations management page |
| GET | `/app/admin/integrations/jira/connect` | `IntegrationController@jiraConnect` | auth, admin | Redirect to Jira OAuth connect |
| GET | `/app/admin/integrations/jira/callback` | `IntegrationController@jiraCallback` | auth, admin | Jira OAuth callback handler |
| POST | `/app/admin/integrations/jira/disconnect` | `IntegrationController@jiraDisconnect` | auth, admin, csrf | Disconnect Jira integration |
| GET | `/app/admin/integrations/jira/configure` | `IntegrationController@jiraConfigure` | auth, admin | Jira configuration page |
| POST | `/app/admin/integrations/jira/configure` | `IntegrationController@jiraSaveConfigure` | auth, admin, csrf | Save Jira configuration |
| POST | `/app/admin/integrations/jira/push` | `IntegrationController@jiraPush` | auth, admin, csrf | Push work items/stories to Jira |
| POST | `/app/admin/integrations/jira/pull` | `IntegrationController@jiraPull` | auth, admin, csrf | Pull status updates from Jira |
| GET | `/app/admin/integrations/jira/users` | `IntegrationController@jiraSearchUsers` | auth, admin | AJAX: search Jira users for assignment |
| POST | `/app/admin/integrations/jira/import-teams` | `IntegrationController@jiraImportTeams` | auth, admin, csrf | Import teams from Jira project |
| POST | `/app/admin/integrations/jira/bulk-pull-status` | `IntegrationController@jiraBulkPullStatus` | auth, admin, csrf | Bulk pull status updates for all mapped items |
| GET | `/app/admin/integrations/sync-log` | `IntegrationController@syncLog` | auth, admin | View Jira sync log |
| GET | `/app/admin/integrations/sync-log/export` | `IntegrationController@syncLogExport` | auth, admin | Download sync log as CSV |
| POST | `/webhook/integration/jira` | `IntegrationController@jiraWebhook` | — | Receive Jira issue-updated webhook events |

### GitHub App Integration (Admin)

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/app/admin/integrations/github/install` | `GitHubAppController@install` | auth, admin | Redirect to GitHub App install flow |
| GET | `/app/admin/integrations/github/callback` | `GitHubAppController@callback` | auth, admin | GitHub App install callback |
| POST | `/app/admin/integrations/github/{id}/disconnect` | `GitHubAppController@disconnect` | auth, admin, csrf | Disconnect a GitHub App installation |

### Git Integration (Admin — Generic Provider)

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| POST | `/app/admin/integrations/git/{provider}/connect` | `GitIntegrationController@connect` | auth, admin, csrf | Connect a git provider (github/gitlab) |
| POST | `/app/admin/integrations/git/{provider}/disconnect` | `GitIntegrationController@disconnect` | auth, admin, csrf | Disconnect a git provider |
| POST | `/app/admin/integrations/git/{provider}/regenerate-secret` | `GitIntegrationController@regenerateSecret` | auth, admin, csrf | Regenerate webhook secret |
| POST | `/app/admin/integrations/git/{provider}/reveal-secret` | `GitIntegrationController@revealSecret` | auth, admin, csrf | Reveal current webhook secret |

### Story Quality Rules (Admin)

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/app/admin/story-quality-rules` | `StoryQualityController@index` | auth, admin | Manage org-specific quality scoring rules |
| POST | `/app/admin/story-quality-rules` | `StoryQualityController@store` | auth, admin, csrf | Create or update a quality rule |
| POST | `/app/admin/story-quality-rules/{id}/delete` | `StoryQualityController@delete` | auth, admin, csrf | Delete a quality rule |

### Admin — Users & Teams

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/app/admin` | `AdminController@index` | auth, admin | Admin dashboard — user count, team count, subscription status |
| GET | `/app/admin/users` | `AdminController@users` | auth, admin | List all users in the organisation |
| POST | `/app/admin/users` | `AdminController@createUser` | auth, admin, csrf | Create a new user (seat limit enforced) |
| POST | `/app/admin/users/{id}` | `AdminController@updateUser` | auth, admin, csrf | Update an existing user's name, email, role, or password |
| POST | `/app/admin/users/{id}/delete` | `AdminController@deleteUser` | auth, admin, csrf | Deactivate a user (soft delete; cannot self-delete) |
| POST | `/app/admin/users/{id}/reactivate` | `AdminController@reactivateUser` | auth, admin, csrf | Reactivate a previously deactivated user |
| GET | `/app/admin/teams` | `AdminController@teams` | auth, admin | List all teams with member counts and member details |
| POST | `/app/admin/teams` | `AdminController@createTeam` | auth, admin, csrf | Create a new team |
| POST | `/app/admin/teams/{id}` | `AdminController@updateTeam` | auth, admin, csrf | Update a team's name, description, or capacity |
| POST | `/app/admin/teams/{id}/delete` | `AdminController@deleteTeam` | auth, admin, csrf | Delete a team |
| POST | `/app/admin/teams/add-member` | `AdminController@addTeamMember` | auth, admin, csrf | Add a user to a team |
| POST | `/app/admin/teams/remove-member` | `AdminController@removeTeamMember` | auth, admin, csrf | Remove a user from a team |

### Admin — Settings & AI

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/app/admin/settings` | `AdminController@settings` | auth, admin | Organisation settings page |
| POST | `/app/admin/settings` | `AdminController@saveSettings` | auth, admin, csrf | Save organisation settings |
| POST | `/app/admin/test-ai` | `AdminController@testAi` | auth, admin, csrf | Test AI connection with a sample prompt |

### Admin — Billing

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/app/admin/billing` | `AdminController@billing` | auth, billing | Billing and subscription overview |
| GET | `/app/admin/billing/portal` | `AdminController@billingPortal` | auth, billing | Redirect to Stripe billing portal |
| POST | `/app/admin/billing/portal` | `AdminController@billingPortal` | auth, billing, csrf | POST-triggered redirect to Stripe portal |
| POST | `/app/admin/billing/contact` | `AdminController@saveBillingContact` | auth, billing, csrf | Save billing contact details |
| POST | `/app/admin/billing/seats/invoice` | `AdminController@purchaseSeatsInvoice` | auth, billing, csrf | Purchase additional seats via invoice |
| POST | `/app/admin/billing/seats/stripe` | `AdminController@purchaseSeatsStripe` | auth, billing, csrf | Purchase additional seats via Stripe |

### Admin — Xero & Invoices

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/app/admin/xero/connect` | `XeroController@connect` | auth, billing | Redirect to Xero OAuth connect |
| GET | `/app/admin/xero/callback` | `XeroController@callback` | auth, billing | Xero OAuth callback handler |
| POST | `/app/admin/xero/disconnect` | `XeroController@disconnect` | auth, billing, csrf | Disconnect Xero integration |
| GET | `/app/admin/invoices` | `XeroController@invoices` | auth, billing | List invoices (Xero-linked or manual) |
| POST | `/app/admin/invoices/create` | `XeroController@createInvoice` | auth, billing, csrf | Create a new invoice |
| POST | `/app/admin/invoices/sync` | `XeroController@syncInvoices` | auth, billing, csrf | Sync invoice status from Xero |
| POST | `/app/admin/invoices/{id}/push-to-xero` | `XeroController@pushToXero` | auth, billing, csrf | Push an invoice to Xero |

### Admin — Audit Logs

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/app/admin/audit-logs` | `AdminController@auditLogs` | auth, admin | View organisation audit log |
| GET | `/app/admin/audit-logs/export` | `AdminController@exportAuditLogs` | auth, admin | Export audit log as CSV |

### Account

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/app/account/tokens` | `AccessTokenController@index` | auth | Personal access tokens management page |
| POST | `/app/account/tokens` | `AccessTokenController@create` | auth, csrf | Create a new personal access token |
| POST | `/app/account/tokens/{id}/revoke` | `AccessTokenController@revoke` | auth, csrf | Revoke a personal access token |
| POST | `/app/account/team` | `AccessTokenController@saveTeam` | auth, csrf | Save the user's team assignment |
| POST | `/app/account/jira-identity` | `AccessTokenController@saveJiraIdentity` | auth, csrf | Link Jira account ID to this user |
| GET | `/app/account/jira/users` | `AccessTokenController@jiraUsers` | auth | AJAX: search Jira users for self-linking |
| GET | `/app/account/mfa` | `AuthController@showMfaSetup` | auth | TOTP MFA setup page |
| GET | `/app/account/mfa/recovery-codes` | `AuthController@showRecoveryCodes` | auth | View MFA recovery codes |
| POST | `/app/account/mfa/enable` | `AuthController@enableMfa` | auth, csrf | Enable TOTP MFA with a verified code |
| POST | `/app/account/mfa/disable` | `AuthController@disableMfa` | auth, csrf | Disable TOTP MFA |
| GET | `/app/account/export-data` | `UserDataExportController@index` | auth | GDPR data export page |
| POST | `/app/account/export-data` | `UserDataExportController@export` | auth, csrf | Download all personal data as JSON |

### Superadmin

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/superadmin` | `SuperadminController@index` | auth, superadmin | Superadmin dashboard — org count, user count, subscription count |
| GET | `/superadmin/organisations` | `SuperadminController@organisations` | auth, superadmin | List all organisations with status, user count, and subscription info |
| POST | `/superadmin/organisations/create` | `SuperadminController@createOrg` | auth, superadmin, csrf | Create a new organisation manually |
| POST | `/superadmin/organisations/{id}` | `SuperadminController@updateOrg` | auth, superadmin, csrf | Suspend, enable, or soft-delete an organisation |
| GET | `/superadmin/organisations/{id}/export` | `SuperadminController@exportOrg` | auth, superadmin | Download all organisation data as JSON |
| POST | `/superadmin/organisations/{id}/jira` | `SuperadminController@toggleJira` | auth, superadmin, csrf | Enable or disable Jira integration for an org |
| POST | `/superadmin/organisations/{id}/evaluation-board` | `SuperadminController@toggleEvaluationBoard` | auth, superadmin, csrf | Enable or disable Evaluation Board (Sounding Board + Virtual Board Review) for an org |
| GET | `/superadmin/defaults` | `SuperadminController@defaults` | auth, superadmin | View and edit system-wide default settings |
| POST | `/superadmin/defaults` | `SuperadminController@saveDefaults` | auth, superadmin, csrf | Save system-wide default settings |
| POST | `/superadmin/defaults/test-ai` | `SuperadminController@testAiConnection` | auth, superadmin, csrf | Test AI connection from superadmin panel |
| GET | `/superadmin/personas` | `SuperadminController@personas` | auth, superadmin | View and manage system-default persona panels |
| POST | `/superadmin/personas` | `SuperadminController@savePersona` | auth, superadmin, csrf | Save updated prompt descriptions for default persona members |
| POST | `/superadmin/personas/evaluate` | `SuperadminController@evaluatePersona` | auth, superadmin, csrf | Test-evaluate a persona prompt |
| GET | `/superadmin/audit-logs` | `SuperadminController@auditLogs` | auth, superadmin | Cross-org audit log view |
| GET | `/superadmin/audit-logs/export` | `SuperadminController@exportAuditLogs` | auth, superadmin | Export cross-org audit log as CSV |
| GET | `/superadmin/users` | `SuperadminController@users` | auth, superadmin | List all users across all organisations |
| GET | `/superadmin/subscriptions` | `SuperadminController@subscriptions` | auth, superadmin | List all subscriptions across all organisations |
| POST | `/superadmin/assign-superadmin` | `SuperadminController@assignSuperadmin` | auth, superadmin, csrf | Promote a user to the superadmin role |

### REST API (v1)

These endpoints use bearer token authentication (`Authorization: Bearer <token>`) via `personal_access_tokens`. They are intended for use by the StratFlow MCP server and external tooling.

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/api/v1/me` | `ApiStoriesController@me` | api_auth | Return the authenticated user's profile and team |
| POST | `/api/v1/me/team` | `ApiStoriesController@setMyTeam` | api_auth | Set the authenticated user's team |
| GET | `/api/v1/stories` | `ApiStoriesController@index` | api_auth | List stories for the user's org; supports `project_id`, `mine=1`, `status` filters |
| GET | `/api/v1/stories/team` | `ApiStoriesController@teamStories` | api_auth | List stories assigned to the user's team |
| GET | `/api/v1/stories/{id}` | `ApiStoriesController@show` | api_auth | Get a single user story |
| POST | `/api/v1/stories/{id}/status` | `ApiStoriesController@updateStatus` | api_auth | Update story status |
| POST | `/api/v1/stories/{id}/assign` | `ApiStoriesController@assign` | api_auth | Assign a story to a user |
| GET | `/api/v1/projects` | `ApiProjectsController@index` | api_auth | List projects accessible to the authenticated user |

---

## Notes

### Stripe Webhook

`POST /webhook/stripe` deliberately has **no CSRF middleware**. Stripe sends a raw signed HTTP POST, not a browser form submission. The controller verifies authenticity using `STRIPE_WEBHOOK_SECRET` via Stripe's SDK signature verification.

### Git Webhooks

`POST /webhook/git/github` and `/webhook/git/gitlab` have **no CSRF middleware**. Requests are authenticated via an HMAC signature in the `X-Hub-Signature-256` (GitHub) or `X-Gitlab-Token` (GitLab) header, verified against the org's stored webhook secret.

### Jira Webhook

`POST /webhook/integration/jira` has **no CSRF middleware**. Authenticated by validating a shared secret in the Jira webhook URL query parameter.

### Static-before-dynamic route ordering

Static path segments (e.g. `/generate`, `/reorder`, `/export`) are registered before dynamic `{id}` patterns to prevent them being matched as ID values.

### Response formats

- Standard pages return HTML rendered from `templates/`
- AJAX endpoints return JSON
- Export endpoints return file downloads (`text/csv` or `application/json`)

### REST API authentication

API v1 routes use `ApiAuthMiddleware` which validates a `Bearer` token from the `Authorization` header against the `personal_access_tokens` table. Tokens are stored as SHA-256 hashes — the plaintext is shown once at creation and never stored.
