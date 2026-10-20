<?php

namespace Eponymos\Admin;

class Admin
{
    /** WordPress hook suffixes keyed by page slug. */
    private array $hooks = [];

    public function register(): void
    {
        add_action('admin_menu',            [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);

    }

    public static function deactivation_prompt_static(): void
    {
        (new self())->deactivation_prompt();
    }

    public function deactivation_prompt(): void
    {
        if (!current_user_can('activate_plugins')) return;
        $plugin = plugin_basename(EPONYMOS_FILE);
        $nonce = wp_create_nonce('eponymos_deactivate');
        $ajax_url = admin_url('admin-ajax.php');
        echo '<script>(function(){document.addEventListener("click",function(e){var link=e.target.closest("a");if(!link||link.classList.contains("eponymos-deactivate-bound")||link.href.indexOf("action=deactivate")===-1||link.href.indexOf(encodeURIComponent(\'' . esc_js($plugin) . '\'))===-1)return;link.classList.add("eponymos-deactivate-bound");var choice=window.confirm("Deactivate Eponymos and delete ALL plugin data, including questionnaires, submissions, library data, and methodology?\\n\\nChoose Cancel to keep the data.");if(choice){e.preventDefault();var data=new FormData();data.append("action","eponymos_delete_data");data.append("nonce",\'' . esc_js($nonce) . '\');fetch(\'' . esc_url_raw($ajax_url) . '\',{method:"POST",credentials:"same-origin",body:data}).then(function(){window.location.href=link.href;});}});})();</script>';
    }

    // ── Menu ────────────────────────────────────────────────────────────────

    public function menu(): void
    {
        $this->hooks['builder'] = add_menu_page(
            'Eponymos',
            'Eponymos',
            'manage_options',
            'eponymos',
            [$this, 'page_builder'],
            'dashicons-calculator',
            30
        );

        $this->hooks['builder_sub'] = add_submenu_page(
            'eponymos',
            'Questionnaire Builder',
            'Builder',
            'manage_options',
            'eponymos',
            [$this, 'page_builder']
        );

        $this->hooks['question_library'] = add_submenu_page(
            'eponymos',
            'Question Library — Eponymos',
            'Question Library',
            'manage_options',
            'eponymos-question-library',
            [$this, 'page_question_library']
        );

        $this->hooks['option_library'] = add_submenu_page(
            'eponymos',
            'Option Library — Eponymos',
            'Option Library',
            'manage_options',
            'eponymos-option-library',
            [$this, 'page_option_library']
        );

        $this->hooks['submissions'] = add_submenu_page(
            'eponymos',
            'Submissions — Eponymos',
            'Submissions',
            'manage_options',
            'eponymos-submissions',
            [$this, 'page_submissions']
        );

        $this->hooks['methodology'] = (new MethodologyPage())->register();
        $this->hooks['samples'] = (new SamplePage())->register();
    }

    // ── Assets ───────────────────────────────────────────────────────────────

    public function assets(string $hook): void
    {
        $page = $this->current_page($hook);
        if (!$page) return;

        wp_enqueue_style(
            'eponymos-admin',
            EPONYMOS_URL . 'assets/css/admin.css',
            [],
            EPONYMOS_VERSION
        );

        $endpoint = rest_url('eponymos/v1/admin');
        $nonce    = wp_create_nonce('wp_rest');

        if ($page === 'builder') {
            wp_enqueue_script(
                'eponymos-admin',
                EPONYMOS_URL . 'assets/js/admin.js',
                ['jquery'],
                EPONYMOS_VERSION,
                true
            );
            wp_localize_script('eponymos-admin', 'EponymosAdmin', [
                'endpoint'          => $endpoint,
                'nonce'             => $nonce,
                'types'             => ['single_choice', 'multiple_choice', 'text', 'textarea', 'number', 'email', 'yes_no'],
                'questionnaires'    => $this->questionnaires(),
                'allow_key_editing' => (bool) get_option('eponymos_allow_key_editing', false),
            ]);
        }

        if ($page === 'question_library' || $page === 'option_library') {
            wp_enqueue_script(
                'eponymos-library',
                EPONYMOS_URL . 'assets/js/library.js',
                ['jquery'],
                filemtime(EPONYMOS_DIR . 'assets/js/library.js') ?: EPONYMOS_VERSION,
                true
            );
            wp_localize_script('eponymos-library', 'EponymosAdmin', [
                'endpoint'          => $endpoint,
                'nonce'             => $nonce,
                'types'             => ['single_choice', 'multiple_choice', 'text', 'textarea', 'number', 'email', 'yes_no'],
                'allow_key_editing' => (bool) get_option('eponymos_allow_key_editing', false),
                'page'              => $page,
            ]);
        }

        if ($page === 'submissions') {
            wp_enqueue_style(
                'eponymos-submissions',
                EPONYMOS_URL . 'assets/css/submissions.css',
                [],
                EPONYMOS_VERSION
            );
            wp_enqueue_script(
                'eponymos-submissions',
                EPONYMOS_URL . 'assets/js/submissions.js',
                ['jquery'],
                EPONYMOS_VERSION,
                true
            );
            wp_localize_script('eponymos-submissions', 'EponymosAdmin', [
                'endpoint'       => $endpoint,
                'nonce'          => $nonce,
                'questionnaires' => $this->questionnaires(),
                'restBase'       => rest_url(),
                'previewNonce'   => wp_create_nonce('eponymos_preview_report'),
            ]);
        }
    }

    /** Return a short page key for the current hook, or null if not ours. */
    private function current_page(string $hook): ?string
    {
        $map = [
            'toplevel_page_eponymos'                  => 'builder',
            'eponymos_page_eponymos-question-library' => 'question_library',
            'eponymos_page_eponymos-option-library'   => 'option_library',
            'eponymos_page_eponymos-submissions'      => 'submissions',
            'eponymos_page_eponymos-methodology'       => 'methodology',
            'eponymos_page_eponymos-settings'          => 'settings',
                        'eponymos_page_eponymos-samples'          => 'samples',
        ];
        return $map[$hook] ?? null;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function questionnaires(): array
    {
        static $items = null;
        if ($items === null) {
            $items = (new \Eponymos\Questionnaire\Repository())->admin_questionnaires();
        }
        return $items;
    }

    private function cap_check(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'eponymos'));
        }
    }

    private function render(string $template): void
    {
        $file = EPONYMOS_DIR . 'templates/admin/' . $template . '.php';
        if (is_readable($file)) include $file;
    }

    // ── Page callbacks ────────────────────────────────────────────────────────

    public function page_builder(): void
    {
        $this->cap_check();
        $this->render('builder');
    }

    public function page_question_library(): void
    {
        $this->cap_check();
        $this->render('question-library');
    }

    public function page_option_library(): void
    {
        $this->cap_check();
        $this->render('option-library');
    }

    public function page_submissions(): void
    {
        $this->cap_check();
        $questionnaires = $this->questionnaires();
        $this->render('submissions');
    }
}
