(() => {
  'use strict';

  const ENDPOINTS = {
    packages: '/rwa/api/book/packages.php',
    geo: '/rwa/api/geo/geo.php',
    create: '/rwa/api/book/create.php',
    list: '/rwa/api/book/list.php'
  };

  const I18N = {
    en: {
      ready: 'Ready',
      search_placeholder: 'Search Booking No / Name / Email / Mobile',
      loading: 'Loading...',
      no_rows: 'No booking rows found.',
      load_failed: 'Failed to load booking list.',
      creating: 'Creating booking...',
      create_failed: 'Create failed',
      booking_created: 'Booking created: ',
      customer_name_required: 'Customer Name required',
      email_invalid: 'Email invalid',
      mobile_max: 'Mobile max 15 digits',
      date_invalid: 'Date must be DD/MM/YYYY',
      time_invalid: 'Time must be 10:00–22:00',
      map_location_required: 'Meeting Location required for map search',
      optional: 'Optional',
      malaysia: 'Malaysia',
      default_package: 'RK92-EMA',
      map: 'Map'
    },
    zh: {
      ready: '就绪',
      search_placeholder: '搜索预约编号 / 姓名 / 电邮 / 手机',
      loading: '载入中...',
      no_rows: '未找到预约记录。',
      load_failed: '载入预约列表失败。',
      creating: '创建预约中...',
      create_failed: '创建失败',
      booking_created: '预约已创建：',
      customer_name_required: '必须填写客户姓名',
      email_invalid: '电邮格式无效',
      mobile_max: '手机号码最多 15 位数字',
      date_invalid: '日期格式必须为 DD/MM/YYYY',
      time_invalid: '时间必须在 10:00–22:00',
      map_location_required: '地图搜索必须先填写会面地点',
      optional: '可选',
      malaysia: '马来西亚',
      default_package: 'RK92-EMA',
      map: '地图'
    }
  };

  const $ = (id) => document.getElementById(id);

  const el = {
    q: $('q'),
    btnSearch: $('btnSearch'),
    btnReset: $('btnReset'),
    btnPrint: $('btnPrint'),

    csrf: $('csrf'),
    action: $('action'),
    msg: $('msg'),

    package_key: $('package_key'),
    customer_name: $('customer_name'),
    customer_email: $('customer_email'),
    country: $('country'),
    prefix: $('prefix'),
    mobile: $('mobile'),
    state: $('state'),
    area: $('area'),
    meeting_date: $('meeting_date'),
    meeting_time: $('meeting_time'),
    meeting_location: $('meeting_location'),
    mapSearchBtn: $('mapSearchBtn'),
    btnNowUTC: $('btnNowUTC'),
    maps_link: $('maps_link'),
    meeting_note: $('meeting_note'),
    ace_mobile: $('ace_mobile'),
    btnSubmit: $('btnSubmit'),
    list: $('list'),
    phonePrefix: $('phonePrefix'),
    phoneFlagImg: $('phoneFlagImg'),
    phoneFlagBoxImg: $('phoneFlagBoxImg')
  };

  let GEO = {
    countries: [],
    prefixes: [],
    states: [],
    areas: []
  };

  function langNow() {
    return document.body.classList.contains('book-lang-zh') ? 'zh' : 'en';
  }

  function t(key) {
    const lang = langNow();
    return (I18N[lang] && I18N[lang][key]) || (I18N.en && I18N.en[key]) || key;
  }

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
    document.body.classList.remove('book-lang-en', 'book-lang-zh');
    document.body.classList.add(safe === 'zh' ? 'book-lang-zh' : 'book-lang-en');
    document.querySelectorAll('.lang-btn').forEach((btn) => {
      btn.classList.toggle('active', btn.dataset.lang === safe);
    });
    try { localStorage.setItem('rwa_book_lang', safe); } catch(e) {}
    applyPlaceholders();
    refreshOptionalLabels();
    syncStatusText();
    refreshRenderedListLanguageHints();
  }

  function restoreLang() {
    try {
      const saved = localStorage.getItem('rwa_book_lang');
      if (saved === 'zh' || saved === 'en') {
        setLang(saved);
        return;
      }
    } catch(e) {}
    setLang('en');
  }

  function setMsg(msg, type = 'normal') {
    if (!el.msg) return;
    const cls = type === 'ok' ? 'ok' : (type === 'bad' ? 'bad' : (type === 'warn' ? 'warn' : ''));
    el.msg.innerHTML = cls ? `<span class="${cls}">${esc(msg)}</span>` : esc(msg);
  }

  function syncStatusText() {
    if (!el.msg) return;
    const txt = (el.msg.textContent || '').trim();
    if (!txt || txt === 'Ready' || txt === '就绪') {
      setMsg(t('ready'));
    }
  }

  function applyPlaceholders() {
    if (el.q) el.q.placeholder = t('search_placeholder');
    if (el.customer_name) el.customer_name.placeholder = langNow() === 'zh' ? '输入客户姓名' : 'Enter customer name';
    if (el.customer_email) el.customer_email.placeholder = langNow() === 'zh' ? '输入电邮' : 'Enter email';
    if (el.mobile) el.mobile.placeholder = langNow() === 'zh' ? '输入手机号码' : 'Enter mobile number';
    if (el.meeting_date) el.meeting_date.placeholder = 'DD/MM/YYYY';
    if (el.meeting_location) el.meeting_location.placeholder = langNow() === 'zh' ? '输入会面地点' : 'Enter meeting location';
    if (el.meeting_note) el.meeting_note.placeholder = langNow() === 'zh' ? '输入会面备注' : 'Enter meeting memo';
    if (el.ace_mobile) el.ace_mobile.placeholder = langNow() === 'zh' ? '输入 ACE mobile_e164' : 'Enter ACE mobile_e164';
  }

  function digitsOnly(v) {
    return String(v ?? '').replace(/\D+/g, '');
  }

  function isEmail(v) {
    v = String(v || '').trim();
    if (!v) return true;
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
  }

  function isDateDDMMYYYY(v) {
    return /^\d{2}\/\d{2}\/\d{4}$/.test(String(v || '').trim());
  }

  function isTimeWindow(v) {
    if (!/^\d{2}:\d{2}$/.test(String(v || ''))) return false;
    const [hh, mm] = String(v).split(':').map(Number);
    if (mm !== 0 && mm !== 30) return false;
    return hh >= 10 && hh <= 22;
  }

  function buildMeetingTimes() {
    if (!el.meeting_time) return;
    el.meeting_time.innerHTML = '';
    for (let h = 10; h <= 22; h++) {
      for (const m of [0, 30]) {
        if (h === 22 && m > 0) continue;
        const hh = String(h).padStart(2, '0');
        const mm = String(m).padStart(2, '0');
        const timeText = `${hh}:${mm}`;
        const opt = document.createElement('option');
        opt.value = timeText;
        opt.textContent = timeText;
        el.meeting_time.appendChild(opt);
      }
    }
  }

  function todayDDMMYYYY() {
    const d = new Date();
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const yyyy = d.getFullYear();
    return `${dd}/${mm}/${yyyy}`;
  }

  function updateMapLink() {
    const addr = String(el.meeting_location?.value || '').trim();
    if (el.maps_link) {
      el.maps_link.value = addr
        ? ('https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(addr))
        : '';
    }
  }

  function setPhoneVisualFromCountryIso2(iso2, prefixValue) {
    const safeIso2 = (String(iso2 || 'my').toLowerCase() || 'my');
    const safePrefix = String(prefixValue || '');
    if (el.phonePrefix) el.phonePrefix.textContent = safePrefix || '-';
    if (el.phoneFlagImg) {
      el.phoneFlagImg.src = `/rwa/assets/flags/${safeIso2}.png`;
      el.phoneFlagImg.onerror = () => { el.phoneFlagImg.src = '/rwa/assets/flags/my.png'; };
    }
    if (el.phoneFlagBoxImg) {
      el.phoneFlagBoxImg.src = `/rwa/assets/flags/${safeIso2}.png`;
      el.phoneFlagBoxImg.onerror = () => { el.phoneFlagBoxImg.src = '/rwa/assets/flags/my.png'; };
    }
  }

  function renderPackages(items) {
    const rows = Array.isArray(items) ? items : [];
    if (!el.package_key) return;
    el.package_key.innerHTML = '';

    if (!rows.length) {
      const opt = document.createElement('option');
      opt.value = I18N.en.default_package;
      opt.textContent = I18N.en.default_package;
      el.package_key.appendChild(opt);
      return;
    }

    let picked = false;
    rows.forEach((it) => {
      const key = String(it.package_key || it.code || it.value || '');
      const name = String(it.package_name || it.label || key || '');
      if (!key) return;
      const opt = document.createElement('option');
      opt.value = key;
      opt.textContent = `${key}${name && name !== key ? ' - ' + name : ''}`;
      if (!picked && (it.is_default == 1 || key === 'RK92-EMA')) {
        opt.selected = true;
        picked = true;
      }
      el.package_key.appendChild(opt);
    });

    if (!picked && el.package_key.options.length) {
      el.package_key.options[0].selected = true;
    }
  }

  function normalizeGeoPayload(j) {
    if (!j || typeof j !== 'object') return;
    GEO.countries = Array.isArray(j.countries) ? j.countries : [];
    GEO.prefixes  = Array.isArray(j.prefixes) ? j.prefixes : [];
    GEO.states    = Array.isArray(j.states) ? j.states : [];
    GEO.areas     = Array.isArray(j.areas) ? j.areas : [];
  }

  function renderCountries() {
    if (!el.country) return;
    el.country.innerHTML = '';
    const rows = GEO.countries || [];
    if (!rows.length) {
      const opt = document.createElement('option');
      opt.value = 'MY';
      opt.textContent = t('malaysia');
      opt.dataset.iso2 = 'my';
      opt.dataset.name = t('malaysia');
      el.country.appendChild(opt);
      return;
    }

    let defaultIndex = 0;
    rows.forEach((it, idx) => {
      const iso2 = String(it.iso2 || it.country_code || it.code || '').toLowerCase();
      const zhName = String(it.country_name_zh || it.zh || '');
      const enName = String(it.country_name || it.name || it.label || iso2.toUpperCase());
      const name = langNow() === 'zh' && zhName ? zhName : enName;

      const opt = document.createElement('option');
      opt.value = iso2.toUpperCase();
      opt.textContent = name;
      opt.dataset.iso2 = iso2;
      opt.dataset.name = name;
      opt.dataset.nameEn = enName;
      opt.dataset.nameZh = zhName || enName;
      el.country.appendChild(opt);
      if (iso2 === 'my') defaultIndex = idx;
    });
    el.country.selectedIndex = defaultIndex;
  }

  function renderPrefixes() {
    if (!el.prefix || !el.country) return;
    const selectedIso2 = String((el.country.options[el.country.selectedIndex]?.dataset.iso2 || 'my')).toLowerCase();
    const rows = GEO.prefixes || [];
    el.prefix.innerHTML = '';

    const filtered = rows.filter((it) => String(it.iso2 || it.country_iso2 || '').toLowerCase() === selectedIso2);
    const source = filtered.length ? filtered : rows;

    if (!source.length) {
      const opt = document.createElement('option');
      opt.value = '+60';
      opt.textContent = '+60';
      opt.dataset.iso2 = selectedIso2 || 'my';
      el.prefix.appendChild(opt);
      setPhoneVisualFromCountryIso2(selectedIso2 || 'my', '+60');
      return;
    }

    let defaultIdx = 0;
    source.forEach((it, idx) => {
      const prefix = '+' + String(it.calling_code || it.prefix || '').replace(/^\+/, '');
      const iso2 = String(it.iso2 || it.country_iso2 || selectedIso2).toLowerCase();
      if (!prefix || prefix === '+') return;
      const opt = document.createElement('option');
      opt.value = prefix;
      opt.textContent = prefix;
      opt.dataset.iso2 = iso2;
      el.prefix.appendChild(opt);
      if (prefix === '+60') defaultIdx = idx;
    });

    if (el.prefix.options.length) {
      el.prefix.selectedIndex = Math.min(defaultIdx, el.prefix.options.length - 1);
      const active = el.prefix.options[el.prefix.selectedIndex];
      setPhoneVisualFromCountryIso2(active.dataset.iso2 || selectedIso2 || 'my', active.value);
    }
  }

  function renderStates() {
    if (!el.state || !el.country) return;
    const selectedIso2 = String((el.country.options[el.country.selectedIndex]?.dataset.iso2 || '')).toLowerCase();
    const rows = (GEO.states || []).filter((it) => String(it.iso2 || it.country_iso2 || '').toLowerCase() === selectedIso2);

    el.state.innerHTML = '';
    const empty = document.createElement('option');
    empty.value = '';
    empty.textContent = t('optional');
    el.state.appendChild(empty);

    rows.forEach((it) => {
      const enName = String(it.state_name || it.name || it.label || it.code || '');
      const zhName = String(it.state_name_zh || it.zh || '');
      const opt = document.createElement('option');
      opt.value = String(it.state_code || it.code || it.id || enName);
      opt.textContent = langNow() === 'zh' && zhName ? zhName : enName;
      opt.dataset.stateName = opt.textContent;
      el.state.appendChild(opt);
    });
  }

  function renderAreas() {
    if (!el.area || !el.country) return;
    const selectedIso2 = String((el.country.options[el.country.selectedIndex]?.dataset.iso2 || '')).toLowerCase();
    const selectedState = String(el.state?.value || '');
    let rows = (GEO.areas || []).filter((it) => String(it.iso2 || it.country_iso2 || '').toLowerCase() === selectedIso2);

    if (selectedState !== '') {
      rows = rows.filter((it) => {
        const a = String(it.state_code || it.parent_code || it.parent_id || '');
        return a === selectedState;
      });
    }

    el.area.innerHTML = '';
    const empty = document.createElement('option');
    empty.value = '';
    empty.textContent = t('optional');
    el.area.appendChild(empty);

    rows.forEach((it) => {
      const enName = String(it.area_name || it.name || it.label || it.code || '');
      const zhName = String(it.area_name_zh || it.zh || '');
      const opt = document.createElement('option');
      opt.value = String(it.area_code || it.code || it.id || enName);
      opt.textContent = langNow() === 'zh' && zhName ? zhName : enName;
      el.area.appendChild(opt);
    });
  }

  function refreshOptionalLabels() {
    const selectedCountry = el.country?.value || '';
    const selectedPrefix = el.prefix?.value || '';
    const selectedState = el.state?.value || '';
    const selectedArea = el.area?.value || '';

    renderCountries();
    if (selectedCountry && el.country) {
      for (const opt of el.country.options) {
        if (opt.value === selectedCountry) {
          opt.selected = true;
          break;
        }
      }
    }

    renderPrefixes();
    if (selectedPrefix && el.prefix) {
      for (const opt of el.prefix.options) {
        if (opt.value === selectedPrefix) {
          opt.selected = true;
          break;
        }
      }
    }

    renderStates();
    if (selectedState && el.state) {
      for (const opt of el.state.options) {
        if (opt.value === selectedState) {
          opt.selected = true;
          break;
        }
      }
    }

    renderAreas();
    if (selectedArea && el.area) {
      for (const opt of el.area.options) {
        if (opt.value === selectedArea) {
          opt.selected = true;
          break;
        }
      }
    }

    syncPhoneVisual();
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

  async function loadPackages() {
    try {
      const j = await fetchJSON(ENDPOINTS.packages);
      renderPackages(j.items || j.rows || j.data || []);
    } catch (e) {
      renderPackages([]);
    }
  }

  async function loadGeo() {
    try {
      const j = await fetchJSON(ENDPOINTS.geo);
      normalizeGeoPayload(j);
      renderCountries();
      renderPrefixes();
      renderStates();
      renderAreas();
    } catch (e) {
      GEO = { countries: [], prefixes: [], states: [], areas: [] };
      renderCountries();
      renderPrefixes();
      renderStates();
      renderAreas();
    }
  }

  function bookingCard(it) {
    const uid = String(it.booking_uid || '-');
    const status = String(it.status || '-');
    const name = String(it.customer_name || '-');
    const email = String(it.customer_email || '-');
    const phone = String(it.customer_phone || '-');
    const pkg = String(it.package_key || it.rwa_package || '-');
    const date = String(it.meeting_date || '-');
    const time = String(it.meeting_time || '-');
    const note = String(it.meeting_note || '-');
    const maps = String(it.maps_link || '');
    const mapLabel = t('map');
    const mapBtn = maps ? `<a class="btn secondary" target="_blank" href="${esc(maps)}">${esc(mapLabel)}</a>` : '';

    return `
      <div class="booking-item">
        <div class="booking-top">
          <div>
            <div class="booking-uid">${esc(uid)}</div>
            <div class="booking-main">${esc(name)}</div>
          </div>
          <div class="booking-badges">
            <span class="badge">${esc(status)}</span>
            <span class="badge">${esc(pkg)}</span>
          </div>
        </div>
        <div class="booking-sub">${esc(email)}</div>
        <div class="booking-sub">${esc(phone)}</div>
        <div class="booking-sub">${esc(date)} ${esc(time)}</div>
        <div class="booking-sub">${esc(note)}</div>
        <div class="booking-actions">${mapBtn}</div>
      </div>
    `;
  }

  function refreshRenderedListLanguageHints() {
    if (!el.list) return;
    if (!el.list.children.length) return;
    const raw = (el.list.textContent || '').trim();
    if (raw === t('loading') || raw === t('no_rows') || raw === t('load_failed')) return;
  }

  async function loadList() {
    const q = String(el.q?.value || '').trim();
    let url = ENDPOINTS.list;
    if (q !== '') {
      url += (url.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(q);
    }

    if (el.list) {
      el.list.innerHTML = `<div class="empty">${esc(t('loading'))}</div>`;
    }

    try {
      const j = await fetchJSON(url);
      const rows = j.items || j.rows || j.data || [];
      if (!Array.isArray(rows) || !rows.length) {
        if (el.list) {
          el.list.innerHTML = `<div class="empty">${esc(t('no_rows'))}</div>`;
        }
        return;
      }
      if (el.list) {
        el.list.innerHTML = rows.map(bookingCard).join('');
      }
    } catch (e) {
      if (el.list) {
        el.list.innerHTML = `<div class="empty">${esc(t('load_failed'))}</div>`;
      }
    }
  }

  function syncPhoneVisual() {
    if (!el.prefix || !el.country) return;
    const active = el.prefix.options[el.prefix.selectedIndex];
    const iso2 = String(active?.dataset?.iso2 || el.country.options[el.country.selectedIndex]?.dataset?.iso2 || 'my').toLowerCase();
    const prefix = String(active?.value || '');
    setPhoneVisualFromCountryIso2(iso2, prefix);
  }

  function validateForm() {
    const name = String(el.customer_name?.value || '').trim();
    const email = String(el.customer_email?.value || '').trim();
    const mobile = digitsOnly(el.mobile?.value || '');
    const date = String(el.meeting_date?.value || '').trim();
    const time = String(el.meeting_time?.value || '');

    if (!name) return t('customer_name_required');
    if (!isEmail(email)) return t('email_invalid');
    if (mobile && mobile.length > 15) return t('mobile_max');
    if (!date || !isDateDDMMYYYY(date)) return t('date_invalid');
    if (!time || !isTimeWindow(time)) return t('time_invalid');
    return '';
  }

  async function submitCreate() {
    const err = validateForm();
    if (err) {
      setMsg(err, 'bad');
      return;
    }

    updateMapLink();

    const fd = new FormData();
    fd.append('action', el.action?.value || '');
    fd.append('csrf', el.csrf?.value || '');
    fd.append('package_key', el.package_key?.value || '');
    fd.append('customer_name', String(el.customer_name?.value || '').trim());
    fd.append('customer_email', String(el.customer_email?.value || '').trim());

    const countryOpt = el.country?.options[el.country.selectedIndex];
    const prefixOpt = el.prefix?.options[el.prefix.selectedIndex];
    const stateOpt = el.state?.options[el.state.selectedIndex];
    const areaOpt = el.area?.options[el.area.selectedIndex];

    fd.append('country', countryOpt?.value || '');
    fd.append('country_name', countryOpt?.dataset?.name || countryOpt?.textContent || '');
    fd.append('prefix', String(prefixOpt?.value || ''));
    fd.append('mobile', digitsOnly(el.mobile?.value || ''));
    fd.append('state', String(stateOpt?.dataset?.stateName || stateOpt?.textContent || ''));
    fd.append('area', String(areaOpt?.textContent || ''));
    fd.append('meeting_date', String(el.meeting_date?.value || '').trim());
    fd.append('meeting_time', String(el.meeting_time?.value || ''));
    fd.append('meeting_location', String(el.meeting_location?.value || '').trim());
    fd.append('maps_link', String(el.maps_link?.value || ''));
    fd.append('meeting_note', String(el.meeting_note?.value || '').trim());
    fd.append('manual_assign_ace', digitsOnly(el.ace_mobile?.value || ''));

    setMsg(t('creating'), 'warn');
    if (el.btnSubmit) el.btnSubmit.disabled = true;

    try {
      const j = await fetchJSON(ENDPOINTS.create, {
        method: 'POST',
        body: fd
      });

      if (!j || !j.ok) {
        setMsg(j?.error || t('create_failed'), 'bad');
        return;
      }

      setMsg(t('booking_created') + (j.booking_uid || 'OK'), 'ok');

      if (el.customer_name) el.customer_name.value = '';
      if (el.customer_email) el.customer_email.value = '';
      if (el.mobile) el.mobile.value = '';
      if (el.state) el.state.selectedIndex = 0;
      if (el.area) el.area.selectedIndex = 0;
      if (el.meeting_date) el.meeting_date.value = '';
      if (el.meeting_time) el.meeting_time.selectedIndex = 0;
      if (el.meeting_location) el.meeting_location.value = '';
      if (el.maps_link) el.maps_link.value = '';
      if (el.meeting_note) el.meeting_note.value = '';
      if (el.ace_mobile) el.ace_mobile.value = '';

      await loadList();
    } catch (e) {
      setMsg(t('create_failed'), 'bad');
    } finally {
      if (el.btnSubmit) el.btnSubmit.disabled = false;
    }
  }

  function doPrint() {
    window.print();
  }

  function wireEvents() {
    document.querySelectorAll('.lang-btn').forEach((btn) => {
      btn.addEventListener('click', () => setLang(btn.dataset.lang || 'en'));
    });

    el.country?.addEventListener('change', () => {
      renderPrefixes();
      renderStates();
      renderAreas();
      syncPhoneVisual();
    });

    el.prefix?.addEventListener('change', syncPhoneVisual);
    el.state?.addEventListener('change', renderAreas);

    el.mobile?.addEventListener('input', () => {
      el.mobile.value = digitsOnly(el.mobile.value).slice(0, 15);
    });

    el.ace_mobile?.addEventListener('input', () => {
      el.ace_mobile.value = digitsOnly(el.ace_mobile.value).slice(0, 20);
    });

    el.meeting_location?.addEventListener('input', updateMapLink);

    el.mapSearchBtn?.addEventListener('click', () => {
      updateMapLink();
      if (!el.maps_link?.value) {
        setMsg(t('map_location_required'), 'bad');
        return;
      }
      window.open(el.maps_link.value, '_blank', 'noopener');
    });

    el.btnNowUTC?.addEventListener('click', () => {
      if (el.meeting_date) el.meeting_date.value = todayDDMMYYYY();
    });

    el.btnSubmit?.addEventListener('click', submitCreate);
    el.btnSearch?.addEventListener('click', loadList);
    el.btnReset?.addEventListener('click', () => {
      if (el.q) el.q.value = '';
      loadList();
    });
    el.btnPrint?.addEventListener('click', doPrint);

    el.q?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        loadList();
      }
    });
  }

  async function boot() {
    restoreLang();
    applyPlaceholders();
    buildMeetingTimes();
    wireEvents();
    await loadPackages();
    await loadGeo();
    syncPhoneVisual();
    setMsg(t('ready'));
    await loadList();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once:true });
  } else {
    boot();
  }
})();
