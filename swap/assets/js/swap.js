/*!
 * /var/www/html/public/rwa/swap/assets/js/swap.js
 * SWAP 2.0 Public Index Script
 * v2.2.0-20260326
 */

(function () {
  'use strict';

  const SWAP = {
    state: {
      method: 'passport',
      lang: 'en',
      isLoggedIn: false,
      jobsEndpoint: '/rwa/swap/api/job-alerts.php',
      statusEndpoint: '/rwa/swap/api/status-search.php',
      sendOtpEndpoint: '/rwa/swap/api/send-tg-otp.php'
    },

    dict: {
      en: {
        page_title: 'SWAP 2.0',
        page_sub: 'Human Resources RWA · Tertiary RWA · RHRD-EMA',
        tertiary_rwa: 'Tertiary RWA',
        hero_title: 'Human Resources RWA',
        hero_sub: 'Worker protection, application tracking, welfare monitoring, verified work-hour contribution, and future RHRD certificate issuance under the Human Resource Development RWA framework.',
        btn_apply_now: 'Apply Now',
        btn_open_dashboard: 'Open Worker Dashboard',
        btn_employer_portal: 'Employer Portal',
        btn_apply_selected: 'Apply with Selected Job',
        kpi_search: 'Worker Status Search',
        kpi_pillars: 'Welfare Pillars',
        kpi_hours: 'Hours per Cert',
        kpi_ema: 'EMA$ per Cert',
        search_title: 'Worker Status Search',
        search_sub: 'Public-safe search only. Sensitive worker data is never exposed here.',
        method_passport: 'Passport Number',
        method_application: 'Application ID',
        method_mobile: 'Mobile + Telegram OTP',
        label_passport: 'Passport Number',
        label_application: 'Application ID',
        label_mobile: 'Mobile Number',
        label_otp: 'Telegram OTP',
        ph_passport: 'Enter passport number',
        ph_application: 'Enter application ID',
        ph_mobile: '60123456789',
        ph_otp: 'Enter OTP',
        btn_send_otp: 'Send Telegram OTP',
        btn_search_status: 'Search Status',
        result_empty: 'Search result will appear here.',
        search_notice: 'Public results only show masked passport, status, stage, industry, location, project short code, next action and updated time.',
        jobs_title: 'Jobs Announcement',
        jobs_sub: 'Select a job to prefill your worker application dashboard.',
        jobs_loading: 'Loading jobs...',
        jobs_empty: 'No jobs available at the moment.',
        jobs_failed: 'Failed to load jobs.',
        jobs_select: 'Select Job',
        jobs_apply: 'Apply',
        jobs_open: 'OPEN',
        welfare_title: 'Worker Welfare Protection Engine',
        welfare_sub: 'Welfare score is a worker-protection score, not a productivity score.',
        pillar_legal: 'Legal Status',
        pillar_legal_note: 'Passport, permit, lawful work readiness.',
        pillar_medical: 'Medical',
        pillar_medical_note: 'Medical screening and health clearance.',
        pillar_social: 'Social Protection',
        pillar_social_note: 'SOCSO and worker protection coverage.',
        pillar_accommodation: 'Accommodation',
        pillar_accommodation_note: 'Hostel or accommodation compliance readiness.',
        pillar_safe: 'Safe Work Conditions',
        pillar_safe_note: 'Basic safety and compliant work placement condition.',
        flow_title: 'How SWAP 2.0 Works',
        flow_sub: 'Simple worker-facing process from application to certificate eligibility.',
        step1_title: 'Search or Select Job',
        step1_note: 'Find worker status or choose a live job announcement.',
        step2_title: 'Submit Application',
        step2_note: 'Application is stored in the SWAP worker request pipeline.',
        step3_title: 'Welfare + Compliance',
        step3_note: 'Admin, agent and employer workflows update readiness and protection status.',
        step4_title: 'Work Hours → RHRD Cert',
        step4_note: 'Verified yearly work hours become certificate eligibility pool.',
        footnote: 'SWAP 2.0 public module for worker protection, welfare compliance and Human Resources RWA onboarding.',
        result_searching: 'Searching...',
        result_not_found: 'No matching record found.',
        result_failed: 'Search failed. Please try again.',
        otp_need_mobile: 'Enter mobile number first.',
        otp_sent: 'OTP request submitted.',
        otp_failed: 'Failed to send OTP.',
        job_selected: 'Job selected. Your dashboard form will be prefilled.',
        job_select_failed: 'Unable to select this job.',
        field_masked_passport: 'Masked Passport',
        field_status: 'Status',
        field_stage: 'Stage',
        field_industry: 'Industry',
        field_location: 'Location',
        field_project_code: 'Project Code',
        field_next_action: 'Next Action',
        field_updated_at: 'Updated At',
        general_untitled_job: 'Untitled Job',
        general_general: 'General',
        general_location: 'Location',
        general_project: 'Project',
        public_access: 'PUBLIC ACCESS',
        session_ready: 'SESSION READY'
      },
      zh: {
        page_title: 'SWAP 2.0',
        page_sub: '人力资源 RWA · 三级 RWA · RHRD-EMA',
        tertiary_rwa: '三级 RWA',
        hero_title: '人力资源 RWA',
        hero_sub: '用于工人保护、申请追踪、福利监测、已验证工时贡献，以及未来基于人力资源发展框架的 RHRD 证书发行。',
        btn_apply_now: '立即申请',
        btn_open_dashboard: '打开工人面板',
        btn_employer_portal: '雇主入口',
        btn_apply_selected: '用已选职位申请',
        kpi_search: '工人状态查询',
        kpi_pillars: '福利评分维度',
        kpi_hours: '每证书工时',
        kpi_ema: '每证书 EMA$',
        search_title: '工人状态查询',
        search_sub: '这里只提供公开安全查询，不会显示敏感工人资料。',
        method_passport: '护照号码',
        method_application: '申请编号',
        method_mobile: '手机 + Telegram OTP',
        label_passport: '护照号码',
        label_application: '申请编号',
        label_mobile: '手机号码',
        label_otp: 'Telegram OTP',
        ph_passport: '输入护照号码',
        ph_application: '输入申请编号',
        ph_mobile: '60123456789',
        ph_otp: '输入 OTP',
        btn_send_otp: '发送 Telegram OTP',
        btn_search_status: '查询状态',
        result_empty: '查询结果会显示在这里。',
        search_notice: '公开结果只显示护照掩码、状态、阶段、行业、地点、项目简称、下一步动作和更新时间。',
        jobs_title: '职位公告',
        jobs_sub: '选择职位后可预填到工人申请面板。',
        jobs_loading: '正在加载职位...',
        jobs_empty: '当前没有可用职位。',
        jobs_failed: '职位加载失败。',
        jobs_select: '选择职位',
        jobs_apply: '申请',
        jobs_open: '开放中',
        welfare_title: '工人福利保护引擎',
        welfare_sub: '福利评分是工人保护评分，不是生产力评分。',
        pillar_legal: '法律状态',
        pillar_legal_note: '护照、准证与合法工作准备状态。',
        pillar_medical: '医疗',
        pillar_medical_note: '体检与健康许可状态。',
        pillar_social: '社会保障',
        pillar_social_note: 'SOCSO 与工人保护覆盖情况。',
        pillar_accommodation: '住宿',
        pillar_accommodation_note: '宿舍或住宿合规准备情况。',
        pillar_safe: '安全工作条件',
        pillar_safe_note: '基础安全与合规岗位环境。',
        flow_title: 'SWAP 2.0 运作方式',
        flow_sub: '从申请到证书资格的简明工人流程。',
        step1_title: '查询或选择职位',
        step1_note: '查询工人状态或选择实时职位公告。',
        step2_title: '提交申请',
        step2_note: '申请会进入 SWAP 工人申请流程。',
        step3_title: '福利 + 合规',
        step3_note: '管理员、代理与雇主流程会更新准备度与保护状态。',
        step4_title: '工时 → RHRD 证书',
        step4_note: '经验证的年度工时会成为证书资格池。',
        footnote: 'SWAP 2.0 公开模块用于工人保护、福利合规与人力资源 RWA 接入。',
        result_searching: '查询中...',
        result_not_found: '未找到匹配记录。',
        result_failed: '查询失败，请重试。',
        otp_need_mobile: '请先输入手机号码。',
        otp_sent: 'OTP 请求已提交。',
        otp_failed: '发送 OTP 失败。',
        job_selected: '职位已选择，工人面板将自动预填。',
        job_select_failed: '无法选择该职位。',
        field_masked_passport: '护照掩码',
        field_status: '状态',
        field_stage: '阶段',
        field_industry: '行业',
        field_location: '地点',
        field_project_code: '项目简称',
        field_next_action: '下一步动作',
        field_updated_at: '更新时间',
        general_untitled_job: '未命名职位',
        general_general: '通用',
        general_location: '地点',
        general_project: '项目',
        public_access: '公开访问',
        session_ready: '会话已就绪'
      }
    },

    els: {},

    init() {
      this.cacheDom();
      if (!this.els.form) return;

      this.state.isLoggedIn = this.readBodyFlag('data-swap-logged-in') === '1';
      this.state.jobsEndpoint = this.readBodyFlag('data-swap-jobs-endpoint') || this.state.jobsEndpoint;
      this.state.statusEndpoint = this.readBodyFlag('data-swap-status-endpoint') || this.state.statusEndpoint;
      this.state.sendOtpEndpoint = this.readBodyFlag('data-swap-send-otp-endpoint') || this.state.sendOtpEndpoint;

      this.state.lang = this.detectLang();
      this.bind();
      this.applyLang();
      this.setMethod('passport');
      this.renderEmptyResult(this.t('result_empty'));
      this.loadJobs();
    },

    cacheDom() {
      this.els.form = document.getElementById('swap-status-form');
      this.els.resultBox = document.getElementById('status-result-box');
      this.els.jobsList = document.getElementById('jobs-list');
      this.els.sendOtp = document.getElementById('btn-send-otp');
      this.els.methodBtns = Array.from(document.querySelectorAll('.method-btn'));
      this.els.langBtns = Array.from(document.querySelectorAll('[data-lang-btn]'));
      this.els.methodPanels = {
        passport: document.getElementById('method-passport'),
        application: document.getElementById('method-application'),
        mobile: document.getElementById('method-mobile')
      };
      this.els.sessionBadge = document.querySelector('[data-swap-session-badge]');
    },

    bind() {
      this.els.methodBtns.forEach(btn => {
        btn.addEventListener('click', () => {
          this.setMethod(btn.getAttribute('data-method') || 'passport');
        });
      });

      this.els.langBtns.forEach(btn => {
        btn.addEventListener('click', () => {
          this.setLang(btn.getAttribute('data-lang-btn') || 'en');
        });
      });

      this.els.form.addEventListener('submit', (ev) => this.searchStatus(ev));

      if (this.els.sendOtp) {
        this.els.sendOtp.addEventListener('click', () => this.sendOtp());
      }
    },

    readBodyFlag(name) {
      return document.body ? (document.body.getAttribute(name) || '') : '';
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
      this.renderEmptyResult(this.t('result_empty'));
      this.loadJobs();
    },

    t(key) {
      const pack = this.dict[this.state.lang] || this.dict.en;
      return pack[key] || this.dict.en[key] || key;
    },

    applyLang() {
      document.documentElement.lang = this.state.lang === 'zh' ? 'zh-CN' : 'en';

      document.querySelectorAll('[data-i18n]').forEach(el => {
        const key = el.getAttribute('data-i18n');
        el.textContent = this.t(key);
      });

      document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
        const key = el.getAttribute('data-i18n-placeholder');
        el.setAttribute('placeholder', this.t(key));
      });

      this.els.langBtns.forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-lang-btn') === this.state.lang);
      });

      if (this.els.sessionBadge) {
        this.els.sessionBadge.textContent = this.state.isLoggedIn ? this.t('session_ready') : this.t('public_access');
      }
    },

    setMethod(method) {
      this.state.method = method;

      this.els.methodBtns.forEach(btn => {
        btn.classList.toggle('is-active', btn.getAttribute('data-method') === method);
      });

      Object.keys(this.els.methodPanels).forEach(key => {
        const panel = this.els.methodPanels[key];
        if (panel) panel.style.display = key === method ? '' : 'none';
      });

      if (this.els.sendOtp) {
        this.els.sendOtp.style.display = method === 'mobile' ? '' : 'none';
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

    renderEmptyResult(msg) {
      if (!this.els.resultBox) return;
      this.els.resultBox.innerHTML = `<div class="result-empty">${this.escapeHtml(msg)}</div>`;
    },

    renderStatusResult(item) {
      if (!item || !item.ok) {
        this.renderEmptyResult(item && item.message ? item.message : this.t('result_not_found'));
        return;
      }

      const data = item.data || {};
      this.els.resultBox.innerHTML = `
        <div class="result-grid">
          <div class="result-item">
            <div class="result-key">${this.escapeHtml(this.t('field_masked_passport'))}</div>
            <div class="result-val">${this.escapeHtml(data.masked_passport || '-')}</div>
          </div>
          <div class="result-item">
            <div class="result-key">${this.escapeHtml(this.t('field_status'))}</div>
            <div class="result-val">${this.escapeHtml(data.status || '-')}</div>
          </div>
          <div class="result-item">
            <div class="result-key">${this.escapeHtml(this.t('field_stage'))}</div>
            <div class="result-val">${this.escapeHtml(data.stage || '-')}</div>
          </div>
          <div class="result-item">
            <div class="result-key">${this.escapeHtml(this.t('field_industry'))}</div>
            <div class="result-val">${this.escapeHtml(data.industry || '-')}</div>
          </div>
          <div class="result-item">
            <div class="result-key">${this.escapeHtml(this.t('field_location'))}</div>
            <div class="result-val">${this.escapeHtml(data.location || '-')}</div>
          </div>
          <div class="result-item">
            <div class="result-key">${this.escapeHtml(this.t('field_project_code'))}</div>
            <div class="result-val">${this.escapeHtml(data.project_short_code || '-')}</div>
          </div>
          <div class="result-item">
            <div class="result-key">${this.escapeHtml(this.t('field_next_action'))}</div>
            <div class="result-val">${this.escapeHtml(data.next_action || '-')}</div>
          </div>
          <div class="result-item">
            <div class="result-key">${this.escapeHtml(this.t('field_updated_at'))}</div>
            <div class="result-val">${this.escapeHtml(data.updated_at || '-')}</div>
          </div>
        </div>
      `;
    },

    async postJson(url, payload) {
      const res = await fetch(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload || {})
      });

      return res.json();
    },

    async searchStatus(ev) {
      ev.preventDefault();

      try {
        const payload = { method: this.state.method };

        if (this.state.method === 'passport') {
          payload.passport_no = (document.getElementById('passport_no')?.value || '').trim();
        } else if (this.state.method === 'application') {
          payload.application_id = (document.getElementById('application_id')?.value || '').trim();
        } else {
          payload.mobile_no = (document.getElementById('mobile_no')?.value || '').trim();
          payload.otp_code = (document.getElementById('otp_code')?.value || '').trim();
        }

        this.renderEmptyResult(this.t('result_searching'));
        const data = await this.postJson(this.state.statusEndpoint, payload);
        this.renderStatusResult(data);
      } catch (err) {
        this.renderEmptyResult(this.t('result_failed'));
      }
    },

    async sendOtp() {
      try {
        const mobileNo = (document.getElementById('mobile_no')?.value || '').trim();
        if (!mobileNo) {
          alert(this.t('otp_need_mobile'));
          return;
        }

        const data = await this.postJson(this.state.sendOtpEndpoint, { mobile_no: mobileNo });
        alert(data && data.message ? data.message : this.t('otp_sent'));
      } catch (err) {
        alert(this.t('otp_failed'));
      }
    },

    storeSelectedJob(job) {
      if (!job) return;
      localStorage.setItem('swap_selected_job_uid', job.job_uid || '');
      localStorage.setItem('swap_selected_job_title', job.title || '');
      localStorage.setItem('swap_selected_job_industry', job.industry || '');
      localStorage.setItem('swap_selected_job_location', job.location || '');
      localStorage.setItem('swap_selected_project_key', job.project_key || '');
    },

    bindJobButtons() {
      Array.from(this.els.jobsList.querySelectorAll('[data-pick-job]')).forEach(btn => {
        btn.addEventListener('click', () => {
          try {
            const raw = (btn.getAttribute('data-pick-job') || '').replace(/&#39;/g, "'");
            const job = JSON.parse(raw);
            this.storeSelectedJob(job);
            alert(this.t('job_selected'));
          } catch (e) {
            alert(this.t('job_select_failed'));
          }
        });
      });
    },

    renderJobs(items) {
      if (!Array.isArray(items) || !items.length) {
        this.els.jobsList.innerHTML = `<div class="empty-note">${this.escapeHtml(this.t('jobs_empty'))}</div>`;
        return;
      }

      this.els.jobsList.innerHTML = items.map(job => `
        <div class="job-card">
          <div class="job-top">
            <div>
              <div class="job-title">${this.escapeHtml(job.title || this.t('general_untitled_job'))}</div>
              <div class="job-meta">
                <span class="meta-pill">${this.escapeHtml(job.industry || this.t('general_general'))}</span>
                <span class="meta-pill">${this.escapeHtml(job.location || this.t('general_location'))}</span>
                <span class="meta-pill">${this.escapeHtml(job.project_key || this.t('general_project'))}</span>
              </div>
            </div>
            <span class="badge gold">${this.escapeHtml(this.t('jobs_open'))}</span>
          </div>
          <div class="job-actions">
            <button type="button" class="small-btn" data-pick-job='${JSON.stringify(job).replace(/'/g, '&#39;')}'>${this.escapeHtml(this.t('jobs_select'))}</button>
            <a class="small-btn secondary" href="/rwa/swap/dashboard.php">${this.escapeHtml(this.t('jobs_apply'))}</a>
          </div>
        </div>
      `).join('');

      this.bindJobButtons();
    },

    async loadJobs() {
      try {
        this.els.jobsList.innerHTML = `<div class="empty-note">${this.escapeHtml(this.t('jobs_loading'))}</div>`;

        const res = await fetch(this.state.jobsEndpoint, {
          headers: { 'Accept': 'application/json' }
        });

        const data = await res.json();

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
      } catch (err) {
        this.els.jobsList.innerHTML = `<div class="empty-note">${this.escapeHtml(this.t('jobs_failed'))}</div>`;
      }
    }
  };

  document.addEventListener('DOMContentLoaded', function () {
    SWAP.init();
  });
})();