/* global jQuery, EponymosAdmin */
;(function ($) {
  'use strict';

  var cfg  = window.EponymosAdmin;
  if (!cfg) return;
  var base = cfg.endpoint;
  var root = $('.eponymos-admin');
  var page = cfg.page; // 'question_library' | 'option_library'

  // ── utilities ───────────────────────────────────────────────────────────────

  function api(path, method, body) {
    return fetch(base + path, {
      method: method || 'GET',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
      body: body ? JSON.stringify(body) : undefined,
    }).then(function (r) {
      return r.json().then(function (x) {
        if (!r.ok) throw x;
        return x;
      });
    });
  }

  function esc(v) {
    return $('<span>').text(v == null ? '' : String(v)).html();
  }

  function slugify(v) {
    v = String(v || '').toLowerCase();
    v = v.replace(/[^a-z0-9]+/g, '_');
    v = v.replace(/^_+|_+$/g, '');
    return v || 'item';
  }

  function notice(msg, isError) {
    var el = root.find('.eponymos-notice');
    el.text(msg).toggleClass('notice-error', !!isError);
    setTimeout(function () { el.text('').removeClass('notice-error'); }, 3000);
  }

  // ── modal ───────────────────────────────────────────────────────────────────

  function closeModal() {
    root.find('[data-builder-modal]').prop('hidden', true);
  }

  function openModal(title, html, onSubmit, afterInsert) {
    var m = root.find('[data-builder-modal]');
    m.find('[data-modal-title]').text(title);
    var $form = m.find('[data-builder-form]')
      .html(
        html +
        '<div class="eponymos-modal-actions">' +
        '<button type="button" class="button" data-modal-close>Cancel</button>' +
        '<button type="submit" class="button button-primary">Save</button>' +
        '</div>'
      )
      .off('submit')
      .on('submit', function (e) { e.preventDefault(); onSubmit($(this)); });
    m.prop('hidden', false).find('input,textarea,select').first().trigger('focus');
    if (typeof afterInsert === 'function') afterInsert($form);
  }

  root.on('click', '[data-modal-close]', closeModal);

  function info(text) { return '<span class="epo-info" tabindex="0" title="' + esc(text) + '" aria-label="' + esc(text) + '">i</span>'; }

  function mField(label, name, value, type, help) {
    type = type || 'text';
    return (
      '<label class="eponymos-modal-field"><span class="epo-field-label">' + esc(label) + (help ? info(help) : '') + '</span>' +
      (type === 'textarea'
        ? '<textarea name="' + name + '">' + esc(value || '') + '</textarea>'
        : '<input type="' + type + '" name="' + name + '" value="' + esc(value || '') + '">') +
      '</label>'
    );
  }

  function mKeyField(label, name, value, help) {
    return (
      '<label class="eponymos-modal-field"><span class="epo-field-label">' + esc(label) + info(help || 'A short permanent name used by the system. It is not shown to visitors.') + '</span>' +
        '<input name="' + name + '" value="' + esc(value || '') + '" placeholder="auto-generated">' +
      '</label>'
    );
  }

  function mSelect(label, name, options, selected, help) {
    var opts = options.map(function (o) {
      var val = typeof o === 'object' ? o.value : o;
      var lbl = typeof o === 'object' ? o.label : o;
      return '<option value="' + esc(val) + '"' + (String(val) === String(selected) ? ' selected' : '') + '>' + esc(lbl) + '</option>';
    }).join('');
    return '<label class="eponymos-modal-field"><span class="epo-field-label">' + esc(label) + (help ? info(help) : '') + '</span><select name="' + name + '">' + opts + '</select></label>';
  }

  function mSelect(label, name, options, selected) {
    var selectedValues = Array.isArray(selected) ? selected.map(String) : [String(selected)];
    var opts = options.map(function (o) {
      var val = typeof o === 'object' ? o.value : o;
      var lbl = typeof o === 'object' ? o.label : o;
      return '<option value="' + esc(val) + '"' + (selectedValues.indexOf(String(val)) !== -1 ? ' selected' : '') + '>' + esc(lbl) + '</option>';
    }).join('');
    return '<label class="eponymos-modal-field">' + esc(label) + '<select name="' + name + '">' + opts + '</select></label>';
  }

  function wireKeyAutofill($form, labelName, keyName, isDirty) {
    var $label = $form.find('[name="' + labelName + '"]');
    var $key   = $form.find('[name="' + keyName + '"]');
    var dirty  = !!isDirty;
    if (dirty) {
      $key.prop('readonly', true);
      $('<button type="button" class="button button-small" style="margin-left:4px">Edit key</button>')
        .insertAfter($key)
        .on('click', function () { $key.prop('readonly', false).trigger('focus'); $(this).remove(); });
    } else {
      $label.on('input', function () { if (!dirty) $key.val(slugify($label.val())); });
      $key.on('input', function () { dirty = true; $label.off('input'); });
    }
  }

  // ── fetch ───────────────────────────────────────────────────────────────────

  function fetchLibQuestions() {
    return api('/library/questions').then(function (x) { return x.items || []; });
  }

  function fetchLibOptionGroups() {
    return api('/library/option-groups').then(function (x) { return x.items || []; });
  }

  function encodeDataJson(value) {
    return encodeURIComponent(JSON.stringify(value || {}));
  }

  function decodeDataJson(value) {
    try {
      return JSON.parse(decodeURIComponent(value || '{}'));
    } catch (e) {
      return {};
    }
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // QUESTION LIBRARY PAGE
  // ═══════════════════════════════════════════════════════════════════════════

  function loadLibQuestions() {
    var container = root.find('[data-library-questions]');
    container.html('<p>Loading\u2026</p>');
    return fetchLibQuestions().then(function (items) {
      renderLibQuestions(items);
    }).catch(function () {
      container.html('<p>Could not load questions.</p>');
    });
  }

  function renderLibQuestions(items) {
    var container = root.find('[data-library-questions]');
    if (!items.length) {
      container.html(
        '<div class="epo-empty-state" style="min-height:160px;border:2px dashed #dcdcde;border-radius:6px">' +
        '<p style="margin:0;color:#646970">No library questions yet. Click <strong>New question</strong> to add one.</p>' +
        '</div>'
      );
      return;
    }
    var h =
      '<table class="widefat striped" style="margin-top:4px">' +
      '<thead><tr>' +
      '<th style="width:32%">Label</th>' +
      '<th style="width:18%">Key</th>' +
      '<th style="width:12%">Type</th>' +
      '<th style="width:20%">Option Group</th>' +
      '<th style="width:8%;text-align:center">Calc</th>' +
      '<th style="width:8%;text-align:center">Status</th>' +
      '<th style="width:2%"></th>' +
      '</tr></thead><tbody>';
    items.forEach(function (q) {
      var typeLabel = String(q.type || '').replace(/_/g, ' ');
      var isCalc = parseInt(q.calculation_eligible, 10);
      var isActive = q.status === 'active';
      h +=
        '<tr data-lib-question-id="' + q.id + '">' +
        '<td><strong style="font-size:13px">' + esc(q.label) + '</strong></td>' +
        '<td><code style="font-size:11px">' + esc(q.question_key) + '</code></td>' +
        '<td><span style="font-size:11px;text-transform:capitalize">' + esc(typeLabel) + '</span></td>' +
        '<td style="color:#646970;font-size:12px">' + esc(q.option_group_name || '—') + '</td>' +
        '<td style="text-align:center">' + (isCalc ? '<span style="color:#00a32a;font-size:16px" title="Calculation eligible">&#10003;</span>' : '<span style="color:#dcdcde">—</span>') + '</td>' +
        '<td style="text-align:center"><span style="font-size:11px;padding:2px 7px;border-radius:20px;font-weight:500;background:' + (isActive ? '#e0f0e0;color:#00a32a' : '#f0f0f0;color:#646970') + '">' + esc(q.status) + '</span></td>' +
        '<td style="white-space:nowrap">' +
          '<button class="button-link" data-lq-edit data-lq-data="' + esc(encodeDataJson(q)) + '">Edit</button> ' +
          '<button class="button-link-delete" data-lq-delete>Delete</button>' +
        '</td></tr>';
    });
    h += '</tbody></table>';
    container.html(h);
  }

  // New question
  root.on('click', '[data-library-new-question]', function () {
    fetchLibOptionGroups().then(function (groups) {
      var groupOpts = [{ value: 0, label: '\u2014 none \u2014' }].concat(
        groups.map(function (g) { return { value: g.id, label: g.name }; })
      );
      openModal(
        'New library question',
        mField('Label', 'internal_name', '', 'text', 'A short name for this question used in the questionnaire builder and admin screens.') +
        mField('Question text — what should be asked?', 'label', '', 'text', 'The complete question visitors will read. This can be a long sentence.') +
        mKeyField('Stable key', 'question_key', '', 'Generated from the Label and used internally. Keep it short; visitors will not see it.') +
        mSelect('Answer type', 'type', cfg.types || [], 'single_choice', 'Choose how visitors will answer this question.') +
        mField('Help text', 'help_text', '', 'textarea', 'Optional guidance shown with the question.') +
        mField('Placeholder', 'placeholder', '', 'text', 'Optional example shown inside a text field before it is filled in.') +
        mSelect('Option groups', 'option_group_ids', groupOpts, 0, 'Connects this question to one or more reusable answer option groups from the Option Library. Hold Ctrl or Shift to choose multiple.').replace('<select ', '<select multiple size="6" ') +
        '<label class="eponymos-modal-field"><input type="checkbox" name="hide_option_group_titles" checked> Hide option-group titles for this question</label>' +
        '<label class="eponymos-modal-field"><span class="epo-field-label">Use in calculations ' + info('Allows this answer or its option calculation values to be used by the calculation engine.') + '</span><input type="checkbox" name="calculation_eligible"></label>',
        function (f) {
          var label = f.find('[name=label]').val();
          var internalName = f.find('[name=internal_name]').val();
          var key   = f.find('[name=question_key]').val() || slugify(internalName);
          var groupIds = (f.find('[name=option_group_ids]').val() || []).map(function (id) { return parseInt(id, 10); }).filter(Boolean);
          var b = {
            label: label, question_key: key, internal_name: internalName,
            type: f.find('[name=type]').val(),
            help_text: f.find('[name=help_text]').val(),
            placeholder: f.find('[name=placeholder]').val(),
            option_group_id: groupIds[0] || 0,
            option_group_ids: groupIds,
            hide_option_group_titles: f.find('[name=hide_option_group_titles]').prop('checked'),
            calculation_eligible: f.find('[name=calculation_eligible]').prop('checked'),
          };
          if (!b.label) return;
          api('/library/questions', 'POST', b).then(function () {
            closeModal();
            loadLibQuestions();
            notice('Question added.');
          }).catch(function () { notice('Could not save question.', true); });
        },
        function ($form) { wireKeyAutofill($form, 'internal_name', 'question_key', false); }
      );
    });
  });

  // Edit question
  root.on('click', '[data-lq-edit]', function () {
    var id = parseInt($(this).closest('[data-lib-question-id]').data('lib-question-id'), 10);
    var q;
    q = decodeDataJson($(this).attr('data-lq-data'));
    if (!q.id) return;
    fetchLibOptionGroups().then(function (groups) {
      var groupOpts = [{ value: 0, label: '\u2014 none \u2014' }].concat(
        groups.map(function (g) { return { value: g.id, label: g.name }; })
      );
      openModal(
        'Edit library question',
        mField('Label', 'internal_name', q.internal_name || '', 'text', 'A short name for this question used in the questionnaire builder and admin screens.') +
        mField('Question text — what should be asked?', 'label', q.label, 'text', 'The complete question visitors will read. This can be a long sentence.') +
        mKeyField('Stable key', 'question_key', q.question_key, 'Generated from the Label and used internally. Keep it short; visitors will not see it.') +
        mSelect('Answer type', 'type', cfg.types || [], q.type, 'Choose how visitors will answer this question.') +
        mField('Help text', 'help_text', q.help_text || '', 'textarea', 'Optional guidance shown with the question.') +
        mField('Placeholder', 'placeholder', q.placeholder || '', 'text', 'Optional example shown inside a text field before it is filled in.') +
        mSelect('Option groups', 'option_group_ids', groupOpts, q.option_group_ids && q.option_group_ids.length ? q.option_group_ids : (q.option_group_id || 0), 'Connects this question to one or more reusable answer option groups from the Option Library. Hold Ctrl or Shift to choose multiple.').replace('<select ', '<select multiple size="6" ') +
        '<label class="eponymos-modal-field"><input type="checkbox" name="hide_option_group_titles"' + (q.hide_option_group_titles === true ? ' checked' : '') + '> Hide option-group titles for this question</label>' +
        '<label class="eponymos-modal-field"><input type="checkbox" name="calculation_eligible"' + (parseInt(q.calculation_eligible, 10) ? ' checked' : '') + '> Calculation eligible</label>' +
        mSelect('Status', 'status', ['active', 'inactive'], q.status || 'active'),
        function (f) {
          var groupIds = (f.find('[name=option_group_ids]').val() || []).map(function (groupId) { return parseInt(groupId, 10); }).filter(Boolean);
          var b = {
            label: f.find('[name=label]').val(),
            question_key: f.find('[name=question_key]').val(),
            internal_name: f.find('[name=internal_name]').val(),
            type: f.find('[name=type]').val(),
            help_text: f.find('[name=help_text]').val(),
            placeholder: f.find('[name=placeholder]').val(),
            option_group_id: groupIds[0] || 0,
            option_group_ids: groupIds,
            hide_option_group_titles: f.find('[name=hide_option_group_titles]').prop('checked'),
            calculation_eligible: f.find('[name=calculation_eligible]').prop('checked'),
            status: f.find('[name=status]').val(),
          };
          api('/library/questions/' + id, 'POST', b).then(function () {
            closeModal();
            loadLibQuestions();
            notice('Question saved.');
          }).catch(function () { notice('Could not save question.', true); });
        },
        function ($form) { wireKeyAutofill($form, 'internal_name', 'question_key', true); }
      );
    });
  });

  // Delete question
  root.on('click', '[data-lq-delete]', function () {
    var id = parseInt($(this).closest('[data-lib-question-id]').data('lib-question-id'), 10);
    if (!confirm('Delete this library question?')) return;
    api('/library/questions/' + id, 'DELETE').then(function () {
      loadLibQuestions();
      notice('Question deleted.');
    }).catch(function () { notice('Could not delete question.', true); });
  });

  // ═══════════════════════════════════════════════════════════════════════════
  // OPTION LIBRARY PAGE
  // ═══════════════════════════════════════════════════════════════════════════

  function loadOptionGroups() {
    var container = root.find('[data-library-groups]');
    container.html('<p>Loading\u2026</p>');
    return fetchLibOptionGroups().then(function (groups) {
      renderLibOptionGroups(groups);
    }).catch(function () {
      container.html('<p>Could not load option groups.</p>');
    });
  }

  function renderLibOptionGroups(groups) {
    var container = root.find('[data-library-groups]');
    if (!groups.length) {
      container.html(
        '<div class="epo-empty-state" style="min-height:160px;border:2px dashed #dcdcde;border-radius:6px">' +
        '<p style="margin:0;color:#646970">No option groups yet. Click <strong>New option group</strong> to create one.</p>' +
        '</div>'
      );
      return;
    }
    var h = '';
    groups.forEach(function (g) {
      var isActive = g.status === 'active';
      var optCount = (g.options || []).length;
      h +=
        '<article class="eponymos-lib-group" data-lib-group-id="' + g.id + '">' +
          '<header>' +
            '<strong>' + esc(g.name) + '</strong>' +
            '<em class="' + (isActive ? '' : 'inactive') + '">' + esc(g.status) + '</em>' +
            '<span style="font-size:11px;color:#646970">' + optCount + ' option' + (optCount !== 1 ? 's' : '') + '</span>' +
            '<div class="lib-group-actions">' +
              '<button class="button-link" data-lg-edit>Edit</button>' +
              '<button class="button button-small" data-lg-add-option>+ Add option</button>' +
              '<button class="button-link-delete" data-lg-delete>Delete</button>' +
            '</div>' +
          '</header>' +
          (g.description ? '<p style="margin:8px 14px 0;font-size:12px;color:#646970">' + esc(g.description) + '</p>' : '') +
          '<div class="lib-options" data-lib-options>' + renderLibOptions(g.options || []) + '</div>' +
        '</article>';
    });
    container.html(h);
  }

  function renderLibOptions(options) {
    if (!options || !options.length) return '<p class="lib-no-options">No options yet.</p>';
    return options.map(function (o) {
      return (
        '<div class="eponymos-option" data-lib-option-id="' + o.id + '">' +
          '<input data-lo="label" value="' + esc(o.label) + '" placeholder="Label">' +
          '<input data-lo="option_key" value="' + esc(o.option_key) + '" placeholder="Key" readonly>' +
          '<input data-lo="calculation_value" value="' + esc(o.calculation_value) + '" placeholder="Calc value">' +
          '<button class="button-link" data-lo-save>Save</button> ' +
          '<button class="button-link-delete" data-lo-delete>Delete</button>' +
        '</div>'
      );
    }).join('');
  }



  // New group
  root.on('click', '[data-library-new-group]', function () {
    openModal(
      'New option group',
      mField('Group name', 'name', '', 'text', 'A short name for this reusable set of answer options.') + mField('Description', 'description', '', 'textarea', 'Explain when this set of options should be used.'),
      function (f) {
        var b = { name: f.find('[name=name]').val(), description: f.find('[name=description]').val() };
        if (!b.name) return;
        api('/library/option-groups', 'POST', b).then(function () {
          closeModal();
          loadOptionGroups();
          notice('Option group created.');
        }).catch(function () { notice('Could not create group.', true); });
      }
    );
  });

  // Edit group
  root.on('click', '[data-lg-edit]', function () {
    var id = parseInt($(this).closest('[data-lib-group-id]').data('lib-group-id'), 10);
    api('/library/option-groups/' + id).then(function (g) {
      openModal(
        'Edit option group',
        mField('Group name', 'name', g.name, 'text', 'A short name for this reusable set of answer options.') +
        mField('Description', 'description', g.description || '', 'textarea', 'Explain when this set of options should be used.') +
        mSelect('Status', 'status', ['active', 'inactive'], g.status || 'active', 'Inactive groups are not available for new library questions.'),
        function (f) {
          var b = {
            name: f.find('[name=name]').val(),
            description: f.find('[name=description]').val(),
            status: f.find('[name=status]').val(),
          };
          api('/library/option-groups/' + id, 'POST', b).then(function () {
            closeModal();
            loadOptionGroups();
            notice('Option group saved.');
          }).catch(function () { notice('Could not save group.', true); });
        }
      );
    }).catch(function () { notice('Could not load group.', true); });
  });

  // Delete group
  root.on('click', '[data-lg-delete]', function () {
    var id = parseInt($(this).closest('[data-lib-group-id]').data('lib-group-id'), 10);
    if (!confirm('Delete this option group and all its options?')) return;
    api('/library/option-groups/' + id, 'DELETE').then(function () {
      loadOptionGroups();
      notice('Option group deleted.');
    }).catch(function () { notice('Could not delete group.', true); });
  });

  // Add option to group
  root.on('click', '[data-lg-add-option]', function () {
    var article  = $(this).closest('[data-lib-group-id]');
    var group_id = parseInt(article.data('lib-group-id'), 10);
    openModal(
      'Add option',
      mField('Answer text', 'label', '', 'text', 'The answer visitors will see.') +
      mKeyField('Stable key', 'option_key', '', 'A short permanent identifier for this answer. Visitors will not see it.') +
      mField('Calculation value', 'calculation_value', '', 'text', 'Optional number used by calculations when this answer is selected.'),
      function (f) {
        var label = f.find('[name=label]').val();
        var b = {
          add_option: true,
          label: label,
          option_key: f.find('[name=option_key]').val() || slugify(label),
          calculation_value: f.find('[name=calculation_value]').val(),
        };
        if (!b.label) return;
        api('/library/option-groups/' + group_id, 'POST', b).then(function () {
          closeModal();
          loadOptionGroups();
          notice('Option added.');
        }).catch(function () { notice('Could not add option.', true); });
      },
      function ($form) { wireKeyAutofill($form, 'label', 'option_key', false); }
    );
  });

  // Inline option save
  root.on('click', '[data-lo-save]', function () {
    var btn = $(this).prop('disabled', true).text('Saving\u2026');
    var row = btn.closest('[data-lib-option-id]');
    var id  = parseInt(row.data('lib-option-id'), 10);
    var b   = {};
    row.find('[data-lo]').each(function () { b[$(this).data('lo')] = $(this).val(); });
    api('/library/options/' + id + '/save', 'POST', b).then(function () {
      btn.prop('disabled', false).text('Save');
      notice('Option saved.');
    }).catch(function () {
      btn.prop('disabled', false).text('Save');
      notice('Could not save option.', true);
    });
  });

  // Delete option
  root.on('click', '[data-lo-delete]', function () {
    var id = parseInt($(this).closest('[data-lib-option-id]').data('lib-option-id'), 10);
    if (!confirm('Delete this option?')) return;
    api('/library/options/' + id + '/delete', 'POST', {}).then(function () {
      loadOptionGroups();
      notice('Option deleted.');
    }).catch(function () { notice('Could not delete option.', true); });
  });

  // ── Auto-init based on which page we're on ──────────────────────────────────

  $(function () {
    if (page === 'question_library') loadLibQuestions();
    if (page === 'option_library')   loadOptionGroups();
  });

})(jQuery);
