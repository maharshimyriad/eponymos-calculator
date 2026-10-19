/* global jQuery, EponymosAdmin */
(function ($) {
  'use strict';

  var c = window.EponymosAdmin;
  if (!c) return;

  var base   = c.endpoint;
  var root   = $('.eponymos-admin');
  var list   = root.find('[data-questionnaires]');
  var editor = root.find('[data-editor]');

  var current      = null;
  var listCache    = Array.isArray(c.questionnaires) ? c.questionnaires : [];
  var requestToken = 0;
  var dragged      = null;
  var draggedType  = null;

  // ── utilities ───────────────────────────────────────────────────────────────

  function api(path, method, body) {
    return fetch(base + path, {
      method: method || 'GET',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': c.nonce },
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

  function notice(msg, isError) {
    var el = root.find('.eponymos-notice');
    el.text(msg).toggleClass('notice-error', !!isError);
    setTimeout(function () { el.text('').removeClass('notice-error'); }, 3000);
  }

  function slugify(v) {
    v = String(v || '').toLowerCase();
    v = v.replace(/[^a-z0-9]+/g, '_');
    v = v.replace(/^_+|_+$/g, '');
    return v || 'item';
  }

  function wireKeyAutofill($label, $key, dirty) {
    if (dirty) {
      $key.prop('readonly', true);
      if (!$key.next('[data-unlock-key]').length) {
        $('<button type="button" class="button button-small" data-unlock-key style="margin-left:4px">Edit</button>')
          .insertAfter($key)
          .on('click', function () {
            $key.prop('readonly', false).trigger('focus');
            $(this).remove();
          });
      }
    } else {
      $key.prop('readonly', false);
      $label.on('input.keyautofill', function () {
        if (!dirty) $key.val(slugify($label.val()));
      });
      $key.on('input.keyautofill', function () {
        dirty = true;
        $label.off('input.keyautofill');
        $key.off('input.keyautofill');
      });
    }
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

  function field(label, name, value, type) {
    type = type || 'text';
    return (
      '<label class="eponymos-modal-field">' + esc(label) +
      (type === 'textarea'
        ? '<textarea name="' + name + '">' + esc(value || '') + '</textarea>'
        : '<input type="' + type + '" name="' + name + '" value="' + esc(value || '') + '">') +
      '</label>'
    );
  }

  root.on('click', '[data-modal-close]', closeModal);

  // ── questionnaire list ──────────────────────────────────────────────────────

  function renderList(items) {
    list.empty();
    if (!items.length) { list.append('<p>No questionnaires yet.</p>'); return; }
    items.forEach(function (q) {
      list.append(
        '<button class="eponymos-q ' + (current && current.questionnaire.id == q.id ? 'selected' : '') +
        '" data-qid="' + q.id + '">' + esc(q.name) + ' <small>' + esc(q.status) + '</small></button>'
      );
    });
  }

  function load(force) {
    if (listCache.length && !force) { renderList(listCache); return Promise.resolve(listCache); }
    return api('/questionnaires').then(function (x) {
      listCache = x.items || [];
      renderList(listCache);
      return listCache;
    });
  }

  function select(id) {
    var token = ++requestToken;
    editor.html('<p>Loading\u2026</p>');
    return api('/questionnaires/' + id).then(function (x) {
      if (token !== requestToken) return;
      current = x;
      window._eponymosBuilderCurrent = current;
      renderList(listCache);
      render();
    }).catch(function () {
      editor.html('<p>Could not load questionnaire.</p>');
    });
  }

  function reload() {
    if (!current) return Promise.resolve();
    return select(current.questionnaire.id);
  }

  // ── builder render ──────────────────────────────────────────────────────────

  function render() {
    if (!current) return;
    var q = current.questionnaire;
    var isActive = q.status === 'active';

    var h =
      '<div class="eponymos-editor-head">' +
        '<div>' +
          '<h2>' + esc(q.name) + '</h2>' +
          (q.description ? '<p>' + esc(q.description) + '</p>' : '') +
        '</div>' +
        '<span class="epo-badge' + (isActive ? '' : ' inactive') + '">' + esc(q.status) + '</span>' +
        '<button class="button button-small" data-duplicate>&#10697; Duplicate</button>' +
        '<button class="button button-small" data-toggle>' + (isActive ? 'Deactivate' : 'Activate') + '</button>' +
        '<button class="button-link-delete" data-delete>Delete</button>' +
      '</div>' +
      '<div style="margin:0 0 12px">' +
        '<button class="button button-primary" data-add-stage>+ Add stage</button>' +
      '</div>' +
      '<div data-stages>';

    if (!current.stages.length) {
      h += '<div class="epo-empty-state" style="min-height:180px">' +
           '<p style="margin:0">No stages yet. Click <strong>Add stage</strong> to get started.</p>' +
           '</div>';
    } else {
      current.stages.forEach(function (s) {
        var qCount = (s.questions || []).length;
        h +=
          '<article class="eponymos-stage-card" draggable="true" data-stage-id="' + s.id + '">' +
            '<header>' +
              '<span class="drag" title="Drag to reorder">&#8597;</span>' +
              '<strong>' + esc(s.title) + '</strong>' +
              (s.status === 'inactive' ? '<span class="epo-chip" style="background:#f0f0f0;color:#646970">inactive</span>' : '') +
              '<span style="font-size:11px;color:#646970">' + qCount + ' question' + (qCount !== 1 ? 's' : '') + '</span>' +
              '<button class="button-link" data-edit-stage>Edit</button>' +
              '<button class="button-link-delete" data-delete-stage>Delete</button>' +
            '</header>' +
            '<div class="stage-body">' +
              (s.description ? '<p style="margin:0 0 10px;color:#646970;font-size:13px">' + esc(s.description) + '</p>' : '') +
              '<div class="stage-actions">' +
                '<button class="button button-primary" data-import-library-question>+ Add from Question Library</button>' +
              '</div>' +
              '<div data-questions>';
        (s.questions || []).forEach(function (q2) { h += questionHtml(q2); });
        h += '</div></div></article>';
      });
    }

    editor.html(h + '</div>');
  }

  // Calculation roles available for mapping.
  // software_savings is optional.
  var CALC_ROLES = [
    { value: '',                 label: '\u2014 no role \u2014' },
    { value: 'pitches_per_month', label: 'pitches_per_month (optional)' },
    { value: 'organization_type', label: 'organization_type (optional)' },
    { value: 'agency_clients',    label: 'agency_clients (optional)' },
    { value: 'active_users',      label: 'active_users (optional)' },
    { value: 'active_days',       label: 'active_days (optional)' },
    { value: 'software_savings',  label: 'software_savings (optional)' },
    { value: 'software_spend',    label: 'software_spend (optional)' },
  ];

  function questionHtml(q) {
    var typeLabel = String(q.type || '').replace(/_/g, ' ');
    var currentRole = q.calculation_role || '';

    var roleOpts = CALC_ROLES.map(function (r) {
      return '<option value="' + esc(r.value) + '"' + (r.value === currentRole ? ' selected' : '') + '>' + esc(r.label) + '</option>';
    }).join('');

    return (
      '<div class="eponymos-question" draggable="true" data-question-id="' + q.id + '">' +
        '<header>' +
          '<span class="drag" title="Drag to reorder">&#8597;</span>' +
          '<div class="question-heading">' +
            '<strong>' + esc(q.internal_name || q.label) + '</strong>' +
            '<span class="question-text">' + esc(q.label || '') + '</span>' +
          '</div>' +
          '<em>' + esc(typeLabel) + '</em>' +
          (parseInt(q.calculation_eligible, 10) ? '<span class="epo-chip" title="Calculation eligible">calc</span>' : '') +
          '<label class="epo-role-label">' +
            'Role&nbsp;<select data-role-select title="Assign a calculation role to map this answer to the methodology">' + roleOpts + '</select>' +
          '</label>' +
          '<button class="button-link-delete" data-delete-question>Remove</button>' +
        '</header>' +
        '<div class="question-body" style="padding:8px 12px;font-size:12px;color:#646970">' +
          'Managed via Question Library &mdash; options and label are edited there.' +
        '</div>' +
      '</div>'
    );
  }

  // Save calculation_role when the dropdown changes (inline, no modal needed).
  root.on('change', '[data-role-select]', function () {
    var $sel  = $(this);
    var id    = $sel.closest('[data-question-id]').data('question-id');
    var role  = $sel.val();
    api('/questions/' + id + '/save', 'POST', { calculation_role: role }).then(function () {
      notice('Role saved.');
      // Update in-memory current so re-render preserves the change.
      if (current) {
        current.stages.forEach(function (s) {
          s.questions.forEach(function (q) {
            if (q.id == id) q.calculation_role = role;
          });
        });
      }
    }).catch(function () { notice('Could not save role.', true); });
  });

  // ── event handlers ──────────────────────────────────────────────────────────

  root.on('click', '[data-qid]', function () { select($(this).data('qid')); });

  root.on('click', '[data-new-questionnaire]', function () {
    openModal(
      'New questionnaire',
      field('Name', 'name', '') + field('Description', 'description', '', 'textarea'),
      function (f) {
        var b = { name: f.find('[name=name]').val(), description: f.find('[name=description]').val() };
        if (!b.name) return;
        api('/questionnaires', 'POST', b).then(function (x) {
          listCache = [];
          closeModal();
          load(true).then(function () { select(x.id); });
        }).catch(function () { notice('Could not create questionnaire.', true); });
      }
    );
  });

  root.on('click', '[data-duplicate]', function () {
    api('/questionnaires/' + current.questionnaire.id, 'POST', { duplicate: true }).then(function (x) {
      listCache = [];
      load(true).then(function () { select(x.id); });
    }).catch(function () { notice('Could not duplicate questionnaire.', true); });
  });

  root.on('click', '[data-toggle]', function () {
    var status = current.questionnaire.status === 'active' ? 'inactive' : 'active';
    api('/questionnaires/' + current.questionnaire.id, 'POST', {
      name: current.questionnaire.name,
      description: current.questionnaire.description,
      status: status,
    }).then(function () {
      listCache = [];
      load(true);
      reload();
      notice('Questionnaire ' + status + '.');
    }).catch(function () { notice('Could not update questionnaire.', true); });
  });

  root.on('click', '[data-delete]', function () {
    if (!confirm('Delete this questionnaire and its stages/questions?')) return;
    api('/questionnaires/' + current.questionnaire.id, 'DELETE').then(function () {
      current = null;
      listCache = [];
      editor.html('<p>Select a questionnaire to begin.</p>');
      load(true);
      notice('Questionnaire deleted.');
    }).catch(function () { notice('Could not delete questionnaire.', true); });
  });

  root.on('click', '[data-add-stage]', function () {
    openModal(
      'Add stage',
      field('Title', 'title', '') + field('Description', 'description', '', 'textarea'),
      function (f) {
        var b = { title: f.find('[name=title]').val(), description: f.find('[name=description]').val() };
        if (!b.title) return;
        api('/questionnaires/' + current.questionnaire.id + '/stages', 'POST', b).then(function () {
          closeModal();
          reload().then(function () { notice('Stage added.'); });
        }).catch(function () { notice('Could not add stage.', true); });
      }
    );
  });

  root.on('click', '[data-edit-stage]', function () {
    var sid = $(this).closest('[data-stage-id]').data('stage-id');
    var s   = current.stages.find(function (x) { return x.id == sid; });
    if (!s) return;
    openModal(
      'Edit stage',
      field('Title', 'title', s.title) +
      field('Description', 'description', s.description, 'textarea') +
      '<label class="eponymos-modal-field">Status<select name="status">' +
        '<option value="active"'   + (s.status === 'active'   ? ' selected' : '') + '>Active</option>' +
        '<option value="inactive"' + (s.status === 'inactive' ? ' selected' : '') + '>Inactive</option>' +
      '</select></label>',
      function (f) {
        var b = {
          title:       f.find('[name=title]').val(),
          description: f.find('[name=description]').val(),
          status:      f.find('[name=status]').val(),
          settings:    s.settings,
        };
        api('/stages/' + s.id + '/save', 'POST', b).then(function () {
          closeModal();
          reload().then(function () { notice('Stage saved.'); });
        }).catch(function () { notice('Could not save stage.', true); });
      }
    );
  });

  root.on('click', '[data-delete-stage]', function () {
    if (!confirm('Delete this stage and its questions?')) return;
    var id = $(this).closest('[data-stage-id]').data('stage-id');
    api('/stages/' + id + '/delete', 'POST', {}).then(function () {
      reload().then(function () { notice('Stage deleted.'); });
    }).catch(function () { notice('Could not delete stage.', true); });
  });

  root.on('click', '[data-import-library-question]', function () {
    var sid = $(this).closest('[data-stage-id]').data('stage-id');
    api('/library/questions').then(function (x) {
      var questions = x.items || [];
      if (!questions.length) {
        openModal(
          'No library questions',
          '<p style="margin:0;color:#646970">Your Question Library is empty. Go to <strong>Question Library</strong> and create some questions first, then come back to add them here.</p>',
          function () { closeModal(); }
        );
        return;
      }
      // A library question can only be added once to a questionnaire. Mark
      // questions already used by any stage as unavailable in the picker.
      var addedQuestionIds = {};
      (current.stages || []).forEach(function (stage) {
        (stage.questions || []).forEach(function (question) {
          if (question.library_question_id) addedQuestionIds[question.library_question_id] = true;
          if (question.library_id) addedQuestionIds[question.library_id] = true;
        });
      });
      var availableCount = 0;
      var opts = questions.map(function (q) {
        var typeLabel = String(q.type || '').replace(/_/g, ' ');
        var alreadyAdded = !!addedQuestionIds[q.id];
        if (!alreadyAdded) availableCount++;
        return '<option value="' + esc(q.id) + '"' + (alreadyAdded ? ' disabled' : '') + '>' +
          (alreadyAdded ? '\u2713 Added — ' : '') + '[' + esc(typeLabel) + '] ' + esc(q.label) + '</option>';
      }).join('');
      openModal(
        'Add questions from library',
        '<div class="eponymos-modal-intro">Select one or more questions to add to this questionnaire.</div>' +
        '<label class="eponymos-modal-field">Choose questions' +
          '<select name="lib_question_ids" multiple size="8"' + (availableCount ? '' : ' disabled') + '>' + opts + '</select>' +
        '</label>' +
        '<p class="eponymos-modal-help">Already added questions are marked and disabled. Hold Ctrl or Shift to select multiple questions.</p>' +
        (availableCount ? '' : '<div class="eponymos-modal-empty">All library questions have already been added.</div>'),
        function (f) {
          var libIds = f.find('[name=lib_question_ids]').val() || [];
          libIds = libIds.map(function (id) { return parseInt(id, 10); }).filter(Boolean);
          if (!libIds.length) {
            notice('Select an available question first.', true);
            return;
          }
          if (!libIds.length) return;
          Promise.all(libIds.map(function (libId) {
            return api('/stages/' + sid + '/import-library-question/' + libId, 'POST', {});
          })).then(function () {
            closeModal();
            reload().then(function () {
              notice(libIds.length === 1 ? 'Question added. Assign a calculation role if needed.' : 'Questions added. Assign calculation roles if needed.');
            });
          }).catch(function () { notice('Could not add questions.', true); });
        }
      );
    }).catch(function () { notice('Could not load Question Library.', true); });
  });

  root.on('click', '[data-delete-question]', function () {
    if (!confirm('Remove this question from the stage?')) return;
    var id = $(this).closest('[data-question-id]').data('question-id');
    api('/questions/' + id + '/delete', 'POST', {}).then(function () {
      reload().then(function () { notice('Question removed.'); });
    }).catch(function () { notice('Could not remove question.', true); });
  });

  root.on('click', '[data-save-option]', function () {
    var btn = $(this).prop('disabled', true).text('Saving\u2026');
    var box = btn.closest('[data-option-id]');
    var id  = box.data('option-id');
    var b   = {};
    box.find('[data-option]').each(function () { b[$(this).data('option')] = $(this).val(); });
    api('/options/' + id + '/save', 'POST', b).then(function () {
      btn.prop('disabled', false).text('Save');
      notice('Option saved.');
    }).catch(function () {
      btn.prop('disabled', false).text('Save');
      notice('Could not save option.', true);
    });
  });

  root.on('click', '[data-delete-option]', function () {
    if (!confirm('Delete this option?')) return;
    var id = $(this).closest('[data-option-id]').data('option-id');
    api('/options/' + id + '/delete', 'POST', {}).then(function () {
      reload().then(function () { notice('Option deleted.'); });
    }).catch(function () { notice('Could not delete option.', true); });
  });

  // ── drag & drop ─────────────────────────────────────────────────────────────

  root.on('mousedown', '[data-question-id]', function () {

  });

  root.on('dragstart', '[draggable]', function (e) {
    var $item = $(this);
    var kind = $item.is('[data-question-id]') ? 'question' : 'stage';
    // A question is nested inside a draggable stage. Ignore the bubbled
    // dragstart from the stage so it cannot replace the active question.
    if (kind === 'stage' && draggedType === 'question') {

      e.stopImmediatePropagation();
      return;
    }
    dragged = this;
    var id = $item.data('question-id') || $item.data('stage-id') || '';
    draggedType = kind;
    var id = $item.data('question-id') || $item.data('stage-id') || '';

    e.originalEvent.dataTransfer.effectAllowed = 'move';
    e.originalEvent.dataTransfer.setData('text/plain', String(id));
    $item.addClass('dragging');
  });

  // Prevent interactive controls from starting a drag instead of allowing
  // their normal click/change behavior.
  root.on('dragstart', '[draggable] button, [draggable] select, [draggable] input, [draggable] textarea', function (e) {
    e.stopPropagation();
    e.preventDefault();
  });

  root.on('dragend', '[draggable]', function (e) {
    var kind = $(this).is('[data-question-id]') ? 'question' : 'stage';

    if (kind === 'stage' && draggedType === 'question') {
      e.stopImmediatePropagation();
      return;
    }
    dragged = null;
    draggedType = null;
    $(this).removeClass('dragging');
    root.find('.drag-over').removeClass('drag-over');
  });

  root.on('dragenter dragover', '[data-question-id]', function (e) {
    e.preventDefault();
    e.stopPropagation();

    e.originalEvent.dataTransfer.dropEffect = 'move';
    if (!$(this).hasClass('drag-over')) {
      root.find('.drag-over').removeClass('drag-over');
      $(this).addClass('drag-over');
    }
  });

  root.on('dragenter dragover', '[data-stage-id]', function (e) {
    // Stage cards remain draggable for stage ordering, but must not become
    // the active drop target while reordering questions inside them.
    if (dragged && $(dragged).is('[data-question-id]')) return;
    e.preventDefault();
    e.originalEvent.dataTransfer.dropEffect = 'move';
    if (!$(this).hasClass('drag-over')) {
      root.find('.drag-over').removeClass('drag-over');
      $(this).addClass('drag-over');
    }
  });

  root.on('dragleave', '[data-question-id], [data-stage-id]', function (e) {
    if (!this.contains(e.originalEvent.relatedTarget)) {
      $(this).removeClass('drag-over');
    }
  });

  root.on('drop', '[data-question-id]', function (e) {
    e.preventDefault();
    e.stopPropagation();

    $(this).removeClass('drag-over');
    if (!dragged || dragged === this || draggedType !== 'question') {
      return;
      return;
    }
    var sourceStage = $(dragged).closest('[data-stage-id]').data('stage-id');
    var targetStage = $(this).closest('[data-stage-id]').data('stage-id');
    if (sourceStage != targetStage) {
      return;
      return;
    }

    // Insert before or after the target based on the pointer position so the
    // visual order matches where the question was dropped.
    var rect = this.getBoundingClientRect();
    if (e.originalEvent.clientY > rect.top + rect.height / 2) {
      this.parentNode.insertBefore(dragged, this.nextSibling);
    } else {
      this.parentNode.insertBefore(dragged, this);
    }
    var stage = $(this).closest('[data-stage-id]');
    var ids   = stage.find('[data-question-id]').map(function () { return $(this).data('question-id'); }).get();
    var sid   = stage.data('stage-id');

    api('/questions/0/reorder', 'POST', { stage_id: sid, ids: ids })
      .catch(function () { notice('Could not reorder questions.', true); });
  });

  root.on('drop', '[data-stage-id]', function (e) {
    e.preventDefault();
    e.stopPropagation();
    $(this).removeClass('drag-over');
    if (!dragged || !$(dragged).is('[data-stage-id]') || dragged === this) return;
    this.parentNode.insertBefore(dragged, this);
    var ids = root.find('[data-stage-id]').map(function () { return $(this).data('stage-id'); }).get();
    api('/stages/0/reorder', 'POST', { questionnaire_id: current.questionnaire.id, ids: ids })
      .catch(function () { notice('Could not reorder stages.', true); });
  });

  // ── init ────────────────────────────────────────────────────────────────────

  renderList(listCache);

})(jQuery);
