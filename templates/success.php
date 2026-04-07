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
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                 aria-hidden="true" width="64" height="64">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="9 12 11 14 15 10"/>
            </svg>
        </div>

        <h1 class="success-heading">Thank You!</h1>
        <p class="success-subheading">Your subscription is now active.</p>
        <p class="success-body">
            We're thrilled to have you on board. You'll receive a confirmation email shortly.
        </p>

        <div class="success-links">
            <h2 class="success-links__heading">Explore Our Other Products</h2>
            <ul class="success-links__list">
                <li>
                    <a href="#assessments" class="success-links__link">
                        Assessments
                        <span class="success-links__arrow">&rarr;</span>
                    </a>
                </li>
                <li>
                    <a href="#trainings" class="success-links__link">
                        Trainings
                        <span class="success-links__arrow">&rarr;</span>
                    </a>
                </li>
            </ul>
        </div>

        <a href="/login" class="btn btn-primary success-cta">Access StratFlow</a>

    </div>
</section>
