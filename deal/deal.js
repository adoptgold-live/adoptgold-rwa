(() => {
  'use strict';

  const ENDPOINTS = {
    pending: '/rwa/api/deal/list-ace.php',
    accepted: '/rwa/api/deal/list-deal.php',
    accept: '/rwa/api/deal/accept.php',
    cancel: '/rwa/api/deal/cancel.php',
    reassign: '/rwa/api/deal/reassign.php',
    createDeal: '/rwa/api/deal/create-deal.php'
  };

  const $ = (id) => document.getElementById(id);

  const el = {
    pendingPanel: $('pendingPanel'),
    acceptedPanel: $('acceptedPanel'),
    tabPending: $('tabPending'),
    tabAccepted: $('tabAccepted'),

    pendingMsg: $('pendingMsg'),
    acceptedMsg: $('acceptedMsg'),

    pendingSearch: $('pendingSearch'),
    acceptedSearch: $('acceptedSearch'),
    pendingSearchBtn: $('pendingSearchBtn'),
    acceptedSearchBtn: $('acceptedSearchBtn'),
    pendingResetBtn: $('pendingResetBtn'),
    acceptedResetBtn: $('acceptedResetBtn'),

    pendingList: $('pendingList'),
    acceptedList: $('acceptedList'),

    csrfAccept: $('csrfDealAccept'),
    csrfCancel: $('csrfDealCancel'),
    csrfReassign: $('csrfDealReassign'),
    csrfCreate: $('csrfDealCreate'),

    qrModalBack: $('qrModalBack'),
    qrModalTitle: $('qrModalTitle'),
    qrBox: $('qrBox'),
    qrCloseBtn: $('qrCloseBtn')
  };

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (m) => ({
      '&':'&amp;',
      '<':'&lt;',
      '>':'&gt;',
      '"':'&quot;',
      "'":'&#039;'
    }[m]));
  }

  function setLang(lang) {
    const safe = lang === 'zh' ? 'zh' : 'en';
    document.body.classList.remove('deal-lang-en', 'deal-lang-zh');
    document.body.classList.add(safe === 'zh' ? 'deal-lang-zh' : 'deal-lang-en');
    document.querySelectorAll('.lang-btn').forEach((btn) => {
      btn.classList.toggle('active', btn.dataset.lang === safe);
    });
    try { localStorage.setItem('rwa_deal_lang', safe); } catch(e) {}
  }

  function restoreLang() {
    try {
      const saved = localStorage.getItem('rwa_deal_lang');
      if (saved === 'zh' || saved === 'en') {
        setLang(saved);
        return;
      }
    } catch(e) {}
    setLang('en');
  }

  function setMsg(target, msg, type = 'normal') {
    const cls = type === 'ok' ? 'ok' : (type === 'bad' ? 'bad' : (type === 'warn' ? 'warn' : ''));
    if (target) {
      target.innerHTML = cls ? `<span class="${cls}">${esc(msg)}</span>` : esc(msg);
    }
  }

  function getCsrf(kind) {
    if (kind === 'accept') return el.csrfAccept?.value || '';
    if (kind === 'cancel') return el.csrfCancel?.value || '';
    if (kind === 'reassign') return el.csrfReassign?.value || '';
    if (kind === 'create') return el.csrfCreate?.value || '';
    return '';
  }

  async function fetchJSON(url, opts = {}) {
    const res = await fetch(url, Object.assign({
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }, opts));

    const text = await res.text();
    if (text.trim().startsWith('<')) {
      throw new Error('HTML response from ' + url);
    }
    return JSON.parse(text);
  }

  function switchTab(which) {
    const isPending = which === 'pending';
    const mobileMode = window.innerWidth < 960;

    if (el.tabPending) el.tabPending.classList.toggle('active', isPending);
    if (el.tabAccepted) el.tabAccepted.classList.toggle('active', !isPending);

    if (mobileMode) {
      if (el.pendingPanel) el.pendingPanel.classList.toggle('hidden', !isPending);
      if (el.acceptedPanel) el.acceptedPanel.classList.toggle('hidden', isPending);
    } else {
      if (el.pendingPanel) el.pendingPanel.classList.remove('hidden');
      if (el.acceptedPanel) el.acceptedPanel.classList.remove('hidden');
    }
  }

  function normalizePlus(v) {
    v = String(v ?? '').trim().replace(/\s+/g, '');
    if (!v) return '';
    v = v.replace(/^\++/, '+');
    if (v[0] !== '+') v = '+' + v;
    return v;
  }

  function qrSrcFor(text) {
    return '/dashboard/inc/qr.php?text=' + encodeURIComponent(text || '');
  }

  function renderPending(items, allFull) {
    const rows = Array.isArray(items) ? items : [];
    if (!el.pendingList) return;

    if (!rows.length) {
      el.pendingList.innerHTML = `<div class="empty"><span class="deal-i18n-en">No pending items.</span><span class="deal-i18n-zh">暂无待处理项目。</span></div>`;
      return;
    }

    el.pendingList.innerHTML = rows.map((it) => {
      const isQueue = Number(it.is_queue) === 1;
      const acceptBlocked = Number(it.accept_blocked) === 1 || !!allFull;

      const uid = it.booking_uid || ('#' + it.id);
      const name = it.customer_name || '';
      const email = it.customer_email || '';
      const cc = normalizePlus(it.calling_code);
      const phone = (cc || '') + (it.mobile_e164 || it.customer_mobile || '');
      const geo = [it.customer_country_name, it.state_name, it.area_name].filter(Boolean).join(' / ');
      const loc = it.offline_address || it.meeting_location || '';
      const maps = it.maps_link || it.maps || '';
      const dt = [it.meeting_date, it.meeting_time].filter(Boolean).join(' ');

      return `
        <div class="item">
          <div class="item-top">
            <div>
              <div class="uid">${esc(uid)}</div>
              <div class="item-main">${esc(name)}</div>
            </div>
            <div class="badges">
              <span class="badge">${isQueue ? 'QUEUE' : 'PENDING'}</span>
            </div>
          </div>

          <div class="item-sub">${esc(email)}</div>
          <div class="item-sub">${esc(phone)}</div>
          <div class="item-sub">${esc(geo)}</div>
          <div class="item-sub">${esc(dt)}</div>
          <div class="item-sub">${esc(loc)} ${maps ? `· <a href="${esc(maps)}" target="_blank">Map</a>` : ''}</div>

          <div class="row2">
            <input class="inline-input pending-ace-mobile" data-booking-id="${esc(it.id)}" placeholder="Manual ACE mobile_e164">
            <button type="button" class="btn" data-act="accept" data-id="${esc(it.id)}" ${acceptBlocked ? 'disabled' : ''}>
              <span class="deal-i18n-en">Accept</span>
              <span class="deal-i18n-zh">接受</span>
            </button>
          </div>

          ${allFull ? `<div class="item-sub"><span class="bad">All ACE full → queue</span></div>` : ''}
        </div>
      `;
    }).join('');
  }

  function renderAccepted(items) {
    const rows = Array.isArray(items) ? items : [];
    if (!el.acceptedList) return;

    if (!rows.length) {
      el.acceptedList.innerHTML = `<div class="empty"><span class="deal-i18n-en">No accepted items.</span><span class="deal-i18n-zh">暂无已接受项目。</span></div>`;
      return;
    }

    el.acceptedList.innerHTML = rows.map((it) => {
      const uid = it.booking_uid || ('#' + it.booking_id);
      const name = it.customer_name || '';
      const email = it.customer_email || '';
      const cc = normalizePlus(it.calling_code);
      const phone = (cc || '') + (it.mobile_e164 || it.customer_mobile || '');
      const geo = [it.customer_country_name, it.state_name, it.area_name].filter(Boolean).join(' / ');
      const dt = [it.meeting_date, it.meeting_time].filter(Boolean).join(' ');
      const loc = it.offline_address || it.meeting_location || '';
      const maps = it.maps_link || it.maps || '';
      const memo = it.meeting_note || it.memo || '';
      const ace = it.ace_wallet || '';

      const cal = it.cal || {};
      const meet = cal.google_meet_url || '';
      const voov = cal.voov_meeting_url || '';
      const gEvent = cal.google_event_url || '';

      const deal = it.deal || {};
      const dealUid = deal.deal_uid || '';
      const qrText = dealUid ? dealUid : uid;
      const qrSrc = qrSrcFor(qrText);

      return `
        <div class="item">
          <div class="item-top">
            <div>
              <div class="uid">${esc(uid)}</div>
              <div class="item-main">${esc(name)}</div>
            </div>
            <div class="badges">
              <span class="badge">ACCEPTED</span>
              ${dealUid ? `<span class="badge">${esc(dealUid)}</span>` : ''}
            </div>
          </div>

          <div class="item-sub">${esc(email)}</div>
          <div class="item-sub">${esc(phone)}</div>
          <div class="item-sub">${esc(geo)}</div>
          <div class="item-sub">${esc(dt)}</div>
          <div class="item-sub">ACE: ${esc(ace)}</div>
          <div class="item-sub">${esc(loc)} ${maps ? `· <a href="${esc(maps)}" target="_blank">Map</a>` : ''}</div>
          ${memo ? `<div class="item-sub">MEMO: ${esc(memo)}</div>` : ''}

          <div class="item-actions">
            ${meet ? `<a class="btn secondary" href="${esc(meet)}" target="_blank">Google Meet</a>` : ''}
            ${voov ? `<a class="btn secondary" href="${esc(voov)}" target="_blank">Voov</a>` : ''}
            ${gEvent ? `<a class="btn secondary" href="${esc(gEvent)}" target="_blank">Cal</a>` : ''}
            <button type="button" class="btn secondary" data-act="qr" data-qr-src="${esc(qrSrc)}" data-qr-title="${esc(dealUid || uid)}">QR</button>
          </div>

          <div class="row3">
            <input class="inline-input accepted-main" data-booking-id="${esc(it.booking_id)}" placeholder="Main amount EMX">
            <input class="inline-input accepted-tips" data-booking-id="${esc(it.booking_id)}" placeholder="Tips amount EMX">
            <button type="button" class="btn" data-act="create-deal" data-id="${esc(it.booking_id)}">
              <span class="deal-i18n-en">Create Deal</span>
              <span class="deal-i18n-zh">创建成交</span>
            </button>
          </div>

          <div class="row2">
            <input class="inline-input accepted-reassign" data-booking-id="${esc(it.booking_id)}" placeholder="Reassign ACE mobile_e164">
            <button type="button" class="btn secondary" data-act="reassign" data-id="${esc(it.booking_id)}">
              <span class="deal-i18n-en">Reassign</span>
              <span class="deal-i18n-zh">重分配</span>
            </button>
          </div>

          <div class="row2">
            <button type="button" class="btn danger" data-act="cancel" data-id="${esc(it.booking_id)}">
              <span class="deal-i18n-en">Cancel</span>
              <span class="deal-i18n-zh">取消</span>
            </button>
            <div></div>
          </div>
        </div>
      `;
    }).join('');
  }

  async function loadPending() {
    const q = String(el.pendingSearch?.value || '').trim();
    const url = ENDPOINTS.pending + '?q=' + encodeURIComponent(q);
    const j = await fetchJSON(url);
    if (!j || j.ok !== true) throw new Error(j?.error || 'LIST_PENDING_FAIL');
    renderPending(j.items || [], Number(j.all_ace_full) === 1);
  }

  async function loadAccepted() {
    const q = String(el.acceptedSearch?.value || '').trim();
    const url = ENDPOINTS.accepted + '?q=' + encodeURIComponent(q);
    const j = await fetchJSON(url);
    if (!j || j.ok !== true) throw new Error(j?.error || 'LIST_ACCEPTED_FAIL');
    renderAccepted(j.items || []);
  }

  async function postForm(url, data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k, v ?? ''));
    return fetchJSON(url, { method:'POST', body:fd });
  }

  async function doAccept(bookingId) {
    const input = document.querySelector(`.pending-ace-mobile[data-booking-id="${bookingId}"]`);
    const manual = String(input?.value || '').trim();

    setMsg(el.pendingMsg, 'Accepting...', 'warn');
    const j = await postForm(ENDPOINTS.accept, {
      csrf: getCsrf('accept'),
      booking_id: String(bookingId),
      manual_mobile_e164: manual
    });

    if (!j || j.ok !== true) {
      setMsg(el.pendingMsg, j?.message || j?.error || 'Accept failed', 'bad');
      return;
    }
    setMsg(el.pendingMsg, 'Accepted', 'ok');
    await Promise.allSettled([loadPending(), loadAccepted()]);
  }

  async function doCancel(bookingId) {
    const reason = prompt('Cancel reason / 取消原因', '') || '';
    setMsg(el.acceptedMsg, 'Cancelling...', 'warn');

    const j = await postForm(ENDPOINTS.cancel, {
      csrf: getCsrf('cancel'),
      booking_id: String(bookingId),
      reason
    });

    if (!j || j.ok !== true) {
      setMsg(el.acceptedMsg, j?.message || j?.error || 'Cancel failed', 'bad');
      return;
    }
    setMsg(el.acceptedMsg, 'Cancelled', 'ok');
    await Promise.allSettled([loadPending(), loadAccepted()]);
  }

  async function doReassign(bookingId) {
    const input = document.querySelector(`.accepted-reassign[data-booking-id="${bookingId}"]`);
    const target = String(input?.value || '').trim();
    if (!target) {
      setMsg(el.acceptedMsg, 'Target mobile_e164 required', 'bad');
      return;
    }

    setMsg(el.acceptedMsg, 'Reassigning...', 'warn');
    const j = await postForm(ENDPOINTS.reassign, {
      csrf: getCsrf('reassign'),
      booking_id: String(bookingId),
      target_mobile_e164: target
    });

    if (!j || j.ok !== true) {
      setMsg(el.acceptedMsg, j?.message || j?.error || 'Reassign failed', 'bad');
      return;
    }
    setMsg(el.acceptedMsg, 'Reassigned', 'ok');
    await Promise.allSettled([loadPending(), loadAccepted()]);
  }

  async function doCreateDeal(bookingId) {
    const mainInput = document.querySelector(`.accepted-main[data-booking-id="${bookingId}"]`);
    const tipsInput = document.querySelector(`.accepted-tips[data-booking-id="${bookingId}"]`);
    const main = String(mainInput?.value || '').trim();
    const tips = String(tipsInput?.value || '0').trim() || '0';

    if (!main) {
      setMsg(el.acceptedMsg, 'Main amount required', 'bad');
      return;
    }

    setMsg(el.acceptedMsg, 'Creating deal...', 'warn');
    const j = await postForm(ENDPOINTS.createDeal, {
      csrf: getCsrf('create'),
      booking_id: String(bookingId),
      main_amount: main,
      tips_amount: tips
    });

    if (!j || j.ok !== true) {
      setMsg(el.acceptedMsg, j?.message || j?.error || 'Create deal failed', 'bad');
      return;
    }
    setMsg(el.acceptedMsg, 'Deal created: ' + (j.deal_uid || 'OK'), 'ok');
    await loadAccepted();
  }

  function openQr(src, title) {
    if (el.qrModalTitle) el.qrModalTitle.textContent = title || 'QR';
    if (el.qrBox) el.qrBox.innerHTML = src ? `<img src="${esc(src)}" alt="QR">` : '';
    if (el.qrModalBack) el.qrModalBack.classList.add('show');
  }

  function wireEvents() {
    document.querySelectorAll('.lang-btn').forEach((btn) => {
      btn.addEventListener('click', () => setLang(btn.dataset.lang || 'en'));
    });

    el.tabPending?.addEventListener('click', () => switchTab('pending'));
    el.tabAccepted?.addEventListener('click', () => switchTab('accepted'));

    el.pendingSearchBtn?.addEventListener('click', loadPending);
    el.acceptedSearchBtn?.addEventListener('click', loadAccepted);

    el.pendingResetBtn?.addEventListener('click', () => {
      if (el.pendingSearch) el.pendingSearch.value = '';
      loadPending();
    });

    el.acceptedResetBtn?.addEventListener('click', () => {
      if (el.acceptedSearch) el.acceptedSearch.value = '';
      loadAccepted();
    });

    el.pendingSearch?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        loadPending();
      }
    });

    el.acceptedSearch?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        loadAccepted();
      }
    });

    document.addEventListener('click', (e) => {
      const btn = e.target.closest('button');
      if (!btn) return;
      const act = btn.dataset.act;
      if (!act) return;

      const id = Number(btn.dataset.id || 0);

      if (act === 'accept' && id) doAccept(id);
      if (act === 'cancel' && id) doCancel(id);
      if (act === 'reassign' && id) doReassign(id);
      if (act === 'create-deal' && id) doCreateDeal(id);
      if (act === 'qr') openQr(btn.dataset.qrSrc || '', btn.dataset.qrTitle || '');
    });

    el.qrCloseBtn?.addEventListener('click', () => {
      el.qrModalBack?.classList.remove('show');
    });

    el.qrModalBack?.addEventListener('click', (e) => {
      if (e.target === el.qrModalBack) {
        el.qrModalBack.classList.remove('show');
      }
    });

    window.addEventListener('resize', () => {
      const pendingActive = el.tabPending?.classList.contains('active');
      switchTab(pendingActive ? 'pending' : 'accepted');
    });
  }

  async function boot() {
    restoreLang();
    wireEvents();
    switchTab('pending');
    await Promise.allSettled([loadPending(), loadAccepted()]);
    setInterval(() => {
      loadPending().catch(() => {});
      loadAccepted().catch(() => {});
    }, 10000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once:true });
  } else {
    boot();
  }
})();
