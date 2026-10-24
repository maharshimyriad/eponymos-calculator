<?php

namespace Eponymos\Report;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

class PdfReport
{
    public function render(array $submission): string
    {
        if (!class_exists(Mpdf::class)) {
            throw new \RuntimeException('mPDF is not available. Run composer install for the Eponymos plugin.');
        }

        $mpdf = new Mpdf($this->get_pdf_config());
        $mpdf->SetTitle('Eponymos PR Tech Needs Report');

        $this->register_headers_and_footers($mpdf, $submission);
        $mpdf->WriteHTML($this->render_html($submission));

        return $mpdf->Output('', 'S');
    }

    private function get_pdf_config(): array
    {
        $upload_dir = wp_upload_dir();
        $temp_dir = trailingslashit($upload_dir['basedir']) . 'eponymos-mpdf';
        if (!is_dir($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }

        $font_dir = dirname(__DIR__, 2) . '/assets/fonts';
        $config = (new ConfigVariables())->getDefaults();
        $font_config = (new FontVariables())->getDefaults();

        return [
            'mode' => 'utf-8',
            'format' => [228.6, 279.4],
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 25,
            'margin_bottom' => 0,
            'tempDir' => $temp_dir,
            'fontDir' => array_merge($config['fontDir'], [$font_dir]),
            'fontdata' => $font_config['fontdata'] + [
                'poppins' => [
                    'R' => 'Poppins-Regular.ttf',
                    'B' => 'Poppins-SemiBold.ttf',
                    'I' => 'Poppins-Italic.ttf',
                    'BI' => 'Poppins-SemiBoldItalic.ttf',
                ],
                'poppinsthin' => [
                    'R' => 'Poppins-Thin.ttf',
                    'I' => 'Poppins-ThinItalic.ttf',
                ],
                'poppinsextralight' => [
                    'R' => 'Poppins-ExtraLight.ttf',
                    'I' => 'Poppins-ExtraLightItalic.ttf',
                ],
                'poppinslight' => [
                    'R' => 'Poppins-Light.ttf',
                    'I' => 'Poppins-LightItalic.ttf',
                ],
                'poppinsmedium' => [
                    'R' => 'Poppins-Medium.ttf',
                    'I' => 'Poppins-MediumItalic.ttf',
                ],
                'poppinssemibold' => [
                    'R' => 'Poppins-SemiBold.ttf',
                    'I' => 'Poppins-SemiBoldItalic.ttf',
                ],
                'poppinsbold' => [
                    'R' => 'Poppins-Bold.ttf',
                    'I' => 'Poppins-BoldItalic.ttf',
                ],
                'poppinsextrabold' => [
                    'R' => 'Poppins-ExtraBold.ttf',
                    'I' => 'Poppins-ExtraBoldItalic.ttf',
                ],
                'poppinsblack' => [
                    'R' => 'Poppins-Black.ttf',
                    'I' => 'Poppins-BlackItalic.ttf',
                ],
            ],
            'default_font' => 'poppins',
        ];
    }

    private function register_headers_and_footers(Mpdf $mpdf, array $submission): void
    {
        $logo = $this->resolve_footer_image_source('logo');
        $mpdf->DefHTMLHeaderByName(
            'report_header',
            '<table class="header" width="100%" style="margin: 0; border-collapse: collapse; background: #100522; color: #fff; font-size: 9pt; line-height: 1.3;">
                <tr>
                    <td style="padding: 7mm 16mm; vertical-align: middle;">
                        Your Full PR Tech Needs Report<br>
                        Prepared exclusively for <span style="font-family: poppinssemibold;font-weight: 400; color: #fff;">' . $this->esc($this->organisation_name($submission)) . '</span>'
            . (($submission['test_mode'] ?? '') === 'without_email' && !empty($submission['email'])
                ? '<br><span style="font-size: 8pt;">Test email: ' . $this->esc($submission['email']) . '</span>'
                : '') . '
                    </td>
                    <td style="padding: 7mm 16mm; text-align: right; width: 35%; vertical-align: middle;">
                        ' . ($logo !== '' ? '<img src="' . $this->esc_attr($logo) . '" alt="Eponymos" style="display: inline-block; width: 44mm; height: auto; border: 0; margin: 0; padding: 0;" />' : '') . '
                    </td>
                </tr>
            </table>'
        );

        foreach (['dam', 'roi', 'predictability', 'reporting', 'mediacenter', 'social'] as $section) {
            $footer_name = $section . '_footer';
            $mpdf->DefHTMLFooterByName($footer_name, $this->build_footer_image_html($section));
        }
    }

    private function get_footer_images(): array
    {
        return [
            'logo' => 'https://eponymos.com/wp-content/uploads/2026/09/eponymos-logo-menu.png',
            'cover' => 'https://eponymos.com/wp-content/uploads/2026/09/cover-page-image-scaled.jpg',
            'dam' => 'https://eponymos.com/wp-content/uploads/2026/10/page-7-new-image.png',
            'roi' => 'https://eponymos.com/wp-content/uploads/2026/09/page-5-image.png',
            'predictability' => 'https://eponymos.com/wp-content/uploads/2026/09/Asset-3@300x-8.png',
            'reporting' => 'https://eponymos.com/wp-content/uploads/2026/09/Asset-4@300x-8.png',
            'mediacenter' => 'https://eponymos.com/wp-content/uploads/2026/09/pdf-epo-page-7-image.png',
            'social' => 'https://eponymos.com/wp-content/uploads/2026/09/page-8-image.png',
        ];
    }

    private function resolve_footer_image_source(string $section): string
    {
        $images = $this->get_footer_images();
        $url = $images[$section] ?? '';
        if ($url === '') {
            return '';
        }

        $upload_dir = wp_upload_dir();
        $cache_dir = trailingslashit($upload_dir['basedir']) . 'eponymos-mpdf/footer-images';
        if (!is_dir($cache_dir)) {
            wp_mkdir_p($cache_dir);
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $filename = sanitize_file_name(basename($path) ?: $section . '.jpg');
        $local_path = $cache_dir . '/' . $filename;

        if (!file_exists($local_path) && function_exists('wp_remote_get')) {
            $response = wp_remote_get($url, [
                'timeout' => 5,
                'sslverify' => false,
            ]);

            if (!is_wp_error($response)) {
                $status = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);

                if ($status === 200 && $body !== '') {
                    file_put_contents($local_path, $body);
                }
            }
        }

        return file_exists($local_path) ? $local_path : '';
    }

    private function build_footer_image_html(string $section): string
    {
        $image_url = $this->resolve_footer_image_source($section);
        if ($image_url === '') {
            return '';
        }

        if ($section === 'dam') {
            return '<table width="100%" style="margin: 0; border-collapse: collapse; background: transparent; style:padding:0 16mm;">
                <tr>
                    <td width="62%" style="padding: 0 0 0 16mm; margin: 0; vertical-align: middle;">
                        <img src="' . $this->esc_attr($image_url) . '" alt="Digital asset management" style="display: block; width: 60%; height: auto; border: 0; margin: 0; padding: 0;" />
                    </td>
                    <td width="38%" style="padding: 0 16mm 0 8mm; vertical-align: middle; font-family: poppinssemibold; font-size: 14pt; font-weight: 400; line-height: 1.2; color: #100b25; text-align: center;">
                        More than a journalist resource, your digital asset library becomes <span style="color: #d84bd1;">valuable infrastructure for the entire organisation. </span>
                    </td>
                </tr>
            </table>';
        }

        $width = $section === 'dam' ? '50%' : '100%';
        $captions = [
            'predictability' => 'Hyper-personalized pitches tailored to the journalists most likely to cover your story.',
            'reporting' => 'Gain visibility into media activity and emerging narratives as your campaign unfolds.',
            'social' => 'From press release and press conference script to media center and social, Eponymos™ drafts and adapts your message for every channel.<span style="color: #d84bd1;"> Your team stays in control, reviewing and approving everything before it\'s published.</span>',
        ];
        $caption_row = isset($captions[$section])
            ? '<tr><td align="center" style="padding: 0 16mm 3mm; text-align: center; font-family: poppinssemibold; font-size: 14pt; font-weight: 400; line-height: 1.25; color:#100b25;">' . $captions[$section] . '</td></tr>'
            : '';

        return '<table width="100%" style="margin: 0; border-collapse: collapse; background: transparent;">'
            . $caption_row
            . '<tr>
                <td style="padding: 0; margin: 0; width: 100%; text-align: center;">
                    <img src="' . $this->esc_attr($image_url) . '" alt="' . $this->esc_attr($section) . ' footer image" style="display: block; width: ' . $width . '; height: auto; border: 0; margin: 0 auto; padding: 0;" />
                </td>
            </tr>
        </table>';
    }

    public function render_html(array $submission): string
    {
        return $this->html($submission);
    }

    private function html(array $submission): string
    {
        $data = $this->data($submission);
        $tools = $this->tool_rows($data['answers'], $data['option_groups']);
        $labels = array_map(static fn(array $tool): string => $tool['label'], $tools);
        $count = count($labels);
        $phrase = $count === 1 ? 'a tool' : $count . ' disconnected tools';
        $intro = $data['name'] . ' currently runs ' . $phrase . ' to do what Eponymos™ does in a single workflow: ' . $this->join($labels) . '.';

        $rows = '';
        foreach ($tools as $tool) {
            $rows .= '<tr><td>' . $this->esc($tool['category']) . '</td><td align="left" valign="middle"><table align="left" border="0" cellpadding="0" cellspacing="0"><tr><td style="border:0; padding:0 2mm 0 0; vertical-align:middle;"><svg width="14" height="14" viewBox="0 0 30 30" xmlns="http://www.w3.org/2000/svg"><circle cx="15" cy="15" r="15" fill="#00a65a" /></svg></td><td style="border:0; padding:0; vertical-align:middle;">Included</td></tr></table></td><td>' . $this->esc($tool['label']) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="muted">No current-tool selection was recorded.</td></tr>';
        }
        $contact_link = '<a href="' . $this->esc_attr(home_url('/contact-us/')) . '" style="display:inline-block;padding:2mm 4mm;background:#d94f87;color:#fff;text-decoration:none;font-weight:bold;">Contact us for pricing</a>';
        $networked_price = $data['is_networked'] ? $contact_link : $this->money($data['monthly_price']) . '/mo';
        $rows .= '<tr class="cost"><td><b>Monthly software cost</b></td><td class="msc-td"><b class="pink">' . $networked_price . '</b></td><td class="msc-td"><b>' . $this->money($data['spend']) . '/mo</b></td></tr>';
        $summary = $data['is_networked']
            ? 'Networked pricing is tailored to your organisation. ' . $contact_link . ' Software savings and return on investment comparisons require a confirmed quote.'
            : ($data['spend'] > 0 && $data['annual_saving'] > 0
            ? 'You\'re spending <b>' . $this->money($data['spend']) . ' a month</b> across ' . $count . ' disconnected tool' . ($count === 1 ? '' : 's') . ' to do what <b class="pink">Eponymos™ does in one workflow- a ' . $this->money($data['annual_saving']) . '/year reduction</b>, before accounting for the time your team gets back.'
            : 'The software-spend comparison could not be calculated from the submitted answers.');
        $current_tool_copy = 'Your current tool stack supports the research, outreach, distribution, and campaign work your team needs to keep moving.'
            . ' The exact capabilities, limits, pricing, and workflow depend on the products and plans selected.';
        $pr_tools = array_values(array_filter($tools, static function (array $tool): bool {
            return strpos(strtolower($tool['category']), 'pr') !== false;
        }));
        $pr_labels = array_map(static fn(array $tool): string => $tool['label'], $pr_tools);
        $current_tool_title = $pr_labels ? $this->join($pr_labels) : 'Current PR software';
        $dam_tools = array_values(array_filter($tools, static function (array $tool): bool {
            $category = strtolower($tool['category']);
            return strpos($category, 'dam') !== false || strpos($category, 'asset') !== false;
        }));
        $dam_labels = array_map(static fn(array $tool): string => $tool['label'], $dam_tools);
        $asset_answers = $this->selected_values($data['answers'], ['asset', 'content_format', 'content', 'capability']);
        $voice_answers = $this->selected_values($data['answers'], ['spokesperson', 'voice']);
        $page_three_cards = '';
        if ($dam_labels || $asset_answers) {
            $dam_context = $dam_labels
                ? 'Selected asset tool for comparison: ' . $this->join($dam_labels) . '.'
                : 'Selected asset or content requirement: ' . $this->join($asset_answers) . '.';
            $page_three_cards .= '<div class="feature-card"><h2>Digital Asset Management</h2><p>Eponymos™ is built around a full-featured Digital Asset Management (DAM) solution, providing a centralized platform for managing, approving, organizing, and distributing digital assets across your entire organization, including Marketing, Sales, Communications, Brand, Creative, Social Media, Events, HR, and Legal.</p><p>Unlike standalone PR tools, Eponymos™ integrates asset management into the broader communications workflow, ensuring teams can easily find, access, and reuse approved images, videos, and campaign content, with clear workflows for approvals, permissions, and distribution.</p><p>' . $this->esc($dam_context) . '</p></div>';
        }
        if ($voice_answers) {
            $page_three_cards .= '<div class="feature-card"><h2>Spokesperson Voice</h2><p>We analyse a spokesperson’s past press conferences, interviews and public speaking to identify their language, tone and communication patterns. This data trains a language model in their unique voice, enabling optimised press conference scripts that sound natural and remain consistent with how they communicate.</p><ul><li>Selected requirement: ' . $this->esc($this->join($voice_answers)) . '.</li><li>Create statements, scripts, and media content in the established voice of key spokespeople.</li></ul></div>';
        }
        if ($page_three_cards === '') {
            $page_three_cards = '<div class="feature-card"><h2>What you gain beyond PR</h2><p>No specific asset or spokesperson requirement was selected.</p><ul><li>A connected workflow can reduce switching between tools.</li><li>Additional capabilities can be configured around the needs selected by the team.</li></ul></div>';
        }
        $payback = $data['annual_value'] > 0 && $data['annual_investment'] > 0
            ? max(1, (int) floor(($data['annual_investment'] * 365) / $data['annual_value']))
            : null;
        $payback_text = $data['is_networked']
            ? 'Networked pricing is custom. Contact us to discuss pricing before estimating payback or return on investment.'
            : ($payback !== null
            ? 'Combining software consolidation savings with the value of time reclaimed, Eponymos™ could pay for itself in approximately ' . $payback . ' days. Its AI-personalized targeting is designed to help teams produce more relevant pitches and make better use of their PR investment.'
            : 'The submitted answers do not provide enough information to estimate a payback period.');
        $challenge_answers = $this->selected_values($data['answers'], ['frustration', 'challenge', 'pain', 'gap', 'placement', 'outcome']);
        $page_five_context = $challenge_answers
            ? 'In our questionnaire, you indicated that you wanted the following: ' . $this->join($challenge_answers) . '.'
            : 'In our questionnaire, you indicated that you wanted a more consistent and measurable pitching process.';
        $page_eight_context = 'Selected publishing context: LinkedIn, Instagram and Facebook and other social channels.';
        $generated_date = $data['prepared_at'] !== '' ? wp_date(get_option('date_format'), strtotime($data['prepared_at'])) : wp_date(get_option('date_format'));

        return '<!doctype html><html><head><meta charset="utf-8"><style>' . $this->css() . '</style></head><body>'
            . '<section class="cover-page" style="page: cover; background-image: url(\'https://eponymos.com/wp-content/uploads/2026/09/cover-page-image-scaled.jpg\'); background-position: center top; background-size: 100% 100%; background-repeat: no-repeat;">'
            . '<div class="cover-content">'
            . '<img src="https://eponymos.com/wp-content/uploads/2026/09/eponymos-logo-menu.png" alt="Eponymos" style="display: inline-block; width: 70mm; height: auto; border: 0; margin-bottom: 10mm; padding: 0;" />'
            . '<h1>Your Full PR Tech Needs Report</h1>'
            . '<p class="cover-prepared">Prepared exclusively for<br><strong style="font-size:20pt;">' . $this->esc($data['name']) . '</strong></p>'
            . '<p class="cover-date">Generated ' . $this->esc($generated_date) . '</p>'
            . '</div></section>'
            . '<section class="page" style="page: pricing;">'
            . '<div class="content"><h1>' . ($data['is_networked'] ? '1. Your current software and Networked pricing' : '1. What switching to Eponymos™ saves you') . '</h1><p class="intro">' . $this->esc($intro) . '</p>'
            . '<table class="comparison"><thead><tr><th>Category</th><th>With Eponymos™</th><th>' . $this->esc($data['name']) . '\'s current stack</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p class="note">Note: Prices shown are illustrative estimates, not vendor quotes. PR software estimates use buyer-reported Vendr median annual contracts from February 2026, converted to monthly amounts; social media and digital asset management prices are estimates. Actual costs vary by product, plan, users, distribution reach, and contract terms. Confirm current quotes before making a purchasing decision.</p>'
            . '<div class="metrics"><table class="metric-values"><tr><td><div class="metric-value">' . ($data['annual_saving'] === null ? 'Not available' : $this->money($data['annual_saving'])) . '</div><div class="metric-label">software savings / year</div></td><td><div class="metric-value">' . ($data['hours'] === null ? 'N/A' : $this->number(ceil($data['hours'])) . ' hrs') . '</div><div class="metric-label">reclaimed / month</div></td><td class="metric-last"><div class="metric-value">' . ($data['productivity'] === null ? 'N/A' : $this->money($data['productivity'])) . '</div><div class="metric-label">productivity value / year</div></td></tr></table><p>' . $this->esc($data['pitch_text']) . '</p><p class="summary"><strong>' . $summary . '</strong></p></div>'
            . '</div></section>'
            . '<section class="page page-two" style="page: tech_report; page-break-before: always;">'
            . '<div class="content"><h1>2. Hyper-personalized pitching, not just better targeting</h1>'
            . '<div class="pitch-card pitch-card-current"><h2>' . $this->esc($current_tool_title) . '</h2><p>' . $this->esc($current_tool_copy) . '</p><p>Your team may still need to move between systems to find contacts, prepare outreach, coordinate distribution, and track what happens next. Results and efficiency vary with the tools, plans, data, and processes in use.</p></div>'
            . '<div class="versus">VS</div>'
            . '<div class="pitch-card pitch-card-eponymos"><h2>Eponymos™: Personalized Media Outreach at Scale</h2><p>Eponymos™ can generate personalized pitches for more than one million journalists, tailored to each reporter’s recent coverage, writing style, and interests.</p><p>For press releases, Eponymos™ identifies the journalists most likely to cover your story based on their recent work and current interests, then creates a personalized release for each journalist on that targeted media list.</p><p><strong>Global reach, targeted relevance:</strong> Scale personalized pitches across a database of over one million journalists while delivering tailored press releases to the specific journalists most relevant to your story today.</p></div>'
            . '</div></section>'
            . '<section class="page page-three" style="page: dam; page-break-before: always;">'
            . '<div class="content"><h1>3. What you gain beyond PR</h1>' . $page_three_cards . '</div></section>'
            . '<section class="page page-four" style="page: roi; page-break-before: always;">'
            . '<div class="content"><h1>4. Return on investment</h1>'
            . '<div class="roi-card"><table class="roi-values"><tr><td><div class="metric-value">' . ($data['annual_investment'] === null ? $contact_link : $this->money($data['annual_investment'])) . '</div><div class="metric-label">annual investment</div></td><td><div class="metric-value">' . $this->money($data['annual_value']) . '</div><div class="metric-label">annual value delivered</div></td><td class="metric-last"><div class="metric-value">' . ($data['roi'] === null ? 'Not available' : '~' . $this->number($data['roi']) . '%') . '</div><div class="metric-label">estimated first-year ROI</div></td></tr></table><p>' . $this->esc($payback_text) . '</p></div>'
            . '<div class="roi-image-caption"><strong>Save on software.<br>Reclaim team hours.<br><span class="pink">Get more from your PR investment.</span></strong></div>'
            . '</div></section>'
            . '<section class="page page-five" style="page: predictability; page-break-before: always;">'
            . '<div class="content"><h1>5. Greater predictability of outcomes</h1><div class="feature-card page-five-card"><h2>Data-Driven Journalist Matching, Opportunity Analysis &amp; Placement</h2><p>Media relationships are unpredictable by nature - Eponymos™ replaces guesswork with a repeatable, data-informed workflow.</p><p>' . $this->esc($page_five_context) . '</p><h3>What Eponymos™ Helps You Achieve</h3><ul><li>Journalist matching and opportunity signals help prioritise relevant contacts and stories.</li><li>Reviewing performance in real time helps the team improve the next pitch instead of relying only on retrospective reporting.</li></ul></div></div></section>'
            . '<section class="page page-six" style="page: reporting; page-break-before: always;">'
            . '<div class="content"><h1>6. Reporting that sharpens your narrative</h1><div class="feature-card page-six-card"><h2>Media Intelligence That Informs Your Campaigns</h2><p>Eponymos™ brings media intelligence into the campaign workflow, helping teams develop more relevant pitches around the stories journalists are most likely to cover.</p><p>Live media insights and sentiment analysis provide a broader view of the evolving media landscape, helping teams understand the narratives, issues, and conversations shaping their communications environment.</p><p>Campaign reporting brings together key activity and performance insights, giving teams a clearer view of their outreach and emerging coverage.</p><p>Clear, accessible reporting helps teams, leadership, and clients understand campaign progress, media activity, and the outcomes of their communications efforts.</p></div></div></section>'
            . '<section class="page page-seven" style="page: mediacenter; page-break-before: always;">'
            . '<div class="content"><h1>7. A media center built for how journalists work now</h1><div class="feature-card page-seven-card"><p>Your Eponymos™ media center is the trusted source journalists and AI assistants turn to for accurate, approved information about your organisation.</p><ul><li>A resource journalists return to: your latest news, background, fact sheets and spokesperson bios, always current and in one place.</li><li>Fast, secure access to visuals: approved images, video and logos are ready to download without email requests.</li><li>Stories with context: every announcement is linked to its supporting assets, so journalists get the full picture and supporting assets quickly.</li><li>Readable by AI: a machine-readable layer helps LLMs and AI search understand your content and cite it accurately.</li><li>Discoverable everywhere: structured content surfaces in traditional search as well as AI-generated answers, putting your approved version in front of the people asking.</li></ul></div></div></section>'
            . '<section class="page page-eight" style="page: social; page-break-before: always;">'
            . '<div class="content"><h1>8. One narrative, pushed consistently Everywhere</h1><div class="feature-card page-eight-card"><h2>Automated, Data-Driven Social Publishing Across Channels</h2><p>Eponymos™ helps keep campaign messaging consistent across the channels selected by your team.</p><ul><li>' . $this->esc($page_eight_context) . '</li><li>Generate aligned channel content from the same pitch and story data.</li><li>Reduce repeated manual adaptation while keeping the narrative coherent wherever it appears.</li></ul></div></div></section>'
            . '<section class="page disclaimer-page" style="page: disclaimer; page-break-before: always;"><div class="content"><h1>Important information about this report</h1>'
            . '<p>This report is generated automatically from the information you provided to the Eponymos™ calculator. It is for general informational and planning purposes only. It is not a quote, offer, contract, or guarantee of any kind.</p>'
            . '<p><strong>Estimates only.</strong> All figures in this report, including costs, savings, time reclaimed, productivity value, return on investment, and payback periods, are illustrative estimates. They are based on your inputs, publicly available or third-party data, and Eponymos assumptions that may not have been independently verified. Actual results depend on factors specific to your organization and may differ materially. Estimated productivity value reflects potential time savings, not guaranteed reductions in cost.</p>'
            . '<p><strong>Third-party products and pricing.</strong> Any references to third-party products, services, or prices are estimates for comparison only and are not vendor quotes. Pricing, features, and terms change over time and vary by plan, usage, and contract. Confirm current details directly with each provider before making a purchasing decision.</p>'
            . '<p><strong>Eponymos features and outcomes.</strong> Descriptions of Eponymos features, capabilities, and benefits are general in nature. Availability and performance may vary by plan and may change without notice. References to improved outcomes, such as media coverage, response rates, or efficiency, describe intended benefits, not guaranteed results.</p>'
            . '<p><strong>No professional advice.</strong> This report does not constitute financial, legal, or procurement advice. You are responsible for your own evaluation and purchasing decisions.</p>'
            . '<p><strong>Trademarks.</strong> All third-party names, products, and brands mentioned are trademarks of their respective owners and are used for identification and comparison purposes only. Their use does not imply affiliation, sponsorship, or endorsement.</p>'
            . '<p><strong>Your information.</strong> Information you submit is handled in accordance with our Privacy Policy <a href="https://eponymos.com/privacy-policy/">https://eponymos.com/privacy-policy/</a>. Use of Eponymos™ is subject to our Terms of Service.</p>'
            . '<p>© 2026 Eponymos™. All rights reserved.</p></div></section>'
            . '</body></html>';
    }

    private function data(array $submission): array
    {
        $inputs = (array) ($submission['calculation_inputs'] ?? []);
        $outputs = (array) ($submission['calculation_outputs'] ?? []);
        $details = (array) ($submission['answers_detail'] ?? []);
        $option_groups = $this->option_group_map((int) ($submission['questionnaire_id'] ?? 0));
        $name = 'Organisation';
        foreach ($details as $answer) {
            if (($answer['question_key'] ?? '') === 'organization_name') {
                $values = (array) ($answer['answer_label'] ?? []);
                $name = trim((string) ($values[0] ?? $answer['answer'] ?? '')) ?: $name;
                break;
            }
        }
        $is_networked = (($submission['pricing_result']['plan'] ?? '') === 'networked');
        $monthly_price = $is_networked ? null : (is_numeric($inputs['monthly_price'] ?? $inputs['eponymos_monthly_price'] ?? null) ? (float) ($inputs['monthly_price'] ?? $inputs['eponymos_monthly_price']) : null);
        $spend = (float) ($outputs['current_monthly_software_cost'] ?? 0);
        $annual_periods = (float) ($inputs['annual_periods'] ?? \Eponymos\Calculation\Methodology::load()->get('roi.annual_periods'));
        $pitches = is_numeric($inputs['pitches_per_month'] ?? null) ? (float) $inputs['pitches_per_month'] : null;
        $current_hours = is_numeric($inputs['current_hours_per_pitch'] ?? null) ? (float) $inputs['current_hours_per_pitch'] : null;
        $new_hours = is_numeric($inputs['new_hours_per_pitch'] ?? null) ? (float) $inputs['new_hours_per_pitch'] : null;
        $hourly_rate = is_numeric($inputs['hourly_rate'] ?? null) ? (float) $inputs['hourly_rate'] : null;
        $productivity_source = trim((string) ($inputs['productivity_source'] ?? '')) ?: 'Eponymos internal planning assumption; not independently benchmarked';
        $productivity_source_date = trim((string) ($inputs['productivity_source_date'] ?? ''));
        $productivity_source_text = 'Source: ' . $productivity_source . ($productivity_source_date !== '' ? ' (' . $productivity_source_date . ')' : '') . '. These figures are estimates and may vary by team.';
        $hours = is_numeric($outputs['hours_reclaimed'] ?? null) ? (float) $outputs['hours_reclaimed'] : null;
        $pitch_text = $pitches !== null && $current_hours !== null && $new_hours !== null && $hourly_rate !== null
            ? 'Illustrative estimate based on ' . $this->number($pitches) . ' pitches per month, ' . $this->number($current_hours) . ' hours per pitch with the current workflow, ' . $this->number($new_hours) . ' hours per pitch with Eponymos, and ' . $this->money($hourly_rate) . ' per hour. ' . $productivity_source_text
            : 'Reclaimed time and productivity were not calculated because no numeric pitches-per-month answer is configured. Methodology assumptions: ' . $this->number((float) ($inputs['current_hours_per_pitch'] ?? 0)) . ' current hours per pitch, ' . $this->number((float) ($inputs['new_hours_per_pitch'] ?? 0)) . ' Eponymos hours per pitch, and ' . $this->money((float) ($inputs['hourly_rate'] ?? 0)) . ' per hour. ' . $productivity_source_text;
        return [
            'name' => $name,
            'answers' => $details,
            'option_groups' => $option_groups,
            'is_networked' => $is_networked,
            'spend' => $spend,
            'monthly_price' => $monthly_price,
            'annual_saving' => $is_networked ? null : (is_numeric($outputs['annual_software_savings'] ?? null)
                ? (float) $outputs['annual_software_savings']
                : ($spend > 0 ? max(0, $spend - $monthly_price) * $annual_periods : null)),
            'productivity' => is_numeric($outputs['annual_productivity_value'] ?? null) ? (float) $outputs['annual_productivity_value'] : null,
            'annual_investment' => $is_networked ? null : (is_numeric($outputs['annual_investment'] ?? null) ? (float) $outputs['annual_investment'] : 0),
            'annual_value' => is_numeric($outputs['total_annual_value'] ?? null) ? (float) $outputs['total_annual_value'] : 0,
            'roi' => is_numeric($outputs['roi_percent'] ?? null) ? (float) $outputs['roi_percent'] : null,
            'selected_categories' => array_values((array) ($inputs['selected_software_categories'] ?? [])),
            'category_values' => (array) ($inputs['software_category_values'] ?? []),
            'prepared_at' => (string) ($submission['created_at'] ?? ''),
            'hours' => $hours,
            'pitch_text' => $pitch_text,
        ];
    }

    private function tool_rows(array $answers, array $option_groups): array
    {
        $rows = [];
        foreach ($answers as $answer) {
            $question_key = (string) ($answer['question_key'] ?? '');
            $raw_values = is_array($answer['answer'] ?? null) ? $answer['answer'] : [$answer['answer'] ?? ''];
            $labels = (array) ($answer['answer_label'] ?? []);
            foreach ($raw_values as $index => $raw_value) {
                $label = (string) ($labels[$index] ?? $raw_value);
                $option = $option_groups[$question_key][(string) $raw_value] ?? [];
                $category = (string) ($option['group'] ?? '');
                $calculation_value = $option['calculation_value'] ?? null;
                if ($category === ''
                    || ($option['calculation_role'] ?? '') !== 'software_savings'
                    || !is_numeric($calculation_value)
                    || (float) $calculation_value <= 0
                    || preg_match('/none|not sure/i', $label)) {
                    continue;
                }
                $rows[] = ['category' => $category, 'label' => (string) $label];
            }
        }
        return $rows;
    }

    private function option_group_map(int $questionnaire_id): array
    {
        if (!$questionnaire_id) {
            return [];
        }

        try {
            $structure = (new \Eponymos\Questionnaire\Repository())->admin_structure($questionnaire_id);
            $competitorCosts = (array) \Eponymos\Calculation\Methodology::load()->get('competitor_costs', []);
            $map = [];
            foreach (($structure['stages'] ?? []) as $stage) {
                foreach (($stage['questions'] ?? []) as $question) {
                    $question_key = (string) ($question['question_key'] ?? '');
                    $calculation_role = (string) ($question['calculation_role'] ?? $question['validation']['calculation_role'] ?? '');
                    if ($question_key === '') {
                        continue;
                    }
                    foreach (($question['option_groups'] ?? []) as $group) {
                        $name = (string) ($group['label'] ?? '');
                        foreach (($group['options'] ?? []) as $option) {
                            $key = (string) ($option['option_key'] ?? '');
                            if ($key !== '' && $name !== '') {
                                $map[$question_key][$key] = [
                                    'group' => $name,
                                    'calculation_value' => $calculation_role === 'software_savings'
                                        ? ($competitorCosts[$key]['value'] ?? ($option['calculation_value'] ?? null))
                                        : ($option['calculation_value'] ?? null),
                                    'calculation_role' => $calculation_role,
                                ];
                            }
                        }
                    }
                }
            }

            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function selected_values(array $answers, array $needles): array
    {
        $values = [];
        foreach ($answers as $answer) {
            $haystack = strtolower((string) ($answer['question_key'] ?? '') . ' ' . (string) ($answer['question_label'] ?? ''));
            foreach ($needles as $needle) {
                if (strpos($haystack, strtolower($needle)) === false) {
                    continue;
                }
                foreach ((array) ($answer['answer_label'] ?? []) as $label) {
                    $label = trim((string) $label);
                    if ($label !== '' && !preg_match('/none|not sure/i', $label)) {
                        $values[] = $label;
                    }
                }
                break;
            }
        }

        return array_values(array_unique($values));
    }

    private function css(): string
    {
        return '@page {
    margin: 0;
}
body {
    margin: 0;
    font-family:
        poppins,
        DejaVu Sans,
        sans-serif;
    color: #100b25;
    font-size: 10pt;
}
.cover-page {
    height: 279mm;
    background-color: #100522;
    background-image: url(https://eponymos.com/wp-content/uploads/2026/09/cover-page-image-scaled.jpg);
    background-position: center top;
    background-size: 100% 100%;
    background-repeat: no-repeat;
    color: #fff;
    overflow: hidden;
}

.cover-page .header {
    display: none !important;
}

.cover-logo {
    width: 55mm;
    height: auto;
    margin-bottom: 10mm;
}
.cover-content {
    padding: 179mm 19.5mm 0;
    box-sizing: border-box;
    color: #fff;
}
.cover-page h1 {
    font-size: 30pt;
    font-weight: 400;
    line-height: 1.1;
    margin: 0 0 6mm;
}
.cover-prepared {
    font-size: 14pt;
    line-height: 1.35;
    margin: 0 0 4mm;
}
.cover-prepared strong {
    font-family: poppinssemibold;
    font-weight: 400;
    font-weight:20pt;
}
.cover-date {
    color: #d8d1df;
    font-size: 9pt;
    margin: 0 0 6mm;
}
.cover-categories {
    width: 100%;
    border-collapse: collapse;
    color: #fff;
    font-size: 9pt;
}
.cover-categories th,
.cover-categories td {
    border-bottom: 0.2mm solid rgba(255, 255, 255, 0.35);
    padding: 2mm 0;
    text-align: left;
}
.cover-categories th:last-child,
.cover-categories td:last-child {
    text-align: right;
}
.cover-categories th {
    font-family: poppinssemibold;
    font-weight: 400;
}
.page {
    min-height: 0;
    padding: 0 0 0;
    box-sizing: border-box;
}
.page-two {
    page-break-before: always;
}
.page-three {
    page-break-before: always;
}
.page-four {
    page-break-before: always;
}
.page-five,
.page-six,
.page-seven,
.page-eight {
    page-break-before: always;
}
.page-eight {
    page-break-after: avoid;
}
.header {
    width: 100%;
    margin: 0 0 12mm;
    padding: 7mm 16mm;
    background: #100522;
    color: #fff;
    border-collapse: collapse;
    box-sizing: border-box;
    font-size: 9pt;
    line-height: 1.3;
}
.header td {
    padding: 5mm 16mm;
    vertical-align: middle;
    font-size:10pt;
}
.header .logo {
    text-align: right;
    width: 40%;
    font-family: poppinssemibold;
    font-size: 16pt;
    font-weight: 400;
}

.header .organisation-name{
    font-family: poppinssemibold;
    font-size: 12pt;
    font-weight: 400;
}


.content {
    padding: 0 15mm;
}
.page h1 {
    font-size: 24pt;
    font-weight: 400;
    line-height: 1.1;
    margin: 0 0 12mm;
}
.page-two h1 {
    max-width: 150mm;
}
.pitch-card {
    border: 0.4mm solid #100b25;
    border-radius: 5mm;
    padding: 7mm 9mm;
    margin: 0 0 5mm;
    font-size: 10pt;
    line-height: 1.4;
}
.pitch-card-current {
    background: #e7e7ea;
    border-color: #e7e7ea;
}
.pitch-card h2 {
    font-family: poppinssemibold;
    font-size: 14pt;
    font-weight: 400;
    line-height: 1.2;
    margin: 0 0 4mm;
}
.pitch-card p {
    margin: 0 0 4mm;
    font-size: 10pt;
}
.pitch-card p:last-child {
    margin-bottom: 0;
}
.versus {
    color: #d84bd1;
    font-family: poppinssemibold;
    font-size: 28pt;
    font-weight: 400;
    line-height: 1;
    text-align: center;
    margin: 4mm 0;
}
.pitch-card-eponymos {
    background: #fff;
}
.feature-card {
    border: 0.4mm solid #100b25;
    border-radius: 5mm;
    padding: 3mm 9mm;
    margin: 0 0 5mm;
    font-size: 10pt;
    line-height: 1.4;
}
.feature-card h2 {
    font-family: poppinssemibold;
    font-size: 14pt;
    font-weight: 400;
    margin: 0 0 4mm;
}
.feature-card h3 {
    font-family: poppinssemibold;
    font-size: 11pt;
    font-weight: 400;
    margin: 5mm 0 2mm;
}
.feature-card p {
    margin: 0 0 4mm;
    font-size: 10pt;
}
.feature-card ul {
    margin: 0;
    padding-left: 6mm;
}
.feature-card li {
    margin: 2mm 0;
}
.page-three-layout {
    width: 100%;
    margin-top: 7mm;
    overflow: hidden;
}
.page-three-layout:after {
    content: "";
    display: block;
    clear: both;
}
.page-three-visual {
    float: left;
    width: 62%;
    margin-right: 5mm;
}
.page-three-visual img {
    display: block;
    width: 100%;
    height: auto;
    border: 0.4mm solid #100b25;
    border-radius: 4mm;
    background: #fff;
}
.page-three-copy {
    float: left;
    width: calc(38% - 5mm);
    font-family: poppinssemibold;
    font-size: 14pt;
    font-weight: 400;
    line-height: 1.2;
    color: #100b25;
    margin-top: 18mm;
}
.intro {
    font-size: 9pt;
    line-height: 1.35;
    margin: 0 0 7mm;
}
.comparison {
    width: 100%;
    border-collapse: collapse;
    font-size: 9pt;
    margin-bottom: 4mm;
}
.comparison th {
    font-family: poppinssemibold;
    text-align: left;
    border-bottom: 0.4mm solid #100b25;
    padding: 2mm 2mm 2mm 0;
    font-weight: 600;
    font-size: 10pt;
}
.comparison td {
    border-bottom: 0.15mm solid #ddd;
    padding: 2mm 2mm 2mm 0;
    font-size: 10pt;
}
.comparison .cost td {
    font-family: poppins;
    border-top: 0.35mm solid #100b25;
    border-bottom: 0;
    padding-top: 2.5mm;
    font-weight: 600;
}
.comparison .cost td.msc-td {
    font-size: 14pt;
}
.pink {
    color: #d84bd1;
}
.muted{
    color: #777;
}
.note {
    color:#130f23;
    font-size: 8pt;
    font-style: italic;
    line-height: 1.3;
    margin: 5mm 0;
}
.metrics {
    border: 0.4mm solid #100b25;
    border-radius: 4mm;
    padding: 5mm 7mm;
    margin-top: 7mm;
}
.metric-values {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}
.metric-values td {
    width: 33.333%;
    text-align: center;
    vertical-align: top;
    border-right: 0.2mm solid #ccc;
    padding: 0 3mm;
}
.metric-values td:last-child {
    border-right: 0;
}
.metric-values .metric-last {
    border-right: 0;
}
.metric-value {
    font-family: poppinssemibold;
    display: block;
    color: #d84bd1;
    font-size: 28pt;
    font-weight: 400;
    line-height: 1.1;
    white-space: nowrap;
}
.metric-label {
    font-family: poppinssemibold;
    display: block;
    font-size: 10pt;
    font-weight: 400;
    line-height: 1.2;
    margin-top: 1mm;
}
.metrics p {
    font-size: 10pt;
    line-height: 1.35;
    margin: 6mm 0 0;
}
.metrics .summary {
    font-family: poppinssemibold;
    font-size: 14pt;
    font-weight: 400;
    margin-top: 9mm;
}
.metrics .summary b {
    font-family: poppinssemibold;
    font-weight: 400;
}
.metrics .summary strong {
    font-family: poppinssemibold;
    font-weight: 400;
}
.roi-card {
    border: 0.4mm solid #100b25;
    border-radius: 5mm;
    padding: 7mm 9mm;
    margin: 0 0 16mm;
}
.roi-values {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}
.roi-values td {
    width: 33.333%;
    text-align: center;
    vertical-align: top;
    border-right: 0.2mm solid #ccc;
    padding: 0 3mm;
}
.roi-values .metric-last {
    border-right: 0;
}
.roi-card p {
    font-size: 9pt;
    line-height: 1.35;
    margin: 8mm 0 0;
}
.roi-image-caption {
    margin-top: 35mm;
    text-align: center;
}
.roi-image-caption strong {
    display: block;
    font-family: poppinssemibold;
    font-size: 14pt;
    font-weight: 400;
    line-height: 1.25;
    margin-bottom: 6mm;
}
.roi-image-caption strong span {
    color: #d84bd1;
}
.roi-image-caption img {
    display: block;
    width: 100%;
    max-width: none;
    height: auto;
    margin: 0;
}
.header b {
    font-family: poppins;
    font-weight: 700;
}
.disclaimer-page h1 {
    font-size: 14pt;
    margin-bottom: 6mm;
}
.disclaimer-page p {
    font-size: 9pt;
    line-height: 1.3;
    margin: 0 0 3mm;
}
@page {
    margin: 0;
    margin-top: 34mm;
    margin-bottom: 26mm;
    margin-header: 0;
    margin-footer: 0;
    header: none;
    footer: none;
}
@page cover {
    margin: 0;
    margin-top: 0;
    margin-bottom: 0;
    header: none;
    footer: none;
}
@page pricing,
@page tech_report,
@page dam,
@page roi,
@page predictability,
@page reporting,
@page mediacenter,
@page social,
@page disclaimer {
    margin-top: 34mm;
    margin-bottom: 26mm;
    header: html_report_header;
    footer: none;
}
@page dam {
    footer: html_dam_footer;
}
@page roi {
    footer: html_roi_footer;
}
@page predictability {
    footer: html_predictability_footer;
}
@page reporting {
    footer: html_reporting_footer;
}
@page mediacenter {
    footer: html_mediacenter_footer;
}
@page social {
    footer: html_social_footer;
}
';
    }

    private function organisation_name(array $submission): string
    {
        $details = (array) ($submission['answers_detail'] ?? []);
        $name = 'Organisation';

        foreach ($details as $answer) {
            if (($answer['question_key'] ?? '') === 'organization_name') {
                $values = (array) ($answer['answer_label'] ?? []);
                $name = trim((string) ($values[0] ?? $answer['answer'] ?? '')) ?: $name;
                break;
            }
        }

        return $name;
    }

    private function join(array $items): string
    {
        $items = array_values(array_unique(array_filter(array_map('strval', $items))));
        if (!$items) {
            return 'no current tools';
        }
        if (count($items) === 1) {
            return $items[0];
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }

    private function esc($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    private function esc_attr($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    private function money($value): string
    {
        return '$' . number_format((float) $value, 0);
    }

    private function number($value): string
    {
        return number_format((float) $value, 0);
    }

    private function category_label(string $category): string
    {
        return [
            'pr_software' => 'PR software',
            'social_media_tools' => 'Social media tools',
            'digital_asset_management' => 'Digital asset management',
        ][$category] ?? ucwords(str_replace('_', ' ', $category));
    }
}

