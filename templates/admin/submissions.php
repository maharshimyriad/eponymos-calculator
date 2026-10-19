<?php defined('ABSPATH') || exit; ?>
<div class="wrap eponymos-admin" id="eponymos-submissions-page">

    <h1>
        <span class="dashicons dashicons-email-alt" style="font-size:22px;width:22px;height:22px;color:#2271b1"></span>
        <?php esc_html_e('Submissions', 'eponymos'); ?>
    </h1>

    <div class="eponymos-submissions">

        <nav class="nav-tab-wrapper eponymos-submission-tabs" aria-label="Submission views">
            <button type="button" class="nav-tab nav-tab-active" data-submission-tab="submissions">Submissions</button>
            <button type="button" class="nav-tab" data-submission-tab="email-logs">Email delivery log</button>
            <button type="button" class="nav-tab" data-submission-tab="flow-logs">Technical flow log</button>
        </nav>

        <div class="submissions-toolbar">
            <input type="search" data-submission-search
                   placeholder="<?php esc_attr_e('Search by email or questionnaire…', 'eponymos'); ?>">
            <select data-submission-questionnaire>
                <option value=""><?php esc_html_e('All questionnaires', 'eponymos'); ?></option>
                <?php foreach ($questionnaires as $q) : ?>
                    <option value="<?php echo esc_attr($q['id']); ?>">
                        <?php echo esc_html($q['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select data-submission-status>
                <option value=""><?php esc_html_e('All statuses', 'eponymos'); ?></option>
                <option value="completed"><?php esc_html_e('Completed', 'eponymos'); ?></option>
            </select>
            <button class="button" data-submission-search-button>
                <?php esc_html_e('Search', 'eponymos'); ?>
            </button>
        </div>

        <div data-submission-notice role="status" aria-live="polite"></div>
        <div data-submission-list>
            <p style="color:#646970;font-size:13px"><?php esc_html_e('Loading submissions…', 'eponymos'); ?></p>
        </div>
        <div data-email-log-panel hidden></div>
        <div data-flow-log-panel hidden></div>
        <div data-submission-detail hidden></div>

    </div>
</div>
