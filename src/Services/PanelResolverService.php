<?php

declare(strict_types=1);

namespace StratFlow\Services;

use StratFlow\Core\Database;
use StratFlow\Models\PersonaMember;
use StratFlow\Models\PersonaPanel;

class PanelResolverService
{
    // ===========================
    // DEFAULT PANEL DEFINITIONS
    // ===========================

    private const DEFAULT_PANELS = [
        'executive' => [
            'name'    => 'Executive Panel',
            'members' => [
                ['role_title' => 'CEO',                           'prompt_description' => 'You focus on overall strategic vision, market positioning, competitive advantage, and long-term value creation. Evaluate whether this aligns with organisational goals and sustainable growth.'],
                ['role_title' => 'CFO',                           'prompt_description' => 'You focus on financial viability, ROI, cost structures, budget implications, and resource allocation efficiency. Evaluate the financial soundness and risk-adjusted returns.'],
                ['role_title' => 'COO',                           'prompt_description' => 'You focus on operational feasibility, execution risk, process efficiency, scalability, and resource constraints. Evaluate whether this can be delivered practically.'],
                ['role_title' => 'CMO',                           'prompt_description' => 'You focus on market fit, customer value proposition, competitive differentiation, and go-to-market strategy. Evaluate the commercial viability and customer impact.'],
                ['role_title' => 'Enterprise Business Strategist','prompt_description' => 'You focus on strategic coherence, portfolio alignment, capability gaps, and transformation readiness. Evaluate how this fits the broader enterprise strategy.'],
            ],
        ],
        'product_management' => [
            'name'    => 'Product Management Panel',
            'members' => [
                ['role_title' => 'Agile Product Manager',   'prompt_description' => 'You focus on backlog prioritisation, stakeholder value, iterative delivery, and outcome-driven planning. Evaluate whether the right things are being built in the right order.'],
                ['role_title' => 'Product Owner',           'prompt_description' => 'You focus on user needs, acceptance criteria clarity, story completeness, and sprint readiness. Evaluate whether requirements are well-defined and deliverable.'],
                ['role_title' => 'Expert System Architect', 'prompt_description' => 'You focus on technical architecture, system design, integration complexity, technical debt, and non-functional requirements. Evaluate the technical soundness and scalability.'],
                ['role_title' => 'Senior Developer',        'prompt_description' => 'You focus on implementation complexity, code quality, testing strategy, and delivery estimates. Evaluate whether the work is practically implementable and well-scoped.'],
            ],
        ],
    ];

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ===========================
    // PUBLIC INTERFACE
    // ===========================

    /**
     * Resolve the best-matching panel for an org + type (org-specific → system default → seeded).
     *
     * @param  int    $orgId     Organisation ID
     * @param  string $panelType 'executive' or 'product_management'
     * @return array             Panel row
     */
    public function resolve(int $orgId, string $panelType): array
    {
        $panel = $this->findExisting($orgId, $panelType);
        if ($panel !== null) {
            return $panel;
        }
        return $this->seedDefault($panelType);
    }

    /**
     * Resolve panel and return both the panel row and its members array.
     *
     * @param  int    $orgId     Organisation ID
     * @param  string $panelType 'executive' or 'product_management'
     * @return array             [panel, members]
     */
    public function resolveWithMembers(int $orgId, string $panelType): array
    {
        $panel   = $this->resolve($orgId, $panelType);
        $members = PersonaMember::findByPanelId($this->db, (int) $panel['id']);
        return [$panel, $members];
    }

    // ===========================
    // HELPERS
    // ===========================

    /**
     * Search for an existing panel row (org-specific first, then system defaults).
     */
    private function findExisting(int $orgId, string $panelType): ?array
    {
        foreach (PersonaPanel::findByOrgId($this->db, $orgId) as $panel) {
            if ($panel['panel_type'] === $panelType) {
                return $panel;
            }
        }
        foreach (PersonaPanel::findDefaults($this->db) as $panel) {
            if ($panel['panel_type'] === $panelType) {
                return $panel;
            }
        }
        return null;
    }

    /**
     * Create a system-default panel + members from the built-in definitions.
     */
    private function seedDefault(string $panelType): array
    {
        $definition = self::DEFAULT_PANELS[$panelType] ?? self::DEFAULT_PANELS['executive'];
        $panelId    = PersonaPanel::create($this->db, [
            'org_id'     => null,
            'panel_type' => $panelType,
            'name'       => $definition['name'],
        ]);
        foreach ($definition['members'] as $member) {
            PersonaMember::create($this->db, [
                'panel_id'           => $panelId,
                'role_title'         => $member['role_title'],
                'prompt_description' => $member['prompt_description'],
            ]);
        }
        return PersonaPanel::findById($this->db, $panelId);
    }
}
