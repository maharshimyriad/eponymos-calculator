<?php

namespace Eponymos\Submission;

use Eponymos\Questionnaire\Repository as QuestionnaireRepository;
use Eponymos\Report\ReportEngine;

class Service
{
    private $repository;
    private $questionnaires;
    public function __construct()
    {
        $this->repository = new Repository();
        $this->questionnaires = new QuestionnaireRepository();
    }

    public function list_items($args = [])
    {
        return $this->repository->query($args);
    }

    public function detail($id)
    {
        $submission = $this->repository->find($id);
        if (!$submission) return null;

        // Historical answer rows are the source of truth. Never rebuild this view
        // from the current questionnaire, because labels/options may have changed.
        $stages = [];
        foreach ($submission['answers_detail'] as $stored) {
            $title = !empty($stored['stage_title']) ? $stored['stage_title'] : 'Answers';
            if (!isset($stages[$title])) $stages[$title] = ['title' => $title, 'answers' => []];
            $stages[$title]['answers'][] = [
                'question' => $stored['question_label'],
                'key' => $stored['question_key'],
                'answer' => $this->display_answer($stored),
                'resolved_value' => $stored['resolved_value'],
            ];
        }
        $submission['stages'] = array_values($stages);
        $submission['questionnaire_name'] = $submission['questionnaire_name'] ?: $this->questionnaire_name($submission['questionnaire_id']);
        $submission['report'] = ['status' => 'available', 'data' => (new ReportEngine())->build($submission)];
        unset($submission['answers_detail']);
        return $submission;
    }

    private function questionnaire_name($id)
    {
        $item = $this->questionnaires->admin_questionnaire((int) $id);
        return $item ? $item['name'] : 'Unavailable questionnaire';
    }

    private function display_answer($stored)
    {
        $label = $stored['answer_label'];
        if (is_array($label)) return implode(', ', array_map('strval', $label));
        if ($label !== [] && $label !== null && $label !== '') return (string) $label;
        $answer = $stored['answer'];
        if (is_array($answer)) return implode(', ', array_map('strval', $answer));
        return (string) $answer;
    }
}
