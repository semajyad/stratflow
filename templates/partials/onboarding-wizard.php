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
$roleHint = match($userRole) {
    'viewer'          => 'As a viewer, you can browse all projects and track progress without making changes.',
    'project_manager' => 'As a project manager, you\'ll create and manage projects through the entire workflow.',
    'org_admin'       => 'As an organisation admin, you can manage users, teams, and integrations alongside the core workflow.',
    'developer'       => 'As a developer, your primary workspace is personal access tokens and API-driven access to StratFlow data.',
    'superadmin'      => 'As a superadmin, you have full access including cross-organisation management.',
    default           => 'You\'ll work through 8 steps from strategy document to prioritised Jira backlog.',
};
?>

<div id="onboarding-wizard" class="modal-overlay" style="position:fixed; inset:0; background:rgba(15,23,42,0.7); display:flex; align-items:center; justify-content:center; z-index:2000; backdrop-filter:blur(4px);">
    <div class="card" style="max-width:620px; width:90%; margin:0;">
        <div class="card-body" style="padding:2rem;">
            <!-- Step content -->
            <div id="onboarding-step-1" class="onboarding-step">
                <div style="text-align:center; margin-bottom:1.5rem;">
                    <div style="width:72px; height:72px; background:linear-gradient(135deg, #4f46e5, #6366f1); border-radius:16px; display:inline-flex; align-items:center; justify-content:center; margin-bottom:1rem;">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5">
                            <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
                        </svg>
                    </div>
                    <h2 style="margin:0 0 0.5rem; font-size:1.5rem;">Welcome to StratFlow</h2>
                    <p class="text-muted" style="margin:0; font-size:0.95rem;">
                        Turn strategy documents into a prioritised, AI-ready engineering roadmap in minutes.
                    </p>
                </div>
                <div style="background:#f8fafc; border-radius:10px; padding:1rem 1.25rem; margin-bottom:1.5rem;">
                    <p style="margin:0; font-size:0.875rem; color:var(--text-secondary);">
                        <strong>Your role: <?= htmlspecialchars(ucwords(str_replace('_', ' ', $userRole))) ?></strong><br>
                        <?= htmlspecialchars($roleHint) ?>
                    </p>
                </div>
            </div>

            <div id="onboarding-step-2" class="onboarding-step" style="display:none;">
                <h2 style="margin:0 0 1rem; font-size:1.35rem;">The 8-step workflow</h2>
                <p class="text-muted" style="font-size:0.9rem; margin-bottom:1.25rem;">
                    Each step builds on the previous one. You can always jump between steps using the sidebar or stepper.
                </p>
                <div style="display:flex; flex-direction:column; gap:0.5rem; font-size:0.875rem;">
                    <div style="display:flex; align-items:center; gap:0.75rem;"><span class="badge badge-primary" style="width:24px; height:24px; padding:0; display:inline-flex; align-items:center; justify-content:center;">1</span> <strong>Upload</strong> — PDF/DOCX/PPTX strategy documents</div>
                    <div style="display:flex; align-items:center; gap:0.75rem;"><span class="badge badge-primary" style="width:24px; height:24px; padding:0; display:inline-flex; align-items:center; justify-content:center;">2</span> <strong>Roadmap</strong> — AI generates visual strategy map</div>
                    <div style="display:flex; align-items:center; gap:0.75rem;"><span class="badge badge-primary" style="width:24px; height:24px; padding:0; display:inline-flex; align-items:center; justify-content:center;">3</span> <strong>Work Items</strong> — AI creates high-level epics</div>
                    <div style="display:flex; align-items:center; gap:0.75rem;"><span class="badge badge-primary" style="width:24px; height:24px; padding:0; display:inline-flex; align-items:center; justify-content:center;">4</span> <strong>Prioritise</strong> — Score with RICE or WSJF</div>
                    <div style="display:flex; align-items:center; gap:0.75rem;"><span class="badge badge-primary" style="width:24px; height:24px; padding:0; display:inline-flex; align-items:center; justify-content:center;">5</span> <strong>Risks</strong> — AI identifies and scores risks</div>
                    <div style="display:flex; align-items:center; gap:0.75rem;"><span class="badge badge-primary" style="width:24px; height:24px; padding:0; display:inline-flex; align-items:center; justify-content:center;">6</span> <strong>Stories</strong> — Decompose to ~3-day user stories</div>
                    <div style="display:flex; align-items:center; gap:0.75rem;"><span class="badge badge-primary" style="width:24px; height:24px; padding:0; display:inline-flex; align-items:center; justify-content:center;">7</span> <strong>Sprints</strong> — Allocate stories to team sprints</div>
                    <div style="display:flex; align-items:center; gap:0.75rem;"><span class="badge badge-primary" style="width:24px; height:24px; padding:0; display:inline-flex; align-items:center; justify-content:center;">8</span> <strong>Governance</strong> — Detect strategic drift over time</div>
                </div>
            </div>

            <div id="onboarding-step-3" class="onboarding-step" style="display:none;">
                <h2 style="margin:0 0 1rem; font-size:1.35rem;">Bidirectional Jira sync</h2>
                <p class="text-muted" style="font-size:0.9rem; margin-bottom:1rem;">
                    StratFlow connects to Jira Cloud so your strategy flows straight into your team's execution pipeline:
                </p>
                <ul style="margin:0 0 1rem; padding-left:1.25rem; font-size:0.875rem; line-height:1.8;">
                    <li><strong>Epics</strong> — Work items push as Jira Epics</li>
                    <li><strong>Stories</strong> — User stories push as Jira Stories with points</li>
                    <li><strong>Risks</strong> — Risks push as Jira Risk issues with likelihood/impact</li>
                    <li><strong>Sprints</strong> — Sprints create on your chosen team board</li>
                    <li><strong>Goals</strong> — OKRs sync to Atlassian Goals</li>
                    <li><strong>Pull</strong> — Changes made in Jira flow back to StratFlow</li>
                </ul>
                <p class="text-muted" style="font-size:0.85rem; margin:0;">
                    Jira integration is configured by your administrator. Once connected, you'll see a "Sync to Jira" button on every workflow page.
                </p>
            </div>

            <div id="onboarding-step-4" class="onboarding-step" style="display:none;">
                <div style="text-align:center;">
                    <div style="width:64px; height:64px; background:#d1fae5; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; margin-bottom:1rem;">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                    </div>
                    <h2 style="margin:0 0 0.5rem; font-size:1.35rem;">You're ready to go</h2>
                    <p class="text-muted" style="font-size:0.9rem; margin-bottom:1.25rem; max-width:440px; margin-left:auto; margin-right:auto;">
                        Create your first project from the home page, or open an existing one. StratFlow will guide you through each step — the stepper at the top of every page shows your progress.
                    </p>
                    <p class="text-muted" style="font-size:0.8rem; margin:0;">
                        Need to see this again? You can dismiss now — the workflow stepper and contextual help will guide you along the way.
                    </p>
                </div>
            </div>

            <!-- Navigation -->
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:2rem; padding-top:1.25rem; border-top:1px solid var(--border);">
                <div id="onboarding-step-indicator" style="display:flex; gap:0.4rem;">
                    <span class="onboarding-dot onboarding-dot--active" style="width:8px; height:8px; border-radius:50%; background:var(--primary);"></span>
                    <span class="onboarding-dot" style="width:8px; height:8px; border-radius:50%; background:var(--border);"></span>
                    <span class="onboarding-dot" style="width:8px; height:8px; border-radius:50%; background:var(--border);"></span>
                    <span class="onboarding-dot" style="width:8px; height:8px; border-radius:50%; background:var(--border);"></span>
                </div>
                <div class="flex gap-2">
                    <button type="button" id="onboarding-skip" class="btn btn-secondary btn-sm js-onboarding-skip">Skip</button>
                    <button type="button" id="onboarding-next" class="btn btn-primary js-onboarding-next">Next &rarr;</button>
                </div>
            </div>
        </div>
    </div>
</div>
