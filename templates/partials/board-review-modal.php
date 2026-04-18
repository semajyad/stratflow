<?php
/**
 * Board Review Modal (Partial)
 *
 * Full-screen overlay for configuring and displaying virtual board reviews.
 * Included once in the app layout; shown/hidden via JS.
 * Uses "review level" terminology per product brief.
 */
?>
<div class="modal-overlay br-modal hidden" id="board-review-modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3>Virtual Board Review</h3>
            <button class="modal-close js-close-board-review" type="button">&times;</button>
        </div>

        <!-- Configuration Form -->
        <div class="br-config" id="br-config">
            <div class="form-group">
                <label for="br-review-level">Choose review level:</label>
                <select id="br-review-level" class="form-control">
                    <option value="devils_advocate">Devil's Advocate &mdash; Constructive challenge</option>
                    <option value="red_teaming">Red Teaming &mdash; Adversarial critique</option>
                    <option value="gordon_ramsay">Gordon Ramsay &mdash; No holds barred</option>
                </select>
            </div>
            <button class="btn btn-primary js-run-board-review" id="br-evaluate-btn" type="button">Run Board Review</button>
        </div>

        <!-- Loading State -->
        <div class="br-loading hidden" id="br-loading">
            <p>The board is deliberating&hellip; This may take a moment.</p>
            <div class="spinner"></div>
        </div>

        <!-- Results Container -->
        <div class="br-results hidden" id="br-results">
            <div class="br-conversation" id="br-conversation">
                <!-- Populated by JS -->
            </div>
            <div class="br-recommendation" id="br-recommendation">
                <!-- Populated by JS -->
            </div>
            <div class="br-actions" id="br-actions">
                <button class="btn btn-primary js-accept-board-review" id="br-accept-btn" type="button">Accept &amp; Apply Changes</button>
                <button class="btn btn-secondary js-reject-board-review" id="br-reject-btn" type="button">Reject</button>
            </div>
        </div>

        <!-- Post-response State -->
        <div class="br-responded hidden" id="br-responded">
            <!-- Populated by JS with accepted/rejected confirmation -->
        </div>
    </div>
</div>
