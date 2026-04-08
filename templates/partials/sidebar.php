<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <a href="/app/home">StratFlow</a>
    </div>
    <nav class="sidebar-nav">
        <a href="/app/home" class="nav-link <?= ($active_page ?? '') === 'home' ? 'active' : '' ?>">
            Home
        </a>
        <a href="/app/upload" class="nav-link <?= ($active_page ?? '') === 'upload' ? 'active' : '' ?>">
            Document Upload
        </a>
        <a href="/app/diagram" class="nav-link <?= ($active_page ?? '') === 'diagram' ? 'active' : '' ?>">
            Strategy Roadmap
        </a>
        <a href="/app/work-items" class="nav-link <?= ($active_page ?? '') === 'work-items' ? 'active' : '' ?>">
            High-Level Work Items
        </a>
        <a href="/app/prioritisation" class="nav-link <?= ($active_page ?? '') === 'prioritisation' ? 'active' : '' ?>">
            Prioritisation
        </a>
        <a href="/app/risks" class="nav-link <?= ($active_page ?? '') === 'risks' ? 'active' : '' ?>">
            Risk Modelling
        </a>
        <a href="/app/user-stories" class="nav-link <?= ($active_page ?? '') === 'user-stories' ? 'active' : '' ?>">
            User Stories
        </a>
        <a href="/app/sprints" class="nav-link <?= ($active_page ?? '') === 'sprints' ? 'active' : '' ?>">Sprint Allocation</a>

        <?php if (in_array($user['role'] ?? '', ['org_admin', 'superadmin'])): ?>
            <hr class="sidebar-divider">
            <a href="/app/admin" class="nav-link <?= ($active_page ?? '') === 'admin' ? 'active' : '' ?>">
                &#9881; Administration
            </a>
        <?php endif; ?>

        <?php if (($user['role'] ?? '') === 'superadmin'): ?>
            <a href="/superadmin" class="nav-link <?= ($active_page ?? '') === 'superadmin' ? 'active' : '' ?>">
                &#128081; Superadmin
            </a>
        <?php endif; ?>
    </nav>
</aside>
