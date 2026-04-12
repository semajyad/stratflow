<?php
/**
 * Sounding Board Modal (Partial)
 *
 * Full-screen overlay modal for configuring and displaying AI persona
 * evaluations. Included once in the app layout; shown/hidden via JS.
 */
?>
<div class="modal-overlay sounding-board-modal hidden" id="sounding-board-modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3>Strategic Sounding Board</h3>
            <button class="modal-close js-close-sounding-board" type="button">&times;</button>
        </div>

        <!-- Configuration Form -->
        <div class="sb-config" id="sb-config">
            <div class="form-group">
                <label>Panel Type</label>
                <select id="sb-panel-type" class="form-control">
                    <option value="executive">Executive Panel (CEO, CFO, COO, CMO, Strategist)</option>
                    <option value="product_management">Product Management Panel (PM, PO, Architect, Dev)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Evaluation Level</label>
                <select id="sb-eval-level" class="form-control">
                    <option value="devils_advocate">Devil's Advocate &mdash; Constructive challenge</option>
                    <option value="red_teaming">Red Teaming &mdash; Adversarial critique</option>
                    <option value="gordon_ramsay">Gordon Ramsay &mdash; No holds barred</option>
                </select>
            </div>
            <button class="btn btn-primary js-run-sounding-board" id="sb-evaluate-btn" type="button">Evaluate</button>
        </div>

        <!-- Loading State -->
        <div class="sb-loading hidden" id="sb-loading">
            <p>Running AI evaluation... This may take a moment.</p>
            <div class="spinner"></div>
        </div>

        <!-- Results Container -->
        <div class="sb-results hidden" id="sb-results">
            <!-- Populated by JS -->
        </div>
    </div>
</div>
