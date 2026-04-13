/**
 * Superadmin Personas Page Logic
 * 
 * Handles modal toggling for critical review level prompts.
 * Accordion toggling is handled globally by app.js.
 */
(function() {
    const levelModal = document.getElementById('level-prompts-modal');
    if (!levelModal) return;

    // Open level modal
    document.querySelectorAll('.js-open-level-modal').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            levelModal.style.display = 'flex';
        });
    });

    // Close modal
    document.querySelectorAll('.js-close-modal').forEach(btn => {
        btn.addEventListener('click', function() {
            const modalId = this.getAttribute('data-modal') || 'level-prompts-modal';
            const target = document.getElementById(modalId);
            if (target) target.style.display = 'none';
        });
    });

    // Close on overlay click
    levelModal.addEventListener('click', (e) => {
        if (e.target === levelModal) levelModal.style.display = 'none';
    });

    // Close on escape
    window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') levelModal.style.display = 'none';
    });
})();
