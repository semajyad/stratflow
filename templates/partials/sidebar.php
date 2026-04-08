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
        <a href="#" class="nav-link disabled" title="Coming Soon">AI Execution</a>
    </nav>
</aside>
