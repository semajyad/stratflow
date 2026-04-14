<?php

/**
 * AdminController
 *
 * Handles the organisation admin area: user management (CRUD with seat limits),
 * team management (CRUD with member assignment), and organisation settings
 * (workflow personas, defaults, tripwires).
 *
 * All routes require 'auth' + 'admin' middleware (org_admin or superadmin role).
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\PasswordPolicy;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\Integration;
use StratFlow\Models\Organisation;
use StratFlow\Models\PasswordToken;
use StratFlow\Models\Subscription;
use StratFlow\Models\Team;
use StratFlow\Models\TeamMember;
use StratFlow\Models\User;
use StratFlow\Security\PermissionService;
use StratFlow\Services\AuditLogger;
use StratFlow\Services\EmailService;
use StratFlow\Services\StripeService;

class AdminController
{
    protected Request $request;
    protected Response $response;
    protected Auth $auth;
    protected Database $db;
    protected array $config;
    public function __construct(Request $request, Response $response, Auth $auth, Database $db, array $config)
    {
        $this->request  = $request;
        $this->response = $response;
        $this->auth     = $auth;
        $this->db       = $db;
        $this->config   = $config;
    }

    // =========================================================================
    // DASHBOARD
    // =========================================================================

    /**
     * Render the admin dashboard overview.
     *
     * Shows user count vs seat limit, team count, and subscription status.
     */
    public function index(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $userCount  = User::countByOrgId($this->db, $orgId);
        $seatLimit  = Subscription::getSeatLimit($this->db, $orgId);
        $teams      = Team::findByOrgId($this->db, $orgId);
        $sub        = Subscription::findByOrgId($this->db, $orgId);
// Recent activity for this org
        $recentActivity = [];
        try {
            $recentActivity = \StratFlow\Models\AuditLog::findFiltered($this->db, $orgId, null);
            $recentActivity = array_slice($recentActivity, 0, 8);
        } catch (\Throwable $e) {
        /* non-critical */
        }

        $this->response->render('admin/index', [
            'user'            => $user,
            'user_count'      => $userCount,
            'seat_limit'      => $seatLimit,
            'team_count'      => count($teams),
            'subscription'    => $sub,
            'recent_activity' => $recentActivity,
            'active_page'     => 'admin',
            'flash_message' => $_SESSION['flash_message'] ?? null,
            'flash_error'   => $_SESSION['flash_error']   ?? null,
        ], 'app');
        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    // =========================================================================
    // USER MANAGEMENT
    // =========================================================================

    /**
     * List all users in the organisation.
     */
    public function users(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $users     = User::findByOrgId($this->db, $orgId);
        $seatLimit = Subscription::getSeatLimit($this->db, $orgId);
        $userCount = User::countByOrgId($this->db, $orgId);
        $membershipCounts = [];
        if ($this->db->tableExists('project_memberships')) {
            $rows = $this->db->query("SELECT user_id, COUNT(*) AS membership_count
                 FROM project_memberships pm
                 JOIN projects p ON p.id = pm.project_id
                 WHERE p.org_id = :org_id
                 GROUP BY user_id", [':org_id' => $orgId])->fetchAll();
            foreach ($rows as $row) {
                $membershipCounts[(int) $row['user_id']] = (int) $row['membership_count'];
            }
        } elseif ($this->db->tableExists('project_members')) {
            $rows = $this->db->query("SELECT user_id, COUNT(*) AS membership_count
                 FROM project_members pm
                 JOIN projects p ON p.id = pm.project_id
                 WHERE p.org_id = :org_id
                 GROUP BY user_id", [':org_id' => $orgId])->fetchAll();
            foreach ($rows as $row) {
                $membershipCounts[(int) $row['user_id']] = (int) $row['membership_count'];
            }
        }

        foreach ($users as &$managedUser) {
            $managedUser['account_type'] = PermissionService::accountTypeFor($managedUser);
            $managedUser['access_summary'] = PermissionService::describeAccessSummary($managedUser, $this->db, $membershipCounts[(int) $managedUser['id']] ?? 0);
        }
        unset($managedUser);
        $jiraConnected = false;
        try {
            $jiraInteg = \StratFlow\Models\Integration::findByOrgAndProvider($this->db, $orgId, 'jira');
            $jiraConnected = $jiraInteg && ($jiraInteg['status'] ?? '') !== 'disconnected';
        } catch (\Throwable) {
        /* non-critical */
        }

        $this->response->render('admin/users', [
            'user'             => $user,
            'users'            => $users,
            'seat_limit'       => $seatLimit,
            'user_count'       => $userCount,
            'assignable_roles' => PermissionService::assignableRolesFor($user),
            'jira_connected'   => $jiraConnected,
            'active_page'      => 'admin',
            'flash_message'    => $_SESSION['flash_message'] ?? null,
            'flash_error'      => $_SESSION['flash_error']   ?? null,
        ], 'app');
        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Create a new user in the organisation.
     *
     * Validates seat limit and email uniqueness. Generates a random password
     * (not shared), creates a set_password token, and sends a welcome email
     * so the user can set their own password.
     */
    public function createUser(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
// Check seat limit
        $currentCount = User::countByOrgId($this->db, $orgId);
        $seatLimit    = Subscription::getSeatLimit($this->db, $orgId);
        if ($currentCount >= $seatLimit) {
            $_SESSION['flash_error'] = "Seat limit reached ({$seatLimit}). Upgrade your plan to add more users.";
            $this->response->redirect('/app/admin/users');
            return;
        }

        $email    = trim((string) $this->request->post('email', ''));
        $fullName = trim((string) $this->request->post('full_name', ''));
        $role     = (string) $this->request->post('role', 'user');
// Validate
        if ($fullName === '' || $email === '') {
            $_SESSION['flash_error'] = 'Name and email are required.';
            $this->response->redirect('/app/admin/users');
            return;
        }

        $allowedRoles = PermissionService::assignableRolesFor($user);
        if (!in_array($role, $allowedRoles, true)) {
            $role = 'user';
        }

        // Org admins are always project admins; otherwise respect the form checkbox.
        $isProjectAdmin = ($role === 'org_admin')
            ? 1
            : ($this->request->post('is_project_admin') === '1' ? 1 : 0);
// Check email uniqueness
        $existing = User::findByEmail($this->db, $email);
        if ($existing) {
            $_SESSION['flash_error'] = 'A user with that email already exists.';
            $this->response->redirect('/app/admin/users');
            return;
        }

        // Generate a random password hash (user will never know this password)
        $randomPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $jiraAccountId   = trim((string) $this->request->post('jira_account_id', '')) ?: null;
        $jiraDisplayName = trim((string) $this->request->post('jira_display_name', '')) ?: null;
        $newUserId = User::create($this->db, [
            'org_id'               => $orgId,
            'full_name'            => $fullName,
            'email'                => $email,
            'password_hash'        => $randomPassword,
            'role'                 => $role,
            'account_type'         => PermissionService::accountTypeFor(['role' => $role]),
            'is_project_admin'     => $isProjectAdmin,
            'has_billing_access'   => $this->request->post('has_billing_access') === '1' ? 1 : 0,
            'has_executive_access' => $this->request->post('has_executive_access') === '1' ? 1 : 0,
            'jira_account_id'      => $jiraAccountId,
            'jira_display_name'    => $jiraDisplayName,
        ]);
// Create set_password token and send welcome email
        $token = PasswordToken::create($this->db, $newUserId, 'set_password');
        $setPasswordUrl = rtrim($this->config['app']['url'], '/') . '/set-password/' . $token;
        $emailService = new EmailService($this->config);
        $emailService->sendWelcome($email, $fullName, $setPasswordUrl);
        AuditLogger::log($this->db, (int) $user['id'], AuditLogger::USER_CREATED, $this->request->ip(), $_SERVER['HTTP_USER_AGENT'] ?? '', [
            'new_user_id' => $newUserId,
            'email'       => $email,
            'role'        => $role,
        ]);
        $_SESSION['flash_message'] = 'User created. A welcome email has been sent to ' . $email . '.';
        $this->response->redirect('/app/admin/users');
    }

    /**
     * Update an existing user's details.
     *
     * Verifies the target user belongs to the same organisation.
     *
     * @param string $id User primary key (from route param)
     */
    public function updateUser($id): void
    {
        $user    = $this->auth->user();
        $orgId   = (int) $user['org_id'];
        $userId  = (int) $id;
        $target = User::findById($this->db, $userId);
        if (!$target || (int) $target['org_id'] !== $orgId) {
            $_SESSION['flash_error'] = 'User not found.';
            $this->response->redirect('/app/admin/users');
            return;
        }

        $fullName = trim((string) $this->request->post('full_name', ''));
        $email    = trim((string) $this->request->post('email', ''));
        $role     = (string) $this->request->post('role', 'user');
        $password = (string) $this->request->post('password', '');
        if ($fullName === '' || $email === '') {
            $_SESSION['flash_error'] = 'Name and email are required.';
            $this->response->redirect('/app/admin/users');
            return;
        }

        $allowedRoles = PermissionService::assignableRolesFor($user);
        if (!in_array($role, $allowedRoles, true)) {
            $role = 'user';
        }

        // Check email uniqueness (excluding current user)
        $existing = User::findByEmail($this->db, $email);
        if ($existing && (int) $existing['id'] !== $userId) {
            $_SESSION['flash_error'] = 'A user with that email already exists.';
            $this->response->redirect('/app/admin/users');
            return;
        }

        // Org admins are always project admins; otherwise respect the form checkbox
        $isProjectAdmin = ($role === 'org_admin')
            ? 1
            : ($this->request->post('is_project_admin') === '1' ? 1 : 0);
        $jiraAccountId   = trim((string) $this->request->post('jira_account_id', '')) ?: null;
        $jiraDisplayName = trim((string) $this->request->post('jira_display_name', '')) ?: null;
        $data = [
            'full_name'            => $fullName,
            'email'                => $email,
            'role'                 => $role,
            'account_type'         => PermissionService::accountTypeFor(['role' => $role]),
            'is_project_admin'     => $isProjectAdmin,
            'has_billing_access'   => $this->request->post('has_billing_access')   === '1' ? 1 : 0,
            'has_executive_access' => $this->request->post('has_executive_access') === '1' ? 1 : 0,
            'jira_account_id'      => $jiraAccountId,
            'jira_display_name'    => $jiraDisplayName,
        ];
// Enforce password policy if a new password is provided
        if ($password !== '') {
            $policyErrors = PasswordPolicy::validate($password);
            if (!empty($policyErrors)) {
                $_SESSION['flash_error'] = implode(' ', $policyErrors);
                $this->response->redirect('/app/admin/users');
                return;
            }
            $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $data['password_changed_at'] = date('Y-m-d H:i:s');
        }

        // Track role changes for audit
        $oldRole = $target['role'];
        User::update($this->db, $userId, $data);
// Audit log: role change
        if ($role !== $oldRole) {
            AuditLogger::log($this->db, (int) $user['id'], AuditLogger::USER_ROLE_CHANGED, $this->request->ip(), $_SERVER['HTTP_USER_AGENT'] ?? '', [
                'target_user_id' => $userId,
                'old_role'       => $oldRole,
                'new_role'       => $role,
            ]);
        }

        // Audit log: password change by admin
        if ($password !== '') {
            AuditLogger::log($this->db, (int) $user['id'], AuditLogger::PASSWORD_CHANGE, $this->request->ip(), $_SERVER['HTTP_USER_AGENT'] ?? '', [
                'target_user_id' => $userId,
                'method'         => 'admin_reset',
            ]);
        }

        $_SESSION['flash_message'] = 'User "' . $fullName . '" updated successfully.';
        $this->response->redirect('/app/admin/users');
    }

    /**
     * Deactivate a user (soft delete).
     *
     * Prevents self-deletion and verifies org membership.
     *
     * @param string $id User primary key (from route param)
     */
    public function reactivateUser($id): void
    {
        $user   = $this->auth->user();
        $orgId  = (int) $user['org_id'];
        $userId = (int) $id;
        $target = User::findById($this->db, $userId);
        if (!$target || (int) $target['org_id'] !== $orgId) {
            $_SESSION['flash_error'] = 'User not found.';
            $this->response->redirect('/app/admin/users');
            return;
        }

        User::reactivate($this->db, $userId);
        \StratFlow\Services\AuditLogger::log($this->db, (int) $user['id'], \StratFlow\Services\AuditLogger::USER_ROLE_CHANGED, $this->request->ip(), $_SERVER['HTTP_USER_AGENT'] ?? '', [
            'target_user_id' => $userId,
            'action'         => 'reactivated',
        ]);
        $_SESSION['flash_message'] = 'User "' . $target['full_name'] . '" has been reactivated.';
        $this->response->redirect('/app/admin/users');
    }

    public function deleteUser($id): void
    {
        $user   = $this->auth->user();
        $orgId  = (int) $user['org_id'];
        $userId = (int) $id;
// Prevent self-deletion
        if ($userId === (int) $user['id']) {
            $_SESSION['flash_error'] = 'You cannot deactivate your own account.';
            $this->response->redirect('/app/admin/users');
            return;
        }

        $target = User::findById($this->db, $userId);
        if (!$target || (int) $target['org_id'] !== $orgId) {
            $_SESSION['flash_error'] = 'User not found.';
            $this->response->redirect('/app/admin/users');
            return;
        }

        User::deactivate($this->db, $userId);
        AuditLogger::log($this->db, (int) $user['id'], AuditLogger::USER_DELETED, $this->request->ip(), $_SERVER['HTTP_USER_AGENT'] ?? '', [
            'target_user_id' => $userId,
            'email'          => $target['email'],
        ]);
        $_SESSION['flash_message'] = 'User "' . $target['full_name'] . '" has been deactivated.';
        $this->response->redirect('/app/admin/users');
    }

    // =========================================================================
    // TEAM MANAGEMENT
    // =========================================================================

    /**
     * List all teams in the organisation with member counts.
     */
    public function teams(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $teams = Team::findByOrgId($this->db, $orgId);
        $users = User::findByOrgId($this->db, $orgId);
// Load members for each team
        $teamMembers = [];
        foreach ($teams as $team) {
            $teamMembers[(int) $team['id']] = TeamMember::findByTeamId($this->db, (int) $team['id']);
        }

        $this->response->render('admin/teams', [
            'user'          => $user,
            'teams'         => $teams,
            'team_members'  => $teamMembers,
            'org_users'     => $users,
            'active_page'   => 'admin',
            'flash_message' => $_SESSION['flash_message'] ?? null,
            'flash_error'   => $_SESSION['flash_error']   ?? null,
        ], 'app');
        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Create a new team in the organisation.
     */
    public function createTeam(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $name        = trim((string) $this->request->post('name', ''));
        $description = trim((string) $this->request->post('description', ''));
        $capacity    = (int) $this->request->post('capacity', '0');
        if ($name === '') {
            $_SESSION['flash_error'] = 'Team name is required.';
            $this->response->redirect('/app/admin/teams');
            return;
        }

        Team::create($this->db, [
            'org_id'      => $orgId,
            'name'        => $name,
            'description' => $description,
            'capacity'    => $capacity,
        ]);
        $_SESSION['flash_message'] = 'Team "' . $name . '" created successfully.';
        $this->response->redirect('/app/admin/teams');
    }

    /**
     * Update an existing team's details.
     *
     * @param string $id Team primary key (from route param)
     */
    public function updateTeam($id): void
    {
        $user   = $this->auth->user();
        $orgId  = (int) $user['org_id'];
        $teamId = (int) $id;
        $team = Team::findById($this->db, $teamId);
        if (!$team || (int) $team['org_id'] !== $orgId) {
            $_SESSION['flash_error'] = 'Team not found.';
            $this->response->redirect('/app/admin/teams');
            return;
        }

        $name        = trim((string) $this->request->post('name', ''));
        $description = trim((string) $this->request->post('description', ''));
        $capacity    = (int) $this->request->post('capacity', '0');
        if ($name === '') {
            $_SESSION['flash_error'] = 'Team name is required.';
            $this->response->redirect('/app/admin/teams');
            return;
        }

        Team::update($this->db, $teamId, [
            'name'        => $name,
            'description' => $description,
            'capacity'    => $capacity,
        ]);
        $_SESSION['flash_message'] = 'Team "' . $name . '" updated successfully.';
        $this->response->redirect('/app/admin/teams');
    }

    /**
     * Delete a team. CASCADE handles team_members cleanup.
     *
     * @param string $id Team primary key (from route param)
     */
    public function deleteTeam($id): void
    {
        $user   = $this->auth->user();
        $orgId  = (int) $user['org_id'];
        $teamId = (int) $id;
        $team = Team::findById($this->db, $teamId);
        if (!$team || (int) $team['org_id'] !== $orgId) {
            $_SESSION['flash_error'] = 'Team not found.';
            $this->response->redirect('/app/admin/teams');
            return;
        }

        Team::delete($this->db, $teamId);
        $_SESSION['flash_message'] = 'Team "' . $team['name'] . '" deleted.';
        $this->response->redirect('/app/admin/teams');
    }

    /**
     * Add a user to a team. Both must belong to the same organisation.
     */
    public function addTeamMember(): void
    {
        $user   = $this->auth->user();
        $orgId  = (int) $user['org_id'];
        $teamId = (int) $this->request->post('team_id', '0');
        $userId = (int) $this->request->post('user_id', '0');
// Verify team belongs to org
        $team = Team::findById($this->db, $teamId);
        if (!$team || (int) $team['org_id'] !== $orgId) {
            $_SESSION['flash_error'] = 'Team not found.';
            $this->response->redirect('/app/admin/teams');
            return;
        }

        // Verify user belongs to org
        $targetUser = User::findById($this->db, $userId);
        if (!$targetUser || (int) $targetUser['org_id'] !== $orgId) {
            $_SESSION['flash_error'] = 'User not found.';
            $this->response->redirect('/app/admin/teams');
            return;
        }

        TeamMember::addMember($this->db, $teamId, $userId);
        $_SESSION['flash_message'] = $targetUser['full_name'] . ' added to ' . $team['name'] . '.';
        $this->response->redirect('/app/admin/teams');
    }

    /**
     * Remove a user from a team.
     */
    public function removeTeamMember(): void
    {
        $user   = $this->auth->user();
        $orgId  = (int) $user['org_id'];
        $teamId = (int) $this->request->post('team_id', '0');
        $userId = (int) $this->request->post('user_id', '0');
// Verify team belongs to org
        $team = Team::findById($this->db, $teamId);
        if (!$team || (int) $team['org_id'] !== $orgId) {
            $_SESSION['flash_error'] = 'Team not found.';
            $this->response->redirect('/app/admin/teams');
            return;
        }

        TeamMember::removeMember($this->db, $teamId, $userId);
        $_SESSION['flash_message'] = 'Member removed from ' . $team['name'] . '.';
        $this->response->redirect('/app/admin/teams');
    }

    // =========================================================================
    // INVOICE MANAGEMENT
    // =========================================================================

    /**
     * List all Stripe invoices for the organisation.
     *
     * Loads the org's stripe_customer_id, retrieves invoices from Stripe,
     * and renders the invoices template. If no customer ID is configured,
     * shows an empty list.
     */
    /**
     * Billing dashboard — subscription overview, seat usage, plan management.
     */
    public function billing(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $org = Organisation::findById($this->db, $orgId);
        $sub = Subscription::findByOrgId($this->db, $orgId);
        $seatLimit = Subscription::getSeatLimit($this->db, $orgId);
// Count active users
        $activeUsers = User::findByOrgId($this->db, $orgId);
        $activeCount = count(array_filter($activeUsers, fn($u) => (bool) $u['is_active']));
// Fetch live Stripe subscription details if available (only for Stripe-billed orgs)
        $stripeDetails = null;
        $isInvoiceBilling = ($sub === null || ($sub['billing_method'] ?? 'invoice') === 'invoice'
                             || str_starts_with($sub['stripe_subscription_id'] ?? '', 'manual_'));
        if (!$isInvoiceBilling && $sub && !empty($sub['stripe_subscription_id'])) {
            try {
                $stripe = $this->makeStripe();
                $stripeDetails = $stripe->getSubscription($sub['stripe_subscription_id']);
            } catch (\Throwable $e) {
    // Non-critical — show local data
            }
        }

        $hasStripeCustomer = !empty($org['stripe_customer_id']);
// Check whether a Stripe price is configured for this org's plan type
        $planType = $sub['plan_type'] ?? 'product';
        $hasStripePriceConfig = !empty($this->config['stripe']['price_' . $planType] ?? '');
        if (!$hasStripePriceConfig && !empty($this->config['stripe']['price_product'] ?? '')) {
            $hasStripePriceConfig = true;
        // fall back to product price
        }

        // Billing contact from org settings_json
        $orgSettings    = json_decode($org['settings_json'] ?? '{}', true) ?? [];
        $billingContact = $orgSettings['billing_contact'] ?? [];
// Load Stripe invoices and subscriptions for inline display
        $stripeInvoices       = [];
        $stripeSubscriptions  = [];
        if ($hasStripeCustomer) {
            try {
                $stripe = $this->makeStripe();
                $stripeInvoices      = $stripe->listInvoices($org['stripe_customer_id']);
                $stripeSubscriptions = $stripe->listSubscriptions($org['stripe_customer_id']);
            } catch (\Throwable) {
            }
        }

        // Xero integration status for billing page
        $xeroIntegration = Integration::findByOrgAndProvider($this->db, $orgId, 'xero');
        $xeroConnected   = $xeroIntegration && $xeroIntegration['status'] === 'active';
        $xeroTenantName  = $xeroConnected
            ? (json_decode($xeroIntegration['config_json'] ?? '{}', true)['tenant_name'] ?? 'Xero')
            : null;
// Track which Stripe invoice IDs have already been pushed to Xero
        $pushedToXero = [];
        if ($xeroConnected) {
            $stmt = $this->db->query("SELECT reference FROM xero_invoices WHERE org_id = :org_id AND reference IS NOT NULL", [':org_id' => $orgId]);
            $pushedToXero = array_column($stmt->fetchAll(), 'reference');
        }

        $this->response->render('admin/billing', [
            'user'                => $user,
            'org'                 => $org,
            'subscription'        => $sub,
            'stripe_details'      => $stripeDetails,
            'is_invoice_billing'  => $isInvoiceBilling,
            'seat_limit'          => $seatLimit,
            'active_users'        => $activeCount,
            'total_users'         => count($activeUsers),
            'has_stripe'          => $hasStripeCustomer,
            'stripe_invoices'     => $stripeInvoices,
            'xero_connected'      => $xeroConnected,
            'xero_tenant_name'    => $xeroTenantName,
            'pushed_to_xero'      => $pushedToXero,
            'billing_contact'      => $billingContact,
            'stripe_subscriptions'    => $stripeSubscriptions,
            'has_stripe_price_config' => $hasStripePriceConfig,
            'csrf_token'              => $_SESSION['csrf_token'] ?? '',
            'active_page'         => 'billing',
            'flash_message'       => $_SESSION['flash_message'] ?? null,
            'flash_error'         => $_SESSION['flash_error']   ?? null,
        ], 'app');
        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Redirect to Stripe Customer Portal for self-service billing management.
     */
    public function billingPortal(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $org   = Organisation::findById($this->db, $orgId);
        if (!$org || empty($org['stripe_customer_id'])) {
            $_SESSION['flash_error'] = 'No billing account linked. Contact support.';
            $this->response->redirect('/app/admin/billing');
            return;
        }

        try {
            $stripe = $this->makeStripe();
            $appUrl = rtrim($this->config['app']['url'] ?? '', '/');
            $portalUrl = $stripe->createPortalSession($org['stripe_customer_id'], $appUrl . '/app/admin/billing');

            \StratFlow\Services\AuditLogger::log(
                $this->db,
                (int) $user['id'],
                \StratFlow\Services\AuditLogger::ADMIN_ACTION,
                $this->request->ip(),
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                ['action' => 'billing_portal_access']
            );
            $this->response->redirect($portalUrl);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not open billing portal: ' . $e->getMessage();
            $this->response->redirect('/app/admin/billing');
        }
    }

    /**
     * Save billing contact details (name, email) to org settings_json.
     */
    public function saveBillingContact(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $org   = Organisation::findById($this->db, $orgId);
        $name  = trim((string) $this->request->post('billing_contact_name', ''));
        $email = trim((string) $this->request->post('billing_contact_email', ''));
        $settings = json_decode($org['settings_json'] ?? '{}', true) ?? [];
        $settings['billing_contact'] = [
            'name'  => $name,
            'email' => $email,
        ];
        Organisation::update($this->db, $orgId, ['settings_json' => json_encode($settings)]);
        $_SESSION['flash_message'] = 'Billing contact saved.';
        $this->response->redirect('/app/admin/billing');
    }

    /**
     * Purchase additional seats for an invoice-billed organisation.
     *
     * Increments the seat limit immediately. The updated seat count is
     * reflected in the cost breakdown and will be included in the next invoice.
     */
    public function purchaseSeatsInvoice(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $sub = Subscription::findByOrgId($this->db, $orgId);
        if (!$sub) {
            $_SESSION['flash_error'] = 'No active subscription found.';
            $this->response->redirect('/app/admin/billing');
            return;
        }

        $seatsToAdd = max(1, (int) $this->request->post('seats_to_add', 0));
        if ($seatsToAdd < 1) {
            $_SESSION['flash_error'] = 'Please enter at least 1 seat to add.';
            $this->response->redirect('/app/admin/billing');
            return;
        }

        $currentLimit = Subscription::getSeatLimit($this->db, $orgId);
        $newLimit     = $currentLimit + $seatsToAdd;
        Subscription::updateSeatLimit($this->db, $orgId, $newLimit);

        \StratFlow\Services\AuditLogger::log(
            $this->db,
            (int) $user['id'],
            \StratFlow\Services\AuditLogger::ADMIN_ACTION,
            $this->request->ip(),
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            ['action' => 'seats_purchased_invoice', 'org_id' => $orgId,
             'seats_added' => $seatsToAdd, 'new_limit' => $newLimit]
        );
        $pricePerSeat = (int) ($sub['price_per_seat_cents'] ?? 0);
        $addedCost    = $pricePerSeat > 0
            ? ' Your next invoice will include an additional $' . number_format(($pricePerSeat * $seatsToAdd) / 100, 2) . '.'
            : '';
        $_SESSION['flash_message'] = "{$seatsToAdd} seat" . ($seatsToAdd > 1 ? 's' : '') .
            " added. New seat limit: {$newLimit}.{$addedCost}";
        $this->response->redirect('/app/admin/billing');
    }

    /**
     * Initiate a Stripe Checkout Session for purchasing a seat subscription.
     *
     * Creates a Checkout Session for the org's plan price at the requested
     * quantity and redirects the user to the Stripe-hosted payment page.
     */
    public function purchaseSeatsStripe(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $org = Organisation::findById($this->db, $orgId);
        $sub = Subscription::findByOrgId($this->db, $orgId);
        $quantity = max(1, (int) $this->request->post('seat_quantity', 1));
        $planType = $sub['plan_type'] ?? 'product';
        $priceMap = [
            'product'     => $this->config['stripe']['price_product']     ?? '',
            'consultancy' => $this->config['stripe']['price_consultancy'] ?? '',
        ];
        $priceId = $priceMap[$planType] ?? ($priceMap['product'] ?? '');
        if ($priceId === '') {
            $_SESSION['flash_error'] = 'No Stripe price configured for this plan. Contact support.';
            $this->response->redirect('/app/admin/billing');
            return;
        }

        $appUrl     = rtrim($this->config['app']['url'] ?? '', '/');
        $successUrl = $appUrl . '/app/admin/billing?seats_purchased=1';
        $cancelUrl  = $appUrl . '/app/admin/billing';
        $customerId = !empty($org['stripe_customer_id']) ? $org['stripe_customer_id'] : null;
        try {
            $stripe  = $this->makeStripe();
            $session = $stripe->createSeatCheckout($priceId, $quantity, $successUrl, $cancelUrl, $customerId);

            \StratFlow\Services\AuditLogger::log(
                $this->db,
                (int) $user['id'],
                \StratFlow\Services\AuditLogger::ADMIN_ACTION,
                $this->request->ip(),
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                ['action' => 'stripe_seat_checkout_started', 'org_id' => $orgId, 'quantity' => $quantity]
            );
            $this->response->redirect($session->url);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not start checkout: ' . $e->getMessage();
            $this->response->redirect('/app/admin/billing');
        }
    }

    public function invoices(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $org      = Organisation::findById($this->db, $orgId);
        $invoices = [];
        if ($org && !empty($org['stripe_customer_id'])) {
            try {
                $stripe   = $this->makeStripe();
                $invoices = $stripe->listInvoices($org['stripe_customer_id']);
            } catch (\Exception $e) {
                $_SESSION['flash_error'] = 'Could not load invoices: ' . $e->getMessage();
            }
        }

        $this->response->render('admin/invoices', [
            'user'          => $user,
            'invoices'      => $invoices,
            'active_page'   => 'admin',
            'flash_message' => $_SESSION['flash_message'] ?? null,
            'flash_error'   => $_SESSION['flash_error']   ?? null,
        ], 'app');
        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Redirect the user to the Stripe-hosted PDF URL for a single invoice.
     *
     * @param string $id Stripe invoice ID (in_xxx)
     */
    public function downloadInvoice($id): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
// Verify the invoice belongs to this org's Stripe customer
        $org = Organisation::findById($this->db, $orgId);
        if (!$org || empty($org['stripe_customer_id'])) {
            $_SESSION['flash_error'] = 'No billing account configured.';
            $this->response->redirect('/app/admin/invoices');
            return;
        }

        try {
            $stripe  = $this->makeStripe();
// Verify the invoice belongs to this org's customer before redirecting
            \Stripe\Stripe::setApiKey($this->config['stripe']['secret_key']);
            $invoice = \Stripe\Invoice::retrieve($id);
            if ($invoice->customer !== $org['stripe_customer_id']) {
                $_SESSION['flash_error'] = 'Invoice not found.';
                $this->response->redirect('/app/admin/invoices');
                return;
            }

            $pdfUrl = $invoice->invoice_pdf;
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'Could not retrieve invoice PDF.';
            $this->response->redirect('/app/admin/invoices');
            return;
        }

        if (empty($pdfUrl)) {
            $_SESSION['flash_error'] = 'No PDF available for this invoice.';
            $this->response->redirect('/app/admin/invoices');
            return;
        }

        $this->response->redirect($pdfUrl);
    }

    // =========================================================================
    // SETTINGS
    // =========================================================================

    /**
     * Return the default settings structure.
     *
     * @return array Default settings with personas, defaults, and tripwires
     */
    private function getDefaultSettings(): array
    {
        return [
            'personas' => [
                'agile_product_manager'        => 'Translate the Mermaid strategy diagram and OKRs to the prioritised list of high-level work items',
                'technical_project_manager'    => 'Generate a description of high-level work items',
                'expert_system_architect'      => 'Convert the summary to analysis, analyse video from files',
                'enterprise_risk_manager'      => 'Generate risks, read the risk, and write the mitigation strategy',
                'agile_product_owner'          => 'Decompose the High Level Items into user stories',
                'enterprise_business_strategist' => 'Analyse the provided file to a 3-paragraph summary',
            ],
            'hl_item_default_months'       => 2,
            'hl_item_sizing_method'        => 'sprints',
            'sprint_length_weeks'          => 2,
            'user_story_max_size'          => 13,
            'capacity_tripwire_percent'    => 20,
            'dependency_tripwire_enabled'  => true,
            'quality' => [
                'enabled'     => true,
                'threshold'   => 70,
                'enforcement' => 'warn',
            ],
            'ai' => [
                'model'   => '',   // empty = use platform default (gemini-3-flash-preview
                'api_key' => '',   // empty = use platform default key
            ],
            'field_order_work_item' => ['title','okr_title','okr_description','owner','estimated_sprints','description','acceptance_criteria','kr_hypothesis','git_links'],
            'field_order_story'     => ['title','description','parent_hl_item_id','team_assigned','size','acceptance_criteria','kr_hypothesis','blocked_by','git_links'],
        ];
    }

    /**
     * Render the organisation settings page.
     *
     * Loads settings_json from the org row, merging with defaults for any missing keys.
     */

    /**
     * View audit logs for this organisation.
     */
    public function auditLogs(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $filterType = $this->request->get('type', '') ?: null;
        $logs = \StratFlow\Models\AuditLog::findFiltered($this->db, $orgId, $filterType);
        $eventTypes = [
            \StratFlow\Services\AuditLogger::LOGIN_SUCCESS,
            \StratFlow\Services\AuditLogger::LOGIN_FAILURE,
            \StratFlow\Services\AuditLogger::PASSWORD_CHANGE,
            \StratFlow\Services\AuditLogger::USER_CREATED,
            \StratFlow\Services\AuditLogger::USER_DELETED,
            \StratFlow\Services\AuditLogger::ADMIN_ACTION,
            \StratFlow\Services\AuditLogger::SETTINGS_CHANGED,
            \StratFlow\Services\AuditLogger::PROJECT_CREATED,
            \StratFlow\Services\AuditLogger::DOCUMENT_UPLOADED,
            \StratFlow\Services\AuditLogger::INTEGRATION_SYNC,
        ];
        $this->response->render('admin/audit-logs', [
            'user'          => $user,
            'logs'          => $logs,
            'event_types'   => $eventTypes,
            'filter_type'   => $filterType,
            'active_page'   => 'audit-logs',
            'flash_message' => $_SESSION['flash_message'] ?? null,
            'flash_error'   => $_SESSION['flash_error'] ?? null,
        ], 'app');
        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Export org audit logs as CSV.
     */
    public function exportAuditLogs(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $filterType = $this->request->get('type', '') ?: null;
        $dateFrom   = $this->request->get('from', '') ?: null;
        $dateTo     = $this->request->get('to', '') ?: null;
        $logs = \StratFlow\Models\AuditLog::findFiltered($this->db, $orgId, $filterType, $dateFrom, $dateTo);

        \StratFlow\Services\AuditLogger::log(
            $this->db,
            (int) $user['id'],
            \StratFlow\Services\AuditLogger::DATA_EXPORT,
            $this->request->ip(),
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            ['type' => 'audit_logs', 'count' => count($logs)]
        );
        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, ['Timestamp', 'Event', 'User', 'Email', 'IP Address', 'Details']);
        foreach ($logs as $log) {
            fputcsv($csv, [
                $log['created_at'],
                $log['event_type'],
                $log['full_name'] ?? 'System',
                $log['email'] ?? '',
                $log['ip_address'],
                $log['details_json'] ?? '',
            ]);
        }
        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);
        $this->response->download($content, 'audit-logs-' . date('Y-m-d') . '.csv', 'text/csv');
    }

    public function settings(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $org      = Organisation::findById($this->db, $orgId);
        $defaults = $this->getDefaultSettings();
        $settings = $defaults;
        if ($org && !empty($org['settings_json'])) {
            $saved = json_decode($org['settings_json'], true);
            if (is_array($saved)) {
                $settings = array_replace_recursive($defaults, $saved);
            }
        }

        $systemSettings = \StratFlow\Models\SystemSettings::get($this->db);
        $this->response->render('admin/settings', [
            'user'            => $user,
            'settings'        => $settings,
            'system_settings' => $systemSettings,
            'active_page'     => 'admin',
            'flash_message'   => $_SESSION['flash_message'] ?? null,
            'flash_error'     => $_SESSION['flash_error']   ?? null,
        ], 'app');
        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Save organisation settings from the form.
     *
     * Collects persona prompts, defaults, and tripwires from POST data,
     * JSON-encodes them, and stores in organisations.settings_json.
     */
    public function saveSettings(): void
    {
        $user  = $this->auth->user();
        $orgId = (int) $user['org_id'];
        $settings = [
            'personas' => [
                'agile_product_manager'          => trim((string) $this->request->post('persona_agile_product_manager', '')),
                'technical_project_manager'      => trim((string) $this->request->post('persona_technical_project_manager', '')),
                'expert_system_architect'        => trim((string) $this->request->post('persona_expert_system_architect', '')),
                'enterprise_risk_manager'        => trim((string) $this->request->post('persona_enterprise_risk_manager', '')),
                'agile_product_owner'            => trim((string) $this->request->post('persona_agile_product_owner', '')),
                'enterprise_business_strategist' => trim((string) $this->request->post('persona_enterprise_business_strategist', '')),
            ],
            'hl_item_default_months'      => (int) $this->request->post('hl_item_default_months', '2'),
            'hl_item_sizing_method'       => in_array($this->request->post('hl_item_sizing_method'), ['sprints','weeks','months','t_shirt'], true)
                ? $this->request->post('hl_item_sizing_method') : 'sprints',
            'sprint_length_weeks'         => max(1, min(12, (int) $this->request->post('sprint_length_weeks', '2'))),
            'user_story_max_size'         => (int) $this->request->post('user_story_max_size', '13'),
            'capacity_tripwire_percent'   => (int) $this->request->post('capacity_tripwire_percent', '20'),
            'dependency_tripwire_enabled' => $this->request->post('dependency_tripwire_enabled') === '1',
            'quality' => [
                'enabled'     => $this->request->post('quality_enabled') === '1',
                'threshold'   => min(100, max(0, (int) $this->request->post('quality_threshold', 70))),
                'enforcement' => in_array($this->request->post('quality_enforcement'), ['warn', 'block'], true)
                    ? $this->request->post('quality_enforcement') : 'warn',
            ],
        ];
// Preserve the existing API key if the form was submitted with a blank field
        // (password inputs render blank for security; blank = "keep current")
        $submittedKey = trim((string) $this->request->post('ai_api_key', ''));
        $org          = Organisation::findById($this->db, $orgId);
        $existing     = [];
        if ($org && !empty($org['settings_json'])) {
            $existing = json_decode($org['settings_json'], true) ?? [];
        }
        $settings['ai'] = [
            'provider' => trim((string) $this->request->post('ai_provider', '')),
            'model'    => trim((string) $this->request->post('ai_model', '')),
            'api_key'  => $submittedKey !== '' ? $submittedKey : ($existing['ai']['api_key'] ?? ''),
        ];
// Field ordering — submitted as comma-separated key lists
        $validWiFields = ['title','okr_title','okr_description','owner','estimated_sprints','description','acceptance_criteria','kr_hypothesis','git_links'];
        $validStFields = ['title','description','parent_hl_item_id','team_assigned','size','acceptance_criteria','kr_hypothesis','blocked_by','git_links'];
        $wiOrder = array_values(array_intersect(array_filter(array_map('trim', explode(',', (string) $this->request->post('field_order_work_item', '')))), $validWiFields));
        $stOrder = array_values(array_intersect(array_filter(array_map('trim', explode(',', (string) $this->request->post('field_order_story', '')))), $validStFields));
        // Append any missing fields at the end (safety net)
        foreach ($validWiFields as $f) {
            if (!in_array($f, $wiOrder, true)) {
                        $wiOrder[] = $f;
            }
        }
        foreach ($validStFields as $f) {
            if (!in_array($f, $stOrder, true)) {
                $stOrder[] = $f;
            }
        }

        $settings['field_order_work_item'] = $wiOrder;
        $settings['field_order_story']     = $stOrder;
        Organisation::update($this->db, $orgId, [
            'settings_json' => json_encode($settings),
        ]);
        AuditLogger::log($this->db, (int) $user['id'], AuditLogger::SETTINGS_CHANGED, $this->request->ip(), $_SERVER['HTTP_USER_AGENT'] ?? '', [
            'org_id' => $orgId,
        ]);
        $_SESSION['flash_message'] = 'Settings saved successfully.';
        $this->response->redirect('/app/admin/settings');
    }

    /**
     * Test the AI connection using the submitted provider/model/key.
     */
    public function testAi(): void
    {
        $provider = trim((string) $this->request->post('ai_provider', ''));
        $model    = trim((string) $this->request->post('ai_model', ''));
        $apiKey   = trim((string) $this->request->post('ai_api_key', ''));
// Fall back to org's saved key if none submitted
        if ($apiKey === '') {
            $user  = $this->auth->user();
            $org   = \StratFlow\Models\Organisation::findById($this->db, (int) $user['org_id']);
            $saved = [];
            if ($org && !empty($org['settings_json'])) {
                $saved = json_decode($org['settings_json'], true) ?? [];
            }
            $apiKey = $saved['ai']['api_key'] ?? '';
        }

        // Only Google Gemini is supported as primary provider; others return a clear message
        $supported = ['', 'google'];
        if (!in_array($provider, $supported, true)) {
            $this->response->json([
                'status'  => 'error',
                'message' => ucfirst($provider) . ' provider is not yet integrated — contact StratFlow support.',
            ]);
            return;
        }

        // Use the platform key if none provided
        if ($apiKey === '') {
            $apiKey = $this->config['gemini_api_key'] ?? '';
        }

        $resolvedModel = $model ?: 'gemini-3-flash-preview';
        try {
            $config = $this->config;
            $config['gemini']['api_key'] = $apiKey ?: ($config['gemini']['api_key'] ?? '');
            $config['gemini']['model']   = $resolvedModel;
            $gemini = new \StratFlow\Services\GeminiService($config);
            $gemini->generate('Reply with exactly one word: OK', '');
            $this->response->json(['status' => 'ok', 'message' => 'Connected to ' . $resolvedModel]);
        } catch (\Throwable $e) {
            $this->response->json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Instantiate a StripeService using the app configuration.
     */
    private function makeStripe(): StripeService
    {
        return new StripeService($this->config['stripe']);
    }
}
