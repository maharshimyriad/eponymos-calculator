<?php defined('ABSPATH') || exit; ?>
<div class="eponymos-questionnaire" data-eponymos>
    <?php if (!empty($testModeLabel)): ?>
        <div class="eponymos-test-mode-banner" role="status"><?php echo esc_html($testModeLabel); ?></div>
    <?php endif; ?>
    <div class="eponymos-questionnaire-static-text">
        <p><?php echo !empty($testModeLabel) ? esc_html__('Administrator test run', 'eponymos') : 'Build your Eponymos™ plan'; ?></p>
        <span>Answer a few questions to get your personalised estimate.</span>
    </div>
    <div class="eponymos-estimate-title">
        <h2><?php echo !empty($testModeLabel) ? esc_html__('Test estimate', 'eponymos') : 'Build your Eponymos™ plan'; ?></h2>
        <p>Your estimate is ready!</p>
    </div>
    <!-- Progress / stage indicator -->
    <div class="eponymos-progress" aria-live="polite" role="status"></div>

    <!-- Error display -->
    <div class="eponymos-error" role="alert" aria-live="assertive"></div>

    <!-- Question screen -->
    <div class="eponymos-screen eponymos-screen--questions" hidden>
        <div class="eponymos-stage"></div>
        <div class="eponymos-actions">
            <button type="button" class="eponymos-btn eponymos-btn--ghost eponymos-stage-one-button" data-stage-one hidden><?php esc_html_e('Back to Stage 1', 'eponymos'); ?></button>
            <button type="button" class="eponymos-btn eponymos-btn--ghost eponymos-back-btn" data-back><?php esc_html_e('Back', 'eponymos'); ?></button>
            <button type="button" class="eponymos-btn eponymos-btn--primary" data-next><?php esc_html_e('Continue', 'eponymos'); ?></button>
        </div>
    </div>

    <!-- Email gate screen -->
    <div class="eponymos-screen eponymos-screen--email" hidden>
        <div class="eponymos-stage"></div>
        <div class="eponymos-actions">
            <button type="button" class="eponymos-btn eponymos-btn--ghost" data-back><?php esc_html_e('Back', 'eponymos'); ?></button>
        </div>
    </div>

    <!-- Loading screen -->
    <div class="eponymos-screen eponymos-screen--loading" hidden>
        <div class="eponymos-loading">
            <div class="eponymos-spinner" aria-hidden="true"></div>
            <div class="eponymos-loading-steps" data-loading-steps>
                <p class="eponymos-loading-step is-active" data-loading-step></p>
            </div>
        </div>
    </div>

    <!-- Stage result screen -->
    <div class="eponymos-screen eponymos-screen--result" hidden>
        <div class="eponymos-result"></div>
    </div>
    <div class="eponymos-report-cta-slot"></div>

</div>
<div class="eponymos-question-art-layer" data-eponymos-art aria-hidden="true">
    <div class="eponymos-question-art eponymos-question-art--left"><img src="https://eponymos.com/wp-content/uploads/2026/09/pr-female-character-yellow-jacket.webp" alt=""></div>
    <div class="eponymos-question-art eponymos-question-art--right"><img src="https://eponymos.com/wp-content/uploads/2026/09/pr-male-character-brown-cap.webp" alt=""></div>
</div>

