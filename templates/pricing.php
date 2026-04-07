<?php
/**
 * Pricing Template
 *
 * Displays two pricing tier cards side by side. Each card contains a feature
 * list and a form that POSTs to /checkout with the appropriate Stripe price_id.
 */
?>

<section class="pricing-section">
    <div class="pricing-header">
        <h1>Simple, Transparent Pricing</h1>
        <p>Choose the plan that fits your team. No hidden fees.</p>
    </div>

    <div class="pricing-cards">

        <!-- StratFlow Product -->
        <div class="pricing-card">
            <div class="pricing-card-header">
                <h2 class="pricing-tier">StratFlow Product</h2>
                <div class="pricing-price">
                    <span class="pricing-amount">Contact us</span>
                </div>
                <p class="pricing-description">Everything your team needs to plan and execute strategy.</p>
            </div>

            <ul class="pricing-features">
                <li>Strategy Diagram Generation</li>
                <li>AI-Powered Work Item Creation</li>
                <li>Drag-and-Drop Prioritisation</li>
                <li>CSV/JSON Export</li>
                <li>5 User Licence</li>
                <li>1 Year Access</li>
            </ul>

            <form method="POST" action="/checkout">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="price_id" value="<?= htmlspecialchars($price_product) ?>">
                <button type="submit" class="btn btn-primary btn-block">Get Started</button>
            </form>
        </div>

        <!-- StratFlow + Consultancy -->
        <div class="pricing-card pricing-card--featured">
            <div class="pricing-card-badge">Most Popular</div>
            <div class="pricing-card-header">
                <h2 class="pricing-tier">StratFlow + Consultancy</h2>
                <div class="pricing-price">
                    <span class="pricing-amount">Contact us</span>
                </div>
                <p class="pricing-description">Full product access plus hands-on expert facilitation.</p>
            </div>

            <ul class="pricing-features">
                <li>Strategy Diagram Generation</li>
                <li>AI-Powered Work Item Creation</li>
                <li>Drag-and-Drop Prioritisation</li>
                <li>CSV/JSON Export</li>
                <li>5 User Licence</li>
                <li>1 Year Access</li>
                <li class="pricing-features__extra">10 Hours Consultancy &amp; Facilitation</li>
                <li class="pricing-features__extra">Priority Support</li>
            </ul>

            <form method="POST" action="/checkout">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="price_id" value="<?= htmlspecialchars($price_consultancy) ?>">
                <button type="submit" class="btn btn-primary btn-block">Get Started</button>
            </form>
        </div>

    </div>
</section>
