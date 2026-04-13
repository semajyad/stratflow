<?php
/**
 * Onboarding Wizard
 *
 * 4-step modal shown on first login (tracked via _onboarding_shown session flag).
 * Visible once per session; users can dismiss and never see it again.
 */
if (!empty($_SESSION['_onboarding_shown'])) {
    return;
}
$_SESSION['_onboarding_shown'] = true;

$userRole = $user['role'] ?? 'user';
$roleHint = match ($userRole) {
    'viewer' => 'As a viewer, you can browse all projects and track progress without making changes.',
    'project_manager' => 'As a project manager, you\'ll create and manage projects through the entire workflow.',
    'org_admin' => 'As an organisation admin, you can manage users, teams, and integrations alongside the core workflow.',
    'developer' => 'As a developer, your primary workspace is personal access tokens and API-driven access to StratFlow data.',
    'superadmin' => 'As a superadmin, you have full access including cross-organisation management.',
    default => 'You\'ll work through 8 steps from strategy document to prioritised Jira backlog.',
};
?>

<div id="onboarding-wizard" class="modal-overlay onboarding-modal">
    <div class="card onboarding-card">
        <div class="card-body onboarding-card-body">
            <div id="onboarding-step-1" class="onboarding-step">
                <div class="onboarding-hero">
                    <div class="onboarding-hero-icon">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5">
                            <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
                        </svg>
                    </div>
                    <h2 class="onboarding-title onboarding-title--welcome">Welcome to StratFlow</h2>
                    <p class="text-muted onboarding-copy onboarding-copy--intro">
                        Turn strategy documents into a prioritised, AI-ready engineering roadmap in minutes.
                    </p>
                </div>
                <div class="onboarding-role-card">
                    <p class="onboarding-role-copy">
                        <strong>Your role: <?= htmlspecialchars(ucwords(str_replace('_', ' ', $userRole))) ?></strong><br>
                        <?= htmlspecialchars($roleHint) ?>
                    </p>
                </div>
            </div>

            <div id="onboarding-step-2" class="onboarding-step hidden">
                <h2 class="onboarding-title">The 8-step workflow</h2>
                <p class="text-muted onboarding-copy onboarding-copy--section">
                    Each step builds on the previous one. You can always jump between steps using the sidebar or stepper.
                </p>
                <div class="onboarding-list">
                    <div class="onboarding-list-item"><span class="badge badge-primary onboarding-step-badge">1</span> <strong>Upload</strong> - PDF/DOCX/PPTX strategy documents</div>
                    <div class="onboarding-list-item"><span class="badge badge-primary onboarding-step-badge">2</span> <strong>Roadmap</strong> - AI generates visual strategy map</div>
                    <div class="onboarding-list-item"><span class="badge badge-primary onboarding-step-badge">3</span> <strong>Work Items</strong> - AI creates High Level items</div>
                    <div class="onboarding-list-item"><span class="badge badge-primary onboarding-step-badge">4</span> <strong>Prioritise</strong> - Score with RICE or WSJF</div>
                    <div class="onboarding-list-item"><span class="badge badge-primary onboarding-step-badge">5</span> <strong>Risks</strong> - AI identifies and scores risks</div>
                    <div class="onboarding-list-item"><span class="badge badge-primary onboarding-step-badge">6</span> <strong>Stories</strong> - Decompose to ~3-day user stories</div>
                    <div class="onboarding-list-item"><span class="badge badge-primary onboarding-step-badge">7</span> <strong>Sprints</strong> - Allocate stories to team sprints</div>
                    <div class="onboarding-list-item"><span class="badge badge-primary onboarding-step-badge">8</span> <strong>Governance</strong> - Detect strategic drift over time</div>
                </div>
            </div>

            <div id="onboarding-step-3" class="onboarding-step hidden">
                <h2 class="onboarding-title">Bidirectional Jira sync</h2>
                <p class="text-muted onboarding-copy onboarding-copy--jira">
                    StratFlow connects to Jira Cloud so your strategy flows straight into your team's execution pipeline:
                </p>
                <ul class="onboarding-jira-list">
                    <li><strong>High Level Items</strong> - Work items push as Jira Epics</li>
                    <li><strong>Stories</strong> - User stories push as Jira Stories with points</li>
                    <li><strong>Risks</strong> - Risks push as Jira Risk issues with likelihood/impact</li>
                    <li><strong>Sprints</strong> - Sprints create on your chosen team board</li>
                    <li><strong>Goals</strong> - OKRs sync to Atlassian Goals</li>
                    <li><strong>Pull</strong> - Changes made in Jira flow back to StratFlow</li>
                </ul>
                <p class="text-muted onboarding-copy onboarding-copy--small">
                    Jira integration is configured by your administrator. Once connected, you'll see a "Sync to Jira" button on every workflow page.
                </p>
            </div>

            <div id="onboarding-step-4" class="onboarding-step hidden">
                <div class="onboarding-finish">
                    <div class="onboarding-finish-icon">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                    </div>
                    <h2 class="onboarding-title">You're ready to go</h2>
                    <p class="text-muted onboarding-copy onboarding-copy--finish">
                        Create your first project from the home page, or open an existing one. StratFlow will guide you through each step - the stepper at the top of every page shows your progress.
                    </p>
                    <p class="text-muted onboarding-copy onboarding-copy--tiny">
                        Need to see this again? You can dismiss now - the workflow stepper and contextual help will guide you along the way.
                    </p>
                </div>
            </div>

            <div class="onboarding-nav">
                <div id="onboarding-step-indicator" class="onboarding-step-indicator">
                    <span class="onboarding-dot onboarding-dot--active"></span>
                    <span class="onboarding-dot"></span>
                    <span class="onboarding-dot"></span>
                    <span class="onboarding-dot"></span>
                </div>
                <div class="flex gap-2">
                    <button type="button" id="onboarding-skip" class="btn btn-secondary btn-sm js-onboarding-skip">Skip</button>
                    <button type="button" id="onboarding-next" class="btn btn-primary js-onboarding-next">Next &rarr;</button>
                </div>
            </div>
        </div>
    </div>
</div>
