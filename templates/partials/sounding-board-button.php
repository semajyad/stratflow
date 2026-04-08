<?php
/**
 * Sounding Board Trigger Button (Partial)
 *
 * Reusable button that opens the sounding board modal.
 * Only rendered when the org's subscription includes evaluation board access.
 *
 * Variables: $has_evaluation_board (bool), $active_page (string), $project (array)
 */
?>
<?php if (($has_evaluation_board ?? false)): ?>
<button class="btn btn-secondary sounding-board-trigger"
        data-screen="<?= htmlspecialchars($active_page ?? '') ?>"
        data-project-id="<?= $project['id'] ?? '' ?>">
    &#127919; Sounding Board
</button>
<?php endif; ?>
