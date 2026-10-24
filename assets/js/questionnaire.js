  /**
   * Eponymos Questionnaire — Stage Controller
   *
   * Architecture:
   *   - One stage rendered at a time.
   *   - StageController owns the state for the current stage.
   *   - After each stage completes: show result, offer continue to next stage.
   *   - The backend is authoritative for stage order, questions, validation,
   *     calculation, and results. The frontend only controls navigation.
   */
  (function () {
    'use strict';

    var siteMain = document.querySelector('body.page-id-924 .site-main');
    var siteHeader = document.querySelector('.ekit-template-content-markup.ekit-template-content-header.ekit-template-content-theme-support');
    if (siteMain && siteHeader) {
      var updateHeaderHeight = function () {
        siteMain.style.setProperty('--eponymos-site-header-height', siteHeader.getBoundingClientRect().height + 'px');
      };
      updateHeaderHeight();
      if ('ResizeObserver' in window) {
        new ResizeObserver(updateHeaderHeight).observe(siteHeader);
      } else {
        window.addEventListener('resize', updateHeaderHeight);
      }
    }

    var cfg = window.EponymosQuestionnaire;
    if (!cfg) return;

    /* ── Utilities ─────────────────────────────────────────────────────────── */

    function esc(v) {
      return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
      });
    }

    function post(url, body) {
      var headers = { 'Content-Type': 'application/json' };
      if (cfg.testMode && cfg.testMode.enabled && cfg.testMode.nonce) {
        headers['X-WP-Nonce'] = cfg.testMode.nonce;
      }
      return fetch(url, {
        method: 'POST',
        headers: headers,
        body: JSON.stringify(body),
      }).then(function (r) {
        return r.json().then(function (x) {
          if (!r.ok) throw x;
          return x;
        });
      });
    }

    function getSavedAnswers(questionnaireId) {
      try {
        return JSON.parse(localStorage.getItem('eponymos_answers_' + questionnaireId) || '{}');
      } catch (e) {
        return {};
      }
    }

    function saveCurrentAnswers(questionnaireId, answers) {
      try {
        var existing = {};
        try {
          existing = JSON.parse(localStorage.getItem('eponymos_answers_' + questionnaireId) || '{}');
        } catch (e) {
          existing = {};
        }
        var merged = Object.assign({}, existing, answers || {});
        localStorage.setItem('eponymos_answers_' + questionnaireId, JSON.stringify(merged));
      } catch (e) { }
    }

    function isFollowupStage(stage, stageIndex) {
      return stageIndex > 0 || Number(stage && stage.position || 0) > 0;
    }

    function loadingStepMarkup(label, active) {
      return '<p class="eponymos-loading-step' + (active ? ' is-active' : '') + '" data-loading-step>' +
        '<span class="eponymos-loading-icon" aria-hidden="true"></span>' +
        '<span>' + esc(label) + '</span>' +
        '</p>';
    }

    function updateStaticIntroText(root, stageIndex) {
      var staticText = root && root.querySelector('.eponymos-questionnaire-static-text');
      if (!staticText) return;

      var title = staticText.querySelector('p');
      var body = staticText.querySelector('span');
      if (!title || !body) return;

      if (stageIndex > 0) {
        title.textContent = 'Get your full PR Tech Needs Report';
        body.textContent = 'Answer a few more questions and we’ll refine your estimate, identify where Eponymos™ could consolidate your current PR tools, and recommend the right setup for your organisation.';
        return;
      }

      title.textContent = 'Build your Eponymos™ plan';
      body.textContent = 'Answer a few questions to get your personalized estimate.';
    }

    /* ── Screen manager ────────────────────────────────────────────────────── */

    function Screens(root) {
      this.root = root;
      this.artLayer = root.parentElement ? root.parentElement.querySelector('[data-eponymos-art]') : null;
      var artLayer = this.artLayer;
      if (artLayer) {
        window.addEventListener('scroll', function () {
          if (artLayer.classList.contains('eponymos-art-is-questions')) {
            artLayer.style.bottom = '0';
            artLayer.style.position = 'fixed';
          }
        }, { passive: true });
      }
    }

    Screens.prototype.show = function (name) {
      var self = this;
      self.root.classList.toggle('eponymos-is-result', name === 'result');
      self.root.classList.toggle('eponymos-is-questions', name === 'questions');
      if (self.artLayer) {
        self.artLayer.classList.toggle('eponymos-art-is-questions', name === 'questions');
      }
      self.root.querySelectorAll('.eponymos-screen').forEach(function (el) {
        el.hidden = true;
      });
      var target = self.root.querySelector('.eponymos-screen--' + name);
      if (target) target.hidden = false;
    };

    /* ── Question renderer ─────────────────────────────────────────────────── */

    function renderQuestion(container, question, savedAnswer, maximumUsers) {
      var q = question;
      var v = savedAnswer !== undefined ? savedAnswer : (q.type === 'yes_no' ? 'no' : undefined);
      var input = '';

      var optionGroups = Array.isArray(q.option_groups) && q.option_groups.length
        ? q.option_groups
        : [{ label: '', options: q.options || [] }];
      // Library groups are the source of truth for grouped questions. Keep a
      // fallback for older payloads where options were flattened only.
      optionGroups = optionGroups.map(function (group) {
        return { label: group.label || '', hide_title: !!group.hide_title, options: Array.isArray(group.options) ? group.options : [] };
      });
      var instruction = '';

      if (q.type === 'single_choice' || q.type === 'yes_no') {
        instruction = 'Choose one.';
        var groupedSingleChoice = Array.isArray(q.option_groups) && q.option_groups.length > 1;
        input = optionGroups.map(function (group, groupIndex) {
          var groupName = groupedSingleChoice ? 'answer_group_' + groupIndex : 'answer';
          var selectedValue = groupedSingleChoice && Array.isArray(v)
            ? (v[groupIndex] || '')
            : (groupedSingleChoice ? '' : v);
          return '<div class="eponymos-option-group">' +
            (!group.hide_title && group.label ? '<h3 class="eponymos-option-group-title">' + esc(group.label) + '</h3>' : '') +
            (group.options || []).map(function (o) {
              return '<label class="eponymos-option-label">' +
                '<input type="radio" name="' + esc(groupName) + '" data-answer-group="' + esc(String(groupIndex)) + '" value="' + esc(o.option_key) + '"' + (selectedValue === o.option_key ? ' checked' : '') + '>' +
                '<span>' + esc(o.label) + '</span>' +
                '</label>';
            }).join('') +
            ((group.options || []).length ? '' : '<p class="eponymos-option-group-empty">No options available.</p>') +
          '</div>';
        }).join('');
      } else if (q.type === 'multiple_choice') {
        instruction = 'Choose any that apply.';
        var checked = Array.isArray(v) ? v : [];
        input = optionGroups.map(function (group) {
          return '<div class="eponymos-option-group">' +
            (!group.hide_title && group.label ? '<h3 class="eponymos-option-group-title">' + esc(group.label) + '</h3>' : '') +
            (group.options || []).map(function (o) {
              return '<label class="eponymos-option-label">' +
                '<input type="checkbox" name="answer" value="' + esc(o.option_key) + '"' + (checked.indexOf(o.option_key) !== -1 ? ' checked' : '') + '>' +
                '<span>' + esc(o.label) + '</span>' +
                '</label>';
            }).join('') +
            ((group.options || []).length ? '' : '<p class="eponymos-option-group-empty">No options available.</p>') +
          '</div>';
        }).join('');
      } else {
        var inputType = q.type === 'number' ? 'number' : q.type === 'email' ? 'email' : 'text';
        var validation = q.validation || {};
        var activeUsersQuestion = q.calculation_role === 'active_users' || validation.calculation_role === 'active_users';
        var activeUsersMin = Number.isInteger(Number(validation.min)) && Number(validation.min) >= 1 ? Number(validation.min) : 1;
        var activeUsersMax = Number(maximumUsers);
        if (!Number.isInteger(activeUsersMax) || activeUsersMax < activeUsersMin) activeUsersMax = activeUsersMin;
        input = '<input type="' + inputType + '" name="answer" class="eponymos-text-input"' +
          (activeUsersQuestion ? ' min="' + activeUsersMin + '" step="1" max="' + activeUsersMax + '" required' : '') +
          (q.placeholder ? ' placeholder="' + esc(q.placeholder) + '"' : '') +
          ' value="' + esc(v || '') + '">';
      }

      container.innerHTML =
        '<h2 class="eponymos-question-title">' + esc(q.label) + '</h2>' +
        (q.help_text ? '<p class="eponymos-help-text">' + esc(q.help_text) + '</p>' : '') +
        (instruction ? '<p class="eponymos-option-instruction">' + instruction + '</p>' : '') +
        '<div class="eponymos-options' + (optionGroups.length > 1 ? ' eponymos-options--grouped' : '') + '">' + input + '</div>';
    }

    function renderEmailGate(container, savedEmail, testMode) {
      var testEmail = testMode && testMode.enabled ? testMode.placeholderEmail : '';
      container.innerHTML =
        '<h2 class="eponymos-question-title">Your estimate is almost ready.</h2>' +
        '<p class="eponymos-help-text">' + (testEmail ? 'Administrator test run. A placeholder email is used.' : 'Enter your work email to view your Eponymos™ estimate and receive your personalized report.') + '</p>' +
        '<div class="eponymos-options">' +
        '<label for="eponymos-work-email">' + (testEmail ? 'Test placeholder email' : 'Work email') + '</label>' +
        '<input type="email" id="eponymos-work-email" class="eponymos-text-input" data-email required placeholder="name@company.com" value="' + esc(testEmail || savedEmail || '') + '"' + (testEmail ? ' readonly' : '') + '>' +
        '</div>' +
        '<button type="button" class="eponymos-btn eponymos-btn--primary" data-submit-stage>View my estimate</button>';
    }

    /* ── Stage result renderer ─────────────────────────────────────────────── */

    function renderStageTwoReport(container, result, stageIndex) {
      var pricing = result.pricing || {};
      var outputs = result.calculation_outputs || {};
      var inputs = result.calculation_inputs || {};
      var isNetworked = pricing.plan === 'networked';
      var hasPrice = !isNetworked && pricing.monthly_estimate !== null && pricing.monthly_estimate !== undefined && pricing.monthly_estimate !== '' && Number.isFinite(Number(pricing.monthly_estimate));
      var estimate = hasPrice ? Number(pricing.monthly_estimate) : null;
      var annualPeriods = Number(result.calculation_inputs && result.calculation_inputs.annual_periods || 0);
      var annualEponymos = hasPrice ? Number(outputs.annual_investment || estimate * annualPeriods) : null;
      var annualValue = Number(outputs.total_annual_value || 0);
      var selectedTools = Array.isArray(result.selected_tools) ? result.selected_tools : [];
      var selectedCategories = Array.isArray(result.selected_software_categories)
        ? result.selected_software_categories
        : (Array.isArray(inputs.selected_software_categories) ? inputs.selected_software_categories : []);
      if (!selectedCategories.length && selectedTools.length) {
        var categoryMap = {};
        selectedTools.forEach(function (tool) {
          var name = String(tool.option_group_name || tool.category || '').trim();
          if (name) categoryMap[name] = true;
        });
        selectedCategories = Object.keys(categoryMap);
      }
      var categoryLabels = {
        pr_software: 'PR software',
        social_media_tools: 'Social media tools',
        digital_asset_management: 'Digital asset management'
      };
      var selectedCategoryTotal = estimate;
      var currentMonthlySpend = Number(outputs.current_monthly_software_cost || inputs.current_monthly_software_cost || outputs.monthly_software_cost || 0);
      var annualTraditional = currentMonthlySpend * annualPeriods;
      var annualSoftwareSavings = hasPrice ? Number(outputs.annual_software_savings || (Number(outputs.monthly_software_savings || inputs.monthly_software_savings || 0) * annualPeriods) || 0) : null;
      var hoursReclaimed = outputs.hours_reclaimed === null || outputs.hours_reclaimed === undefined || !Number.isFinite(Number(outputs.hours_reclaimed))
        ? null
        : Number(outputs.hours_reclaimed);
      var annualProductivity = outputs.annual_productivity_value === null || outputs.annual_productivity_value === undefined || !Number.isFinite(Number(outputs.annual_productivity_value))
        ? null
        : Number(outputs.annual_productivity_value);
      var productivitySource = inputs.productivity_source || 'Eponymos internal planning assumption; not independently benchmarked';
      var productivitySourceDate = inputs.productivity_source_date ? ' (' + inputs.productivity_source_date + ')' : '';
      var productivityInputs = Number(inputs.current_hours_per_pitch || 0).toLocaleString() + ' current hours per pitch, ' + Number(inputs.new_hours_per_pitch || 0).toLocaleString() + ' Eponymos hours per pitch, and $' + Number(inputs.hourly_rate || 0).toLocaleString(undefined, { maximumFractionDigits: 0 }) + ' per hour';
      var productivityAssumptions = inputs.pitches_per_month !== null && inputs.pitches_per_month !== undefined && inputs.pitches_per_month !== '' && Number.isFinite(Number(inputs.pitches_per_month))
        ? 'Estimate uses ' + Number(inputs.pitches_per_month).toLocaleString() + ' pitches/month and ' + productivityInputs + '. Source: ' + productivitySource + productivitySourceDate + '. These figures are estimates and may vary by team.'
        : 'Productivity assumptions: ' + productivityInputs + '. Source: ' + productivitySource + productivitySourceDate + '. These figures are estimates; reclaimed values require a monthly pitch-volume answer.';
      if (hasPrice && !(annualSoftwareSavings > 0) && Number.isFinite(currentMonthlySpend) && Number.isFinite(estimate)) {
        annualSoftwareSavings = Math.max(0, currentMonthlySpend - estimate) * annualPeriods;
      }
      var annualSavingsDisplay = hasPrice ? Math.max(0, annualSoftwareSavings) : 0;
      var formatMoney = function (value) {
        return '$' + Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
      };
      var spendBasis = inputs.stage_2_benchmark_source || 'based on typical pricing for the tools you selected';
      var stageTwoSavingsCallout = annualSavingsDisplay > 0 && annualTraditional > 0
        ? '<div class="eponymos-report-annual-callout"><span>$</span><p>Potentially save <strong>' + esc(formatMoney(annualSavingsDisplay)) + ' a year.</strong><br><b>' + esc(Math.round(annualSavingsDisplay / annualTraditional * 100)) + '% less</b> than your selected tools.</p></div>'
        : '';
      var stageTwoSummary = isNetworked
        ? 'Networked pricing is tailored to your organisation. Contact us for a quote; software savings and ROI comparisons require confirmed pricing.'
        : annualSavingsDisplay > 0
        ? 'You\'re spending <strong>' + esc(formatMoney(currentMonthlySpend)) + ' a month</strong> across ' + esc(String(selectedTools.length)) + ' selected tools to do what <strong>Eponymos&trade; does in one workflow</strong> — a <strong>' + esc(formatMoney(annualSavingsDisplay)) + '/year reduction</strong>, before accounting for the time your team gets back.'
        : 'Based on these answers, no annual software-cost saving is projected. Reclaimed team time is shown separately where pitch-volume data is available.';
      var softwareSavingsMetric = isNetworked
        ? '<div><strong>Not available</strong><span>software savings / year</span></div>'
        : annualSavingsDisplay > 0
        ? '<div><strong>' + esc(formatMoney(annualSavingsDisplay)) + '</strong><span>software savings / year</span></div>'
        : '';
      var lowerCostBenefit = isNetworked
        ? '<article><h4>Custom Networked pricing</h4><p>Contact our team to discuss pricing for your organisation.</p><a class="eponymos-btn eponymos-btn--primary" href="/contact-us/">Contact us</a></article>'
        : annualSavingsDisplay > 0
        ? '<article><h4>Lower total cost</h4><p>Consolidate tools and save ' + esc(formatMoney(annualSavingsDisplay)) + ' every year.</p></article>'
        : '<article><h4>Cost comparison</h4><p>No software-cost saving is projected from the answers provided.</p></article>';
      var selectedToolPriceNote = 'Selected-tool prices are estimates, not vendor quotes. Actual costs vary by product and contract.';
      var selectedToolRows = selectedTools.map(function (tool) {
        var label = typeof tool === 'object' ? (tool.label || tool.name || '') : String(tool);
        var value = typeof tool === 'object' ? Number(tool.value || tool.calculation_value || 0) : 0;
        var category = typeof tool === 'object' ? (tool.category || tool.option_group_name || '') : '';
        return '<li><span>' + esc(label) + (category ? '<small>' + esc(category) + '</small>' : '') + '</span>';
      }).join('');
      var pricingComparison = isNetworked
        ? '<section class="eponymos-report-cta"><div><h3>Networked pricing</h3><p>Networked pricing is tailored to your organisation. Contact our team to discuss your requirements.</p></div><a class="eponymos-btn eponymos-btn--primary eponymos-report-button" href="/contact-us/">Contact us for pricing</a></section>'
        : '<section class="eponymos-report-comparison"><h3>Your current PR tools vs Eponymos&trade;</h3><div class="eponymos-report-columns"><div class="eponymos-report-card"><h4>Your current PR tools</h4><ul>' + selectedToolRows + '</ul><p class="eponymos-report-total"><span>Monthly spend ' + esc(spendBasis) + '</span><strong>' + esc(formatMoney(currentMonthlySpend)) + '<small>month</small></strong></p></div><div class="eponymos-report-vs" aria-hidden="true">VS</div><div class="eponymos-report-card eponymos-report-card--eponymos"><h4>Eponymos&trade;</h4><ul>' + (selectedCategories.length ? selectedCategories.map(function (category) { var label = categoryLabels[category] || category; return '<li><span>' + esc(label) + '</span></li>'; }).join('') : '<li><span>No software categories selected</span></li>') + '</ul><p class="eponymos-report-total"><span>Your Eponymos&trade; estimate</span><strong>' + esc(formatMoney(selectedCategoryTotal)) + '<small>month</small></strong></p></div></div></section>';
      var html =
        '<div class="eponymos-stage-two-report">' +
        '<main class="eponymos-report-content">' +
        '<section class="eponymos-report-intro">' +
        '<div><h2>Here is your personalized PR tech needs report</h2><p>Based on your answers, we\'ve analysed your current setup and created a recommendation to help you work smarter, simplify your stack and tell better stories.</p></div>' +
        '<div class="eponymos-report-monthly"><span>' + (isNetworked ? 'Networked pricing' : 'Your monthly estimate') + '</span>' + (isNetworked ? '<a class="eponymos-btn eponymos-btn--primary" href="/contact-us/">Contact us</a>' : '<strong>' + esc(formatMoney(selectedCategoryTotal)) + '</strong>') + '</div>' +
        '</section>' +
        pricingComparison +
        '<p class="eponymos-report-summary">' + stageTwoSummary + '</p>' +
        '<section class="eponymos-report-metrics' + (annualSavingsDisplay > 0 ? '' : ' eponymos-report-metrics--two') + '">' + softwareSavingsMetric + '<div><strong>' + (hoursReclaimed === null ? 'N/A' : esc(Math.ceil(hoursReclaimed).toLocaleString() + ' hrs')) + '</strong><span>reclaimed / month</span></div><div><strong>' + (annualProductivity === null ? 'N/A' : esc(formatMoney(annualProductivity))) + '</strong><span>productivity value / year</span></div></section>' +
        '<p class="eponymos-report-productivity-note">' + esc(productivityAssumptions) + '</p>' +
        '<div class="eponymos-report-benefits-title"> <h3>How Eponymos&trade; can benefit you</h3> </div>' +
        '<section class="eponymos-report-benefits"><div class="eponymos-benefit-grid">' +
        lowerCostBenefit +
        '<article><h4>Built for PR &amp; comms teams</h4><p>From pitch to placement. Designed for how PR really works.</p></article>' +
        '<article><h4>Pay only when it\'s used</h4><p>Flexible usage-based pricing - you only pay for active use, no wasted licenses.</p></article>' +
        '<article><h4>Consistent spokesperson voice</h4><p>Create statements, scripts and media content in the established voice of your key spokesperson.</p></article>' +
        '<article><h4>One platform, less complexity</h4><p>Everything in one place. No more switching between tools or juggling logins.</p></article>' +
        '<article><h4>Smarter journalist targeting</h4><p>Use media intelligence to identify and reach the journalists most relevant to each story.</p></article>' +
        '</div></section>' +
        (isNetworked ? '' : '<section class="eponymos-report-savings"><h3>See how Eponymos&trade; saves you money</h3><p>Against boilerplate software costs, Eponymos&trade; works with every new licence and platform. Eponymos&trade; keeps your tech stack consolidated and you with total actual usage.</p>' +
        '<div class="eponymos-report-chart"><div class="eponymos-report-chart-labels"><span><b>Selected tools</b><strong>' + esc(formatMoney(annualTraditional)) + ' year</strong></span><span><b>Eponymos&trade;</b><strong>' + esc(formatMoney(annualEponymos)) + ' year</strong></span></div><svg viewBox="0 0 620 150" role="img" aria-label="Comparison of selected tools and Eponymos annual costs"><g class="eponymos-report-grid"><line x1="42" y1="20" x2="590" y2="20"></line><line x1="42" y1="55" x2="590" y2="55"></line><line x1="42" y1="90" x2="590" y2="90"></line><line x1="42" y1="125" x2="590" y2="125"></line></g><polyline class="eponymos-report-line eponymos-report-line--traditional" points="44,24 130,30 220,34 310,40 400,44 490,50 580,54"></polyline><polyline class="eponymos-report-line eponymos-report-line--eponymos" points="44,116 130,116 220,116 310,116 400,116 490,116 580,116"></polyline><g class="eponymos-report-axis"><text x="0" y="24">$20K</text><text x="0" y="59">$10K</text><text x="0" y="94">$5K</text><text x="0" y="129">$0</text><text x="44" y="143">1</text><text x="130" y="143">2</text><text x="220" y="143">3</text><text x="310" y="143">4</text><text x="400" y="143">5</text><text x="490" y="143">6</text></g></svg><span class="eponymos-report-chart-title">Spend over 12 months</span></div></section>' +
        stageTwoSavingsCallout +
        '<p class="eponymos-savings-footnote">Legacy PR software means established media database, monitoring and distribution platforms sold on annual contracts. The benchmark is interpolated from the configured legacy comparison anchors. Costs vary by users, modules and terms; features are not identical. Your Eponymos cost depends on actual usage.</p>') +
        '<div class="eponymos-report-download">' +
        (result.report_url
          ? '<a class="eponymos-btn eponymos-btn--primary" href="' + esc(result.report_url) + '" target="_blank" rel="noopener" download>Download full report (PDF)</a>'
          : '<button type="button" class="eponymos-btn eponymos-btn--primary" data-download-report>Download full report</button>') +
        '</div>' +
        '</main></div>';

      html += '<div class="eponymos-stage-review-actions" aria-label="Review questionnaire answers">' +
        '<button type="button" class="eponymos-btn eponymos-btn--ghost" data-review-stage="0">Review Stage 1 answers</button>' +
        '<button type="button" class="eponymos-btn eponymos-btn--ghost" data-review-stage="' + esc(stageIndex) + '">Review Stage 2 answers</button>' +
        '</div>';
      html += '<div class="eponymos-new-estimate-actions"><button type="button" class="eponymos-btn eponymos-new-estimate-button" data-start-new-estimate><span class="dashicons dashicons-update" aria-hidden="true"></span>Start a new estimate</button></div>';
      container.innerHTML = html;
    }

    function generateReportHtml(result, stage) {
      var pricing  = result.pricing || {};
      var outputs  = result.calculation_outputs || {};
      var inputs   = result.calculation_inputs  || {};
      var isNetworked = pricing.plan === 'networked';
      var fmt = function (v) { return '$' + Number(v || 0).toLocaleString(undefined, { maximumFractionDigits: 0 }); };
      var rows = [
        ['Monthly estimate',            isNetworked ? 'Contact us for pricing' : fmt(pricing.monthly_estimate)],
        ['Active user days',            Number(pricing.active_user_days || 0).toLocaleString()],
        ['Annual software savings',     isNetworked ? 'Not available without a quote' : fmt(outputs.annual_software_savings)],
        ['Current tools per year',      fmt(Number(inputs.current_monthly_software_cost || 0) * Number(inputs.annual_periods || 0))],
        ['Eponymos per year',           isNetworked ? 'Contact us for pricing' : fmt(Number(pricing.monthly_estimate || 0) * Number(inputs.annual_periods || 0))],
      ];
      if (!isNetworked) rows.splice(2, 0, ['Rate per active user day', fmt(pricing.price_per_active_user_day)]);
      var tableRows = rows.map(function (r) {
        return '<tr><td style="padding:8px 12px;border-bottom:1px solid #eee;color:#555">' + esc(r[0]) + '</td>' +
              '<td style="padding:8px 12px;border-bottom:1px solid #eee;font-weight:700;text-align:right">' + esc(r[1]) + '</td></tr>';
      }).join('');
      return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">' +
        '<title>Eponymos Report — ' + esc(stage.title) + '</title>' +
        '<style>body{font-family:system-ui,sans-serif;max-width:800px;margin:40px auto;padding:0 20px;color:#1d2327}' +
        'h1{font-size:24px;margin-bottom:4px}p.sub{color:#646970;margin-top:0}' +
        'table{width:100%;border-collapse:collapse;margin-top:24px}' +
        'th{background:#f6f7f7;padding:10px 12px;text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#646970}' +
        '.footer{margin-top:40px;font-size:12px;color:#aaa;border-top:1px solid #eee;padding-top:16px}</style>' +
        '</head><body>' +
        '<h1>Eponymos Estimate Report</h1>' +
        '<p class="sub">' + esc(stage.title) + ' — Generated ' + new Date().toLocaleDateString() + '</p>' +
        '<table><thead><tr><th>Metric</th><th style="text-align:right">Value</th></tr></thead><tbody>' +
        tableRows +
        '</tbody></table>' +
        (isNetworked ? '<p><a href="/contact-us/" style="display:inline-block;padding:10px 16px;background:#d94f87;color:#fff;text-decoration:none;font-weight:700;border-radius:4px">Contact us for Networked pricing</a></p>' : '') +
        '<p class="footer">Generated by Eponymos Questionnaire. This estimate is based on your answers and the configured calculation methodology.</p>' +
        '</body></html>';
    }

    function renderStageResult(container, result, stage, nextStage, stageIndex) {
      var questionnaire = container.closest('.eponymos-questionnaire');
      var isNetworked = (result.pricing || {}).plan === 'networked';
      if (questionnaire) {
        var estimateTitle = questionnaire.querySelector('.eponymos-estimate-title h2');
        var estimateDescription = questionnaire.querySelector('.eponymos-estimate-title p');
        if (estimateTitle) {
          estimateTitle.textContent = cfg.testMode && cfg.testMode.enabled
            ? cfg.testMode.title
            : (stageIndex > 0 ? 'Get your full PR Tech Needs Report' : 'Build your Eponymos™ plan');
        }
        if (estimateDescription) {
          estimateDescription.textContent = stageIndex > 0 ? 'Your report is ready!' : 'Your estimate is ready!';
        }

        var existingEstimateDownload = questionnaire.querySelector('.eponymos-estimate-download');
        if (existingEstimateDownload) {
          existingEstimateDownload.remove();
        }
        if (stageIndex > 0 && estimateDescription) {
          var estimateDownload = document.createElement('div');
          estimateDownload.className = 'eponymos-estimate-download';
          estimateDownload.innerHTML = result.report_url
            ? '<a class="eponymos-btn eponymos-btn--primary" href="' + esc(result.report_url) + '" target="_blank" rel="noopener" download>Download full report (PDF)</a>'
            : '<button type="button" class="eponymos-btn eponymos-btn--primary" data-download-report>Download full report</button>';
          estimateDescription.insertAdjacentElement('afterend', estimateDownload);
        }
      }

      if (isFollowupStage(stage, stageIndex)) {
        renderStageTwoReport(container, result, stageIndex);
        var stageTwoCtaSlot = questionnaire && questionnaire.querySelector('.eponymos-report-cta-slot');
        if (stageTwoCtaSlot) {
          stageTwoCtaSlot.innerHTML =
            '<section class="eponymos-report-cta eponymos-report-cta--stage-two">' +
            '<div>' +
            '<h3>' + (isNetworked ? 'Discuss Networked pricing' : 'Ready to take the next step?') + '</h3>' +
            '<p>' + (isNetworked ? 'Networked pricing is tailored to your organisation. Contact our team to discuss your requirements.' : 'Talk to our team about your results and see how Eponymos™ could work for your organisation.') + '</p>' +
            '</div>' +
            '<a class="eponymos-btn eponymos-btn--primary eponymos-report-button" href="/contact-us/">' +
            (isNetworked ? 'Contact us for pricing' : 'Talk to our team') + ' <span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>' +
            '</a>' +
            '</section>';
        }
        return;
      }

      var pricing = result.pricing || {};
      var outputs = result.calculation_outputs || {};
      var isNetworked = pricing.plan === 'networked';
      var hasPrice = !isNetworked && pricing.monthly_estimate !== null && pricing.monthly_estimate !== undefined && pricing.monthly_estimate !== '' && Number.isFinite(Number(pricing.monthly_estimate));
      var estimate = hasPrice
        ? '$' + Number(pricing.monthly_estimate).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        : null;
      var activeDays = Number(pricing.active_user_days || 0);
      var unitPrice = Number(pricing.price_per_active_user_day || 0);
      var stageOneSavings = Number(pricing.stage_1_annual_saving || 0);
      var savingsText = '$' + Math.max(0, stageOneSavings).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
      var planLabel = pricing.plan ? String(pricing.plan).charAt(0).toUpperCase() + String(pricing.plan).slice(1) : 'Configured';
      var users = Number(pricing.active_users || 0);
      var daysPerUser = Number(pricing.working_days_per_month || 0);
      var wholeMoney = function (value) { return '$' + Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 }); };
      var legacySegmentNames = {
        small_team: 'small-team legacy PR software (1-5 users)',
        mid_market: 'mid-market legacy PR software (6-15 users)',
        enterprise: 'enterprise legacy PR software (16+ users)'
      };
      var legacyComparison = 'Compared with ' + (legacySegmentNames[pricing.legacy_segment] || 'the sourced legacy benchmark for your team size') + '.';
      var stateHeadline = '';
      if (pricing.plan === 'networked') {
        stateHeadline = 'Your organization is ready for Networked — talk to us';
      } else if (pricing.upgrade_offer && pricing.upgrade_offer.plan === 'professional') {
        stateHeadline = 'Upgrade to Professional for ' + wholeMoney(pricing.upgrade_offer.monthly_difference) + '/month more: CSM, training, security';
      } else if (pricing.plan === 'professional' && users <= Number(pricing.included_users || 0)) {
        stateHeadline = 'Your ' + users + ' users qualify you for Professional: ' + wholeMoney(pricing.monthly_estimate) + '/month';
      } else if (pricing.plan === 'professional') {
        stateHeadline = 'Professional for your ' + users + ' users: ' + wholeMoney(pricing.monthly_estimate) + '/month';
      } else if (pricing.plan === 'enterprise') {
        stateHeadline = 'Your ' + users + ' users qualify you for Enterprise: ' + wholeMoney(pricing.monthly_estimate) + '/month';
      } else {
        stateHeadline = 'Your estimate: ' + wholeMoney(pricing.monthly_estimate) + '/month (' + users + ' users x ' + daysPerUser + ' days x $' + Number(pricing.price_per_active_user_day || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }) + ')';
      }
      var savingsCallout = !isNetworked && stageOneSavings > 0
        ? '<div class="eponymos-savings-callout"><span>$</span><p>Potentially save <strong>' + esc(savingsText) + ' per year</strong><br>' + esc(legacyComparison) + '</p></div>'
        : '';
      var legacyFootnote = '<p class="eponymos-savings-footnote">Legacy PR software means established media database, monitoring and distribution platforms sold on annual contracts. The benchmark is interpolated from the configured legacy comparison anchors. Costs vary by users, modules and terms; features are not identical. Your Eponymos cost depends on actual usage.</p>';
      var upgradeHtml = (pricing.upgrade_offers || []).map(function (offer) {
        return '<div class="eponymos-upgrade-callout"><strong>Optional ' + esc(String(offer.plan || '').charAt(0).toUpperCase() + String(offer.plan || '').slice(1)) + ' upgrade</strong><br>' + esc('$' + Number(offer.monthly_difference || 0).toLocaleString(undefined, { maximumFractionDigits: 0 }) + ' more per month') + '<br><small>' + esc(offer.message || '') + '</small></div>';
      }).join('');
      var enterpriseContactHtml = pricing.enterprise_contact_required && !isNetworked
        ? '<div class="eponymos-upgrade-callout"><strong>Professional</strong><br>' + esc('$' + Number(pricing.professional_monthly || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })) + '/month<br><strong>Enterprise</strong><br><a href="/contact-us/">' + esc(pricing.enterprise_contact_label || 'Contact us for Enterprise pricing') + '</a></div>'
        : '';
      var nudgeHtml = (pricing.nudges || []).map(function (nudge) {
        return '<p class="eponymos-plan-nudge">' + esc(nudge) + '</p>';
      }).join('');
      var estimateMarkup = isNetworked
        ? '<a class="eponymos-btn eponymos-btn--primary" href="/contact-us/">Contact us for pricing</a>'
        : '<strong>' + esc(estimate || 'Price unavailable') + '</strong>' + (hasPrice ? '<small>' + esc(activeDays.toLocaleString(undefined, { maximumFractionDigits: 0 })) + ' active user days x $' + esc(unitPrice.toLocaleString(undefined, { maximumFractionDigits: 2 })) + '</small>' : '');
      var casualUsageComparison = pricing.plan === 'professional'
        ? '<small class="eponymos-casual-comparison">Casual at your usage: $' + esc(Number(pricing.casual_monthly || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })) + ' per month</small>'
        : '';
      var payNote = isNetworked
        ? '<p class="eponymos-pay-note">Networked pricing is tailored to your organisation. <a href="/contact-us/">Contact us</a> to discuss your requirements.</p>'
        : '<p class="eponymos-pay-note"><strong>You only pay when Eponymos™ is used.</strong><br>$' + esc(unitPrice.toLocaleString(undefined, { maximumFractionDigits: 2 })) + ' per active user, per day — based on your billable team usage.</p>';

      var html =
        '<div class="eponymos-estimate-screen">' +
        '<section class="eponymos-estimate-hero">' +
        '<h3 class="eponymos-plan-state">' + esc(stateHeadline) + '</h3>' +
        '<div class="eponymos-plan-result-grid">' +
        '<div class="eponymos-plan-estimate">' +
        '<div class="eponymos-estimate-summary">' +
        '<span>' + esc(isNetworked ? 'Networked pricing' : planLabel + ' plan — monthly estimate') + '</span>' +
        estimateMarkup +
        casualUsageComparison +
        '</div>' +
        '<section class="eponymos-plan-path-section" aria-label="How your estimate is calculated"><div class="eponymos-plan-path">' +
        '<div><strong>' + esc(users.toLocaleString(undefined, { maximumFractionDigits: 1 })) + '</strong><span>people</span></div><b>&rarr;</b>' +
        '<div><strong>' + esc(daysPerUser.toLocaleString(undefined, { maximumFractionDigits: 1 })) + '</strong><span>days each</span></div><b>&rarr;</b>' +
        '<div><strong>' + esc(activeDays.toLocaleString(undefined, { maximumFractionDigits: 1 })) + '</strong><span>active days</span></div><b>&rarr;</b>' +
        '<div><strong>' + esc(planLabel) + '</strong><span>recommended plan</span></div>' +
        '</div>' +
        '</div>' +
        '</section>' +
        '</div>' +
        '<div class="eponymos-plan-details">' + savingsCallout + upgradeHtml + enterpriseContactHtml + nudgeHtml + (isNetworked ? '' : legacyFootnote) + '</div>' +
        '</div>' +
        '</section>' +

        payNote;

      var ctaHtml = '';
      if (nextStage) {
        ctaHtml =
          '<section class="eponymos-report-cta">' +
          '<div>' +
          '<h3>Want a more accurate recommendation?</h3>' +
          '<p>Answer a few more questions and we’ll refine your estimate, identify where Eponymos™ could consolidate your current PR tools, and recommend the right setup for your organisation.</p>' +
          '</div>' +
          '<button type="button" class="eponymos-btn eponymos-btn--primary eponymos-report-button" data-continue-stage="' + esc(nextStage.id) + '">' +
          'Get your full PR Tech Needs Report <span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>' +
          '</button>' +
          '</section>';
      } else {
        html +=
          '<div class="eponymos-done-block">' +
          '<p class="eponymos-done-msg">Thank you. You\'ll receive your report by email shortly.</p>' +
          '</div>';
      }

      html += '<div class="eponymos-stage-review-actions" aria-label="Review questionnaire answers">';
      html += '<button type="button" class="eponymos-btn eponymos-btn--ghost" data-review-stage="0">Review Stage 1 answers</button>';
      if (stageIndex > 0) {
        html += '<button type="button" class="eponymos-btn eponymos-btn--ghost" data-review-stage="' + esc(stageIndex) + '">Review Stage ' + esc(stageIndex + 1) + ' answers</button>';
      }
      html += '</div>';
      html += '<div class="eponymos-new-estimate-actions"><button type="button" class="eponymos-btn eponymos-new-estimate-button" data-start-new-estimate><span class="dashicons dashicons-update" aria-hidden="true"></span>Start a new estimate</button></div>';

      html += '</div>';
      container.innerHTML = html;

      var ctaSlot = container.closest('.eponymos-questionnaire').querySelector('.eponymos-report-cta-slot');
      if (ctaSlot) {
        ctaSlot.innerHTML = ctaHtml;
      }
    }

    /* ── Stage Controller ──────────────────────────────────────────────────── */

    /**
     * Controls the user experience for a single questionnaire stage.
     *
     * @param {Element} root      The [data-eponymos] root element.
     * @param {Object}  stage     The stage data object (id, title, questions, …).
     * @param {Object}  questionnaire  Top-level questionnaire metadata.
     * @param {Object}  screens   Screens instance.
     * @param {Function} onComplete  Called with (result, nextStage) when stage done.
     */
    function StageController(root, stage, questionnaire, screens, onComplete, onNavigateStage, initialAnswers, stageIndex, submissionId) {
      this.root = root;
      this.stage = stage;
      this.questionnaire = questionnaire;
      this.screens = screens;
      this.onComplete = onComplete;
      this.onNavigateStage = onNavigateStage;
      this.stageIndex = stageIndex;
      this.submissionId = cfg.testMode && cfg.testMode.enabled ? 0 : (submissionId || 0);
      this.isFollowupStage = isFollowupStage(stage, stageIndex);

      this.questions = stage.questions || [];
      this.questionIndex = 0;   // index within this stage's questions
      this.answers = initialAnswers || {};  // accumulated answers for this stage
      this.email = '';
      try {
        this.email = localStorage.getItem('eponymos_email_' + this.questionnaire.id) || '';
        if (!this.submissionId && !(cfg.testMode && cfg.testMode.enabled))
          this.submissionId = Number(localStorage.getItem('eponymos_submission_id_' + this.questionnaire.id) || 0);
      } catch (e) { }

      // DOM refs
      this.questionScreen = root.querySelector('.eponymos-screen--questions');
      this.emailScreen = root.querySelector('.eponymos-screen--email');
      this.resultScreen = root.querySelector('.eponymos-screen--result');
      this.loadingScreen = root.querySelector('.eponymos-screen--loading');
      this.progressEl = root.querySelector('.eponymos-progress');
      this.errorEl = root.querySelector('.eponymos-error');

      this._bindEvents();
    }

    StageController.prototype._bindEvents = function () {
      var self = this;

      // Next / Continue button on question screen
      self.nextBtn = self.questionScreen.querySelector('[data-next]');
      self.backBtn = self.questionScreen.querySelector('[data-back]');
      self.stageOneBtn = self.questionScreen.querySelector('[data-stage-one]');
      self.emailBackBtn = self.emailScreen.querySelector('[data-back]');

      self.onNext = function () { self._handleNext(); };
      self.onBack = function () { self._handleBack(); };
      self.onStageOne = function () {
        self._captureAnswer();
        self.onNavigateStage(0);
      };
      self.onEmailBack = function () { self._showQuestion(); };
      self.onSubmit = function () { self._handleSubmit(); };

      self.nextBtn.addEventListener('click', self.onNext);

      // Back button on question screen
      self.backBtn.addEventListener('click', self.onBack);
      self.stageOneBtn.addEventListener('click', self.onStageOne);

      // Back button on email screen
      self.emailBackBtn.addEventListener('click', self.onEmailBack);

    };

    StageController.prototype.destroy = function () {
      this.nextBtn.removeEventListener('click', this.onNext);
      this.backBtn.removeEventListener('click', this.onBack);
      this.stageOneBtn.removeEventListener('click', this.onStageOne);
      this.emailBackBtn.removeEventListener('click', this.onEmailBack);
      if (this.submitBtn) {
        this.submitBtn.removeEventListener('click', this.onSubmit);
      }
    };

    StageController.prototype.start = function (questionIndex) {
      var requestedIndex = Number(questionIndex);
      var lastIndex = Math.max(0, this.questions.length - 1);
      this.questionIndex = Number.isFinite(requestedIndex)
        ? Math.max(0, Math.min(requestedIndex, lastIndex))
        : 0;
      this._showQuestion();
    };

    StageController.prototype._clearError = function () {
      this.errorEl.textContent = '';
      this.errorEl.classList.remove('is-visible');
    };

    StageController.prototype._setError = function (msg) {
      this.errorEl.textContent = msg;
      this.errorEl.classList.add('is-visible');
    };

    StageController.prototype._updateProgress = function () {
      var total = this.questions.length;
      var current = this.questionIndex + 1;
      this.progressEl.textContent =
        esc(this.stage.title) + ' — Question ' + current + ' of ' + total;
    };

    StageController.prototype._showQuestion = function () {
      var self = this;
      var q = self.questions[self.questionIndex];
      if (!q) { self._showEmailGate(); return; }

      var stageEl = self.questionScreen.querySelector('.eponymos-stage');
      renderQuestion(stageEl, q, self.answers[q.question_key], cfg.maximumUsers);

      stageEl.querySelectorAll('input, textarea, select').forEach(function (field) {
        field.addEventListener('input', function () {
          var question = self.questions[self.questionIndex];
          var validation = question && question.validation || {};
          var activeUsersQuestion = question && (question.calculation_role === 'active_users' || validation.calculation_role === 'active_users');
          if (field.type === 'number' && activeUsersQuestion) {
            var count = Number(field.value);
            var maximum = Number(cfg.maximumUsers);
            if (!Number.isInteger(maximum) || maximum < 1) maximum = 1;
            if (!field.value.trim() || !Number.isSafeInteger(count) || count < 1) {
              self._setError('Please enter a valid number, e.g. 1, 2, 32.');
            } else if (count > maximum) {
              self._setError('For more than ' + maximum + ' users, continue to receive Contact Us pricing.');
            } else {
              self._clearError();
            }
            return;
          }
          if (field.type === 'radio' || field.type === 'checkbox') {
            self._clearError();
            return;
          }
          if ((field.value || '').trim() !== '') {
            self._clearError();
          }
        });
        field.addEventListener('change', function () {
          if (field.type === 'radio' || field.type === 'checkbox' || (field.value || '').trim() !== '') {
            self._clearError();
          }
        });
      });

      // Back button visibility: hide on first question of the first stage
      var backBtn = self.questionScreen.querySelector('[data-back]');
      backBtn.hidden = self.questionIndex === 0;
      self.stageOneBtn.hidden = !self.isFollowupStage;

      try {
        localStorage.setItem('eponymos_questionnaire_stage_' + self.questionnaire.id, String(self.stageIndex));
        localStorage.setItem('eponymos_questionnaire_question_' + self.questionnaire.id, String(self.questionIndex));
        localStorage.setItem('eponymos_questionnaire_screen_' + self.questionnaire.id, 'questions');
        localStorage.removeItem('eponymos_questionnaire_result_' + self.questionnaire.id);
      } catch (e) { }

      self._updateProgress();
      self._clearError();
      self.screens.show('questions');
    };

    StageController.prototype._startLoadingSequence = function () {
      var self = this;
      var container = self.loadingScreen.querySelector('[data-loading-steps]');
      if (!container) {
        var loading = self.loadingScreen.querySelector('.eponymos-loading');
        if (!loading) return;
        container = document.createElement('div');
        container.className = 'eponymos-loading-steps';
        container.setAttribute('data-loading-steps', '');
        var legacyMessage = loading.querySelector('p');
        if (legacyMessage) legacyMessage.remove();
        loading.appendChild(container);
      }

      self._loadingTimers = [];
      self._loadingStartedAt = Date.now();
      var labels = self.isFollowupStage
        ? ['Saving your answers', 'Updating your report', 'Queueing your PDF and email']
        : ['Calculating your estimate'];
      container.innerHTML = loadingStepMarkup(labels[0], true);
      self.screens.show('loading');

      if (labels.length === 1) return;

      var showNextStep = function (index) {
        if (index >= labels.length) return;
        self._loadingTimers.push(window.setTimeout(function () {
          var steps = container.querySelectorAll('[data-loading-step]');
          var previous = steps[steps.length - 1];
          previous.classList.remove('is-active');
          previous.classList.add('is-complete');
          container.insertAdjacentHTML('beforeend', loadingStepMarkup(labels[index], true));
          showNextStep(index + 1);
        }, 900));
      };
      showNextStep(1);
    };

    StageController.prototype._finishLoadingSequence = function (onFinished) {
      var self = this;
      var finish = function () {
        (self._loadingTimers || []).forEach(function (timer) { window.clearTimeout(timer); });
        self._loadingTimers = [];
        self.loadingScreen.querySelectorAll('[data-loading-step]').forEach(function (step) {
          step.classList.remove('is-active');
          step.classList.add('is-complete');
        });
        if (onFinished) onFinished();
      };
      finish();
    };

    StageController.prototype._captureAnswer = function () {
      var self = this;
      var q = self.questions[self.questionIndex];
      if (!q) return true;

      var stageEl = self.questionScreen.querySelector('.eponymos-stage');
      var value;

      if (q.type === 'multiple_choice') {
        var els = Array.prototype.slice.call(stageEl.querySelectorAll('[name=answer]'));
        value = els.filter(function (e) { return e.checked; }).map(function (e) { return e.value; });
      } else if (q.type === 'single_choice' || q.type === 'yes_no') {
        var groupedSingleChoice = Array.isArray(q.option_groups) && q.option_groups.length > 1;
        if (groupedSingleChoice) {
          value = q.option_groups.map(function (_, groupIndex) {
            var checked = stageEl.querySelector('input[data-answer-group="' + groupIndex + '"]:checked');
            return checked ? checked.value : '';
          });
        } else {
          var els = Array.prototype.slice.call(stageEl.querySelectorAll('[name=answer]'));
          var checked = els.find(function (e) { return e.checked; });
          value = checked ? checked.value : '';
        }
      } else {
        var els = Array.prototype.slice.call(stageEl.querySelectorAll('[name=answer]'));
        value = els[0] ? els[0].value.trim() : '';
      }

      if (q.required) {
        if (Array.isArray(value)) {
          if (!value.some(function (entry) { return entry && String(entry).trim() !== ''; })) {
            self._setError('This field is required.');
            return false;
          }
        } else if (!value || String(value).trim() === '') {
          self._setError('This field is required.');
          return false;
        }
      }

      var validation = q.validation || {};
      var activeUsersQuestion = q.calculation_role === 'active_users' || validation.calculation_role === 'active_users';
      if (activeUsersQuestion && (!Number.isSafeInteger(Number(value)) || Number(value) < 1)) {
        self._setError('Please enter a valid number, e.g. 1, 2, 32.');
        return false;
      }

      self.answers[q.question_key] = value;
      saveCurrentAnswers(self.questionnaire.id, self.answers);
      self._clearError();
      return true;
    };

    StageController.prototype._handleNext = function () {
      if (!this._captureAnswer()) return;

      if (this.questionIndex < this.questions.length - 1) {
        this.questionIndex++;
        this._showQuestion();
      } else if (this.isFollowupStage) {
        this._handleSubmit();
      } else {
        this._showEmailGate();
      }
    };

    StageController.prototype._handleBack = function () {
      if (this.questionIndex > 0) {
        this.questionIndex--;
        this._showQuestion();
      }
    };

    StageController.prototype._showEmailGate = function () {
      var self = this;
      var stageEl = self.emailScreen.querySelector('.eponymos-stage');
      if (cfg.testMode && cfg.testMode.enabled) {
        self.email = cfg.testMode.placeholderEmail;
      }
      renderEmailGate(stageEl, self.email, cfg.testMode);
      self.submitBtn = stageEl.querySelector('[data-submit-stage]');
      self.submitBtn.addEventListener('click', self.onSubmit);

      var emailField = stageEl.querySelector('[data-email]');
      if (emailField) {
        emailField.addEventListener('input', function () {
          if (/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test((emailField.value || '').trim())) {
            self._clearError();
          }
        });
        emailField.addEventListener('change', function () {
          if (/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test((emailField.value || '').trim())) {
            self._clearError();
          }
        });
      }

      try {
        localStorage.setItem('eponymos_questionnaire_stage_' + self.questionnaire.id, String(self.stageIndex));
        localStorage.setItem('eponymos_questionnaire_question_' + self.questionnaire.id, String(self.questionIndex));
        localStorage.setItem('eponymos_questionnaire_screen_' + self.questionnaire.id, 'email');
      } catch (e) { }

      self.progressEl.textContent = esc(self.stage.title) + ' — Almost done!';
      self._clearError();
      self.screens.show('email');
    };

    StageController.prototype._handleSubmit = function () {
      var self = this;
      var stageEl = self.emailScreen.querySelector('.eponymos-stage');
      var emailEl = stageEl.querySelector('[data-email]');
      var email = cfg.testMode && cfg.testMode.enabled
        ? cfg.testMode.placeholderEmail
        : (emailEl ? emailEl.value.trim() : self.email);

      if (!self.isFollowupStage && (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email))) {
        self._setError('Please enter a valid work email address.');
        return;
      }

      self.email = email;
      self._clearError();
      try {
        localStorage.setItem('eponymos_email_' + self.questionnaire.id, self.email);
      } catch (e) { }
      self._startLoadingSequence();

      var allAnswers = {};
      try {
        allAnswers = JSON.parse(localStorage.getItem('eponymos_answers_' + self.questionnaire.id) || '{}');
      } catch (e) { allAnswers = {}; }
      Object.keys(self.answers).forEach(function (key) { allAnswers[key] = self.answers[key]; });
      try {
        localStorage.setItem('eponymos_answers_' + self.questionnaire.id, JSON.stringify(allAnswers));
      } catch (e) { }

      post(cfg.stageEndpoint, {
        questionnaire_id: self.questionnaire.id,
        stage_id: self.stage.id,
        submission_id: self.submissionId,
        answers: self.answers,
        all_answers: allAnswers,
        email: self.email,
        test_mode: cfg.testMode && cfg.testMode.enabled ? cfg.testMode.mode : '',
      }).then(function (result) {
        self._finishLoadingSequence(function () {
          self.onComplete(result, result.next_stage || null);
        });
      }).catch(function (err) {
        self._finishLoadingSequence(function () {
          self.screens.show('questions');
          var msg = (err && err.data && err.data.fields)
            ? Object.values(err.data.fields).join(' ')
            : (err && err.message)
              ? err.message + (err.data && err.data.role ? ' Missing: ' + err.data.role + '.' : '')
              : 'We could not submit your answers. Please try again.';
          self._setError(msg);
        });
      });
    };

    /* ── Questionnaire Orchestrator ────────────────────────────────────────── */

    /**
     * Manages the full multi-stage flow.
     * Starts with the first active stage, then hands off to the next on user request.
     */
    function QuestionnaireOrchestrator(root, data) {
      this.root = root;
      this.questionnaire = data.questionnaire;
      this.stages = (data.stages || []).filter(function (s) { return s.status === 'active'; });
      this.stageIndex = 0;
      this.screens = new Screens(root);
      this.progressEl = root.querySelector('.eponymos-progress');
      this.errorEl = root.querySelector('.eponymos-error');
      this.resultScreen = root.querySelector('.eponymos-screen--result');
      this.controller = null;
      this.submissionId = 0;
    }

    QuestionnaireOrchestrator.prototype._rememberProgress = function (stageIndex, questionIndex, screen, result) {
      try {
        localStorage.setItem('eponymos_questionnaire_stage_' + this.questionnaire.id, String(stageIndex));
        localStorage.setItem('eponymos_questionnaire_question_' + this.questionnaire.id, String(questionIndex));
        localStorage.setItem('eponymos_questionnaire_screen_' + this.questionnaire.id, screen || 'questions');
        if (result) {
          localStorage.setItem('eponymos_questionnaire_result_' + this.questionnaire.id, JSON.stringify(result));
        } else {
          localStorage.removeItem('eponymos_questionnaire_result_' + this.questionnaire.id);
        }
      } catch (e) { }
    };

    QuestionnaireOrchestrator.prototype._startNewEstimate = function () {
      var questionnaireId = String(this.questionnaire.id);
      var keys = [
        'eponymos_answers_' + questionnaireId,
        'eponymos_email_' + questionnaireId,
        'eponymos_submission_id_' + questionnaireId,
        'eponymos_questionnaire_stage_' + questionnaireId,
        'eponymos_questionnaire_question_' + questionnaireId,
        'eponymos_questionnaire_screen_' + questionnaireId,
        'eponymos_questionnaire_result_' + questionnaireId
      ];
      this.stages.forEach(function (stage) {
        keys.push('eponymos_answers_stage_' + stage.id);
      });

      keys.forEach(function (key) {
        try { localStorage.removeItem(key); } catch (e) { }
      });

      this.submissionId = 0;
      this._startStage(0, 0);
    };

    QuestionnaireOrchestrator.prototype.start = function () {
      if (!this.stages.length) {
        this.progressEl.textContent = 'This questionnaire has no active stages.';
        return;
      }

      var savedStageIndex = Number(localStorage.getItem('eponymos_questionnaire_stage_' + this.questionnaire.id));
      var savedQuestionIndex = Number(localStorage.getItem('eponymos_questionnaire_question_' + this.questionnaire.id));
      var savedScreen = localStorage.getItem('eponymos_questionnaire_screen_' + this.questionnaire.id);
      var savedResult = null;
      try {
        savedResult = JSON.parse(localStorage.getItem('eponymos_questionnaire_result_' + this.questionnaire.id) || 'null');
      } catch (e) { savedResult = null; }

      var fallbackStageIndex = Number.isFinite(savedStageIndex) && savedStageIndex >= 0 && savedStageIndex < this.stages.length ? savedStageIndex : 0;
      var fallbackQuestionIndex = Number.isFinite(savedQuestionIndex) ? savedQuestionIndex : 0;

      if (savedScreen === 'result' && savedResult && this.stages[fallbackStageIndex]) {
        this.stageIndex = fallbackStageIndex;
        this._showStageResult(this.stages[fallbackStageIndex], savedResult, savedResult.next_stage || null);
        return;
      }

      this._startStage(fallbackStageIndex, fallbackQuestionIndex);
    };

    QuestionnaireOrchestrator.prototype._startStage = function (index, questionIndex) {
      var self = this;
      var stage = self.stages[index];
      if (!stage) return;

      self._rememberProgress(index, questionIndex || 0, 'questions', null);

      if (self.controller) self.controller.destroy();
      self.stageIndex = index;

      var ctaSlot = self.root.querySelector('.eponymos-report-cta-slot');
      if (ctaSlot) {
        ctaSlot.innerHTML = '';
      }

      updateStaticIntroText(self.root, index);

      var savedAnswers = getSavedAnswers(self.questionnaire.id);
      var stageAnswers = {};
      (stage.questions || []).forEach(function (question) {
        if (Object.prototype.hasOwnProperty.call(savedAnswers, question.question_key)) {
          stageAnswers[question.question_key] = savedAnswers[question.question_key];
        }
      });

      var controller = new StageController(
        self.root,
        stage,
        self.questionnaire,
        self.screens,
        function (result, nextStage) {
          self.submissionId = cfg.testMode && cfg.testMode.enabled ? 0 : Number(result.submission_id || self.submissionId || 0);
          if (!(cfg.testMode && cfg.testMode.enabled)) {
            try {
              localStorage.setItem('eponymos_submission_id_' + self.questionnaire.id, String(self.submissionId));
            } catch (e) { }
          }
          self._showStageResult(stage, result, nextStage);
        },
        function (targetIndex) {
          self._startStage(targetIndex);
        },
        stageAnswers,
        index,
        self.submissionId
      );

      self.controller = controller;
      controller.start(questionIndex);
    };

    QuestionnaireOrchestrator.prototype._showStageResult = function (stage, result, nextStage) {
      var self = this;
      var container = self.resultScreen.querySelector('.eponymos-result');

      self._rememberProgress(self.stageIndex, 0, 'result', result);
      renderStageResult(container, result, stage, nextStage, self.stageIndex);
      self.screens.show('result');

      // Wire the continue button (if rendered)
      var continueBtn = self.root.querySelector('[data-continue-stage]');
      if (continueBtn) {
        continueBtn.addEventListener('click', function () {
          // Find the next stage index by matching the stage id
          var nextId = parseInt(continueBtn.getAttribute('data-continue-stage'), 10);
          var nextIndex = -1;
          self.stages.forEach(function (s, i) {
            if (parseInt(s.id, 10) === nextId) nextIndex = i;
          });
          if (nextIndex !== -1) self._startStage(nextIndex);
        });
      }

      // Download the server-generated PDF. Do not build a client-side HTML file.
      var downloadBtn = container.querySelector('[data-download-report]');
      if (downloadBtn) {
        downloadBtn.addEventListener('click', function () {
          if (!result.report_url) return;
          var link = document.createElement('a');
          link.href = result.report_url;
          link.target = '_blank';
          link.rel = 'noopener';
          link.click();
        });
      }

      container.querySelectorAll('[data-review-stage]').forEach(function (button) {
        button.addEventListener('click', function () {
          var targetIndex = parseInt(button.getAttribute('data-review-stage'), 10);
          var targetStage = self.stages[targetIndex];
          var targetQuestions = targetStage && Array.isArray(targetStage.questions) ? targetStage.questions : [];
          self._startStage(targetIndex, 0);
        });
      });

      container.querySelectorAll('[data-start-new-estimate]').forEach(function (button) {
        button.addEventListener('click', function () {
          self._startNewEstimate();
        });
      });

      // Clear saved answers for this stage from localStorage
      try { localStorage.removeItem('eponymos_answers_stage_' + stage.id); } catch (e) { }
    };

    /* ── Bootstrap ─────────────────────────────────────────────────────────── */

    document.querySelectorAll('[data-eponymos]').forEach(function (root) {
      var data = cfg.data;
      if (!data || !data.questionnaire) return;

      var orchestrator = new QuestionnaireOrchestrator(root, data);
      orchestrator.start();
    });

  }());
