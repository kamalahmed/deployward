/* Deployward admin SPA - vanilla ES6, no framework, no build step */
/* Reads root and nonce from #deployward-app[data-root][data-nonce] */

(function () {
  'use strict';

  /* -------------------------------------------------------------------------
     Bootstrap
     ---------------------------------------------------------------------- */

  document.addEventListener('DOMContentLoaded', function () {
    const appEl = document.getElementById('deployward-app');
    if (!appEl) { return; }

    const root  = appEl.dataset.root;
    const nonce = appEl.dataset.nonce;

    if (!root || !nonce) { return; }

    const app = new DeploywardApp(appEl, root, nonce);
    app.init();
  });

  /* -------------------------------------------------------------------------
     API helper
     ---------------------------------------------------------------------- */

  function buildApi(root, nonce) {
    return async function api(method, path, body) {
      const opts = {
        method: method,
        headers: {
          'X-WP-Nonce': nonce,
          'Content-Type': 'application/json',
        },
      };
      if (body !== undefined) {
        opts.body = JSON.stringify(body);
      }
      const res = await fetch(root + path, opts);
      let data = {};
      try { data = await res.json(); } catch (_) { data = {}; }
      return { status: res.status, data: data };
    };
  }

  /* -------------------------------------------------------------------------
     Main app object
     ---------------------------------------------------------------------- */

  function DeploywardApp(appEl, root, nonce) {
    this.el    = appEl;
    this.api   = buildApi(root, nonce);
    this.tabs  = {};
    this.panels = {};
    this.activeTab = null;
    this.editingDeployment = null;
    this.logTargetId = null;
  }

  DeploywardApp.prototype.init = function () {
    clearEl(this.el);
    this.toastArea = el('div');
    this.el.appendChild(this.toastArea);

    const tabBar = buildTabBar(this);
    this.el.appendChild(tabBar);

    this.panelWrap = el('div');
    this.el.appendChild(this.panelWrap);

    const hash = location.hash.replace('#', '') || 'deployments';
    this.switchTab(hash);
  };

  DeploywardApp.prototype.switchTab = function (tabId) {
    const validTabs = ['deployments', 'add-deployment', 'activity-log', 'getting-started'];
    const id = validTabs.includes(tabId) ? tabId : 'deployments';

    if (this.activeTab === id && id !== 'deployments' && id !== 'activity-log') { return; }
    this.activeTab = id;
    location.hash = '#' + id;

    Object.keys(this.tabs).forEach(function (k) {
      if (this.tabs[k]) {
        toggleClass(this.tabs[k], 'is-active', k === id);
      }
    }, this);

    clearEl(this.panelWrap);

    if (id === 'deployments')     { renderDeployments(this); }
    if (id === 'add-deployment')  { renderAddDeployment(this); }
    if (id === 'activity-log')    { renderActivityLog(this); }
    if (id === 'getting-started') { renderGettingStarted(this); }
  };

  /* -------------------------------------------------------------------------
     Tab bar
     ---------------------------------------------------------------------- */

  function buildTabBar(app) {
    const bar = el('nav');
    bar.className = 'dw-tabs';

    const tabDefs = [
      { id: 'deployments',     label: 'Deployments' },
      { id: 'add-deployment',  label: 'Add Deployment' },
      { id: 'activity-log',    label: 'Activity Log' },
      { id: 'getting-started', label: 'Getting Started' },
    ];

    tabDefs.forEach(function (def) {
      const btn = el('button');
      btn.type = 'button';
      btn.className = 'dw-tab';
      btn.textContent = def.label;
      btn.setAttribute('aria-label', def.label + ' tab');
      btn.addEventListener('click', function () {
        if (def.id === 'add-deployment') {
          app.editingDeployment = null;
        }
        app.switchTab(def.id);
      });
      app.tabs[def.id] = btn;
      bar.appendChild(btn);
    });

    return bar;
  }

  /* -------------------------------------------------------------------------
     Deployments tab
     ---------------------------------------------------------------------- */

  function renderDeployments(app) {
    const panel = el('div');
    panel.className = 'dw-deployments';

    const loading = buildSkeleton();
    panel.appendChild(loading);
    app.panelWrap.appendChild(panel);

    app.api('GET', 'deployments').then(function (res) {
      clearEl(panel);
      if (res.status !== 200) {
        panel.appendChild(buildToastEl('is-error', res.data.error || 'Failed to load deployments.'));
        return;
      }
      const items = (res.data.deployments || []);
      if (items.length === 0) {
        panel.appendChild(buildEmptyState(
          'No deployments yet.',
          'Use the "Add Deployment" tab to connect your first GitHub repository.'
        ));
        return;
      }
      items.forEach(function (d) {
        panel.appendChild(buildDeploymentCard(app, d));
      });
    }).catch(function () {
      clearEl(panel);
      panel.appendChild(buildToastEl('is-error', 'Network error loading deployments.'));
    });
  }

  function buildDeploymentCard(app, d) {
    const card = el('div');
    card.className = 'dw-deployment';

    const head = el('div');
    head.className = 'dw-deployment__head';

    const repo = el('span');
    repo.className = 'dw-deployment__repo';
    repo.textContent = d.repo;
    head.appendChild(repo);

    const pill = buildPill(d);
    head.appendChild(pill);
    card.appendChild(head);

    const meta = el('p');
    meta.className = 'dw-deployment__meta';
    meta.textContent = d.repo + '@' + d.branch + ' -> ' + d.target_type + '/' + d.target_slug;
    card.appendChild(meta);

    const footer = el('div');
    footer.className = 'dw-deployment__footer';

    const timeEl = el('span');
    timeEl.className = 'dw-deployment__time';
    timeEl.textContent = d.last_deployed_sha
      ? 'Last SHA: ' + d.last_deployed_sha.slice(0, 8)
      : 'Never deployed';
    footer.appendChild(timeEl);

    const actions = el('div');
    actions.className = 'dw-deployment__actions';

    actions.appendChild(buildDeployBtn(app, d, pill, footer));
    actions.appendChild(buildRollbackBtn(app, d, pill, footer));
    actions.appendChild(buildViewLogBtn(app, d));
    actions.appendChild(buildEditBtn(app, d));
    actions.appendChild(buildDeleteBtn(app, d, card));

    footer.appendChild(actions);
    card.appendChild(footer);

    return card;
  }

  function buildPill(d) {
    const pill = el('span');
    pill.className = 'dw-pill ' + pillClass(d);
    pill.textContent = pillLabel(d);
    return pill;
  }

  function pillClass(d) {
    if (!d.last_deployed_sha) { return 'is-never'; }
    return 'is-ok';
  }

  function pillLabel(d) {
    if (!d.last_deployed_sha) { return 'Never deployed'; }
    return 'Deployed';
  }

  function buildDeployBtn(app, d, pill, footer) {
    const btn = elBtn('Deploy now', 'dw-btn--primary');
    btn.addEventListener('click', function () {
      runAction(app, btn, pill, function () {
        return app.api('POST', 'deployments/' + d.id + '/deploy', { force: false });
      }, function () {
        renderDeployments(app);
      });
    });
    return btn;
  }

  function buildRollbackBtn(app, d, pill, footer) {
    const btn = elBtn('Rollback', 'dw-btn--danger');
    btn.addEventListener('click', function () {
      if (!confirm('Roll back ' + d.repo + '@' + d.branch + ' to the previous version?')) { return; }
      runAction(app, btn, pill, function () {
        return app.api('POST', 'deployments/' + d.id + '/rollback');
      }, function () {
        renderDeployments(app);
      });
    });
    return btn;
  }

  function buildViewLogBtn(app, d) {
    const btn = elBtn('View log', 'dw-btn--ghost');
    btn.addEventListener('click', function () {
      app.logTargetId = d.id;
      app.switchTab('activity-log');
    });
    return btn;
  }

  function buildEditBtn(app, d) {
    const btn = elBtn('Edit', '');
    btn.addEventListener('click', function () {
      app.editingDeployment = d;
      app.switchTab('add-deployment');
    });
    return btn;
  }

  function buildDeleteBtn(app, d, card) {
    const btn = elBtn('Delete', 'dw-btn--danger');
    btn.addEventListener('click', function () {
      if (!confirm('Delete deployment for ' + d.repo + '? This cannot be undone.')) { return; }
      setInFlight(btn, true);
      app.api('DELETE', 'deployments/' + d.id).then(function (res) {
        setInFlight(btn, false);
        if (res.status === 200) {
          card.parentNode && card.parentNode.removeChild(card);
          app.showToast('is-ok', 'Deployment deleted.');
        } else {
          app.showToast('is-error', (res.data && res.data.error) || 'Delete failed.');
        }
      }).catch(function () {
        setInFlight(btn, false);
        app.showToast('is-error', 'Network error deleting deployment.');
      });
    });
    return btn;
  }

  function runAction(app, btn, pill, apiCall, onSuccess) {
    setInFlight(btn, true);
    setPillDeploying(pill, true);

    apiCall().then(function (res) {
      setInFlight(btn, false);
      setPillDeploying(pill, false);
      const msg = (res.data && (res.data.message || res.data.error)) || 'Done.';
      if (res.status >= 200 && res.status < 300) {
        app.showToast('is-ok', msg);
        onSuccess();
      } else {
        app.showToast('is-error', msg);
      }
    }).catch(function () {
      setInFlight(btn, false);
      setPillDeploying(pill, false);
      app.showToast('is-error', 'Network error.');
    });
  }

  function setPillDeploying(pill, deploying) {
    const prev = pill.dataset.prevClass || '';
    if (deploying) {
      pill.dataset.prevClass = pill.className.replace('dw-pill', '').trim();
      pill.className = 'dw-pill is-deploying';
      pill.textContent = 'Deploying';
    } else {
      pill.className = 'dw-pill ' + (prev || 'is-ok');
      pill.textContent = prev === 'is-never' ? 'Never deployed' : 'Deployed';
    }
  }

  /* -------------------------------------------------------------------------
     Add Deployment tab
     ---------------------------------------------------------------------- */

  function renderAddDeployment(app) {
    const d = app.editingDeployment;
    const isEdit = d !== null && d !== undefined;

    const wrap = el('div');
    wrap.className = 'dw-card';

    const heading = el('h2');
    heading.textContent = isEdit ? 'Edit Deployment' : 'Add Deployment';
    wrap.appendChild(heading);

    const form = el('form');
    form.className = 'dw-form' + (isEdit && d.visibility === 'private' ? ' is-private' : '');

    const errorArea = el('div');

    /* Repo field */
    const repoField = buildField('repo', 'Repository', true);
    const repoInput = repoField.input;
    repoInput.className = 'dw-input';
    repoInput.placeholder = 'owner/repo-name';
    repoInput.value = isEdit ? d.repo : '';
    form.appendChild(repoField.wrapper);

    /* Visibility segmented control */
    const visField = el('div');
    visField.className = 'dw-field';
    const visLabel = el('label');
    visLabel.className = 'dw-label';
    visLabel.textContent = 'Visibility';
    visField.appendChild(visLabel);

    const segmented = buildSegmented(form, isEdit ? d.visibility : 'public');
    visField.appendChild(segmented);
    form.appendChild(visField);

    /* Token field (private only) */
    const tokenField = buildField('token', 'GitHub Token', false);
    tokenField.wrapper.className = 'dw-field dw-token-row';
    const tokenInput = tokenField.input;
    tokenInput.className = 'dw-input';
    tokenInput.type = 'password';
    tokenInput.placeholder = isEdit ? 'Leave blank to keep the saved token' : 'ghp_...';
    tokenInput.autocomplete = 'off';
    form.appendChild(tokenField.wrapper);

    /* Branch row */
    const branchField = el('div');
    branchField.className = 'dw-field';
    const branchLabel = el('label');
    branchLabel.className = 'dw-label';
    branchLabel.textContent = 'Branch';
    branchField.appendChild(branchLabel);

    const branchRow = el('div');
    branchRow.className = 'dw-branch-row';

    const branchSelect = el('select');
    branchSelect.className = 'dw-select';
    branchSelect.style.display = 'none';
    const branchFallback = el('input');
    branchFallback.type = 'text';
    branchFallback.className = 'dw-input';
    branchFallback.placeholder = 'e.g. main';
    branchFallback.value = isEdit ? d.branch : '';

    const fetchBtn = elBtn('Fetch branches', '');
    fetchBtn.type = 'button';

    const branchError = el('p');
    branchError.className = 'dw-error';

    fetchBtn.addEventListener('click', function () {
      const repo = repoInput.value.trim();
      const visibility = getVisibility(form);
      const token = tokenInput.value.trim();
      fetchBranches(app, repo, visibility, token, branchSelect, branchFallback, branchError, fetchBtn, isEdit ? d.branch : '');
    });

    branchRow.appendChild(branchFallback);
    branchRow.appendChild(branchSelect);
    branchRow.appendChild(fetchBtn);
    branchField.appendChild(branchRow);
    branchField.appendChild(branchError);
    form.appendChild(branchField);

    /* Target type */
    const typeField = buildField('target_type', 'Target Type', true);
    const typeSelect = el('select');
    typeSelect.className = 'dw-select';
    typeSelect.id = 'dw-target-type';
    ['plugin', 'theme', 'mu-plugin'].forEach(function (v) {
      const opt = el('option');
      opt.value = v;
      opt.textContent = v;
      if (isEdit && d.target_type === v) { opt.selected = true; }
      typeSelect.appendChild(opt);
    });
    typeField.wrapper.replaceChild(typeSelect, typeField.input);
    form.appendChild(typeField.wrapper);

    /* Target slug */
    const slugField = buildField('target_slug', 'Target Slug', true);
    const slugInput = slugField.input;
    slugInput.className = 'dw-input';
    slugInput.placeholder = 'e.g. my-plugin';
    slugInput.value = isEdit ? d.target_slug : '';
    const slugHelp = el('p');
    slugHelp.className = 'dw-help';
    slugHelp.textContent = 'The folder name of the plugin or theme.';
    slugField.wrapper.appendChild(slugHelp);
    form.appendChild(slugField.wrapper);

    /* Inline error area */
    form.appendChild(errorArea);

    /* Form actions */
    const actions = el('div');
    actions.className = 'dw-form__actions';

    const submitBtn = elBtn(isEdit ? 'Save changes' : 'Save deployment', 'dw-btn--primary');
    submitBtn.type = 'submit';

    const cancelBtn = elBtn('Cancel', '');
    cancelBtn.type = 'button';
    cancelBtn.addEventListener('click', function () {
      app.editingDeployment = null;
      app.switchTab('deployments');
    });

    actions.appendChild(submitBtn);
    actions.appendChild(cancelBtn);
    form.appendChild(actions);

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      handleFormSubmit(app, form, repoInput, typeSelect, slugInput, branchSelect, branchFallback, tokenInput, isEdit ? d : null, submitBtn, errorArea);
    });

    wrap.appendChild(form);
    app.panelWrap.appendChild(wrap);
  }

  function buildField(id, labelText, required) {
    const wrapper = el('div');
    wrapper.className = 'dw-field';
    const labelEl = el('label');
    labelEl.className = 'dw-label';
    labelEl.htmlFor = 'dw-' + id;
    labelEl.textContent = labelText;
    if (required) {
      const req = el('span');
      req.className = 'dw-required';
      req.textContent = '*';
      labelEl.appendChild(req);
    }
    wrapper.appendChild(labelEl);
    const input = el('input');
    input.id = 'dw-' + id;
    input.type = 'text';
    wrapper.appendChild(input);
    return { wrapper: wrapper, input: input };
  }

  function buildSegmented(form, initial) {
    const seg = el('div');
    seg.className = 'dw-segmented';

    const opts = [{ value: 'public', label: 'Public' }, { value: 'private', label: 'Private' }];
    opts.forEach(function (opt) {
      const radio = el('input');
      radio.type = 'radio';
      radio.className = 'dw-segmented__option';
      radio.name = 'dw-visibility';
      radio.id = 'dw-vis-' + opt.value;
      radio.value = opt.value;
      if (initial === opt.value) { radio.checked = true; }

      radio.addEventListener('change', function () {
        toggleClass(form, 'is-private', opt.value === 'private');
      });

      const labelEl = el('label');
      labelEl.className = 'dw-segmented__label';
      labelEl.htmlFor = 'dw-vis-' + opt.value;
      labelEl.textContent = opt.label;

      seg.appendChild(radio);
      seg.appendChild(labelEl);
    });

    return seg;
  }

  function getVisibility(form) {
    const checked = form.querySelector('input[name="dw-visibility"]:checked');
    return checked ? checked.value : 'public';
  }

  function fetchBranches(app, repo, visibility, token, branchSelect, branchFallback, branchError, fetchBtn, currentBranch) {
    if (!repo) {
      clearEl(branchError);
      branchError.textContent = 'Enter a repository first.';
      return;
    }
    clearEl(branchError);
    setInFlight(fetchBtn, true);

    const body = { repo: repo, visibility: visibility };
    if (visibility === 'private' && token) { body.token = token; }

    app.api('POST', 'branches', body).then(function (res) {
      setInFlight(fetchBtn, false);
      if (res.status === 200 && Array.isArray(res.data.branches)) {
        populateBranchSelect(branchSelect, branchFallback, res.data.branches, currentBranch);
      } else {
        const msg = (res.data && res.data.error) || 'Could not fetch branches.';
        branchError.textContent = msg;
        branchSelect.style.display = 'none';
        branchFallback.style.display = '';
      }
    }).catch(function () {
      setInFlight(fetchBtn, false);
      branchError.textContent = 'Network error fetching branches.';
      branchSelect.style.display = 'none';
      branchFallback.style.display = '';
    });
  }

  function populateBranchSelect(branchSelect, branchFallback, branches, currentBranch) {
    clearEl(branchSelect);
    branches.forEach(function (name) {
      const opt = el('option');
      opt.value = name;
      opt.textContent = name;
      if (name === currentBranch) { opt.selected = true; }
      branchSelect.appendChild(opt);
    });
    branchSelect.style.display = '';
    branchFallback.style.display = 'none';
  }

  function handleFormSubmit(app, form, repoInput, typeSelect, slugInput, branchSelect, branchFallback, tokenInput, editing, submitBtn, errorArea) {
    clearEl(errorArea);

    const repo   = repoInput.value.trim();
    const type   = typeSelect.value;
    const slug   = slugInput.value.trim();
    const vis    = getVisibility(form);
    const token  = tokenInput.value.trim();
    const branch = branchSelect.style.display !== 'none'
      ? branchSelect.value
      : branchFallback.value.trim();

    if (type === 'plugin' && slug === 'deployward') {
      const inlineErr = el('p');
      inlineErr.className = 'dw-error';
      inlineErr.textContent = 'Deployward cannot deploy itself as a target.';
      errorArea.appendChild(inlineErr);
      return;
    }

    const body = {
      repo: repo,
      branch: branch || 'main',
      visibility: vis,
      target_type: type,
      target_slug: slug,
    };
    if (editing) { body.id = editing.id; }
    if (token)   { body.token = token; }

    setInFlight(submitBtn, true);

    app.api('POST', 'deployments', body).then(function (res) {
      setInFlight(submitBtn, false);
      if (res.status === 200 || res.status === 201) {
        app.editingDeployment = null;
        app.showToast('is-ok', editing ? 'Deployment updated.' : 'Deployment saved.');
        app.switchTab('deployments');
      } else {
        const msg = (res.data && res.data.error) || 'Save failed.';
        const inlineErr = el('p');
        inlineErr.className = 'dw-error';
        inlineErr.textContent = msg;
        errorArea.appendChild(inlineErr);
      }
    }).catch(function () {
      setInFlight(submitBtn, false);
      const inlineErr = el('p');
      inlineErr.className = 'dw-error';
      inlineErr.textContent = 'Network error saving deployment.';
      errorArea.appendChild(inlineErr);
    });
  }

  /* -------------------------------------------------------------------------
     Activity Log tab
     ---------------------------------------------------------------------- */

  function renderActivityLog(app) {
    const wrap = el('div');
    wrap.className = 'dw-card';

    const heading = el('h2');
    heading.textContent = 'Activity Log';
    wrap.appendChild(heading);

    const selectorRow = el('div');
    selectorRow.className = 'dw-field';
    const selectorLabel = el('label');
    selectorLabel.className = 'dw-label';
    selectorLabel.htmlFor = 'dw-log-select';
    selectorLabel.textContent = 'Deployment';
    selectorRow.appendChild(selectorLabel);

    const depSelect = el('select');
    depSelect.className = 'dw-select';
    depSelect.id = 'dw-log-select';
    const placeholder = el('option');
    placeholder.value = '';
    placeholder.textContent = 'Choose a deployment...';
    depSelect.appendChild(placeholder);
    selectorRow.appendChild(depSelect);
    wrap.appendChild(selectorRow);

    const logArea = el('div');
    wrap.appendChild(logArea);
    app.panelWrap.appendChild(wrap);

    let currentPage = 1;

    app.api('GET', 'deployments').then(function (res) {
      if (res.status !== 200) { return; }
      (res.data.deployments || []).forEach(function (d) {
        const opt = el('option');
        opt.value = d.id;
        opt.textContent = d.repo + ' (' + d.branch + ')';
        depSelect.appendChild(opt);
      });

      if (app.logTargetId) {
        depSelect.value = app.logTargetId;
        app.logTargetId = null;
        loadLog(app, depSelect.value, 1, logArea, currentPage, function (p) { currentPage = p; });
      }
    });

    depSelect.addEventListener('change', function () {
      currentPage = 1;
      clearEl(logArea);
      if (!depSelect.value) { return; }
      loadLog(app, depSelect.value, currentPage, logArea, currentPage, function (p) { currentPage = p; });
    });
  }

  function loadLog(app, depId, page, logArea, currentPage, setPage) {
    clearEl(logArea);
    const loading = buildSkeleton();
    logArea.appendChild(loading);

    app.api('GET', 'deployments/' + depId + '/log?page=' + page).then(function (res) {
      clearEl(logArea);
      if (res.status !== 200) {
        logArea.appendChild(buildToastEl('is-error', (res.data && res.data.error) || 'Failed to load log.'));
        return;
      }
      const entries = res.data.entries || [];
      if (entries.length === 0 && page === 1) {
        logArea.appendChild(buildEmptyState('No log entries yet.', 'Run a deployment to see activity here.'));
        return;
      }

      const logList = el('div');
      logList.className = 'dw-log';
      entries.forEach(function (entry) {
        logList.appendChild(buildLogRow(entry));
      });
      logArea.appendChild(logList);

      const pager = buildPager(page, entries.length, function (newPage) {
        setPage(newPage);
        loadLog(app, depId, newPage, logArea, newPage, setPage);
      });
      logArea.appendChild(pager);
    }).catch(function () {
      clearEl(logArea);
      logArea.appendChild(buildToastEl('is-error', 'Network error loading log.'));
    });
  }

  function buildLogRow(entry) {
    const row = el('div');
    row.className = 'dw-log__row';

    const timeEl = el('span');
    timeEl.className = 'dw-log__time';
    timeEl.textContent = entry.created_at || entry.time || '';
    row.appendChild(timeEl);

    const pill = el('span');
    pill.className = 'dw-pill ' + logStatusPill(entry.status);
    pill.textContent = entry.status || '';
    row.appendChild(pill);

    const shaEl = el('span');
    shaEl.className = 'dw-log__sha';
    shaEl.textContent = entry.sha ? entry.sha.slice(0, 8) : '';
    row.appendChild(shaEl);

    const msgEl = el('span');
    msgEl.className = 'dw-log__message';
    msgEl.textContent = entry.message || '';
    row.appendChild(msgEl);

    return row;
  }

  function logStatusPill(status) {
    if (status === 'success') { return 'is-ok'; }
    if (status === 'failed')  { return 'is-failed'; }
    if (status === 'skipped') { return 'is-never'; }
    return 'is-never';
  }

  function buildPager(page, count, onNavigate) {
    const pager = el('div');
    pager.className = 'dw-log__pager';

    const prevBtn = elBtn('Prev', '');
    prevBtn.disabled = page <= 1;
    prevBtn.addEventListener('click', function () { onNavigate(page - 1); });

    const label = el('span');
    label.className = 'dw-log__pager-label';
    label.textContent = 'Page ' + page;

    const nextBtn = elBtn('Next', '');
    nextBtn.disabled = count < 20;
    nextBtn.addEventListener('click', function () { onNavigate(page + 1); });

    pager.appendChild(prevBtn);
    pager.appendChild(label);
    pager.appendChild(nextBtn);
    return pager;
  }

  /* -------------------------------------------------------------------------
     Getting Started tab
     ---------------------------------------------------------------------- */

  function renderGettingStarted(app) {
    const wrap = el('div');
    wrap.className = 'dw-getting-started';

    const heading = el('h2');
    heading.textContent = 'Getting Started';
    wrap.appendChild(heading);

    wrap.appendChild(buildFlow('Public Repository Flow', [
      { title: 'Open Add Deployment', desc: 'Click the "Add Deployment" tab at the top of this page.' },
      { title: 'Enter the repository', desc: 'Paste the public GitHub repository URL or owner/repo slug into the Repository field.' },
      { title: 'Pick a branch', desc: 'Click "Fetch branches" to load available branches, then choose the one you want to deploy from.' },
      { title: 'Choose a target', desc: 'Select target type (plugin, theme, or mu-plugin) and enter the folder name as the target slug.' },
      { title: 'Save and deploy', desc: 'Click "Save deployment", then click "Deploy now" on the Deployments tab to run the first deploy.' },
    ]));

    wrap.appendChild(buildFlow('Private Repository Flow', [
      { title: 'Open Add Deployment', desc: 'Click the "Add Deployment" tab at the top of this page.' },
      { title: 'Enter the repository', desc: 'Paste the private GitHub repository owner/repo slug.' },
      { title: 'Set visibility to Private', desc: 'Click the "Private" button in the segmented Visibility control to reveal the Token field.' },
      {
        title: 'Create a GitHub fine-grained token',
        desc: 'Go to GitHub Settings -> Developer settings -> Fine-grained personal access tokens. Create a token scoped to this repository with Contents: Read-only permission, and paste it in the Token field. It is stored encrypted on your server.',
      },
      { title: 'Pick a branch', desc: 'Click "Fetch branches" (the token will be used to list branches), then choose the branch to deploy.' },
      { title: 'Choose a target', desc: 'Select target type and enter the folder name as the target slug.' },
      { title: 'Save and deploy', desc: 'Click "Save deployment", then click "Deploy now" on the Deployments tab.' },
    ]));

    const note = el('p');
    note.className = 'dw-help';
    note.textContent = 'Automatic deploy-on-push via webhooks arrives in the next release (the triggers module).';
    wrap.appendChild(note);

    app.panelWrap.appendChild(wrap);
  }

  function buildFlow(title, steps) {
    const section = el('section');

    const titleEl = el('h3');
    titleEl.className = 'dw-flow-title';
    titleEl.textContent = title;
    section.appendChild(titleEl);

    const list = el('ol');
    list.className = 'dw-steps';

    steps.forEach(function (step, i) {
      const item = el('li');
      item.className = 'dw-step';

      const num = el('span');
      num.className = 'dw-step__number';
      num.setAttribute('aria-hidden', 'true');
      num.textContent = String(i + 1);

      const body = el('div');
      body.className = 'dw-step__body';

      const stepTitle = el('p');
      stepTitle.className = 'dw-step__title';
      stepTitle.textContent = step.title;

      const stepDesc = el('p');
      stepDesc.className = 'dw-step__desc';
      stepDesc.textContent = step.desc;

      body.appendChild(stepTitle);
      body.appendChild(stepDesc);
      item.appendChild(num);
      item.appendChild(body);
      list.appendChild(item);
    });

    section.appendChild(list);
    return section;
  }

  /* -------------------------------------------------------------------------
     Toast
     ---------------------------------------------------------------------- */

  DeploywardApp.prototype.showToast = function (type, message) {
    const toast = buildToastEl(type, message);
    this.toastArea.appendChild(toast);
    setTimeout(function () {
      if (toast.parentNode) { toast.parentNode.removeChild(toast); }
    }, 5000);
  };

  function buildToastEl(type, message) {
    const toast = el('div');
    toast.className = 'dw-toast ' + type;
    const msg = el('span');
    msg.textContent = message;
    toast.appendChild(msg);
    const close = el('button');
    close.type = 'button';
    close.className = 'dw-toast__close';
    close.textContent = 'x';
    close.setAttribute('aria-label', 'Dismiss');
    close.addEventListener('click', function () {
      if (toast.parentNode) { toast.parentNode.removeChild(toast); }
    });
    toast.appendChild(close);
    return toast;
  }

  /* -------------------------------------------------------------------------
     Empty state + loading skeleton
     ---------------------------------------------------------------------- */

  function buildEmptyState(title, desc) {
    const wrap = el('div');
    wrap.className = 'dw-empty';
    const icon = el('span');
    icon.className = 'dw-empty__icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = 'deploy';
    wrap.appendChild(icon);
    const t = el('p');
    t.className = 'dw-empty__title';
    t.textContent = title;
    wrap.appendChild(t);
    const d = el('p');
    d.className = 'dw-empty__desc';
    d.textContent = desc;
    wrap.appendChild(d);
    return wrap;
  }

  function buildSkeleton() {
    const wrap = el('div');
    for (let i = 0; i < 3; i++) {
      const line = el('div');
      line.className = 'dw-skeleton';
      line.style.marginBottom = '12px';
      line.style.height = i === 0 ? '40px' : '24px';
      wrap.appendChild(line);
    }
    return wrap;
  }

  /* -------------------------------------------------------------------------
     In-flight state helpers
     ---------------------------------------------------------------------- */

  function setInFlight(btn, active) {
    btn.disabled = active;
    if (active) {
      const spin = el('span');
      spin.className = 'dw-spin';
      spin.setAttribute('data-spin', '1');
      btn.appendChild(spin);
    } else {
      const spin = btn.querySelector('[data-spin]');
      if (spin) { btn.removeChild(spin); }
    }
  }

  /* -------------------------------------------------------------------------
     DOM utilities
     ---------------------------------------------------------------------- */

  function el(tag) { return document.createElement(tag); }

  function elBtn(text, extraClass) {
    const btn = el('button');
    btn.type = 'button';
    btn.className = 'dw-btn' + (extraClass ? ' ' + extraClass : '');
    btn.textContent = text;
    return btn;
  }

  function clearEl(node) {
    while (node.firstChild) { node.removeChild(node.firstChild); }
  }

  function toggleClass(node, cls, force) {
    if (force) {
      node.classList.add(cls);
    } else {
      node.classList.remove(cls);
    }
  }

}());
