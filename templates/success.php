<?php
/**
 * Success Template
 *
 * Shown after a successful Stripe checkout. Confirms the subscription
 * is active and provides next-step navigation links.
 */
?>

<section class="success-section">
    <div class="success-card">

        <div class="success-icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                 width="72" height="72">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="9 12 11 14 15 10"/>
            </svg>
        </div>

        <h1 class="success-heading">Thank You!</h1>
        <p class="success-subheading">Your subscription is now active.</p>
        <p class="success-body">
            We're thrilled to have you on board. Check your email for login credentials
            and next steps to get started with StratFlow.
        </p>

        <div class="success-next-steps">
            <h3>What happens next?</h3>
            <ol>
                <li>Check your email for your temporary password</li>
                <li>Log in and create your first project</li>
                <li>Upload a strategy document or paste meeting notes</li>
                <li>Let AI generate your roadmap</li>
            </ol>
        </div>

        <a href="/login" class="btn btn-primary btn-lg success-cta">Access StratFlow</a>

        <div class="success-links">
            <p>Explore our other products:</p>
            <div class="success-links-row">
                <a href="#assessments" class="btn btn-secondary btn-sm">Assessments</a>
                <a href="#trainings" class="btn btn-secondary btn-sm">Trainings</a>
            </div>
        </div>

    </div>
</section>
