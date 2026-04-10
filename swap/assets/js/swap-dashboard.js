/*!
 * /rwa/swap/assets/js/swap-dashboard.js
 * SWAP 2.0 Worker Dashboard Script
 * v3.0.0-20260326
 */

(function () {
  'use strict';

  const SWAPD = {
    state: {
      lang: 'en',
      userId: 0,
      jobsEndpoint: '/rwa/swap/api/job-alerts.php',
      applyEndpoint: '/rwa/swap/api/apply.php',
      myApplicationEndpoint: '/rwa/swap/api/my-application.php',
      myCertSummaryEndpoint: '/rwa/swap/api/my-cert-summary.php',
      mintCertEndpoint: '/rwa/swap/api/mint-cert.php',
      supportWa: '',
      selectedJob: null,
      latestApplication: null,
      certSummary: null,
      busyApply: false,
      busyMint: false
    },

    dict: {
      en: {
        dashboard_title: 'SWAP 2.0 Worker Dashboard',
        dashboard_sub: 'Human Resources RWA · Worker Application · RHRD-EMA',
        worker_dashboard_badge: 'Worker Dashboard',
        hero_dashboard_title: 'Worker Control Panel',
        hero_dashboard_sub: 'Submit or update your application, review your latest status, track welfare review progress, and check RHRD certificate eligibility from one dashboard.',
        btn_apply_update: 'Apply / Update',
        btn_refresh: 'Refresh',
        btn_mint_cert: 'Mint Cert',
        btn_back_jobs: 'Back to Jobs',
        no_job_selected: 'No job selected',
        status_pending_load: 'Status loading...',
        application_form_title: 'Worker Application Form',
        application_form_sub: 'Your selected job will prefill here automatically. Update your details and submit to the SWAP review pipeline.',
        selected_job_summary: 'Selected Job Summary',
        field_job_title: 'Job Title',
        field_job_uid: 'Job UID',
        field_industry: 'Industry',
        field_location: 'Location',
        field_project_code: 'Project Key',
        field_job_action: 'Change Job',
        btn_clear_job: 'Clear Selected Job',
        label_nickname: 'Nickname',
        ph_nickname: 'Your nickname',
        label_email: 'Email',
        ph_email: 'your@email.com',
        label_passport: 'Passport Number',
        ph_passport: 'Enter passport number',
        label_mobile: 'Mobile Number',
        ph_mobile: '60123456789',
        label_telegram: 'Telegram',
        ph_telegram: '@username',
        label_whatsapp_preview: 'WhatsApp Preview',
        label_country: 'Country',
        ph_country: 'Country',
        label_state: 'State / Province',
        ph_state: 'State or Province',
        label_area: 'Area',
        ph_area: 'Area',
        label_experience: 'Experience (Years)',
        ph_experience: '0',
        label_preferred_industry: 'Preferred Industry',
        ph_preferred_industry: 'Construction / Manufacturing / Services',
        label_wallet: 'Wallet',
        label_worker_note: 'Worker Note',
        ph_worker_note: 'Add any note for employer / agent / admin review',
        btn_submit_application: 'Submit Application',
        btn_reset_form: 'Reset Form',
        application_submit_idle: 'Application result will appear here.',
        worker_panels_title: 'Worker Dashboard Panels',
        worker_panels_sub: 'Review your selected job, latest application status, welfare progress and support actions here.',
        my_application_title: 'My Latest Application',
        application_loading: 'Loading application...',
        welfare_overview_title: 'Welfare Overview',
        field_welfare_score: 'Welfare Score',
        field_deployable_status: 'Deployable Status',
        field_risk_level: 'Risk Level',
        field_next_action: 'Next Action',
        not_calculated: 'Not calculated',
        pending_review: 'Pending review',
        support_title: 'Support / Contact',
        field_support_note: 'Support Note',
        support_note_text: 'Employer / agent will contact after review.',
        btn_open_whatsapp: 'Open WhatsApp',
        recent_jobs_title: 'Recent Jobs',
        jobs_loading: 'Loading jobs...',
        jobs_empty: 'No jobs available at the moment.',
        jobs_failed: 'Failed to load jobs.',
        jobs_select: 'Select Job',
        jobs_apply: 'Apply',
        jobs_open: 'OPEN',
        mint_cert_panel_title: 'RHRD Certificate Eligibility',
        mint_cert_panel_sub: 'Track your verified work hours, available yearly hours, and certificate mint eligibility here.',
        field_verified_hours: 'Verified Hours',
        field_consumed_hours: 'Consumed Hours',
        field_available_hours: 'Available Hours',
        field_claimable_certs: 'Claimable Certs',
        field_mint_rule: 'Mint Rule',
        field_ema_cost: 'EMA$ Required',
        mint_rule_text: '10 hours = 1 cert',
        mint_cost_text: '100 EMA$ = 1 cert',
        mint_cert_idle_note: 'Mint status and eligibility note will appear here.',
        btn_refresh_cert: 'Refresh Cert Summary',
        mint_cert_result_idle: 'Mint result will appear here.',
        flow_title: 'How SWAP 2.0 Works',
        flow_sub: 'Simple worker-facing process from application to certificate eligibility.',
        step1_title: 'Select Job',
        step1_note_dashboard: 'Choose a job from the public jobs board and review its details in your dashboard.',
        step2_title: 'Submit Application',
        step2_note_dashboard: 'Submit or update your worker application with identity, location and worker note.',
        step3_title: 'Review + Welfare',
        step3_note_dashboard: 'Admin, agent and employer review your application and welfare readiness.',
        step4_title: 'Work Hours → Cert',
        step4_note_dashboard: 'Verified work hours become yearly eligibility pool for RHRD certificate minting.',
        session_ready: 'SESSION READY',
        result_refreshing: 'Refreshing dashboard...',
        result_loading: 'Loading...',
        no_support_wa: 'WhatsApp support is not configured yet.',
        wa_preview_empty: 'Enter mobile number',
        selected_job_cleared: 'Selected job cleared.',
        job_selected: 'Job selected. Dashboard updated.',
        job_select_failed: 'Unable to select this job.',
        apply_submitting: 'Submitting application...',
        apply_success: 'Application submitted successfully.',
        apply_failed: 'Application submission failed.',
        apply_validation_job: 'Please select a job first.',
        apply_validation_passport: 'Please enter passport number.',
        apply_validation_mobile: 'Please enter mobile number.',
        refresh_done: 'Dashboard refreshed.',
        latest_app_none: 'No application record found yet.',
        app_id: 'Application ID',
        app_status: 'Status',
        app_stage: 'Stage',
        app_updated_at: 'Updated At',
        verified_hours_fmt: 'h',
        consumed_hours_fmt: 'h',
        available_hours_fmt: 'h',
        mint_cert_disabled: 'Not enough available hours to mint certificate yet.',
        mint_cert_ready: 'Eligible to mint certificate now.',
        mint_submitting: 'Minting certificate...',
        mint_success: 'Certificate mint request completed.',
        mint_failed: 'Certificate mint failed.',
        mint_not_ready: 'Not eligible to mint yet.',
        general_dash: '-'
      },
      zh: {
        dashboard_title: 'SWAP 2.0 工人面板',
        dashboard_sub: '人力资源 RWA · 工人申请 · RHRD-EMA',
        worker_dashboard_badge: '工人面板',
        hero_dashboard_title: '工人控制面板',
        hero_dashboard_sub: '在一个面板中提交或更新申请、查看最新状态、追踪福利审核进度，并查看 RHRD 证书资格。',
        btn_apply_update: '申请 / 更新',
        btn_refresh: '刷新',
        btn_mint_cert: '铸造证书',
        btn_back_jobs: '返回职位',
        no_job_selected: '未选择职位',
        status_pending_load: '状态加载中...',
        application_form_title: '工人申请表',
        application_form_sub: '你已选择的职位会自动预填在这里。更新资料后提交到 SWAP 审核流程。',
        selected_job_summary: '已选职位摘要',
        field_job_title: '职位名称',
        field_job_uid: '职位 UID',
        field_industry: '行业',
        field_location: '地点',
        field_project_code: '项目键',
        field_job_action: '变更职位',
        btn_clear_job: '清除已选职位',
        label_nickname: '昵称',
        ph_nickname: '你的昵称',
        label_email: '邮箱',
        ph_email: 'your@email.com',
        label_passport: '护照号码',
        ph_passport: '输入护照号码',
        label_mobile: '手机号码',
        ph_mobile: '60123456789',
        label_telegram: 'Telegram',
        ph_telegram: '@username',
        label_whatsapp_preview: 'WhatsApp 预览',
        label_country: '国家',
        ph_country: '国家',
        label_state: '州 / 省',
        ph_state: '州或省',
        label_area: '地区',
        ph_area: '地区',
        label_experience: '经验（年）',
        ph_experience: '0',
        label_preferred_industry: '偏好行业',
        ph_preferred_industry: '建筑 / 制造 / 服务',
        label_wallet: '钱包',
        label_worker_note: '工人备注',
        ph_worker_note: '添加给雇主 / 代理 / 管理员审核的备注',
        btn_submit_application: '提交申请',
        btn_reset_form: '重置表单',
        application_submit_idle: '申请结果会显示在这里。',
        worker_panels_title: '工人面板区块',
        worker_panels_sub: '在这里查看已选职位、最新申请状态、福利进度和支持操作。',
        my_application_title: '我的最新申请',
        application_loading: '申请加载中...',
        welfare_overview_title: '福利概览',
        field_welfare_score: '福利分数',
        field_deployable_status: '可部署状态',
        field_risk_level: '风险等级',
        field_next_action: '下一步动作',
        not_calculated: '未计算',
        pending_review: '待审核',
        support_title: '支持 / 联系',
        field_support_note: '支持说明',
        support_note_text: '雇主 / 代理将在审核后联系你。',
        btn_open_whatsapp: '打开 WhatsApp',
        recent_jobs_title: '最近职位',
        jobs_loading: '职位加载中...',
        jobs_empty: '当前没有职位。',
        jobs_failed: '职位加载失败。',
        jobs_select: '选择职位',
        jobs_apply: '申请',
        jobs_open: '开放中',
        mint_cert_panel_title: 'RHRD 证书资格',
        mint_cert_panel_sub: '在这里追踪已验证工时、可用年度工时和证书铸造资格。',
        field_verified_hours: '已验证工时',
        field_consumed_hours: '已消耗工时',
        field_available_hours: '可用工时',
        field_claimable_certs: '可申领证书数',
        field_mint_rule: '铸造规则',
        field_ema_cost: '所需 EMA$',
        mint_rule_text: '10 小时 = 1 张证书',
        mint_cost_text: '100 EMA$ = 1 张证书',
        mint_cert_idle_note: '铸造状态和资格说明会显示在这里。',
        btn_refresh_cert: '刷新证书摘要',
        mint_cert_result_idle: '铸造结果会显示在这里。',
        flow_title: 'SWAP 2.0 运作方式',
        flow_sub: '从申请到证书资格的简明工人流程。',
        step1_title: '选择职位',
        step1_note_dashboard: '从公开职位栏选择职位，并在工人面板查看详情。',
        step2_title: '提交申请',
        step2_note_dashboard: '提交或更新工人申请，包括身份、地点和工人备注。',
        step3_title: '审核 + 福利',
        step3_note_dashboard: '管理员、代理和雇主会审核你的申请和福利准备状态。',
        step4_title: '工时 → 证书',
        step4_note_dashboard: '已验证工时会成为年度 RHRD 证书铸造资格池。',
        session_ready: '会话已就绪',
        result_refreshing: '面板刷新中...',
        result_loading: '加载中...',
        no_support_wa: 'WhatsApp 支持尚未配置。',
        wa_preview_empty: '请输入手机号码',
        selected_job_cleared: '已清除所选职位。',
        job_selected: '职位已选择，面板已更新。',
        job_select_failed: '无法选择该职位。',
        apply_submitting: '申请提交中...',
        apply_success: '申请提交成功。',
        apply_failed: '申请提交失败。',
        apply_validation_job: '请先选择职位。',
        apply_validation_passport: '请输入护照号码。',
        apply_validation_mobile: '请输入手机号码。',
        refresh_done: '面板已刷新。',
        latest_app_none: '暂时没有申请记录。',
        app_id: '申请编号',
        app_status: '状态',
        app_stage: '阶段',
        app_updated_at: '更新时间',
        verified_hours_fmt: '小时',
        consumed_hours_fmt: '小时',
        available_hours_fmt: '小时',
        mint_cert_disabled: '可用工时不足，暂时不能铸造证书。',
        mint_cert_ready: '当前已符合证书铸造资格。',
        mint_submitting: '证书铸造中...',
        mint_success: '证书铸造请求已完成。',
        mint_failed: '证书铸造失败。',
        mint_not_ready: '当前未达到铸造资格。',
        general_dash: '-'
      }
    },

    els: {},

    init() {
      this.cache();
      if (!this.els.form) return;

      this.readConfig();
      this.state.lang = this.detectLang();
      this.bind();
      this.applyLang();
      this.hydrateSelectedJob();
      this.updateWhatsAppPreview();
      this.refreshAll(false);
    },

    cache() {
      this.els.langBtns = Array.from(document.querySelectorAll('[data-lang-btn]'));
      this.els.sessionBadge = document.querySelector('[data-swap-session-badge]');
      this.els.form = document.getElementById('swap-application-form');
      this.els.applyResult = document.getElementById('application-submit-result');
      this.els.myAppBox = document.getElementById('my-application-box');
      this.els.jobsList = document.getElementById('dashboard-jobs-list');
      this.els.mintResult = document.getElementById('mint-cert-result');
      this.els.certNote = document.getElementById('cert-eligibility-note');

      this.els.btnRefreshDashboard = document.getElementById('btn-refresh-dashboard');
      this.els.btnHeroMint = document.getElementById('btn-hero-mint-cert');
      this.els.btnClearJob = document.getElementById('btn-clear-selected-job');
      this.els.btnResetForm = document.getElementById('btn-reset-application');
      this.els.btnOpenWhatsApp = document.getElementById('btn-open-whatsapp');
      this.els.btnMintCert = document.getElementById('btn-mint-cert');
      this.els.btnRefreshCert = document.getElementById('btn-refresh-cert');

      this.els.mobileInput = document.getElementById('mobile_no');
      this.els.whatsAppPreview = document.getElementById('whatsapp_preview');
      this.els.supportWhatsAppView = document.getElementById('support-whatsapp-view');

      this.els.jobUid = document.getElementById('job_uid');
      this.els.projectKey = document.getElementById('project_key');
      this.els.jobTitle = document.getElementById('job_title');
      this.els.jobIndustry = document.getElementById('job_industry');
      this.els.jobLocation = document.getElementById('job_location');

      this.els.selectedJobChip = document.getElementById('swap-selected-job-chip');
      this.els.latestStatusChip = document.getElementById('swap-latest-status-chip');

      this.els.views = {
        jobTitleView: document.getElementById('job-title-view'),
        jobUidView: document.getElementById('job-uid-view'),
        jobIndustryView: document.getElementById('job-industry-view'),
        jobLocationView: document.getElementById('job-location-view'),
        jobProjectView: document.getElementById('job-project-view'),
        sideJobTitle: document.getElementById('side-job-title'),
        sideJobIndustry: document.getElementById('side-job-industry'),
        sideJobLocation: document.getElementById('side-job-location'),
        sideJobProject: document.getElementById('side-job-project'),
        welfareScore: document.getElementById('worker-welfare-score'),
        deployableStatus: document.getElementById('worker-deployable-status'),
        riskLevel: document.getElementById('worker-risk-level'),
        nextAction: document.getElementById('worker-next-action'),
        certVerifiedHours: document.getElementById('cert-verified-hours'),
        certConsumedHours: document.getElementById('cert-consumed-hours'),
        certAvailableHours: document.getElementById('cert-available-hours'),
        certClaimableCerts: document.getElementById('cert-claimable-certs')
      };
    },

    readConfig() {
      const body = document.body;
      this.state.userId = parseInt(body.getAttribute('data-swap-user-id') || '0', 10) || 0;
      this.state.jobsEndpoint = body.getAttribute('data-swap-jobs-endpoint') || this.state.jobsEndpoint;
      this.state.applyEndpoint = body.getAttribute('data-swap-apply-endpoint') || this.state.applyEndpoint;
      this.state.myApplicationEndpoint = body.getAttribute('data-swap-my-application-endpoint') || this.state.myApplicationEndpoint;
      this.state.myCertSummaryEndpoint = body.getAttribute('data-swap-my-cert-summary-endpoint') || this.state.myCertSummaryEndpoint;
      this.state.mintCertEndpoint = body.getAttribute('data-swap-mint-cert-endpoint') || this.state.mintCertEndpoint;
      this.state.supportWa = body.getAttribute('data-swap-support-wa') || '';
    },

    bind() {
      this.els.langBtns.forEach((btn) => {
        btn.addEventListener('click', () => this.setLang(btn.getAttribute('data-lang-btn') || 'en'));
      });

      this.els.mobileInput.addEventListener('input', () => this.updateWhatsAppPreview());

      this.els.form.addEventListener('submit', (ev) => this.submitApplication(ev));

      this.els.btnRefreshDashboard.addEventListener('click', () => this.refreshAll(true));
      this.els.btnHeroMint.addEventListener('click', () => this.scrollToMintPanel());
      this.els.btnClearJob.addEventListener('click', () => this.clearSelectedJob());
      this.els.btnResetForm.addEventListener('click', () => this.resetFormKeepJob());
      this.els.btnOpenWhatsApp.addEventListener('click', (ev) => this.openWhatsApp(ev));
      this.els.btnMintCert.addEventListener('click', () => this.mintCert());
      this.els.btnRefreshCert.addEventListener('click', () => this.loadCertSummary());
    },

    detectLang() {
      const saved = localStorage.getItem('poado_lang');
      if (saved === 'en' || saved === 'zh') return saved;
      const nav = (navigator.language || '').toLowerCase();
      return nav.includes('zh') ? 'zh' : 'en';
    },

    setLang(lang) {
      this.state.lang = lang === 'zh' ? 'zh' : 'en';
      localStorage.setItem('poado_lang', this.state.lang);
      this.applyLang();
      this.renderSelectedJob();
      this.renderLatestApplication();
      this.renderCertSummary();
      this.renderJobs(this.state.cachedJobs || []);
      this.updateWhatsAppPreview();
    },

    t(key) {
      const pack = this.dict[this.state.lang] || this.dict.en;
      return pack[key] || this.dict.en[key] || key;
    },

    applyLang() {
      document.documentElement.lang = this.state.lang === 'zh' ? 'zh-CN' : 'en';

      document.querySelectorAll('[data-i18n]').forEach((el) => {
        const key = el.getAttribute('data-i18n');
        el.textContent = this.t(key);
      });

      document.querySelectorAll('[data-i18n-placeholder]').forEach((el) => {
        const key = el.getAttribute('data-i18n-placeholder');
        el.setAttribute('placeholder', this.t(key));
      });

      this.els.langBtns.forEach((btn) => {
        btn.classList.toggle('active', btn.getAttribute('data-lang-btn') === this.state.lang);
      });

      if (this.els.sessionBadge) {
        this.els.sessionBadge.textContent = this.t('session_ready');
      }
    },

    escapeHtml(v) {
      return String(v ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    },

    jsonFetch(url, options) {
      return fetch(url, options).then((res) => res.json());
    },

    postJson(url, payload) {
      return this.jsonFetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload || {})
      });
    },

    normalizeDigits(v) {
      return String(v || '').replace(/\D+/g, '');
    },

    makeWaLink(v) {
      const digits = this.normalizeDigits(v);
      return digits ? ('https://wa.me/' + digits) : '';
    },

    updateWhatsAppPreview() {
      const mobileVal = this.els.mobileInput ? this.els.mobileInput.value : '';
      const mobileLink = this.makeWaLink(mobileVal);
      const supportLink = this.makeWaLink(this.state.supportWa);

      if (this.els.whatsAppPreview) {
        this.els.whatsAppPreview.value = mobileLink || this.t('wa_preview_empty');
      }

      if (this.els.supportWhatsAppView) {
        this.els.supportWhatsAppView.textContent = supportLink || mobileLink || this.t('no_support_wa');
      }

      if (this.els.btnOpenWhatsApp) {
        const href = supportLink || mobileLink || '#';
        this.els.btnOpenWhatsApp.setAttribute('href', href);
        this.els.btnOpenWhatsApp.setAttribute('target', '_blank');
        this.els.btnOpenWhatsApp.setAttribute('rel', 'noopener noreferrer');
      }
    },

    hydrateSelectedJob() {
      this.state.selectedJob = {
        job_uid: localStorage.getItem('swap_selected_job_uid') || '',
        title: localStorage.getItem('swap_selected_job_title') || '',
        industry: localStorage.getItem('swap_selected_job_industry') || '',
        location: localStorage.getItem('swap_selected_job_location') || '',
        project_key: localStorage.getItem('swap_selected_project_key') || ''
      };

      const hasJob = !!(this.state.selectedJob.job_uid || this.state.selectedJob.title);
      if (!hasJob) {
        this.state.selectedJob = null;
      }

      this.renderSelectedJob();
    },

    renderSelectedJob() {
      const job = this.state.selectedJob;
      const dash = this.t('general_dash');

      const title = job && job.title ? job.title : dash;
      const uid = job && job.job_uid ? job.job_uid : dash;
      const industry = job && job.industry ? job.industry : dash;
      const location = job && job.location ? job.location : dash;
      const project = job && job.project_key ? job.project_key : dash;

      if (this.els.jobUid) this.els.jobUid.value = job ? (job.job_uid || '') : '';
      if (this.els.projectKey) this.els.projectKey.value = job ? (job.project_key || '') : '';
      if (this.els.jobTitle) this.els.jobTitle.value = job ? (job.title || '') : '';
      if (this.els.jobIndustry) this.els.jobIndustry.value = job ? (job.industry || '') : '';
      if (this.els.jobLocation) this.els.jobLocation.value = job ? (job.location || '') : '';

      this.els.views.jobTitleView.textContent = title;
      this.els.views.jobUidView.textContent = uid;
      this.els.views.jobIndustryView.textContent = industry;
      this.els.views.jobLocationView.textContent = location;
      this.els.views.jobProjectView.textContent = project;
      this.els.views.sideJobTitle.textContent = title;
      this.els.views.sideJobIndustry.textContent = industry;
      this.els.views.sideJobLocation.textContent = location;
      this.els.views.sideJobProject.textContent = project;

      if (this.els.selectedJobChip) {
        this.els.selectedJobChip.textContent = job && (job.title || job.job_uid)
          ? (job.title || job.job_uid)
          : this.t('no_job_selected');
      }
    },

    clearSelectedJob() {
      [
        'swap_selected_job_uid',
        'swap_selected_job_title',
        'swap_selected_job_industry',
        'swap_selected_job_location',
        'swap_selected_project_key'
      ].forEach((k) => localStorage.removeItem(k));

      this.state.selectedJob = null;
      this.renderSelectedJob();
      this.writeApplyResult(this.t('selected_job_cleared'), false);
    },

    resetFormKeepJob() {
      this.els.form.reset();
      this.renderSelectedJob();
      this.updateWhatsAppPreview();
      this.writeApplyResult(this.t('application_submit_idle'), false);
    },

    writeApplyResult(message, isError) {
      if (!this.els.applyResult) return;
      this.els.applyResult.innerHTML = `<div class="result-empty"${isError ? ' style="color:#ffd7dc"' : ''}>${this.escapeHtml(message)}</div>`;
    },

    writeMintResult(message, isError) {
      if (!this.els.mintResult) return;
      this.els.mintResult.innerHTML = `<div class="result-empty"${isError ? ' style="color:#ffd7dc"' : ''}>${this.escapeHtml(message)}</div>`;
    },

    renderLatestApplication() {
      const box = this.els.myAppBox;
      if (!box) return;

      const app = this.state.latestApplication;
      if (!app || app.ok === false || !app.data) {
        box.innerHTML = `<div class="result-empty">${this.escapeHtml(this.t('latest_app_none'))}</div>`;
        if (this.els.latestStatusChip) this.els.latestStatusChip.textContent = this.t('latest_app_none');
        return;
      }

      const d = app.data || {};
      box.innerHTML = `
        <div class="result-grid">
          <div class="result-item">
            <div class="result-key">${this.escapeHtml(this.t('app_id'))}</div>
            <div class="result-val">${this.escapeHtml(d.application_id || this.t('general_dash'))}</div>
          </div>
          <div class="result-item">
            <div class="result-key">${this.escapeHtml(this.t('app_status'))}</div>
            <div class="result-val">${this.escapeHtml(d.status || this.t('general_dash'))}</div>
          </div>
          <div class="result-item">
            <div class="result-key">${this.escapeHtml(this.t('app_stage'))}</div>
            <div class="result-val">${this.escapeHtml(d.stage || this.t('general_dash'))}</div>
          </div>
          <div class="result-item">
            <div class="result-key">${this.escapeHtml(this.t('app_updated_at'))}</div>
            <div class="result-val">${this.escapeHtml(d.updated_at || this.t('general_dash'))}</div>
          </div>
        </div>
      `;

      this.els.views.welfareScore.textContent = d.welfare_score ?? this.t('not_calculated');
      this.els.views.deployableStatus.textContent = d.deployable_status || this.t('pending_review');
      this.els.views.riskLevel.textContent = d.risk_level || this.t('pending_review');
      this.els.views.nextAction.textContent = d.next_action || this.t('pending_review');

      if (this.els.latestStatusChip) {
        this.els.latestStatusChip.textContent = d.status || this.t('status_pending_load');
      }
    },

    renderCertSummary() {
      const s = this.state.certSummary;
      const v = s && s.data ? s.data : {};

      const verified = Number(v.verified_hours || 0);
      const consumed = Number(v.consumed_hours || 0);
      const available = Number(v.available_hours || 0);
      const claimable = Number(v.claimable_certs || 0);

      this.els.views.certVerifiedHours.textContent = `${verified}${this.t('verified_hours_fmt')}`;
      this.els.views.certConsumedHours.textContent = `${consumed}${this.t('consumed_hours_fmt')}`;
      this.els.views.certAvailableHours.textContent = `${available}${this.t('available_hours_fmt')}`;
      this.els.views.certClaimableCerts.textContent = String(claimable);

      if (claimable >= 1) {
        this.els.certNote.textContent = this.t('mint_cert_ready');
        this.els.btnMintCert.disabled = false;
        this.els.btnHeroMint.disabled = false;
      } else {
        this.els.certNote.textContent = this.t('mint_cert_disabled');
        this.els.btnMintCert.disabled = true;
        this.els.btnHeroMint.disabled = true;
      }
    },

    async loadLatestApplication() {
      this.els.myAppBox.innerHTML = `<div class="result-empty">${this.escapeHtml(this.t('application_loading'))}</div>`;
      try {
        const data = await this.jsonFetch(this.state.myApplicationEndpoint, {
          headers: { 'Accept': 'application/json' }
        });
        this.state.latestApplication = data;
      } catch (e) {
        this.state.latestApplication = { ok: false };
      }
      this.renderLatestApplication();
    },

    async loadCertSummary() {
      this.els.certNote.textContent = this.t('result_loading');
      try {
        const data = await this.jsonFetch(this.state.myCertSummaryEndpoint, {
          headers: { 'Accept': 'application/json' }
        });
        this.state.certSummary = data;
      } catch (e) {
        this.state.certSummary = { ok: false, data: {} };
      }
      this.renderCertSummary();
    },

    bindJobButtons() {
      Array.from(this.els.jobsList.querySelectorAll('[data-pick-job]')).forEach((btn) => {
        btn.addEventListener('click', () => {
          try {
            const raw = (btn.getAttribute('data-pick-job') || '').replace(/&#39;/g, "'");
            const job = JSON.parse(raw);

            localStorage.setItem('swap_selected_job_uid', job.job_uid || '');
            localStorage.setItem('swap_selected_job_title', job.title || '');
            localStorage.setItem('swap_selected_job_industry', job.industry || '');
            localStorage.setItem('swap_selected_job_location', job.location || '');
            localStorage.setItem('swap_selected_project_key', job.project_key || '');

            this.hydrateSelectedJob();
            this.writeApplyResult(this.t('job_selected'), false);
            window.scrollTo({ top: 0, behavior: 'smooth' });
          } catch (e) {
            this.writeApplyResult(this.t('job_select_failed'), true);
          }
        });
      });
    },

    renderJobs(items) {
      this.state.cachedJobs = Array.isArray(items) ? items : [];
      if (!Array.isArray(items) || !items.length) {
        this.els.jobsList.innerHTML = `<div class="empty-note">${this.escapeHtml(this.t('jobs_empty'))}</div>`;
        return;
      }

      this.els.jobsList.innerHTML = items.map((job) => `
        <div class="job-card">
          <div class="job-top">
            <div>
              <div class="job-title">${this.escapeHtml(job.title || this.t('general_dash'))}</div>
              <div class="job-meta">
                <span class="meta-pill">${this.escapeHtml(job.industry || this.t('general_dash'))}</span>
                <span class="meta-pill">${this.escapeHtml(job.location || this.t('general_dash'))}</span>
                <span class="meta-pill">${this.escapeHtml(job.project_key || this.t('general_dash'))}</span>
              </div>
            </div>
            <span class="badge gold">${this.escapeHtml(this.t('jobs_open'))}</span>
          </div>
          <div class="job-actions">
            <button type="button" class="small-btn" data-pick-job='${JSON.stringify(job).replace(/'/g, '&#39;')}'>${this.escapeHtml(this.t('jobs_select'))}</button>
            <a class="small-btn secondary" href="/rwa/swap/index.php">${this.escapeHtml(this.t('jobs_apply'))}</a>
          </div>
        </div>
      `).join('');

      this.bindJobButtons();
    },

    async loadJobs() {
      this.els.jobsList.innerHTML = `<div class="empty-note">${this.escapeHtml(this.t('jobs_loading'))}</div>`;
      try {
        const data = await this.jsonFetch(this.state.jobsEndpoint, {
          headers: { 'Accept': 'application/json' }
        });

        if (Array.isArray(data)) {
          this.renderJobs(data);
          return;
        }
        if (data && Array.isArray(data.items)) {
          this.renderJobs(data.items);
          return;
        }
        if (data && Array.isArray(data.data)) {
          this.renderJobs(data.data);
          return;
        }

        this.renderJobs([]);
      } catch (e) {
        this.els.jobsList.innerHTML = `<div class="empty-note">${this.escapeHtml(this.t('jobs_failed'))}</div>`;
      }
    },

    validateApplyPayload(payload) {
      if (!payload.job_uid && !payload.job_title) {
        return this.t('apply_validation_job');
      }
      if (!payload.passport_no) {
        return this.t('apply_validation_passport');
      }
      if (!payload.mobile_no) {
        return this.t('apply_validation_mobile');
      }
      return '';
    },

    readFormPayload() {
      const fd = new FormData(this.els.form);
      const out = {};
      fd.forEach((v, k) => { out[k] = String(v || '').trim(); });
      return out;
    },

    async submitApplication(ev) {
      ev.preventDefault();
      if (this.state.busyApply) return;

      const payload = this.readFormPayload();
      const validation = this.validateApplyPayload(payload);
      if (validation) {
        this.writeApplyResult(validation, true);
        return;
      }

      this.state.busyApply = true;
      this.writeApplyResult(this.t('apply_submitting'), false);

      try {
        const data = await this.postJson(this.state.applyEndpoint, payload);
        const ok = !!(data && (data.ok === true || data.success === true));
        this.writeApplyResult(
          ok ? (data.message || this.t('apply_success')) : (data.message || this.t('apply_failed')),
          !ok
        );
        if (ok) {
          await this.loadLatestApplication();
        }
      } catch (e) {
        this.writeApplyResult(this.t('apply_failed'), true);
      } finally {
        this.state.busyApply = false;
      }
    },

    async mintCert() {
      if (this.state.busyMint) return;

      const s = this.state.certSummary && this.state.certSummary.data ? this.state.certSummary.data : {};
      const claimable = Number(s.claimable_certs || 0);
      if (claimable < 1) {
        this.writeMintResult(this.t('mint_not_ready'), true);
        return;
      }

      this.state.busyMint = true;
      this.writeMintResult(this.t('mint_submitting'), false);

      try {
        const data = await this.postJson(this.state.mintCertEndpoint, {
          action: 'mint',
          claimable_certs: claimable
        });
        const ok = !!(data && (data.ok === true || data.success === true));
        this.writeMintResult(
          ok ? (data.message || this.t('mint_success')) : (data.message || this.t('mint_failed')),
          !ok
        );
        if (ok) {
          await this.loadCertSummary();
        }
      } catch (e) {
        this.writeMintResult(this.t('mint_failed'), true);
      } finally {
        this.state.busyMint = false;
      }
    },

    openWhatsApp(ev) {
      const href = this.els.btnOpenWhatsApp.getAttribute('href') || '#';
      if (!href || href === '#') {
        ev.preventDefault();
        this.writeApplyResult(this.t('no_support_wa'), true);
      }
    },

    scrollToMintPanel() {
      const el = document.getElementById('swap-mint-cert-section');
      if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    },

    async refreshAll(showMessage) {
      if (showMessage) {
        this.writeApplyResult(this.t('result_refreshing'), false);
      }
      this.hydrateSelectedJob();
      this.updateWhatsAppPreview();
      await Promise.all([
        this.loadLatestApplication(),
        this.loadCertSummary(),
        this.loadJobs()
      ]);
      if (showMessage) {
        this.writeApplyResult(this.t('refresh_done'), false);
      }
    }
  };

  document.addEventListener('DOMContentLoaded', function () {
    SWAPD.init();
  });
})();