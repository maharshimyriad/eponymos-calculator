<?php defined('ABSPATH') || exit; ?>
<div class="wrap eponymos-admin" id="eponymos-question-library-page">

    <h1>
        <span class="dashicons dashicons-book-alt" style="font-size:22px;width:22px;height:22px;color:#2271b1"></span>
        <?php esc_html_e('Question Library', 'eponymos'); ?>
    </h1>

    <div class="eponymos-notice" role="status" aria-live="polite"></div>

    <!-- Shared modal -->
    <div class="eponymos-modal" data-builder-modal hidden>
        <div class="eponymos-modal-backdrop" data-modal-close></div>
        <div class="eponymos-modal-card" role="dialog" aria-modal="true">
            <button type="button" class="eponymos-modal-close" data-modal-close aria-label="<?php esc_attr_e('Close', 'eponymos'); ?>">&times;</button>
            <h2 data-modal-title></h2>
            <form data-builder-form></form>
        </div>
    </div>

    <div class="eponymos-library">
        <div class="epo-lib-toolbar">
            <h2><?php esc_html_e('Reusable questions', 'eponymos'); ?></h2>
            <p class="description" style="margin:0 0 10px">Create the complete question here, including the exact question text shown to users. Questionnaires use this library definition.</p>
            <button class="button button-primary" data-library-new-question>
                + <?php esc_html_e('New question', 'eponymos'); ?>
            </button>
        </div>
        <div data-library-questions>
            <p style="color:#646970;font-size:13px"><?php esc_html_e('Loading…', 'eponymos'); ?></p>
        </div>
    </div>

</div>
