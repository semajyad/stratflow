# StratFlow User Roles Guide

> **Last Updated:** 2026-04-11
> **Audience:** End users, organisation admins, and implementation teams

## Overview

StratFlow access is controlled by:

1. A base role on the user account
2. Optional access flags
3. Project visibility rules on individual projects

Most people only need this shortcut:

- `User` for normal day-to-day delivery work
- `Project Manager` or `Project admin` for people managing projects
- `Organisation Admin` for customer-side administration
- `Executive dashboard` flag for portfolio reporting
- `Billing access` flag for finance and subscription tasks
- `Developer` for MCP/API-first access
- `Superadmin` for StratFlow platform staff

## Quick Reference

| Role / Access | Best for | What they can do |
|---|---|---|
| `Viewer` | Stakeholders who mainly need visibility | Open accessible projects, follow workflow pages, view traceability, access their token page |
| `User` | Team members doing normal delivery work | Use the core strategy-to-delivery workflow pages |
| `Project Manager` | Delivery leads | Same standard workflow access as `User`; usually paired with `Project admin` |
| `Organisation Admin` | Customer admins | Full workflow access plus users, teams, settings, integrations, audit logs, and usually billing |
| `Superadmin` | StratFlow internal admins | Full cross-organisation platform access |
| `Developer` | API/MCP users | Restricted to account/token pages and PAT-based API use |
| `Project admin` flag | Project owners | Create and manage projects across the org |
| `Billing access` flag | Finance or ops contacts | Billing, invoices, seat management, and Xero/Stripe billing flows |
| `Executive dashboard` flag | Executives and sponsors | Organisation-wide executive dashboards |

## Base Roles

### Viewer

Best for people who mainly need visibility into progress.

What viewers can do:

- Open projects they are allowed to see
- Move through the main workflow pages
- View traceability and progress information
- Access their own developer token page

Important note:

- The UI labels viewers as read-only, but current route enforcement is broader than that. Admin, billing, executive, and superadmin areas are still protected, but standard workflow pages are not fully locked down for viewers yet.

### User

This is the default role for most delivery team members.

What users can do:

- Upload strategy documents
- Build and save roadmaps
- Generate and manage work items
- Prioritise work
- Manage risks
- Create user stories
- Plan sprints
- Review governance and traceability
- Create and revoke their own personal access tokens

Users cannot:

- Open admin-only areas
- Open billing unless separately flagged
- Open the executive dashboard unless separately flagged
- Open superadmin areas

### Project Manager

Use this for delivery leads who coordinate project execution.

In the current codebase, `project_manager` behaves more like a role label than a separately enforced permission tier.

What project managers can do today:

- Use the same standard workflow pages as a normal user

What project managers usually also need:

- `Project admin` if they should create projects, edit project access, rename projects, or manage Jira links

### Organisation Admin

Use this for the main customer administrator inside an organisation.

What organisation admins can do:

- Everything a normal user can do
- Manage users
- Manage teams
- Manage organisation settings
- Configure integrations
- View organisation audit logs
- Delete projects
- See all projects in their organisation, including restricted ones

Billing behaviour:

- Organisation admins usually get billing access automatically
- If another user in the organisation has explicit billing access, that person can become the dedicated billing owner instead

### Superadmin

This is for StratFlow platform staff only.

What superadmins can do:

- Everything an organisation admin can do
- Access the superadmin dashboard
- Manage all organisations across the platform
- Export organisation data
- Manage system defaults and default personas
- View platform-level audit logs
- Promote a user to superadmin
- Access billing and executive functions automatically

### Developer

Use this when someone primarily works through the API/MCP integration rather than the normal web workflow.

What developer accounts can do:

- Sign in
- Access `/app/account/tokens`
- Create and revoke personal access tokens
- Store their team selection for API tooling
- Use PAT-authenticated API endpoints through external tools

What developer accounts cannot do:

- Use the normal in-app workflow pages
- Browse the standard dashboard and project workflow screens

After login, developer users are redirected straight to token management.

## Access Flags

These are extra capabilities added to a user account. They are not full roles.

### Project Admin

Use this when someone should manage projects without becoming a full organisation admin.

Project admins can:

- Create new projects
- Edit project details
- Change project visibility
- Manage restricted project membership
- Link Jira projects
- See all projects in the organisation

Organisation admins automatically count as project admins.

### Billing Access

Use this for finance, procurement, or operations contacts.

Users with billing access can:

- Open the billing area
- Review subscription and seat usage
- Manage billing contacts
- Purchase seats
- Access invoice and Xero-related billing pages

This flag is independent of the base role.

### Executive Dashboard Access

Use this for senior stakeholders who need portfolio-level reporting.

Users with executive access can:

- Open the executive dashboard
- View organisation-wide project, backlog, risk, governance, integration, and subscription rollups
- Open the per-project executive dashboard

Organisation admins do not get this automatically. It must be granted explicitly unless the user is a superadmin.

## Project Visibility Rules

Project access is also affected by the project itself.

StratFlow supports:

- `everyone` visibility: every eligible user in the organisation can see the project
- `restricted` visibility: only project members can see the project, unless the user is an organisation admin, superadmin, or project admin

This means two users with the same role may still see different project lists.

## Recommended Role Setups

### Executive sponsor

- Base role: `Viewer`
- Add `Executive dashboard` if they need portfolio reporting
- Add `Billing access` only if they also handle commercial ownership

### Delivery team member

- Base role: `User`

### Scrum master or delivery lead

- Base role: `Project Manager`
- Add `Project admin` if they should create and manage projects

### Internal customer administrator

- Base role: `Organisation Admin`

### Finance contact

- Base role: `Viewer` or `User`
- Add `Billing access`

### Platform engineer using MCP

- Base role: `Developer`

## Current Implementation Notes

These notes matter most for admins and implementation teams.

### 1. Some roles are defined more clearly in schema/UI than in controller enforcement

The database and admin screen list:

- `viewer`
- `user`
- `project_manager`
- `org_admin`
- `superadmin`
- `developer`

Current controller role validation now follows the central assignable-role list in `PermissionService`.

- Org admins can provision: `viewer`, `user`, `project_manager`, `org_admin`
- Superadmins can additionally provision: `developer`, `superadmin`

The remaining provisioning gap was the create-user form not exposing the same access flags as the edit form. That flow is now aligned, so new users can also be provisioned with:

- `is_project_admin`
- `has_billing_access`
- `has_executive_access`

### 2. Viewer read-only enforcement is now in the main workflow paths

Today, the main enforced boundaries are:

- `admin`
- `billing`
- `executive`
- `superadmin`
- workflow write actions via central capability checks
- project-scoped visibility and edit checks on the main workflow controllers

That means:

- `viewer` can browse accessible projects and read workflow pages
- `viewer` cannot use workflow write actions
- restricted projects are hidden or blocked unless the user is a member or has broader project visibility capability

There may still be edge cases to tighten over time, but the old "viewer can probably still write on normal pages" caveat is no longer the right mental model.

### 3. Access flags are part of the real permission model

In practice, who can do what depends on:

- Base role / account type
- `is_project_admin`
- `has_billing_access`
- `has_executive_access`
- Project membership role (`viewer`, `editor`, `project_admin`) for restricted projects

### 4. StratFlow now has a schema-backed capability model

The current backend can resolve permissions from database-backed account types and capability tables when the latest migration has been applied.

That includes:

- `users.account_type`
- capability catalog tables
- user-level capability grants or denies
- role-based project memberships in `project_memberships`

Legacy columns and the older `project_members` table are still read as a compatibility fallback, so older environments can keep working during rollout.

### 5. Project access is now more expressive

Restricted-project membership is no longer just "is this user on the list?"

Membership roles now support:

- `viewer`: view only
- `editor`: view and edit workflow content
- `project_admin`: manage project settings and access for that project

The dashboard project modal now lets admins assign those roles directly when editing restricted-project access.

## Admin Checklist

When setting up a user, decide these in order:

1. What is their base role?
2. Do they need to manage projects for other people?
3. Do they need billing?
4. Do they need executive reporting?
5. Should they see all projects, or only restricted ones they are a member of?
6. Are they a normal UI user or an API/MCP developer account?

## Related Docs

- [README.md](../README.md)
- [API.md](API.md)
- [ARCHITECTURE.md](ARCHITECTURE.md)
- [DATABASE.md](DATABASE.md)
- [Access Control Policy](compliance/policies/access-control-policy.md)
