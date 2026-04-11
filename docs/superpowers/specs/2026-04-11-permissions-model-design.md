# StratFlow Permissions Model Design

**Date:** 2026-04-11  
**Status:** Proposed  
**Scope:** Access control architecture, data model, migration path, and enforcement strategy

## 1. Summary

StratFlow's current access model works for major boundaries such as admin, billing, executive, and superadmin pages, but it is not robust enough for long-term maintainability. The system currently mixes:

- base roles
- feature flags
- project visibility rules
- controller-specific role checks

This creates drift between schema, UI, documentation, and enforcement.

This design proposes a clearer permissions model based on:

1. **Base account type** for broad posture
2. **Capabilities** for named permissions
3. **Project-scoped memberships** for per-project access
4. **Central policy checks** instead of scattered role-name checks

The result is a model that is easier to audit, safer to extend, and clearer for both admins and developers.

## 2. Current Problems

### 2.1 Role definitions are not the single source of truth

The current system defines roles in multiple places:

- `database/schema.sql`
- admin templates
- controller validation
- middleware
- docs

These do not fully agree.

Example:

- schema and admin UI include `viewer`, `project_manager`, and `developer`
- admin controller create/update logic only accepts `user`, `org_admin`, and `superadmin`

### 2.2 Important permissions are implemented as ad hoc flags

The real access model already depends on:

- `role`
- `is_project_admin`
- `has_billing_access`
- `has_executive_access`
- project visibility membership

That is reasonable in concept, but it is not expressed as a formal permission model.

### 2.3 Read-only access is not consistently enforced

The UI presents `viewer` as read-only, but many standard workflow routes currently allow authenticated non-developer users without a dedicated write-permission check.

### 2.4 Project access is too coarse

Project visibility is currently:

- `everyone`
- `restricted`

That is a useful start, but it does not support common real-world cases such as:

- a stakeholder who can view one restricted project but not edit it
- a delivery lead who can manage one project but not all projects in the org
- a contributor who can edit project content but not change access or Jira links

### 2.5 Permission checks are scattered

Controllers often check permissions inline using role names or flags. That makes it easy for one route to behave differently from another route that should be equivalent.

### 2.6 Effective access is hard to explain

An admin cannot easily answer:

- Why can this user see this project?
- Why can this user open billing?
- Why can this user not edit this project?

The system needs an explainable, auditable "effective permissions" model.

## 3. Goals

- Make access control explicit, central, and testable
- Support least privilege without increasing admin confusion
- Support org-wide permissions and project-scoped permissions separately
- Preserve simple default role setup for small organisations
- Make future features easy to secure without inventing new one-off flags
- Ensure docs, UI, API, and backend enforcement all use the same model

## 4. Non-Goals

- Replacing session auth or PAT auth
- Introducing ABAC with dynamic policy expressions
- Building a full enterprise IAM product
- Rewriting every controller in one release

## 5. Design Principles

### 5.1 Role is not enough

Roles should provide a default profile, not be the only permission mechanism.

### 5.2 Permissions should be named capabilities

Checks should answer:

- `can_manage_users`
- `can_manage_projects`
- `can_view_billing`
- `can_view_executive_dashboard`
- `can_edit_project_content`

not:

- `role === 'org_admin'`

### 5.3 Project access must be scoped separately

Project-specific access should not require org-wide elevation.

### 5.4 UI labels must follow enforced backend policy

If the UI says "Viewer (read-only)", the backend must enforce read-only behaviour.

### 5.5 Permission decisions must be explainable

The system should be able to show which rule granted access:

- role default
- explicit capability
- project membership
- superadmin override

## 6. Proposed Model

### 6.1 Layer 1: Account Type

Keep a small set of account types for broad posture:

- `viewer`
- `member`
- `manager`
- `org_admin`
- `superadmin`
- `developer`

Notes:

- `member` replaces today's `user` naming at the model level, though UI could keep "User" if preferred
- `manager` replaces today's `project_manager`
- `developer` remains a special UI posture for MCP/API-focused accounts

The account type should define **default capabilities**, not hard-coded route access by itself.

### 6.2 Layer 2: Capabilities

Introduce a named capability catalog.

Recommended first set:

- `project.create`
- `project.view_all`
- `project.manage_access`
- `project.edit_settings`
- `project.delete`
- `workflow.view`
- `workflow.edit`
- `users.manage`
- `teams.manage`
- `settings.manage`
- `integrations.manage`
- `audit_logs.view`
- `billing.view`
- `billing.manage`
- `executive.view`
- `tokens.manage_own`
- `api.use_own_tokens`
- `superadmin.access`

Rules:

- middleware and controller checks should resolve to capability checks
- a role grants a baseline capability set
- explicit user-level grants or revokes can override defaults if needed later

### 6.3 Layer 3: Project Memberships

Replace the simple restricted-project membership concept with project-scoped access levels.

Recommended project membership roles:

- `viewer`
- `editor`
- `project_admin`

Suggested meaning:

- `viewer`: can view project pages and outputs
- `editor`: can update workflow content inside the project
- `project_admin`: can edit project settings, membership, and project integrations

Org admins and superadmins still override project membership checks.

### 6.4 Layer 4: Effective Permission Resolver

Add a central permission resolver service.

Example API:

```php
$permissions->can($user, 'billing.view');
$permissions->can($user, 'project.edit_settings', $projectId);
$permissions->can($user, 'workflow.edit', $projectId);
```

Evaluation order:

1. superadmin override
2. explicit deny, if the system later supports it
3. explicit user capability grants
4. role default capabilities
5. project membership capabilities
6. fallback deny

This becomes the only supported way to make access decisions.

## 7. Recommended Data Model

### 7.1 Keep users account type simple

Option A:

- keep a single `account_type` column on `users`

Recommended values:

- `viewer`
- `member`
- `manager`
- `org_admin`
- `superadmin`
- `developer`

### 7.2 Add capability catalog tables

```sql
capabilities (
  id INT PK,
  key VARCHAR(100) UNIQUE NOT NULL,
  description VARCHAR(255) NOT NULL
)

account_type_capabilities (
  account_type VARCHAR(50) NOT NULL,
  capability_id INT NOT NULL,
  PRIMARY KEY (account_type, capability_id)
)

user_capabilities (
  user_id INT NOT NULL,
  capability_id INT NOT NULL,
  effect ENUM('grant','deny') NOT NULL DEFAULT 'grant',
  PRIMARY KEY (user_id, capability_id)
)
```

If user-level overrides are too much for phase one, defer `user_capabilities` and start with account-type defaults only.

### 7.3 Replace project_members with richer memberships

```sql
project_memberships (
  project_id INT NOT NULL,
  user_id INT NOT NULL,
  membership_role ENUM('viewer','editor','project_admin') NOT NULL DEFAULT 'viewer',
  PRIMARY KEY (project_id, user_id)
)
```

This can replace or evolve the current `project_members` table.

### 7.4 Optional: materialised effective access cache

Do not start here. Only add if permission checks become a performance issue.

## 8. Default Capability Matrix

### Viewer

- `workflow.view`
- `tokens.manage_own`
- `api.use_own_tokens`

### Member

- `workflow.view`
- `workflow.edit`
- `tokens.manage_own`
- `api.use_own_tokens`

### Manager

- `workflow.view`
- `workflow.edit`
- `project.create`
- `project.edit_settings`
- `project.manage_access`
- `tokens.manage_own`
- `api.use_own_tokens`

### Organisation Admin

- all manager capabilities
- `project.view_all`
- `project.delete`
- `users.manage`
- `teams.manage`
- `settings.manage`
- `integrations.manage`
- `audit_logs.view`
- `billing.view`
- `billing.manage`

### Superadmin

- all capabilities
- `superadmin.access`

### Developer

- `tokens.manage_own`
- `api.use_own_tokens`

If the product later wants a full UI-capable developer role, that should be a separate account type instead of overloading the existing one.

## 9. Route and UI Enforcement Changes

### 9.1 Middleware

Replace role-only middleware with capability-based middleware.

Examples:

- `RequireCapabilityMiddleware('users.manage')`
- `RequireCapabilityMiddleware('billing.view')`
- `RequireCapabilityMiddleware('executive.view')`

### 9.2 Controller policy methods

Introduce policy helpers for project-scoped checks.

Examples:

- `ProjectPolicy::canView($user, $project)`
- `ProjectPolicy::canEditWorkflow($user, $project)`
- `ProjectPolicy::canManageProject($user, $project)`

### 9.3 UI rendering

Navigation, buttons, forms, and labels should all use the same permission resolver.

Examples:

- Only show "New Project" if `project.create`
- Only show edit/delete project controls if `project.edit_settings` or `project.delete`
- Only show billing nav if `billing.view`
- Only show executive nav if `executive.view`

### 9.4 API parity

The PAT-authenticated API must use the exact same permission rules as the browser UI.

## 10. Admin Experience Changes

### 10.1 Replace "role plus hidden logic" with an access summary

On the user management screen, show:

- account type
- extra capabilities granted
- extra capabilities denied
- project memberships
- effective access summary

### 10.2 Show why access exists

For each major area:

- Workflow access: granted by account type `member`
- Billing access: granted by explicit capability
- Executive access: granted by explicit capability
- Project admin on Project X: granted by project membership role

### 10.3 Prevent impossible UI states

The admin screen should never present a role or flag that the backend cannot persist.

## 11. Migration Plan

### Phase 1: Stabilise Current Model

- Centralise allowed roles in one config location
- Make admin controller accept the same roles shown in UI and schema
- Fix `User::update()` so flag fields used by admin UI are actually persisted
- Add tests covering user create/update for all supported account types and flags

### Phase 2: Introduce Capability Resolver

- Add capability catalog
- Map existing role/flag logic into capability checks
- Update middleware to use capabilities
- Keep old columns temporarily for compatibility

### Phase 3: Upgrade Project Memberships

- Replace current restricted membership model with role-based project memberships
- Add project-level policy helpers
- Update project lists, edit forms, and APIs

### Phase 4: Remove Legacy Permission Drift

- Remove hard-coded role comparisons where capability or policy checks exist
- Retire one-off flags that are now expressible as capabilities
- Update docs to reflect only the enforced model

## 12. Backward Compatibility Mapping

Initial migration mapping:

- current `user` -> new `member`
- current `project_manager` -> new `manager`
- current `org_admin` -> `org_admin`
- current `superadmin` -> `superadmin`
- current `viewer` -> `viewer`
- current `developer` -> `developer`

Flags:

- `is_project_admin` -> grant `project.create`, `project.edit_settings`, `project.manage_access`, `project.view_all`
- `has_billing_access` -> grant `billing.view` and optionally `billing.manage`
- `has_executive_access` -> grant `executive.view`

## 13. Risks and Tradeoffs

### Benefit

- Safer long-term growth
- Easier audit and compliance posture
- Fewer controller-specific surprises
- Better support for enterprise customers

### Cost

- More tables and policy logic
- More migration work
- More up-front design discipline required

### Main tradeoff

The system becomes slightly more complex internally, but much clearer externally.

That is the right tradeoff for a multi-tenant product that already has admin, billing, executive, API, and project-scoped access concerns.

## 14. Recommendation

Implement this in two practical steps:

1. **Immediate cleanup**
   Fix schema/UI/controller drift and enforce current roles and flags consistently.

2. **Capability-based redesign**
   Move to account type + capabilities + project memberships as the long-term model.

This avoids a risky big-bang rewrite while still giving StratFlow a durable permission architecture.

## 15. Implementation Checklist

- [ ] Centralise supported account types in one source of truth
- [ ] Fix admin create/update validation to match supported account types
- [ ] Fix `User::update()` to persist role-adjacent access fields actually used by the UI
- [ ] Add capability resolver service
- [ ] Convert middleware to capability checks
- [ ] Add project policy layer
- [ ] Replace `project_members` with role-based `project_memberships`
- [ ] Add tests for effective permission resolution
- [ ] Add an admin-facing effective access summary UI
- [ ] Update user guide and API docs after implementation
