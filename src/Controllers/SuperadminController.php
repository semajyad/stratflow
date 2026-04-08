<?php
/**
 * SuperadminController
 *
 * Handles the ThreePoints superadmin area: cross-organisation management,
 * system default persona panels, and superadmin role assignment.
 *
 * All routes require 'auth' + 'superadmin' middleware (superadmin role only).
 */

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\Organisation;
use StratFlow\Models\PersonaMember;
use StratFlow\Models\PersonaPanel;
use StratFlow\Models\Subscription;
use StratFlow\Models\User;

class SuperadminController
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
     * Render the superadmin dashboard with system-wide counts.
     *
     * Shows total organisations, total users, and active subscriptions.
     */
    public function index(): void
    {
        $user = $this->auth->user();

        $orgCount = $this->countAll('organisations');
        $userCount = $this->countAll('users', 'is_active = 1');
        $subCount = $this->countAll('subscriptions', "status = 'active'");

        $this->response->render('superadmin/index', [
            'user'               => $user,
            'org_count'          => $orgCount,
            'user_count'         => $userCount,
            'subscription_count' => $subCount,
            'active_page'        => 'superadmin',
            'flash_message'      => $_SESSION['flash_message'] ?? null,
            'flash_error'        => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    // =========================================================================
    // ORGANISATION MANAGEMENT
    // =========================================================================

    /**
     * List all organisations with status, user count, and subscription info.
     */
    public function organisations(): void
    {
        $user = $this->auth->user();
        $orgs = Organisation::findAll($this->db);

        // Load subscription for each org
        $orgSubs = [];
        foreach ($orgs as $org) {
            $orgSubs[(int) $org['id']] = Subscription::findByOrgId($this->db, (int) $org['id']);
        }

        // Load all users for the assign-superadmin dropdown
        $allUsers = $this->getAllUsers();

        $this->response->render('superadmin/organisations', [
            'user'          => $user,
            'orgs'          => $orgs,
            'org_subs'      => $orgSubs,
            'all_users'     => $allUsers,
            'active_page'   => 'superadmin',
            'flash_message' => $_SESSION['flash_message'] ?? null,
            'flash_error'   => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Handle organisation actions: suspend, enable, or delete.
     *
     * @param string $id Organisation primary key (from route param)
     */
    public function updateOrg(string $id): void
    {
        $orgId  = (int) $id;
        $action = (string) $this->request->post('action', '');

        $org = Organisation::findById($this->db, $orgId);
        if (!$org) {
            $_SESSION['flash_error'] = 'Organisation not found.';
            $this->response->redirect('/superadmin/organisations');
            return;
        }

        switch ($action) {
            case 'suspend':
                Organisation::suspend($this->db, $orgId);
                $_SESSION['flash_message'] = 'Organisation "' . $org['name'] . '" suspended.';
                break;

            case 'enable':
                Organisation::enable($this->db, $orgId);
                $_SESSION['flash_message'] = 'Organisation "' . $org['name'] . '" enabled.';
                break;

            case 'delete':
                Organisation::delete($this->db, $orgId);
                $_SESSION['flash_message'] = 'Organisation "' . $org['name'] . '" deleted.';
                break;

            default:
                $_SESSION['flash_error'] = 'Unknown action.';
        }

        $this->response->redirect('/superadmin/organisations');
    }

    /**
     * Export all organisation data as a JSON download.
     *
     * @param string $id Organisation primary key (from route param)
     */
    public function exportOrg(string $id): void
    {
        $orgId = (int) $id;
        $data  = Organisation::exportData($this->db, $orgId);

        if (!$data) {
            $_SESSION['flash_error'] = 'Organisation not found.';
            $this->response->redirect('/superadmin/organisations');
            return;
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $filename = 'org_' . $orgId . '_export_' . date('Y-m-d') . '.json';

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    // =========================================================================
    // DEFAULT PERSONA MANAGEMENT
    // =========================================================================

    /**
     * Display and manage system default persona panels (org_id IS NULL).
     *
     * Seeds the default panels if none exist yet.
     */
    public function personas(): void
    {
        $user   = $this->auth->user();
        $panels = PersonaPanel::findDefaults($this->db);

        // Seed defaults if none exist
        if (empty($panels)) {
            $this->seedDefaultPersonas();
            $panels = PersonaPanel::findDefaults($this->db);
        }

        // Load members for each panel
        $panelMembers = [];
        foreach ($panels as $panel) {
            $panelMembers[(int) $panel['id']] = PersonaMember::findByPanelId($this->db, (int) $panel['id']);
        }

        $this->response->render('superadmin/personas', [
            'user'          => $user,
            'panels'        => $panels,
            'panel_members' => $panelMembers,
            'active_page'   => 'superadmin',
            'flash_message' => $_SESSION['flash_message'] ?? null,
            'flash_error'   => $_SESSION['flash_error']   ?? null,
        ], 'app');

        unset($_SESSION['flash_message'], $_SESSION['flash_error']);
    }

    /**
     * Save updated prompt descriptions for default persona members.
     *
     * Expects POST data keyed as member_{id} with new prompt_description values.
     */
    public function savePersona(): void
    {
        $post = $_POST;

        foreach ($post as $key => $value) {
            if (strpos($key, 'member_') === 0) {
                $memberId = (int) str_replace('member_', '', $key);
                if ($memberId > 0) {
                    PersonaMember::update($this->db, $memberId, [
                        'prompt_description' => trim((string) $value),
                    ]);
                }
            }
        }

        $_SESSION['flash_message'] = 'Default personas updated successfully.';
        $this->response->redirect('/superadmin/personas');
    }

    // =========================================================================
    // SUPERADMIN ROLE ASSIGNMENT
    // =========================================================================

    /**
     * Assign the superadmin role to a user.
     *
     * Expects user_id in POST data.
     */
    public function assignSuperadmin(): void
    {
        $userId = (int) $this->request->post('user_id', '0');

        if ($userId <= 0) {
            $_SESSION['flash_error'] = 'Please select a user.';
            $this->response->redirect('/superadmin/organisations');
            return;
        }

        $target = User::findById($this->db, $userId);
        if (!$target) {
            $_SESSION['flash_error'] = 'User not found.';
            $this->response->redirect('/superadmin/organisations');
            return;
        }

        User::update($this->db, $userId, ['role' => 'superadmin']);

        $_SESSION['flash_message'] = 'User "' . $target['full_name'] . '" is now a superadmin.';
        $this->response->redirect('/superadmin/organisations');
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Count all rows in a table, optionally with a WHERE clause.
     *
     * @param string      $table Table name
     * @param string|null $where Optional WHERE clause (without the WHERE keyword)
     * @return int               Row count
     */
    private function countAll(string $table, ?string $where = null): int
    {
        $sql = "SELECT COUNT(*) AS cnt FROM {$table}";
        if ($where) {
            $sql .= " WHERE {$where}";
        }
        $stmt = $this->db->query($sql);
        $row  = $stmt->fetch();
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Fetch all active users across all organisations.
     *
     * @return array Array of user rows
     */
    private function getAllUsers(): array
    {
        $stmt = $this->db->query(
            "SELECT u.*, o.name AS org_name
             FROM users u
             LEFT JOIN organisations o ON o.id = u.org_id
             WHERE u.is_active = 1
             ORDER BY u.full_name ASC"
        );
        return $stmt->fetchAll();
    }

    /**
     * Seed the default system persona panels and their members.
     *
     * Creates Executive and Product Management panels with predefined
     * role titles and prompt descriptions.
     */
    private function seedDefaultPersonas(): void
    {
        // Executive Panel
        $execId = PersonaPanel::create($this->db, [
            'panel_type' => 'executive',
            'name'       => 'Executive Panel',
        ]);

        $execMembers = [
            ['CEO',                           'You are a CEO focused on strategic vision, market positioning, and ROI'],
            ['CFO',                           'You are a CFO focused on financial viability, cost control, and risk management'],
            ['COO',                           'You are a COO focused on operational efficiency, resource allocation, and execution'],
            ['CMO',                           'You are a CMO focused on market fit, customer value, and competitive positioning'],
            ['Enterprise Business Strategist', 'You are a Business Strategist focused on long-term growth and competitive advantage'],
        ];

        foreach ($execMembers as [$role, $prompt]) {
            PersonaMember::create($this->db, [
                'panel_id'           => $execId,
                'role_title'         => $role,
                'prompt_description' => $prompt,
            ]);
        }

        // Product Management Panel
        $pmId = PersonaPanel::create($this->db, [
            'panel_type' => 'product_management',
            'name'       => 'Product Management Panel',
        ]);

        $pmMembers = [
            ['Agile Product Manager',  'You are a PM focused on backlog prioritisation, stakeholder alignment, and delivery velocity'],
            ['Product Owner',          'You are a PO focused on user value, acceptance criteria, and sprint goals'],
            ['Expert System Architect', 'You are an Architect focused on technical feasibility, scalability, and system design'],
            ['Senior Developer',       'You are a Senior Dev focused on implementation complexity, technical debt, and code quality'],
        ];

        foreach ($pmMembers as [$role, $prompt]) {
            PersonaMember::create($this->db, [
                'panel_id'           => $pmId,
                'role_title'         => $role,
                'prompt_description' => $prompt,
            ]);
        }
    }
}
