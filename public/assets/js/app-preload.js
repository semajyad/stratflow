(function() {
    try {
        if (localStorage.getItem('stratflow.sidebarCollapsed') === '1') {
            document.documentElement.classList.add('sidebar-collapsed-preload');
        }
    } catch (error) {
        // Ignore storage access failures and keep the default expanded shell.
    }
})();
