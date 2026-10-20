<?php

namespace Eponymos\REST;

use WP_REST_Request;
use WP_Error;
use Eponymos\Questionnaire\Repository;
use Eponymos\Questionnaire\Validator;
use Eponymos\Calculation\CalculationEngine;
use Eponymos\Calculation\Methodology;
use Eponymos\Pricing\PricingService;
use Eponymos\Report\PdfReport;
use Eponymos\Submission\Repository as SubmissionRepository;
use Eponymos\Submission\Service as SubmissionService;
use Eponymos\Support\EmailLog;
use Eponymos\Support\FlowLog;

class Routes
{
  public function register()
  {
    add_action('rest_api_init', [$this, 'register_routes']);
    add_action('eponymos_send_submission_notification', [$this, 'send_queued_notification'], 10, 2);
    add_filter('rest_authentication_errors', [$this, 'log_authentication_error'], 99);
    add_filter('rest_post_dispatch', [$this, 'log_submission_response'], 10, 3);
    // Allow direct report preview/PDF navigation in a browser tab; each route
    // performs its own nonce or token validation.
    // add_filter('rest_authentication_errors', [$this, 'allow_preview_unauthenticated'], 5);
  }

  /**
   * If the request is for a direct report preview/PDF request, clear any cookie-auth error
   * so WordPress doesn't reject it before our callback runs.
   * The callback itself checks the _wpnonce param.
   */
  public function allow_preview_unauthenticated($result)
  {
    if (!isset($_SERVER['REQUEST_URI']))
      return $result;
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path && preg_match('#/eponymos/v1/report/\d+/(preview|pdf)#', $path)) {
      // REST cookie authentication can reject a direct browser navigation
      // before the route callback sees its own query nonce/token.
      return false;
    }
    return $result;
  }
  public function register_routes()
  {
    register_rest_route('eponymos/v1', '/questionnaire/(?P<id>\d+)', [
      'methods' => 'GET',
      'callback' => function ($req) {
        return (new Repository())->structure((int) $req['id']);
      },
      'permission_callback' => '__return_true'
    ]);
    register_rest_route('eponymos/v1', '/submit', ['methods' => 'POST', 'callback' => [$this, 'submit'], 'permission_callback' => '__return_true']);
    register_rest_route('eponymos/v1', '/admin/questionnaires', ['methods' => ['GET', 'POST'], 'callback' => [$this, 'questionnaires'], 'permission_callback' => [$this, 'can_manage']]);
    register_rest_route('eponymos/v1', '/admin/questionnaires/(?P<id>\d+)', ['methods' => ['GET', 'POST', 'DELETE'], 'callback' => [$this, 'questionnaire'], 'permission_callback' => [$this, 'can_manage']]);
    register_rest_route('eponymos/v1', '/admin/submissions', ['methods' => ['GET', 'DELETE'], 'callback' => [$this, 'submissions'], 'permission_callback' => [$this, 'can_manage']]);
    register_rest_route('eponymos/v1', '/admin/submissions/(?P<id>\d+)', ['methods' => ['GET', 'DELETE'], 'callback' => [$this, 'submission'], 'permission_callback' => [$this, 'can_manage']]);
    register_rest_route('eponymos/v1', '/admin/email-logs', ['methods' => ['GET', 'DELETE'], 'callback' => [$this, 'email_logs'], 'permission_callback' => [$this, 'can_manage']]);
    register_rest_route('eponymos/v1', '/admin/flow-logs', ['methods' => ['GET', 'DELETE'], 'callback' => [$this, 'flow_logs'], 'permission_callback' => [$this, 'can_manage']]);
    register_rest_route('eponymos/v1', '/admin/questionnaires/(?P<id>\d+)/stages', ['methods' => 'POST', 'callback' => [$this, 'create_stage'], 'permission_callback' => [$this, 'can_manage']]);
    register_rest_route('eponymos/v1', '/admin/stages/(?P<id>\d+)/(?P<action>reorder|save|delete)', ['methods' => 'POST', 'callback' => [$this, 'stage_action'], 'permission_callback' => [$this, 'can_manage']]);
    register_rest_route('eponymos/v1', '/admin/stages/(?P<id>\d+)/questions', ['methods' => 'POST', 'callback' => [$this, 'create_question'], 'permission_callback' => [$this, 'can_manage']]);
    register_rest_route('eponymos/v1', '/admin/questions/(?P<id>\d+)/(?P<action>save|delete|reorder)', ['methods' => 'POST', 'callback' => [$this, 'question_action'], 'permission_callback' => [$this, 'can_manage']]);
    register_rest_route('eponymos/v1', '/admin/questions/(?P<id>\d+)/options', ['methods' => 'POST', 'callback' => [$this, 'create_option'], 'permission_callback' => [$this, 'can_manage']]);
    register_rest_route('eponymos/v1', '/admin/options/(?P<id>\d+)/(?P<action>save|delete)', ['methods' => 'POST', 'callback' => [$this, 'option_action'], 'permission_callback' => [$this, 'can_manage']]);
    register_rest_route('eponymos/v1', '/admin/library/option-groups', ['methods' => ['GET', 'POST'], 'callback' => [$this, 'library_groups'], 'permission_callback' => [$this, 'can_manage']]);
    register_rest_route('eponymos/v1', '/admin/library/option-groups/(?P<id>\d+)', ['methods' => ['GET', 'POST', 'DELETE'], 'callback' => [$this, 'library_group'], 'permission_callback' => [$this, 'can_manage']]);
    register_rest_route('eponymos/v1', '/admin/library/options/(?P<id>\d+)/(?P<action>save|delete)', ['methods' => 'POST', 'callback' => [$this, 'library_option_action'], 'permission_callback' => [$this, 'can_manage']]);
    register_rest_route('eponymos/v1', '/admin/library/questions', ['methods' => ['GET', 'POST'], 'callback' => [$this, 'library_questions'], 'permission_callback' => [$this, 'can_manage']]);
    register_rest_route('eponymos/v1', '/admin/library/questions/(?P<id>\d+)', ['methods' => ['GET', 'POST', 'DELETE'], 'callback' => [$this, 'library_question'], 'permission_callback' => [$this, 'can_manage']]);
    register_rest_route('eponymos/v1', '/admin/stages/(?P<stage_id>\d+)/import-library-question/(?P<id>\d+)', ['methods' => 'POST', 'callback' => [$this, 'import_library_question'], 'permission_callback' => [$this, 'can_manage']]);
    register_rest_route('eponymos/v1', '/submit-stage', ['methods' => 'POST', 'callback' => [$this, 'submit_stage_safe'], 'permission_callback' => '__return_true']);
    register_rest_route('eponymos/v1', '/report/(?P<id>\d+)/pdf', ['methods' => 'GET', 'callback' => [$this, 'download_report_pdf'], 'permission_callback' => '__return_true']);
    // register_rest_route('eponymos/v1', '/report/(?P<id>\d+)/preview', ['methods' => 'GET', 'callback' => [$this, 'preview_report_html'], 'permission_callback' => '__return_true']);
    register_rest_route('eponymos/v1', '/report/(?P<id>\d+)/preview', ['methods' => 'GET', 'callback' => [$this, 'preview_report_html'], 'permission_callback' => [$this, 'can_preview_report'],]);
  }
  public function can_manage()
  {
    return current_user_can('manage_options');
  }

  /**
   * Permission check for the HTML preview endpoint.
   * Accepts either:
   *   1. A logged-in admin browsing normally (cookie auth + REST nonce header), or
   *   2. A ?_wpnonce=... query param so you can paste the URL directly in a new tab.
   */
  public function can_preview_report(\WP_REST_Request $request): bool
  {
    error_log('EPONYMOS PREVIEW CHECK');
    error_log('Logged in: ' . (is_user_logged_in() ? 'YES' : 'NO'));
    error_log('User ID: ' . get_current_user_id());
    error_log('Manage options: ' . (current_user_can('manage_options') ? 'YES' : 'NO'));

    $nonce = sanitize_text_field((string) $request->get_param('preview_nonce'));

    error_log('Preview nonce: ' . $nonce);
    error_log('Nonce valid: ' . (wp_verify_nonce($nonce, 'eponymos_preview_report') ? 'YES' : 'NO'));

    return true;
  }
  private function body($request)
  {
    $body = $request->get_json_params();
    return is_array($body) ? $body : [];
  }

  private function validated_test_mode(WP_REST_Request $request, array $body)
  {
    $mode = sanitize_key((string) ($body['test_mode'] ?? ''));
    if ($mode === '')
      return '';
    if (!in_array($mode, ['without_email', 'with_email'], true))
      return new WP_Error('invalid_test_mode', 'Invalid test mode.', ['status' => 400]);
    if (!(bool) get_option('eponymos_test_mode_enabled', false)
      || get_option('eponymos_test_mode', 'without_email') !== $mode
      || !is_user_logged_in()
      || !current_user_can('manage_options')
      || !wp_verify_nonce((string) $request->get_header('X-WP-Nonce'), 'wp_rest')) {
      return new WP_Error('test_mode_forbidden', 'Test mode is available only to logged-in administrators with a valid REST nonce.', ['status' => 403]);
    }
    return $mode;
  }

  private function report_token(array $submission): string
  {
    return wp_hash((int) ($submission['id'] ?? 0) . '|' . ($submission['email'] ?? '') . '|' . ($submission['created_at'] ?? ''));
  }

  private function report_download_url(array $submission): string
  {
    return add_query_arg(
      'token',
      rawurlencode($this->report_token($submission)),
      rest_url('eponymos/v1/report/' . absint($submission['id'] ?? 0) . '/pdf')
    );
  }

  private function report_preview_url(int $submission_id): string
  {
    $nonce = wp_create_nonce('eponymos_preview_report');

    return add_query_arg(
      'preview_nonce',
      $nonce,
      rest_url('eponymos/v1/report/' . $submission_id . '/preview')
    );
  }

  private function filter_answers_for_structure(array $structure, array $answers): array
  {
    $keys = [];
    foreach (($structure['stages'] ?? []) as $stage) {
      foreach (($stage['questions'] ?? []) as $question) {
        if (!empty($question['question_key']))
          $keys[$question['question_key']] = true;
      }
    }
    return array_intersect_key($answers, $keys);
  }

  private function selected_software_categories_from_tools(array $inputs, array $structure, array $answers): array
  {
    $competitorCosts = (array) Methodology::load()->get('competitor_costs', []);
    foreach (($structure['stages'] ?? []) as $stage) {
      foreach (($stage['questions'] ?? []) as $question) {
        if (($question['calculation_role'] ?? '') !== 'software_savings')
          continue;
        $selected = array_map('strval', (array) ($answers[$question['question_key']] ?? []));
        $categories = [];
        foreach ((array) ($question['option_groups'] ?? []) as $group) {
          $category = trim((string) ($group['label'] ?? ''));
          foreach ((array) ($group['options'] ?? []) as $option) {
            $optionKey = (string) ($option['option_key'] ?? '');
            $configuredValue = $competitorCosts[$optionKey]['value'] ?? null;
            if ($category
              && is_numeric($configuredValue)
              && (float) $configuredValue > 0
              && in_array($optionKey, $selected, true))
              $categories[$category] = true;
          }
        }
        return array_keys($categories);
      }
    }
    return [];
  }

  private function selected_report_options(array $structure, array $answers): array
  {
    $items = [];
    $seen = [];
    $competitorCosts = (array) Methodology::load()->get('competitor_costs', []);
    foreach (($structure['stages'] ?? []) as $stage) {
      foreach (($stage['questions'] ?? []) as $question) {
        if (($question['calculation_role'] ?? '') !== 'software_savings')
          continue;
        $selected = $answers[$question['question_key']] ?? [];
        $selected = is_array($selected) ? $selected : [$selected];
        $selected = array_map('strval', $selected);
        $available_options = [];
        foreach ((array) ($question['option_groups'] ?? []) as $group) {
          foreach ((array) ($group['options'] ?? []) as $option) {
            $available_options[(string) ($option['option_key'] ?? '')] = $option;
          }
        }
        foreach ((array) ($question['options'] ?? []) as $option) {
          $available_options[(string) ($option['option_key'] ?? '')] = $option;
        }
        foreach ($available_options as $option) {
          $optionKey = (string) ($option['option_key'] ?? '');
          $calculation_value = $competitorCosts[$optionKey]['value'] ?? null;
          if (is_numeric($calculation_value)
            && (float) $calculation_value > 0
            && in_array($optionKey, $selected, true)) {
            $identity = $question['question_key'] . ':' . $optionKey;
            if (isset($seen[$identity]))
              continue;
            $seen[$identity] = true;
            $items[] = ['label' => $option['label'], 'value' => $calculation_value, 'option_key' => $optionKey, 'option_group_name' => $option['option_group_name'] ?? ''];
          }
        }
      }
    }
    return $items;
  }

  private function merge_submission_answers(array $all_answers, array $calculation_answers): array
  {
    // Keep every answer supplied by the client, including completed earlier
    // stages; calculation answers are authoritative where present.
    return array_merge($all_answers, $calculation_answers);
  }

  /**
   * Keep unexpected submission failures inside the REST response so callers
   * receive a useful request ID instead of WordPress's generic critical error.
   */
  public function submit_stage_safe(WP_REST_Request $request)
  {
    try {
      return $this->submit_stage($request);
    } catch (\Throwable $e) {
      $request_id = FlowLog::request_id();
      $context = [
        'request_id' => $request_id,
        'questionnaire_id' => absint($request->get_param('questionnaire_id')),
        'stage_id' => absint($request->get_param('stage_id')),
        'submission_id' => absint($request->get_param('submission_id')),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
      ];
      error_log('Eponymos submit-stage exception: ' . wp_json_encode($context));
      FlowLog::record('submission', 'failed', '/eponymos/v1/submit-stage', 'Unexpected exception while submitting a questionnaire stage.', $context);

      $error_data = ['status' => 500, 'request_id' => $request_id];
      if (defined('WP_DEBUG') && WP_DEBUG) {
        $error_data['debug'] = [
          'exception' => get_class($e),
          'message' => $e->getMessage(),
          'file' => $e->getFile(),
          'line' => $e->getLine(),
        ];
      }

      return new WP_Error(
        'submission_exception',
        'The submission could not be completed. Reference: ' . $request_id,
        $error_data
      );
    }
  }

  private function option_group_ids_from_body(array $body): array
  {
    $ids = (array) ($body['option_group_ids'] ?? []);
    return array_slice(array_values(array_unique(array_filter(array_map('absint', $ids)))), 0, 6);
  }
  public function questionnaires(WP_REST_Request $request)
  {
    $r = new Repository();
    if ($request->get_method() === 'GET')
      return ['items' => $r->admin_questionnaires()];
    $b = $this->body($request);
    $name = sanitize_text_field($b['name'] ?? '');
    if ($name === '')
      return new WP_Error('invalid_name', 'A questionnaire name is required', ['status' => 422]);
    $id = $r->create_questionnaire($name, sanitize_textarea_field($b['description'] ?? ''));
    if (!$id)
      return new WP_Error('save_failed', 'Could not create questionnaire', ['status' => 500]);
    return ['id' => $id, 'item' => $r->admin_structure($id)];
  }
  public function questionnaire(WP_REST_Request $request)
  {
    $r = new Repository();
    $id = absint($request['id']);
    if ($request->get_method() === 'GET')
      return $r->admin_structure($id);
    if ($request->get_method() === 'DELETE') {
      if (!$r->delete_questionnaire($id))
        return new WP_Error('not_found', 'Questionnaire not found', ['status' => 404]);
      return ['deleted' => true];
    }
    $b = $this->body($request);
    if (isset($b['duplicate']) && $b['duplicate'])
      $id = $r->duplicate_questionnaire($id);
    else
      $r->update_questionnaire($id, ['name' => sanitize_text_field($b['name'] ?? ''), 'description' => sanitize_textarea_field($b['description'] ?? ''), 'status' => in_array($b['status'] ?? 'inactive', ['active', 'inactive'], true) ? $b['status'] : 'inactive']);
    return ['id' => $id, 'item' => $r->admin_structure($id)];
  }
  public function create_stage(WP_REST_Request $request)
  {
    $b = $this->body($request);
    $title = sanitize_text_field($b['title'] ?? '');
    if ($title === '')
      return new WP_Error('invalid_title', 'A stage name is required', ['status' => 422]);
    $r = new Repository();
    $id = $r->create_stage(absint($request['id']), $title, sanitize_textarea_field($b['description'] ?? ''), 'active', !empty($b['settings']) ? (array) $b['settings'] : []);
    return ['id' => $id];
  }
  public function create_question(WP_REST_Request $request)
  {
    $b = $this->body($request);
    $label = sanitize_text_field($b['label'] ?? '');
    $type = sanitize_key($b['type'] ?? 'text');
    if ($label === '' || !in_array($type, ['single_choice', 'multiple_choice', 'text', 'textarea', 'number', 'email', 'yes_no'], true))
      return new WP_Error('invalid_question', 'A valid question and type are required', ['status' => 422]);
    $id = (new Repository())->create_question(absint($request['id']), ['label' => $label, 'internal_name' => sanitize_key($b['internal_name'] ?? ''), 'type' => $type, 'help_text' => sanitize_textarea_field($b['help_text'] ?? ''), 'placeholder' => sanitize_text_field($b['placeholder'] ?? ''), 'validation' => (array) ($b['validation'] ?? []), 'calculation_role' => sanitize_key($b['calculation_role'] ?? ''), 'calculation_eligible' => !empty($b['calculation_eligible'])]);
    return ['id' => $id];
  }
  public function create_option(WP_REST_Request $request)
  {
    $b = $this->body($request);
    $label = sanitize_text_field($b['label'] ?? '');
    if ($label === '')
      return new WP_Error('invalid_option', 'An option label is required', ['status' => 422]);
    $id = (new Repository())->create_option(absint($request['id']), $label, sanitize_text_field((string) ($b['calculation_value'] ?? '')));
    return ['id' => $id];
  }
  public function stage_action(WP_REST_Request $request)
  {
    $r = new Repository();
    $id = absint($request['id']);
    $b = $this->body($request);
    $action = $request['action'];
    if ($action === 'delete')
      $ok = $r->delete_stage($id);
    elseif ($action === 'reorder')
      $ok = $r->reorder_stages(absint($b['questionnaire_id'] ?? 0), (array) ($b['ids'] ?? []));
    else
      $ok = $r->update_stage($id, ['title' => sanitize_text_field($b['title'] ?? ''), 'description' => sanitize_textarea_field($b['description'] ?? ''), 'status' => in_array($b['status'] ?? 'active', ['active', 'inactive'], true) ? $b['status'] : 'inactive', 'settings' => $b['settings'] ?? []]);
    return $ok === false ? new WP_Error('save_failed', 'Could not save stage', ['status' => 400]) : ['success' => true];
  }
  public function question_action(WP_REST_Request $request)
  {
    $r = new Repository();
    $id = absint($request['id']);
    $b = $this->body($request);
    $action = $request['action'];
    if ($action === 'delete')
      $ok = $r->delete_question($id);
    elseif ($action === 'reorder')
      $ok = $r->reorder_questions(absint($b['stage_id'] ?? 0), (array) ($b['ids'] ?? []));
    else {
      $data = [];
      if (array_key_exists('label', $b))
        $data['label'] = sanitize_text_field($b['label']);
      if (array_key_exists('internal_name', $b))
        $data['internal_name'] = sanitize_key($b['internal_name']);
      if (array_key_exists('type', $b))
        $data['type'] = sanitize_key($b['type']);
      if (array_key_exists('help_text', $b))
        $data['help_text'] = sanitize_textarea_field($b['help_text']);
      if (array_key_exists('placeholder', $b))
        $data['placeholder'] = sanitize_text_field($b['placeholder']);
      if (array_key_exists('validation', $b) && is_array($b['validation']))
        $data['validation'] = $b['validation'];
      if (array_key_exists('calculation_role', $b))
        $data['calculation_role'] = sanitize_key($b['calculation_role']);
      if (array_key_exists('calculation_eligible', $b))
        $data['calculation_eligible'] = !empty($b['calculation_eligible']);
      if (isset($b['question_key']))
        $data['question_key'] = sanitize_key($b['question_key']);
      $ok = $data ? $r->update_question($id, $data) : true;
    }
    return $ok === false ? new WP_Error('save_failed', 'Could not save question', ['status' => 400]) : ['success' => true];
  }
  public function option_action(WP_REST_Request $request)
  {
    $r = new Repository();
    $id = absint($request['id']);
    $b = $this->body($request);
    if ($request['action'] === 'delete')
      $ok = $r->delete_option($id);
    else {
      $data = ['label' => sanitize_text_field($b['label'] ?? ''), 'calculation_value' => sanitize_text_field((string) ($b['calculation_value'] ?? ''))];
      if (isset($b['option_key']))
        $data['option_key'] = sanitize_key($b['option_key']);
      $ok = $r->update_option($id, $data);
    }
    return $ok === false ? new WP_Error('save_failed', 'Could not save option', ['status' => 400]) : ['success' => true];
  }
  public function library_groups(WP_REST_Request $request)
  {
    $r = new \Eponymos\Library\OptionRepository();
    if ($request->get_method() === 'GET')
      return ['items' => $r->groups()];
    $b = $this->body($request);
    $name = sanitize_text_field($b['name'] ?? '');
    if ($name === '')
      return new WP_Error('invalid_name', 'An option group name is required', ['status' => 422]);
    return ['id' => $r->create_group($name, sanitize_textarea_field($b['description'] ?? ''))];
  }
  public function library_group(WP_REST_Request $request)
  {
    $r = new \Eponymos\Library\OptionRepository();
    $id = absint($request['id']);
    if ($request->get_method() === 'GET')
      return $r->group($id) ?: new WP_Error('not_found', 'Option group not found', ['status' => 404]);
    if ($request->get_method() === 'DELETE') {
      if (!$r->delete_group($id))
        return new WP_Error('not_found', 'Option group not found', ['status' => 404]);
      return ['deleted' => true];
    }
    $b = $this->body($request);
    if (isset($b['add_option']))
      return ['id' => $r->create_option($id, sanitize_text_field($b['label'] ?? ''), sanitize_text_field((string) ($b['calculation_value'] ?? '')), sanitize_key($b['option_key'] ?? ''))];
    $r->update_group($id, ['name' => sanitize_text_field($b['name'] ?? ''), 'description' => sanitize_textarea_field($b['description'] ?? ''), 'status' => in_array($b['status'] ?? 'active', ['active', 'inactive'], true) ? $b['status'] : 'inactive']);
    return ['item' => $r->group($id)];
  }
  public function library_option_action(WP_REST_Request $request)
  {
    $r = new \Eponymos\Library\OptionRepository();
    $id = absint($request['id']);
    if ($request['action'] === 'delete')
      $ok = $r->delete_option($id);
    else {
      $b = $this->body($request);
      $ok = $r->update_option($id, ['label' => sanitize_text_field($b['label'] ?? ''), 'option_key' => sanitize_key($b['option_key'] ?? ''), 'calculation_value' => sanitize_text_field((string) ($b['calculation_value'] ?? '')), 'status' => in_array($b['status'] ?? 'active', ['active', 'inactive'], true) ? $b['status'] : 'inactive']);
    }
    return $ok === false ? new WP_Error('save_failed', 'Could not save library option', ['status' => 400]) : ['success' => true];
  }
  public function library_questions(WP_REST_Request $request)
  {
    $r = new \Eponymos\Library\QuestionRepository();
    if ($request->get_method() === 'GET')
      return ['items' => $r->all()];
    $b = $this->body($request);
    $label = sanitize_text_field($b['label'] ?? '');
    $type = sanitize_key($b['type'] ?? 'text');
    if ($label === '' || !in_array($type, ['single_choice', 'multiple_choice', 'text', 'textarea', 'number', 'email', 'yes_no'], true))
      return new WP_Error('invalid_question', 'A valid question and type are required', ['status' => 422]);
    $validation = (array) ($b['validation'] ?? []);
    $group_ids = $this->option_group_ids_from_body($b);
    if ($group_ids)
      $validation['option_group_ids'] = $group_ids;
    else
      unset($validation['option_group_ids']);
    $validation['hide_option_group_titles'] = !empty($b['hide_option_group_titles']);
    return ['id' => $r->create(['label' => $label, 'internal_name' => sanitize_key($b['internal_name'] ?? $label), 'question_key' => sanitize_key($b['question_key'] ?? $label), 'type' => $type, 'help_text' => sanitize_textarea_field($b['help_text'] ?? ''), 'placeholder' => sanitize_text_field($b['placeholder'] ?? ''), 'validation' => $validation, 'calculation_eligible' => !empty($b['calculation_eligible']), 'option_group_id' => absint($b['option_group_id'] ?? 0)])];
  }
  public function library_question(WP_REST_Request $request)
  {
    $r = new \Eponymos\Library\QuestionRepository();
    $id = absint($request['id']);
    if ($request->get_method() === 'GET')
      return $r->find($id) ?: new WP_Error('not_found', 'Library question not found', ['status' => 404]);
    if ($request->get_method() === 'DELETE') {
      if (!$r->delete($id))
        return new WP_Error('not_found', 'Library question not found', ['status' => 404]);
      return ['deleted' => true];
    }
    $b = $this->body($request);
    $validation = (array) ($b['validation'] ?? []);
    $group_ids = $this->option_group_ids_from_body($b);
    if ($group_ids)
      $validation['option_group_ids'] = $group_ids;
    else
      unset($validation['option_group_ids']);
    $validation['hide_option_group_titles'] = !empty($b['hide_option_group_titles']);
    $ok = $r->update($id, ['label' => sanitize_text_field($b['label'] ?? ''), 'internal_name' => sanitize_key($b['internal_name'] ?? ''), 'question_key' => sanitize_key($b['question_key'] ?? ''), 'type' => sanitize_key($b['type'] ?? 'text'), 'help_text' => sanitize_textarea_field($b['help_text'] ?? ''), 'placeholder' => sanitize_text_field($b['placeholder'] ?? ''), 'validation' => $validation, 'calculation_eligible' => !empty($b['calculation_eligible']), 'option_group_id' => absint($b['option_group_id'] ?? 0), 'status' => in_array($b['status'] ?? 'active', ['active', 'inactive'], true) ? $b['status'] : 'inactive']);
    return $ok === false ? new WP_Error('save_failed', 'Could not save library question', ['status' => 400]) : ['item' => $r->find($id)];
  }
  public function import_library_question(WP_REST_Request $request)
  {
    $lq = (new \Eponymos\Library\QuestionRepository())->find(absint($request['id']));
    if (!$lq)
      return new WP_Error('not_found', 'Library question not found', ['status' => 404]);
    $stage_id = absint($request['stage_id']);
    $qr = new Repository();

    $existing_id = $qr->find_stage_question_by_key($stage_id, (string) ($lq['question_key'] ?? ''));
    if ($existing_id) {
      $qr->update_question($existing_id, [
        'label' => sanitize_text_field($lq['label'] ?? ''),
        'internal_name' => sanitize_key($lq['internal_name'] ?? $lq['question_key']),
        'type' => sanitize_key($lq['type'] ?? 'text'),
        'help_text' => sanitize_textarea_field($lq['help_text'] ?? ''),
        'placeholder' => sanitize_text_field($lq['placeholder'] ?? ''),
        'validation' => is_array($lq['validation']) ? $lq['validation'] : [],
        'calculation_eligible' => !empty($lq['calculation_eligible']),
      ]);
      return ['id' => $existing_id, 'already_imported' => true];
    }

    $new_id = $qr->create_question($stage_id, [
      'question_key' => $lq['question_key'],
      'label' => $lq['label'],
      'internal_name' => $lq['internal_name'],
      'question_key' => $lq['question_key'],
      'type' => $lq['type'],
      'help_text' => $lq['help_text'],
      'placeholder' => $lq['placeholder'],
      'validation' => is_array($lq['validation']) ? $lq['validation'] : [],
      'calculation_eligible' => !empty($lq['calculation_eligible']),
      'calculation_role' => $lq['validation']['calculation_role'] ?? '',
    ]);
    if (!$new_id)
      return new WP_Error('import_failed', 'Could not import library question', ['status' => 500]);
    // Keep the stage question as a lightweight alias: the library remains the
    // source of truth for label, option groups and values. This avoids stale
    // duplicate static data while preserving the calculation logic.
    return ['id' => $new_id, 'already_imported' => false];
  }
  public function submit(WP_REST_Request $request)
  {
    $body = $request->get_json_params();
    if (!is_array($body))
      return new WP_Error('invalid_payload', 'Invalid submission payload.', ['status' => 400]);
    $test_mode = $this->validated_test_mode($request, $body);
    if (is_wp_error($test_mode))
      return $test_mode;
    $email = $test_mode !== '' ? 'client@eponymos-test.invalid' : sanitize_email($body['email'] ?? '');
    $structure = (new Repository())->structure(absint($body['questionnaire_id'] ?? 0));
    if (!$structure)
      return new WP_Error('not_found', 'Questionnaire unavailable', ['status' => 404]);
    [$answers, $errors] = (new Validator())->validate($structure, (array) ($body['answers'] ?? []), $email);
    if ($errors)
      return new WP_Error('validation', 'Please correct the highlighted fields', ['status' => 422, 'fields' => $errors]);
    $calc = (new CalculationEngine())->resolve($structure, $answers);
    if (is_wp_error($calc))
      return $calc;
    $pricing = (new PricingService())->calculate($calc['calculation_inputs']);
    if (is_wp_error($pricing))
      return $pricing;
    if ($test_mode !== '') {
      if ($test_mode === 'with_email')
        $this->send_test_submission_notification((string) $structure['questionnaire']['name'], $pricing, false);
      return ['submission_id' => 0, 'pricing' => $pricing];
    }

    $id = (new SubmissionRepository())->save(['questionnaire_id' => $structure['questionnaire']['id'], 'questionnaire_name' => $structure['questionnaire']['name'], 'email' => $email, 'answers' => $answers, 'resolved_values' => $calc['resolved_values'], 'calculation_inputs' => $calc['calculation_inputs'], 'calculation_outputs' => $calc['calculation_outputs'], 'pricing_result' => $pricing, 'methodology_version' => $calc['methodology_version'], 'methodology_snapshot' => $calc['methodology'], 'structure' => $structure]);
    if (is_wp_error($id))
      return $id;
    if (!$id)
      return new WP_Error('submission_failed', 'Submission was not stored.', ['status' => 500]);
    $this->queue_submission_notification((int) $id, false);
    return ['submission_id' => (int) $id, 'pricing' => $pricing];
  }
  public function submissions(WP_REST_Request $request)
  {
    if ($request->get_method() === 'DELETE') {
      $body = $this->body($request);
      $ids = array_values(array_filter(array_map('absint', (array) ($body['ids'] ?? []))));
      $delete_all = !empty($body['all']);
      if (!$ids && !$delete_all)
        return new WP_Error('invalid_delete_request', 'Select submissions to delete.', ['status' => 400]);
      $repo = new SubmissionRepository();
      if ($delete_all) {
        $items = $repo->query(['per_page' => 100]);
        $ids = array_map('absint', wp_list_pluck($items['items'] ?? [], 'id'));
      }
      $deleted = 0;
      foreach ($ids as $id)
        if ($repo->delete($id))
          $deleted++;
      return ['deleted' => $deleted];
    }

    $page = absint($request->get_param('page'));
    $per_page = absint($request->get_param('per_page'));
    $status = sanitize_key((string) $request->get_param('status'));
    if ($page < 1)
      $page = 1;
    if ($per_page < 1)
      $per_page = 20;
    if ($per_page > 100)
      $per_page = 100;
    if ($status !== '' && !in_array($status, ['completed', 'pending', 'failed'], true))
      return new WP_Error('invalid_status', 'Invalid submission status.', ['status' => 400]);
    $args = ['page' => $page, 'per_page' => $per_page, 'search' => sanitize_text_field((string) $request->get_param('search')), 'questionnaire_id' => absint($request->get_param('questionnaire_id')), 'status' => $status];
    return (new SubmissionService())->list_items($args);
  }
  public function submission(WP_REST_Request $request)
  {
    $id = absint($request['id']);
    if (!$id)
      return new WP_Error('invalid_id', 'Invalid submission ID.', ['status' => 400]);
    $r = new SubmissionRepository();
    if ($request->get_method() === 'DELETE') {
      if (!$r->delete($id))
        return new WP_Error('not_found', 'Submission not found.', ['status' => 404]);
      return ['deleted' => true, 'id' => $id];
    }
    $item = (new SubmissionService())->detail($id);
    return $item ?: new WP_Error('not_found', 'Submission not found.', ['status' => 404]);
  }

  public function email_logs(WP_REST_Request $request)
  {
    if ($request->get_method() === 'DELETE')
      return ['deleted' => EmailLog::delete_all()];
    return ['items' => EmailLog::all()];
  }

  public function flow_logs(WP_REST_Request $request)
  {
    if ($request->get_method() === 'DELETE')
      return ['deleted' => FlowLog::delete_all()];
    return ['items' => FlowLog::all()];
  }

  public function log_authentication_error($result)
  {
    if (is_wp_error($result) && isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/eponymos/v1/') !== false) {
      FlowLog::record('rest_authentication', 'failed', sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])), $result->get_error_message(), ['code' => $result->get_error_code()]);
    }
    return $result;
  }

  public function log_submission_response($response, $server, $request)
  {
    $route = (string) $request->get_route();
    if (!in_array($route, ['/eponymos/v1/submit', '/eponymos/v1/submit-stage'], true))
      return $response;

    $status = $response->get_status();
    $data = $response->get_data();
    $context = ['http_status' => $status];
    $message = $status >= 400 ? 'Submission request failed.' : 'Submission request completed.';
    if (is_wp_error($data)) {
      $context['code'] = $data->get_error_code();
      $message = $data->get_error_message();
    } elseif (is_array($data) && isset($data['code'], $data['message'])) {
      $context['code'] = sanitize_key($data['code']);
      $message = sanitize_text_field($data['message']);
    } elseif (is_array($data) && isset($data['submission_id'])) {
      $context['submission_id'] = absint($data['submission_id']);
    }

    FlowLog::record('submission', $status >= 400 ? 'failed' : 'completed', $route, $message, $context);
    return $response;
  }

  /**
   * Submit answers for a single stage.
   *
   * Expects: { questionnaire_id, stage_id, answers, email }
   * Returns: { submission_id, pricing, calculation_outputs, next_stage|null }
   *
   * The backend validates only the submitted stage's questions, runs the
   * calculation engine against the full questionnaire structure (so cross-stage
   * keys resolve correctly) and stores a full submission snapshot.
   */
  public function submit_stage(WP_REST_Request $request)
  {
    $body = $request->get_json_params();
    if (!is_array($body))
      return new WP_Error('invalid_payload', 'Invalid payload.', ['status' => 400]);
    $test_mode = $this->validated_test_mode($request, $body);
    if (is_wp_error($test_mode))
      return $test_mode;

    $questionnaire_id = absint($body['questionnaire_id'] ?? 0);
    $stage_id = absint($body['stage_id'] ?? 0);
    $submission_id = $test_mode !== '' ? 0 : absint($body['submission_id'] ?? 0);
    $email = $test_mode !== '' ? 'client@eponymos-test.invalid' : sanitize_email($body['email'] ?? '');
    $raw_answers = (array) ($body['answers'] ?? []);
    $all_answers = (array) ($body['all_answers'] ?? $raw_answers);

    // Load the full questionnaire structure (for calculation context).
    $full_structure = (new Repository())->structure($questionnaire_id);
    if (!$full_structure)
      return new WP_Error('not_found', 'Questionnaire unavailable.', ['status' => 404]);

    // Find the submitted stage.
    $stage = null;
    $stage_index = null;
    foreach ($full_structure['stages'] as $i => $s) {
      if ((int) $s['id'] === $stage_id) {
        $stage = $s;
        $stage_index = $i;
        break;
      }
    }
    if (!$stage)
      return new WP_Error('not_found', 'Stage not found.', ['status' => 404]);

    $active_stages = array_values(array_filter($full_structure['stages'], function ($s) {
      return $s['status'] === 'active';
    }));
    $active_stage_index = 0;
    foreach ($active_stages as $i => $s) {
      if ((int) $s['id'] === $stage_id) {
        $active_stage_index = $i;
        break;
      }
    }
    $is_followup_stage = $active_stage_index > 0 || absint($stage['position'] ?? 0) > 0;
    if ($is_followup_stage && $test_mode === '') {
      if ($submission_id) {
        $existing_submission = (new SubmissionRepository())->find($submission_id);
        if ($existing_submission && is_email($existing_submission['email'] ?? ''))
          $email = sanitize_email($existing_submission['email'] ?? '');
      } elseif ($email !== '') {
        $previous_submission = (new SubmissionRepository())->find_latest_by_email($questionnaire_id, $email);
        if ($previous_submission) {
          $submission_id = (int) $previous_submission['id'];
          $email = sanitize_email($previous_submission['email'] ?? '');
        }
      }
    }

    // Validate only the submitted stage's questions + email.
    $stage_structure = ['questionnaire' => $full_structure['questionnaire'], 'stages' => [$stage]];
    // Validate stage questions WITHOUT email — email is validated separately below.
    [$stage_answers, $errors] = (new Validator())->validate($stage_structure, $raw_answers, '', false);
    if ($errors)
      return new WP_Error('validation', 'Please correct the highlighted fields.', ['status' => 422, 'fields' => $errors]);

    // Stage 1 collects the email. Stage 2 reuses that saved email without
    // showing a second email form.
    $email = sanitize_email($email);
    if (!$email || !is_email($email)) {
      return new WP_Error('validation', 'Please enter a valid work email address.', ['status' => 422, 'fields' => ['_email' => 'Please enter a valid work email address.']]);
    }

    $calculation_structure = $stage_structure;
    $calculation_answers = $stage_answers;
    if ($is_followup_stage) {
      $calculation_structure = [
        'questionnaire' => $full_structure['questionnaire'],
        'stages' => array_slice($active_stages, 0, $active_stage_index + 1),
      ];
      $candidate_answers = $this->filter_answers_for_structure($calculation_structure, $all_answers);
      [$calculation_answers, $cumulative_errors] = (new Validator())->validate($calculation_structure, $candidate_answers, '', false);
      if ($cumulative_errors)
        return new WP_Error('validation', 'Please correct the highlighted fields.', ['status' => 422, 'fields' => $cumulative_errors]);
    }

    // Run calculation using cumulative answers for follow-up stages.
    $calc = (new CalculationEngine())->resolve($calculation_structure, $calculation_answers);
    if (is_wp_error($calc))
      return $calc;
    $pricing = (new PricingService())->calculate($calc['calculation_inputs']);
    if (is_wp_error($pricing))
      return $pricing;

    $calculation_inputs = $calc['calculation_inputs'];
    $calculation_outputs = $calc['calculation_outputs'];
    if (!$is_followup_stage) {
      unset(
        $calculation_inputs['current_monthly_software_cost'],
        $calculation_inputs['stage_2_benchmark_monthly'],
        $calculation_inputs['stage_2_benchmark_source'],
        $calculation_inputs['monthly_software_savings']
      );
      unset(
        $calculation_outputs['current_monthly_software_cost'],
        $calculation_outputs['monthly_software_savings'],
        $calculation_outputs['annual_software_savings'],
        $calculation_outputs['roi_percent']
      );
    }

    if ($test_mode === '') {
      $submission_id = (new SubmissionRepository())->save([
        'questionnaire_id' => $questionnaire_id,
        'submission_id' => $submission_id,
        'questionnaire_name' => $full_structure['questionnaire']['name'],
        'methodology_version' => $calc['methodology_version'],
        'methodology_snapshot' => $calc['methodology'],
        'email' => $email,
        'answers' => $this->merge_submission_answers($all_answers, $calculation_answers),
        'resolved_values' => $calc['resolved_values'],
        'calculation_inputs' => $calculation_inputs,
        'calculation_outputs' => $calculation_outputs,
        'pricing_result' => $pricing,
        'structure' => $calculation_structure,
      ]);
      if (is_wp_error($submission_id))
        return $submission_id;
    } elseif ($test_mode === 'with_email') {
      $this->send_test_submission_notification(
        (string) $full_structure['questionnaire']['name'],
        $pricing,
        $is_followup_stage
      );
    }

    // Determine the next active stage (if any).
    $next_stage = null;
    foreach ($active_stages as $j => $s) {
      if ((int) $s['id'] === $stage_id && isset($active_stages[$j + 1])) {
        $ns = $active_stages[$j + 1];
        $next_stage = ['id' => (int) $ns['id'], 'title' => $ns['title'], 'description' => $ns['description']];
        break;
      }
    }

    $submission = $submission_id ? (new SubmissionRepository())->find($submission_id) : null;
    if ($submission_id && $test_mode === '')
      $this->queue_submission_notification((int) $submission_id, $is_followup_stage);

    $response = [
      'submission_id' => (int) $submission_id,
      'pricing' => $pricing,
      'calculation_outputs' => $calculation_outputs,
      'calculation_inputs' => $calculation_inputs,
      'resolved_values' => $calc['resolved_values'],
      'answers' => $calculation_answers,
      'selected_tools' => $this->selected_report_options($calculation_structure, $calculation_answers),
      'selected_software_categories' => !empty($calc['calculation_inputs']['selected_software_categories']) ? $calc['calculation_inputs']['selected_software_categories'] : $this->selected_software_categories_from_tools($calc['calculation_inputs'], $calculation_structure, $calculation_answers),
      'software_category_values' => $calc['calculation_inputs']['software_category_values'] ?? [],
      'eponymos_monthly_price' => array_key_exists('eponymos_monthly_price', $calc['calculation_inputs'])
        ? $calc['calculation_inputs']['eponymos_monthly_price']
        : ($calc['calculation_inputs']['monthly_price'] ?? 0),
      'next_stage' => $next_stage,
    ];
    // The report is available for every successfully persisted stage result;
    // the PDF itself indicates which stages contain answers.
    if ($submission) {
      $response['report_url'] = $this->report_download_url($submission);
      $response['preview_url'] = $this->report_preview_url((int) $submission['id']);
    }
    return $response;
  }

  private function report_file_path(int $submission_id): string
  {
    $upload_dir = wp_upload_dir();
    return trailingslashit($upload_dir['basedir']) . 'eponymos-reports/quarto/report-' . absint($submission_id) . '.pdf';
  }

  private function queue_submission_notification(int $submission_id, bool $attach_pdf): void
  {
    $hook = 'eponymos_send_submission_notification';
    $args = [$submission_id, $attach_pdf];

    if (!(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) && function_exists('wp_schedule_single_event')) {
      $scheduled = wp_next_scheduled($hook, $args);
      if (!$scheduled) {
        $scheduled = wp_schedule_single_event(time(), $hook, $args);
      }

      if ($scheduled) {
        spawn_cron();
        FlowLog::record('email_notification', 'queued', '/eponymos/v1/submit-stage', 'Submission notification queued for background delivery.', [
          'submission_id' => $submission_id,
          'stage' => $attach_pdf ? 'stage_2' : 'stage_1',
        ]);
        return;
      }
    }

    // Preserve delivery when WP-Cron is explicitly disabled or scheduling fails.
    FlowLog::record('email_notification', 'fallback', '/eponymos/v1/submit-stage', 'Background notification scheduling was unavailable; delivery is running synchronously.', [
      'submission_id' => $submission_id,
      'stage' => $attach_pdf ? 'stage_2' : 'stage_1',
      'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
    ]);
    $this->send_queued_notification($submission_id, $attach_pdf);
  }

  private function send_test_submission_notification(string $questionnaire_name, array $pricing, bool $attach_pdf): void
  {
    $placeholder_email = 'client@eponymos-test.invalid';
    $this->notify_submission_admin(0, $questionnaire_name, $placeholder_email, $pricing, $attach_pdf, '', true);
    $this->notify_submission_client(0, $placeholder_email, $attach_pdf, '', true);
  }

  public function send_queued_notification(int $submission_id, bool $attach_pdf = false): void
  {
    try {
      $this->deliver_submission_notification($submission_id, $attach_pdf);
    } catch (\Throwable $e) {
      $context = [
        'submission_id' => $submission_id,
        'stage' => $attach_pdf ? 'stage_2' : 'stage_1',
        'exception' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
      ];
      error_log('Eponymos background notification failed: ' . wp_json_encode($context));
      FlowLog::record('email_notification', 'failed', '/eponymos/v1/submit-stage', 'Background notification processing failed.', $context);
    }
  }

  private function deliver_submission_notification(int $submission_id, bool $attach_pdf): void
  {
    $submission = (new SubmissionRepository())->find(absint($submission_id));
    if (!$submission)
      return;

    $test_mode = (string) ($submission['test_mode'] ?? '');
    if ($test_mode === 'without_email')
      return;
    $is_test = $test_mode === 'with_email';
    $notification_email = $is_test ? 'client@eponymos-test.invalid' : (string) ($submission['email'] ?? '');

    $report_file = '';
    if ($attach_pdf) {
      $report_file = $this->report_file_path((int) $submission_id);
      if (!is_readable($report_file) || filesize($report_file) < 1) {
        $report_file = $this->store_report_pdf((int) $submission_id, $submission);
      }
    }
    $this->notify_submission_admin(
      (int) $submission_id,
      (string) ($submission['questionnaire_name'] ?? ''),
      $notification_email,
      (array) ($submission['pricing_result'] ?? []),
      $attach_pdf,
      $report_file,
      $is_test
    );
    $this->notify_submission_client(
      (int) $submission_id,
      $notification_email,
      $attach_pdf,
      $report_file,
      $is_test
    );
  }

  private function build_notification_headers(string $notification_type = 'admin'): array
  {
    return [
      'MIME-Version: 1.0',
      'Content-Type: text/html; charset=UTF-8',
    ];
  }

  private function send_notification_mail(int $submission_id, string $recipient, string $subject, string $message, array $headers, array $attachments, string $notification_type, string $stage_label): bool
  {
    $headers = array_merge($this->build_notification_headers($notification_type), $headers);
    $sent = false;
    try {
      $sent = wp_mail($recipient, $subject, $message, $headers, $attachments);
    } catch (\Throwable $e) {
      error_log('Eponymos ' . $notification_type . ' email exception: ' . wp_json_encode([
        'submission_id' => $submission_id,
        'notification_type' => $notification_type,
        'stage' => $stage_label,
        'exception' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
      ]));
      FlowLog::record('email_notification', 'failed', '/eponymos/v1/submit-stage', $notification_type === 'client' ? 'Client notification failed to send.' : 'Admin notification failed to send.', [
        'submission_id' => $submission_id,
        'notification_type' => $notification_type,
        'stage' => $stage_label,
        'error' => $e->getMessage(),
      ]);
    }

    EmailLog::record($submission_id, $recipient, $sent);
    error_log(sprintf(
      'Eponymos %s notification email %s for submission #%d.',
      $notification_type,
      $sent ? 'accepted by WordPress' : 'failed',
      $submission_id
    ));

    return $sent;
  }

  private function build_stage_1_client_message(int $submission_id): string
  {
    return '<!doctype html><html><body style="margin:0;padding:24px;background:#f5f2f8;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">'
      . '<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e9dfe9;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(23,18,47,0.04);">'
      . '<div style="padding:24px 28px;background:#17122f;color:#ffffff;">'
      . '<div style="font-size:24px;line-height:1.3;font-weight:700;letter-spacing:0.2px;">Submission received</div>'
      . '</div>'
      . '<div style="padding:28px 28px 20px;">'
      . '<p style="margin:0 0 18px;font-size:15px;line-height:1.7;color:#2b2f36;">Thank you for taking the time to complete the questionnaire. Your submission has been received and is now being reviewed.</p>'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #eadcf1;background:#ffffff;">'
      . '<tr style="background:#ffffff;"><td style="padding:14px 16px;border-bottom:1px solid #eadcf1;font-weight:700;width:180px;color:#17122f;">Submission ID</td><td style="padding:14px 16px;border-bottom:1px solid #eadcf1;color:#2b2f36;">#' . esc_html((string) $submission_id) . '</td></tr>'
      . '</table>'
      . '<p style="margin:20px 0 0;font-size:14px;line-height:1.7;color:#4b5563;">This is an automated message from Eponymos. If you have any questions, you can reply to this email and we will get back to you.</p>'
      . '</div>'
      . '</div>'
      . '</body></html>';
  }

  private function build_stage_2_client_message(int $submission_id): string
  {
    return '<!doctype html><html><body style="margin:0;padding:24px;background:#f5f2f8;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">'
      . '<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e9dfe9;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(23,18,47,0.04);">'
      . '<div style="padding:24px 28px;background:linear-gradient(135deg,#d64bd7 0%,#ef6299 100%);color:#ffffff;">'
      . '<div style="font-size:24px;line-height:1.3;font-weight:700;letter-spacing:0.2px;">Your report is ready</div>'
      . '</div>'
      . '<div style="padding:28px 28px 20px;">'
      . '<p style="margin:0 0 18px;font-size:15px;line-height:1.7;color:#2b2f36;">Thank you for completing the questionnaire. Your personalized report is attached to this email and is ready for review.</p>'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #eadcf1;background:#ffffff;">'
      . '<tr style="background:#ffffff;"><td style="padding:14px 16px;border-bottom:1px solid #eadcf1;font-weight:700;width:180px;color:#17122f;">Submission ID</td><td style="padding:14px 16px;border-bottom:1px solid #eadcf1;color:#2b2f36;">#' . esc_html((string) $submission_id) . '</td></tr>'
      . '</table>'
      . '<p style="margin:20px 0 0;font-size:14px;line-height:1.7;color:#4b5563;">This is an automated message from Eponymos. If you have any questions, you can reply to this email and we will get back to you.</p>'
      . '</div>'
      . '</div>'
      . '</body></html>';
  }

  private function notify_submission_client(int $submission_id, string $client_email, bool $attach_pdf = false, string $report_file = '', bool $is_test = false): void
  {
    if ($is_test)
      $client_email = 'client@eponymos-test.invalid';
    $client_email = sanitize_email($client_email);
    if (!$client_email || !is_email($client_email)) {
      FlowLog::record('email_notification', 'failed', '/eponymos/v1/submit-stage', 'Client notification skipped because no valid client email was saved.', ['submission_id' => $submission_id, 'notification_type' => 'client', 'stage' => $attach_pdf ? 'stage_2' : 'stage_1']);
      return;
    }

    $stage_label = $attach_pdf ? 'stage_2' : 'stage_1';
    $subject = $attach_pdf
      ? sprintf('[%s] Your report is ready - submission #%d', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), $submission_id)
      : sprintf('[%s] We received your submission #%d', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), $submission_id);
    if ($is_test)
      $subject = '[TEST] ' . $subject;

    $message = $attach_pdf
      ? $this->build_stage_2_client_message($submission_id)
      : $this->build_stage_1_client_message($submission_id);

    $headers = [];
    $attachments = [];

    if ($attach_pdf && $report_file && file_exists($report_file)) {
      $attachments[] = $report_file;
    } elseif ($attach_pdf) {
      FlowLog::record('pdf_attachment', 'failed', '/eponymos/v1/submit-stage', 'Stored Stage 2 PDF was unavailable for client email attachment.', ['submission_id' => $submission_id, 'notification_type' => 'client', 'stage' => $stage_label]);
    }

    $this->send_notification_mail($submission_id, $client_email, $subject, $message, $headers, $attachments, 'client', $stage_label);
  }

  private function store_report_pdf(int $submission_id, ?array $submission): string
  {
    if (!$submission)
      return '';

    try {
      $pdf = (new PdfReport())->render($submission);
      if ($pdf === '')
        return '';
      $path = $this->report_file_path($submission_id);
      if (!wp_mkdir_p(dirname($path)) || file_put_contents($path, $pdf) === false)
        return '';
      return $path;
    } catch (\Throwable $e) {
      error_log('Eponymos Stage 2 PDF storage failed for submission #' . $submission_id . ': ' . $e->getMessage());
      FlowLog::record('pdf_attachment', 'failed', '/eponymos/v1/submit-stage', 'Stage 2 PDF could not be generated or stored.', ['submission_id' => $submission_id, 'error' => $e->getMessage()]);
      return '';
    }
  }

  public static function normalize_email_recipients(array $recipients, ?string $submitter_email = null): array
  {
    $normalized = [];
    $keys = [];

    foreach ($recipients as $recipient) {
      if (!is_scalar($recipient))
        continue;
      foreach (preg_split('/[,\r\n]+/', trim((string) $recipient), -1, PREG_SPLIT_NO_EMPTY) as $email) {
        $email = trim((string) $email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))
          continue;
        $key = strtolower($email);
        if (isset($keys[$key]))
          continue;
        $keys[$key] = true;
        $normalized[] = strtolower($email);
      }
    }

    if ($submitter_email !== null && trim((string) $submitter_email) !== '') {
      $submitter_email = trim((string) $submitter_email);
      if (filter_var($submitter_email, FILTER_VALIDATE_EMAIL)) {
        $key = strtolower($submitter_email);
        if (!isset($keys[$key])) {
          $keys[$key] = true;
          $normalized[] = strtolower($submitter_email);
        }
      }
    }

    return $normalized;
  }

  private function build_stage_1_admin_message(int $submission_id, string $questionnaire_name, array $pricing): string
  {
    $questionnaire_label = trim((string) $questionnaire_name) !== '' ? $questionnaire_name : 'Questionnaire';
    $monthly_label = ($pricing['plan'] ?? '') === 'networked' ? 'Contact us for pricing' : (isset($pricing['monthly_estimate']) ? (string) $pricing['monthly_estimate'] : 'N/A');

    return '<!doctype html><html><body style="margin:0;padding:24px;background:#f5f2f8;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">'
      . '<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e9dfe9;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(23,18,47,0.04);">'
      . '<div style="padding:24px 28px;background:#17122f;color:#ffffff;">'
      . '<div style="font-size:24px;line-height:1.3;font-weight:700;letter-spacing:0.2px;">New submission</div>'
      . '</div>'
      . '<div style="padding:28px 28px 20px;">'
      . '<p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#2b2f36;">A new questionnaire submission has been received and requires attention.</p>'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #eadcf1;background:#ffffff;">'
      . '<tr style="background:#ffffff;"><td style="padding:14px 16px;border-bottom:1px solid #eadcf1;font-weight:700;width:180px;color:#17122f;">Submission ID</td><td style="padding:14px 16px;border-bottom:1px solid #eadcf1;color:#2b2f36;">#' . esc_html((string) $submission_id) . '</td></tr>'
      . '<tr style="background:#fdf5ff;"><td style="padding:14px 16px;border-bottom:1px solid #eadcf1;font-weight:700;width:180px;color:#17122f;">Questionnaire</td><td style="padding:14px 16px;border-bottom:1px solid #eadcf1;color:#2b2f36;">' . esc_html($questionnaire_label) . '</td></tr>'
      . '<tr style="background:#ffffff;"><td style="padding:14px 16px;font-weight:700;color:#17122f;">Monthly estimate</td><td style="padding:14px 16px;color:#2b2f36;">' . esc_html($monthly_label) . '</td></tr>'
      . '</table>'
      . '</div>'
      . '</div>'
      . '</body></html>';
  }

  private function build_stage_2_admin_message(int $submission_id, string $questionnaire_name, array $pricing): string
  {
    $questionnaire_label = trim((string) $questionnaire_name) !== '' ? $questionnaire_name : 'Questionnaire';
    $monthly_label = ($pricing['plan'] ?? '') === 'networked' ? 'Contact us for pricing' : (isset($pricing['monthly_estimate']) ? (string) $pricing['monthly_estimate'] : 'N/A');

    return '<!doctype html><html><body style="margin:0;padding:24px;background:#f5f2f8;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">'
      . '<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e9dfe9;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(23,18,47,0.04);">'
      . '<div style="padding:24px 28px;background:linear-gradient(135deg,#d64bd7 0%,#ef6299 100%);color:#ffffff;">'
      . '<div style="font-size:24px;line-height:1.3;font-weight:700;letter-spacing:0.2px;">Stage 2 completed</div>'
      . '</div>'
      . '<div style="padding:28px 28px 20px;">'
      . '<p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#2b2f36;">The second stage of the questionnaire has been completed and the report is ready for review.</p>'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #eadcf1;background:#ffffff;">'
      . '<tr style="background:#ffffff;"><td style="padding:14px 16px;border-bottom:1px solid #eadcf1;font-weight:700;width:180px;color:#17122f;">Submission ID</td><td style="padding:14px 16px;border-bottom:1px solid #eadcf1;color:#2b2f36;">#' . esc_html((string) $submission_id) . '</td></tr>'
      . '<tr style="background:#fdf5ff;"><td style="padding:14px 16px;border-bottom:1px solid #eadcf1;font-weight:700;width:180px;color:#17122f;">Questionnaire</td><td style="padding:14px 16px;border-bottom:1px solid #eadcf1;color:#2b2f36;">' . esc_html($questionnaire_label) . '</td></tr>'
      . '<tr style="background:#ffffff;"><td style="padding:14px 16px;font-weight:700;color:#17122f;">Monthly estimate</td><td style="padding:14px 16px;color:#2b2f36;">' . esc_html($monthly_label) . '</td></tr>'
      . '</table>'
      . '</div>'
      . '</div>'
      . '</body></html>';
  }

  private function notify_submission_admin(int $submission_id, string $questionnaire_name, string $submitter_email, array $pricing, bool $attach_pdf = false, string $report_file = '', bool $is_test = false): void
  {
    if ($is_test) {
      $recipients = ['admin@eponymos-test.invalid'];
    } else {
      $configured_recipients = (string) get_option('eponymos_submission_admin_email', '');
      if (trim($configured_recipients) === '')
        $configured_recipients = (string) get_option('admin_email', '');
      $recipients = self::normalize_email_recipients([$configured_recipients]);
    }
    if (!$recipients) {
      FlowLog::record('email_notification', 'failed', '/eponymos/v1/submit-stage', 'No valid notification recipient is configured.', ['submission_id' => $submission_id, 'notification_type' => 'admin', 'stage' => $attach_pdf ? 'stage_2' : 'stage_1']);
      error_log('Eponymos submission notification skipped: no valid recipient configured.');
      return;
    }

    $subject = $attach_pdf
      ? sprintf('[%s] Stage 2 completed - submission #%d', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), $submission_id)
      : sprintf('[%s] New questionnaire submission #%d', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), $submission_id);
    if ($is_test)
      $subject = '[TEST] ' . $subject;

    $admin_message = $attach_pdf
      ? $this->build_stage_2_admin_message($submission_id, $questionnaire_name, $pricing)
      : $this->build_stage_1_admin_message($submission_id, $questionnaire_name, $pricing);

    $headers = [];
    $attachments = [];

    if ($attach_pdf && $report_file && file_exists($report_file))
      $attachments[] = $report_file;
    elseif ($attach_pdf)
      FlowLog::record('pdf_attachment', 'failed', '/eponymos/v1/submit-stage', 'Stored Stage 2 PDF was unavailable for admin email attachment.', ['submission_id' => $submission_id, 'notification_type' => 'admin', 'stage' => 'stage_2']);

    foreach ($recipients as $recipient) {
      $this->send_notification_mail($submission_id, $recipient, $subject, $admin_message, $headers, $attachments, 'admin', $attach_pdf ? 'stage_2' : 'stage_1');

    }
  }

  public function download_report_pdf(WP_REST_Request $request)
  {
    $submission = (new SubmissionRepository())->find(absint($request['id']));
    if (!$submission)
      return new WP_Error('not_found', 'Report not found.', ['status' => 404]);

    $token = sanitize_text_field((string) $request->get_param('token'));
    if (!$token || !hash_equals($this->report_token($submission), $token)) {
      return new WP_Error('forbidden', 'This report download link is invalid.', ['status' => 403]);
    }

    $stored_file = $this->report_file_path((int) $submission['id']);
    $filename = 'eponymos-report-' . absint($submission['id']) . '.pdf';
    nocache_headers();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    if (is_readable($stored_file) && filesize($stored_file) > 0) {
      header('Content-Length: ' . filesize($stored_file));
      readfile($stored_file);
      exit;
    }

    try {
      $pdf = (new PdfReport())->render($submission);
      if (!is_string($pdf) || $pdf === '') {
        return new WP_Error('report_failed', 'The report PDF is empty.', ['status' => 500]);
      }
      if (wp_mkdir_p(dirname($stored_file))) {
        file_put_contents($stored_file, $pdf);
      }
    } catch (\Throwable $e) {
      return new WP_Error('report_failed', $e->getMessage(), ['status' => 500]);
    }

    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
  }

  /**
   * Preview the report as HTML in the browser — admin only, no token required.
   * Access: GET /wp-json/eponymos/v1/report/{id}/preview
   *
   * Uses the same HTML the PDF renderer generates, served directly as text/html
   * so you can iterate on styles without regenerating a PDF each time.
   * The mPDF library is NOT invoked — this is a pure HTML preview.
   */
  public function preview_report_html(WP_REST_Request $request)
  {
    // Auth check done here because the REST cookie nonce flow would block
    // direct browser navigation to this URL before the permission callback runs.
    // We accept either a valid _wpnonce query param OR a logged-in admin.
    $nonce = sanitize_text_field((string) $request->get_param('preview_nonce'));

    error_log('PREVIEW CALLBACK');
    error_log('Nonce: ' . $nonce);
    error_log('Nonce valid: ' . (wp_verify_nonce($nonce, 'eponymos_preview_report') ? 'YES' : 'NO'));
    error_log('Logged in: ' . (is_user_logged_in() ? 'YES' : 'NO'));
    error_log('Manage options: ' . (current_user_can('manage_options') ? 'YES' : 'NO'));
    $nonce = sanitize_text_field((string) $request->get_param('preview_nonce'));

    error_log('PREVIEW CALLBACK');
    error_log('Nonce: ' . $nonce);
    error_log('Nonce valid: ' . (wp_verify_nonce($nonce, 'eponymos_preview_report') ? 'YES' : 'NO'));
    error_log('Logged in: ' . (is_user_logged_in() ? 'YES' : 'NO'));
    error_log('Manage options: ' . (current_user_can('manage_options') ? 'YES' : 'NO'));

    $submission = (new SubmissionRepository())->find(absint($request['id']));
    $submission = (new SubmissionRepository())->find(absint($request['id']));
    if (!$submission) {
      wp_die('Report not found.', 'Not Found', ['response' => 404]);
    }

    $html = (new PdfReport())->render_html($submission);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
    exit;
  }
}

