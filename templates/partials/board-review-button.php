<?php
/**
 * Board Review Trigger Button (Partial)
 *
 * Opens the virtual board review modal. Only rendered when the org's
 * subscription includes evaluation board access.
 *
 * Variables: $has_evaluation_board (bool), $project (array), $board_review_screen (string)
 */
?>
<?php if (($has_evaluation_board ?? false)): ?>
<button class="btn btn-secondary board-review-trigger"
        data-screen="<?= htmlspecialchars($board_review_screen ?? '') ?>"
        data-project-id="<?= (int) ($project['id'] ?? 0) ?>">
    &#127775; Board Review
</button>
<?php endif; ?>
