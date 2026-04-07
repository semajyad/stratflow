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
        <a href="#" class="nav-link disabled" title="Coming Soon">Risk Modelling</a>
        <a href="#" class="nav-link disabled" title="Coming Soon">Technical Translation</a>
        <a href="#" class="nav-link disabled" title="Coming Soon">AI Execution</a>
    </nav>
</aside>
