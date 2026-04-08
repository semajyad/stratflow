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
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\Organisation;
use StratFlow\Models\Subscription;
use StratFlow\Models\Team;
use StratFlow\Models\TeamMember;
use StratFlow\Models\User;

class AdminController
{
    protected Request  $request;
    protected Response $response;
    protected Auth     $auth;
    protected Database $db;
    protected array    $config;

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

        $this->response->render('admin/index', [
            'user'          => $user,
            'user_count'    => $userCount,
            'seat_limit'    => $seatLimit,
            'team_count'    => count($teams),
            'subscription'  => $sub,
            'active_page'   => 'admin',
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

        $this->response->render('admin/users', [
            'user'          => $user,
            'users'         => $users,
            'seat_limit'    => $seatLimit,
            'user_count'    => $userCount,
            'active_page'   => 'admin',
            'flash_message' => $_SESSION['flash_message'] ?? null,
            'flash_error'   => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Create a new user in the organisation.
     *
     * Validates seat limit, email uniqueness, and password length.
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
        $password = (string) $this->request->post('password', '');
        $role     = (string) $this->request->post('role', 'user');

        // Validate
        if ($fullName === '' || $email === '' || $password === '') {
            $_SESSION['flash_error'] = 'All fields are required.';
            $this->response->redirect('/app/admin/users');
            return;
        }

        if (strlen($password) < 8) {
            $_SESSION['flash_error'] = 'Password must be at least 8 characters.';
            $this->response->redirect('/app/admin/users');
            return;
        }

        if (!in_array($role, ['user', 'org_admin'], true)) {
            $role = 'user';
        }

        // Check email uniqueness
        $existing = User::findByEmail($this->db, $email);
        if ($existing) {
            $_SESSION['flash_error'] = 'A user with that email already exists.';
            $this->response->redirect('/app/admin/users');
            return;
        }

        User::create($this->db, [
            'org_id'        => $orgId,
            'full_name'     => $fullName,
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => $role,
        ]);

        $_SESSION['flash_message'] = 'User "' . $fullName . '" created successfully.';
        $this->response->redirect('/app/admin/users');
    }

    /**
     * Update an existing user's details.
     *
     * Verifies the target user belongs to the same organisation.
     *
     * @param string $id User primary key (from route param)
     */
    public function updateUser(string $id): void
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

        if (!in_array($role, ['user', 'org_admin'], true)) {
            $role = 'user';
        }

        // Check email uniqueness (excluding current user)
        $existing = User::findByEmail($this->db, $email);
        if ($existing && (int) $existing['id'] !== $userId) {
            $_SESSION['flash_error'] = 'A user with that email already exists.';
            $this->response->redirect('/app/admin/users');
            return;
        }

        $data = [
            'full_name' => $fullName,
            'email'     => $email,
            'role'      => $role,
        ];

        if ($password !== '' && strlen($password) >= 8) {
            $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }

        User::update($this->db, $userId, $data);

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
    public function deleteUser(string $id): void
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
    public function updateTeam(string $id): void
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
    public function deleteTeam(string $id): void
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
                'agile_product_owner'          => 'Decompose the HL Items into user stories',
                'enterprise_business_strategist' => 'Analyse the provided file to a 3-paragraph summary',
            ],
            'hl_item_default_months'       => 2,
            'user_story_max_size'          => 13,
            'capacity_tripwire_percent'    => 20,
            'dependency_tripwire_enabled'  => true,
        ];
    }

    /**
     * Render the organisation settings page.
     *
     * Loads settings_json from the org row, merging with defaults for any missing keys.
     */
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

        $this->response->render('admin/settings', [
            'user'          => $user,
            'settings'      => $settings,
            'active_page'   => 'admin',
            'flash_message' => $_SESSION['flash_message'] ?? null,
            'flash_error'   => $_SESSION['flash_error']   ?? null,
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
            'user_story_max_size'         => (int) $this->request->post('user_story_max_size', '13'),
            'capacity_tripwire_percent'   => (int) $this->request->post('capacity_tripwire_percent', '20'),
            'dependency_tripwire_enabled' => $this->request->post('dependency_tripwire_enabled') === '1',
        ];

        Organisation::update($this->db, $orgId, [
            'settings_json' => json_encode($settings),
        ]);

        $_SESSION['flash_message'] = 'Settings saved successfully.';
        $this->response->redirect('/app/admin/settings');
    }
}
