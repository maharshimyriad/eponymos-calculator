<?php defined('ABSPATH') || exit; ?>
<div class="wrap eponymos-admin" id="eponymos-builder-page">

    <h1>
        <span class="dashicons dashicons-editor-ul" style="font-size:22px;width:22px;height:22px;color:#2271b1"></span>
        <?php esc_html_e('Questionnaire Builder', 'eponymos'); ?>
    </h1>

    <div class="eponymos-notice" role="status" aria-live="polite"></div>
    <p class="description">Questions are added from the Question Library. The library Label is shown here; edit the Label, question text, answer options, and other settings in Question Library.</p>

    <!-- Shared modal -->
    <div class="eponymos-modal" data-builder-modal hidden>
        <div class="eponymos-modal-backdrop" data-modal-close></div>
        <div class="eponymos-modal-card" role="dialog" aria-modal="true">
            <button type="button" class="eponymos-modal-close" data-modal-close aria-label="<?php esc_attr_e('Close', 'eponymos'); ?>">&times;</button>
            <h2 data-modal-title></h2>
            <form data-builder-form></form>
        </div>
    </div>

    <section class="eponymos-builder">

        <aside>
            <h2><?php esc_html_e('Questionnaires', 'eponymos'); ?></h2>
            <button class="button button-primary epo-sidebar-new" data-new-questionnaire>
                + <?php esc_html_e('New questionnaire', 'eponymos'); ?>
            </button>
            <div data-questionnaires>
                <p style="padding:10px 14px;color:#646970;font-size:13px"><?php esc_html_e('Loading…', 'eponymos'); ?></p>
            </div>
        </aside>

        <main>
            <div data-editor>
                <div class="epo-empty-state">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>
                    </svg>
                    <p><?php esc_html_e('Select a questionnaire to begin.', 'eponymos'); ?></p>
                </div>
            </div>
        </main>

    </section>
</div>
