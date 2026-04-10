(() => {
  const boot = window.RWA_MINING_BOOT || {};
  if (!boot.eligible) return;

  const API = {
    status: '/rwa/api/mining/status.php',
    start: '/rwa/api/mining/start.php',
    stop: '/rwa/api/mining/stop.php',
    tick: '/rwa/api/mining/tick.php',
    ledger: '/rwa/api/mining/ledger.php',
    heartbeat: '/rwa/api/mining/heartbeat.php',
  };

  const TICK_MS = 10000;
  const HEARTBEAT_MS = 10000;
  const STATUS_REFRESH_MS = 10000;
  const START_GRACE_MS = 3500;
  const LOOP_VOLUME = 0.28;
  const $ = (id) => document.getElementById(id);

  const UI = {
    rpmText: $('rpmText'),
    batteryFill: $('batteryFill'),
    batteryPct: $('batteryPct'),
    startBtn: $('startMiningBtn'),
    stopBtn: $('stopMiningBtn'),
    upgradeBtn: $('upgradeMinerBtn'),
    refreshBtn: $('refreshBtn'),
    extraBoostBtn: $('extraBoostBtn'),
    tierLabel: $('tierLabel'),
    multiplierText: $('multiplierText'),
    ratePerTick: $('ratePerTick'),
    dailyCap: $('dailyCap'),
    minedToday: $('minedToday'),
    remainingToday: $('remainingToday'),
    statusText: $('statusText'),
    err: $('miningError'),
    ledgerBox: $('ledgerBox'),
    nodeRewardInfo: $('nodeRewardInfo'),
    modal: $('boosterModal'),
    modalClose: $('boosterClose'),
    totalMined: $('totalMined'),
    storageUnclaimedWems: $('storageUnclaimedWems'),
    nonceBox: $('nonceBox'),
    nonceHash: $('nonceHash'),
    nonceStage: $('nonceStage'),
    nonceProgressText: $('nonceProgressText'),
    nonceProgressFill: $('nonceProgressFill'),
    nonceResult: $('nonceResult'),
    bindingQr: $('bindingQr'),
    bindingLink: $('bindingLink'),
    bindingListingBtn: $('bindingListingBtn'),
    langEn: $('lang-en'),
    langZh: $('lang-zh'),
  };

  const SFX = (() => {
    const make = (file) => {
      const a = new Audio('/rwa/assets/sfx/' + file);
      a.preload = 'auto';
      return a;
    };

    const api = {
      enabled: true,
      unlocked: false,
      start: make('mining_start.mp3'),
      stop: make('mining_done.mp3'),
      hash: make('success.mp3'),
      click: make('click.mp3'),
      error: make('error.mp3'),
      loop: make('mining_loop.mp3'),
      loopStarted: false,
      loopFader: null,

      syncEnabled() {
        try {
          if (typeof window.RWA_SFX_ENABLED !== 'undefined') {
            this.enabled = !!window.RWA_SFX_ENABLED;
            return;
          }
          const saved = localStorage.getItem('rwa_sfx_enabled');
          if (saved !== null) this.enabled = (saved === '1' || saved === 'true');
        } catch {}
      },

      unlock() {
        this.unlocked = true;
        this.syncEnabled();
      },

      play(name) {
        try {
          this.syncEnabled();
          if (!this.enabled || !this.unlocked) return;
          const a = this[name];
          if (!a) return;
          a.pause();
          a.currentTime = 0;
          void a.play().catch(() => {});
        } catch {}
      },

      clearFade() {
        if (this.loopFader) {
          clearInterval(this.loopFader);
          this.loopFader = null;
        }
      },

      startLoop(volume = LOOP_VOLUME) {
        try {
          this.syncEnabled();
          if (!this.enabled || !this.unlocked) return;

          this.clearFade();

          const a = this.loop;
          a.loop = true;

          if (!this.loopStarted) {
            a.volume = 0;
            a.currentTime = 0;
            void a.play().catch(() => {});
            this.loopStarted = true;
          }

          this.loopFader = setInterval(() => {
            const next = Math.min(volume, Number(a.volume || 0) + 0.04);
            a.volume = next;
            if (next >= volume) this.clearFade();
          }, 80);
        } catch {}
      },

      stopLoop() {
        try {
          const a = this.loop;
          this.clearFade();

          if (!this.loopStarted) {
            a.pause();
            a.currentTime = 0;
            a.volume = 0;
            return;
          }

          this.loopFader = setInterval(() => {
            const next = Math.max(0, Number(a.volume || 0) - 0.05);
            a.volume = next;
            if (next <= 0) {
              this.clearFade();
              a.pause();
              a.currentTime = 0;
              this.loopStarted = false;
            }
          }, 70);
        } catch {}
      }
    };

    return api;
  })();

  function bindSfxUnlock() {
    const unlock = () => {
      SFX.unlock();
      document.removeEventListener('click', unlock, true);
      document.removeEventListener('touchstart', unlock, true);
      document.removeEventListener('keydown', unlock, true);
    };
    document.addEventListener('click', unlock, true);
    document.addEventListener('touchstart', unlock, true);
    document.addEventListener('keydown', unlock, true);
  }

  const dict = {
    en: {
      title: 'RWA Mining Engine',
      subtitle: 'wEMS tick mining · 10s loop · off-chain ledger · storage-linked unclaimed Web Gold',
      locked_title: 'MINING ACCESS LOCKED',
      locked_sub: 'Profile gate enforced',
      profile_status: 'PROFILE STATUS',
      wallet_status: 'TON WALLET STATUS',
      eligibility: 'MINING ELIGIBILITY',
      go_profile: 'GO TO PROFILE',
      engine_title: 'ROUND RPM MINING ENGINE',
      miner_tier: 'Miner Tier',
      multiplier: 'Multiplier',
      boosted_rate: 'Boosted Rate',
      daily_cap: 'Daily Cap',
      battery_label: 'Battery (fills every 10s tick)',
      mined_today: 'Mined Today',
      remaining: 'Remaining',
      start_mining: 'START MINING',
      stop: 'STOP',
      upgrade_miner: 'UPGRADE MINER',
      refresh: 'REFRESH',
      extra_boost: 'EXTRA BOOST WITH EMA$',
      binding_title: 'BINDING YOUR MINER',
      binding_link: 'Binding Link',
      view_binding: 'VIEW BINDING LISTING',
      economics: 'ECONOMICS',
      total_gold: 'MY TOTAL GOLD MINED',
      nonce_title: 'Mining Nonce Search',
      nonce_hash: 'LIVE NONCE HASH',
      search_progress: 'SEARCH PROGRESS',
      nonce_state: 'NONCE STATE',
      unclaimed_title: 'MY UNCLAIMED WEB GOLD WEMS',
      unclaimed_desc: 'Mined wEMS is linked to the Storage module ledger as My Unclaimed Web Gold wEMS. Claiming or settlement will reduce this unclaimed amount.',
      open_storage: 'OPEN STORAGE LEDGER',
      node_reward: 'Node Reward Info',
      ledger_title: 'Rewards Ledger (Last 50)',
      right_note: 'Binding Commission: 1% of bound adoptee mining rewards. Extra reward, not counted toward own daily mining cap. Claim Rule: Mining is off-chain. On-chain is claim only and KYC is required for withdrawal.',
      booster_modal: 'UPGRADE MINER',
      booster_desc: 'Miner upgrade tier is determined by on-chain EMA$ only. Off-chain EMA balances are not valid for miner upgrade.'
    },
    zh: {
      title: 'RWA 挖矿引擎',
      subtitle: 'wEMS 每10秒挖矿 · 链下账本 · 已连接到 Storage 未领取网金',
      locked_title: '挖矿权限已锁定',
      locked_sub: '需要通过资料与钱包验证',
      profile_status: '资料状态',
      wallet_status: 'TON 钱包状态',
      eligibility: '挖矿资格',
      go_profile: '前往个人资料',
      engine_title: '圆形 RPM 挖矿引擎',
      miner_tier: '矿工等级',
      multiplier: '倍数',
      boosted_rate: '当前速率',
      daily_cap: '每日上限',
      battery_label: '电池进度（每10秒完成一次）',
      mined_today: '今日已挖',
      remaining: '今日剩余',
      start_mining: '开始挖矿',
      stop: '停止',
      upgrade_miner: '升级矿工',
      refresh: '刷新',
      extra_boost: 'EMA$ 额外加速',
      binding_title: '绑定你的矿工',
      binding_link: '绑定链接',
      view_binding: '查看绑定列表',
      economics: '经济数据',
      total_gold: '我的总挖矿网金',
      nonce_title: '挖矿随机数搜索',
      nonce_hash: '实时随机哈希',
      search_progress: '搜索进度',
      nonce_state: '随机数状态',
      unclaimed_title: '我未领取的网金 wEMS',
      unclaimed_desc: '已挖到的 wEMS 已连接到 Storage 模块账本，显示为未领取网金。领取或结算后会减少该数量。',
      open_storage: '打开 Storage 账本',
      node_reward: '节点奖励信息',
      ledger_title: '奖励账本（最近50条）',
      right_note: '绑定佣金：来自被绑定矿工挖矿收益的 1%，且不计入你自己的每日挖矿上限。领取规则：挖矿是链下，链上仅用于领取，并需要 KYC。',
      booster_modal: '升级矿工',
      booster_desc: '矿工升级等级只根据链上 EMA$ 计算，链下 EMA 余额不计入矿工升级。'
    }
  };

  let currentLang = 'en';
  function applyLang(lang) {
    currentLang = lang;
    document.querySelectorAll('[data-i18n]').forEach((el) => {
      const key = el.getAttribute('data-i18n');
      if (dict[lang] && dict[lang][key]) el.textContent = dict[lang][key];
    });
    UI.langEn?.classList.toggle('active', lang === 'en');
    UI.langZh?.classList.toggle('active', lang === 'zh');
    try { localStorage.setItem('rwa_mining_lang', lang); } catch {}
  }
  UI.langEn?.addEventListener('click', () => { SFX.play('click'); applyLang('en'); });
  UI.langZh?.addEventListener('click', () => { SFX.play('click'); applyLang('zh'); });
  try {
    const saved = localStorage.getItem('rwa_mining_lang');
    applyLang(saved === 'zh' ? 'zh' : 'en');
  } catch {
    applyLang('en');
  }

  let st = {
    isMining: false,
    multiplier: 1,
    cap: 0,
    minedToday: 0,
    ratePerTick: 0.33,
    batteryPct: 0,
    nodeRewardPct: 0,
    totalMined: Number(boot.totalMined || 0),
    storageUnclaimed: Number(boot.totalUnclaimedWebGold || 0),
  };

  let stable = {
    cap: null,
    minedToday: null,
    remaining: null,
  };

  let last = performance.now();
  let tickGuard = false;
  let nonceSeed = Date.now();
  let nonceLastFoundAt = 0;
  let heartbeatTimer = null;
  let statusTimer = null;
  let startGraceUntil = 0;

  function showErr(msg) {
    if (!UI.err) return;
    UI.err.style.display = msg ? 'block' : 'none';
    UI.err.textContent = msg || '';
  }

  async function fetchJSON(url, opts = {}) {
    const res = await fetch(url, { credentials: 'include', cache: 'no-store', ...opts });
    const txt = await res.text();
    if (txt.trim().startsWith('<')) throw new Error('SERVER_RETURNED_HTML');
    let j;
    try { j = JSON.parse(txt); } catch { throw new Error('INVALID_JSON'); }
    return j;
  }

  function fmt(n, dp = 8) {
    n = Number(n || 0);
    return n.toFixed(dp).replace(/0+$/,'').replace(/\.$/, '');
  }

  function randHex(len) {
    const chars = '0123456789abcdef';
    let out = '';
    for (let i = 0; i < len; i++) out += chars[Math.floor(Math.random() * chars.length)];
    return out;
  }

  function seededHex(len, seed) {
    const chars = '0123456789abcdef';
    let x = Math.floor(seed) || 1;
    let out = '';
    for (let i = 0; i < len; i++) {
      x ^= x << 13; x ^= x >> 17; x ^= x << 5;
      out += chars[Math.abs(x) % chars.length];
    }
    return out;
  }

  function renderNonce(found = false) {
    if (!UI.nonceHash || !UI.nonceStage || !UI.nonceProgressText || !UI.nonceProgressFill || !UI.nonceResult) return;

    const pct = Math.max(0, Math.min(100, st.batteryPct || 0));
    UI.nonceProgressText.textContent = Math.round(pct) + '%';
    UI.nonceProgressFill.style.width = pct + '%';

    if (!st.isMining) {
      UI.nonceStage.textContent = 'STANDBY';
      UI.nonceStage.classList.remove('is-found');
      UI.nonceResult.textContent = 'WAITING';
      UI.nonceHash.textContent = '0x00000000000000000000000000000000';
      UI.nonceBox?.classList.remove('nonceFlash');
      return;
    }

    if (found) {
      UI.nonceHash.textContent = '0x' + seededHex(32, nonceSeed + Date.now());
      UI.nonceStage.textContent = 'HASH FOUND';
      UI.nonceStage.classList.add('is-found');
      UI.nonceResult.textContent = 'VALID';
      nonceLastFoundAt = Date.now();
      SFX.play('hash');
      if (UI.nonceBox) {
        UI.nonceBox.classList.remove('nonceFlash');
        void UI.nonceBox.offsetWidth;
        UI.nonceBox.classList.add('nonceFlash');
      }
      return;
    }

    if (Date.now() - nonceLastFoundAt < 700) return;

    const speedFactor = pct < 85 ? 1 : 0.45;
    nonceSeed += (11 + Math.floor((100 - pct) * speedFactor));

    if (pct >= 96) {
      UI.nonceHash.textContent = '0x' + seededHex(20, nonceSeed + Math.floor(pct)) + seededHex(12, nonceSeed + 9999);
      UI.nonceStage.textContent = 'LOCKING TARGET';
      UI.nonceResult.textContent = 'SEARCHING';
    } else {
      UI.nonceHash.textContent = '0x' + randHex(32);
      UI.nonceStage.textContent = 'SEARCHING';
      UI.nonceResult.textContent = pct >= 70 ? 'NARROWING' : 'SEARCHING';
    }
    UI.nonceStage.classList.remove('is-found');
  }

  function setBattery(p) {
    p = Math.max(0, Math.min(100, p));
    st.batteryPct = p;
    if (UI.batteryFill) UI.batteryFill.style.width = p + '%';
    if (UI.batteryPct) UI.batteryPct.textContent = Math.round(p) + '%';
    renderNonce(false);
  }

  function setRPM() {
    const base = 120;
    const mul = Math.min(30, Math.max(1, Number(st.multiplier || 1)));
    const ramp = (st.batteryPct / 100) * (220 + mul * 60);
    const rpm = Math.round(base + mul * 110 + ramp * 10);
    if (UI.rpmText) UI.rpmText.textContent = String(rpm);
  }

  function renderTotalsFromStatus(s) {
    if (typeof s.total_mined_wems !== 'undefined') {
      st.totalMined = Number(s.total_mined_wems || 0);
      if (UI.totalMined) UI.totalMined.textContent = fmt(st.totalMined, 8);
    }
    if (typeof s.storage_unclaimed_wems !== 'undefined') {
      st.storageUnclaimed = Number(s.storage_unclaimed_wems || 0);
      if (UI.storageUnclaimedWems) UI.storageUnclaimedWems.textContent = fmt(st.storageUnclaimed, 8) + ' wEMS';
    }
  }

  function syncLoopSound() {
    if (st.isMining) SFX.startLoop(LOOP_VOLUME);
    else SFX.stopLoop();
  }

  function setRunningUi(running) {
    st.isMining = !!running;
    if (UI.statusText) UI.statusText.textContent = 'Status: ' + (running ? 'RUNNING' : 'STOPPED');
    if (UI.startBtn) UI.startBtn.disabled = running || ((stable.cap ?? st.cap) > 0 && (stable.minedToday ?? st.minedToday) >= (stable.cap ?? st.cap));
    if (UI.stopBtn) UI.stopBtn.disabled = !running;
    syncLoopSound();
  }

  function updateStableMetrics(s) {
    const rawCap = Number(s.daily_cap_wems ?? s.daily_cap ?? NaN);
    const rawMined = Number(s.daily_mined_wems ?? s.mined_today ?? NaN);

    if (Number.isFinite(rawCap)) {
      if (stable.cap === null) {
        stable.cap = rawCap;
      } else if (rawCap > 0 || stable.cap === 0) {
        stable.cap = Math.max(stable.cap, rawCap);
      }
    }

    if (Number.isFinite(rawMined)) {
      if (stable.minedToday === null) {
        stable.minedToday = Math.max(0, rawMined);
      } else if (rawMined > 0) {
        stable.minedToday = Math.max(stable.minedToday, rawMined);
      } else if (stable.minedToday === 0 && !st.isMining) {
        stable.minedToday = 0;
      }
    }

    const capVal = stable.cap ?? 0;
    const minedVal = stable.minedToday ?? 0;
    stable.remaining = capVal > 0 ? Math.max(0, capVal - minedVal) : 0;
  }

  function renderStableMetrics() {
    const capVal = stable.cap ?? st.cap ?? 0;
    const minedVal = stable.minedToday ?? st.minedToday ?? 0;
    const remainingVal = stable.remaining ?? (capVal > 0 ? Math.max(0, capVal - minedVal) : 0);

    if (UI.dailyCap) UI.dailyCap.textContent = fmt(capVal, 8);
    if (UI.minedToday) UI.minedToday.textContent = fmt(minedVal, 8);
    if (UI.remainingToday) UI.remainingToday.textContent = fmt(remainingVal, 8);
  }

  function renderStatus(s) {
    st.isMining = !!Number(s.is_mining || s.running || 0);
    st.multiplier = Number(s.multiplier || 1);
    st.cap = Number(s.daily_cap_wems || s.daily_cap || 0);
    st.minedToday = Number(s.daily_mined_wems || s.mined_today || 0);
    st.ratePerTick = Number(s.rate_wems_per_tick || 0.33);
    st.nodeRewardPct = Number(s.node_reward_pct || 0);

    setBattery(Number(s.battery_pct || 0));

    if (UI.tierLabel) UI.tierLabel.textContent = String((s.tier || '—')).toUpperCase();
    if (UI.multiplierText) UI.multiplierText.textContent = 'x' + fmt(st.multiplier, 2);
    if (UI.ratePerTick) UI.ratePerTick.textContent = fmt(st.ratePerTick, 8);

    updateStableMetrics(s);
    renderStableMetrics();

    if (UI.nodeRewardInfo) {
      UI.nodeRewardInfo.textContent = st.nodeRewardPct > 0
        ? `Node Reward Pool: ${fmt(st.nodeRewardPct, 1)}%`
        : 'Nodes Miner: 0.5% Global Node Reward · Super Node: 3%';
    }

    setRunningUi(st.isMining);
    renderTotalsFromStatus(s);
    setRPM();
    renderNonce(false);
  }

  async function refreshStatus() {
    try {
      const s = await fetchJSON(API.status);
      if (!s.ok) throw new Error(s.message || s.error || 'STATUS_FAIL');
      showErr('');
      renderStatus(s);
      return s;
    } catch (e) {
      if (Date.now() > startGraceUntil) {
        if (UI.statusText) UI.statusText.textContent = 'Status: OFFLINE';
      }
      showErr(`STATUS: ${String(e.message || e)} (open ${API.status})`);
      return null;
    }
  }

  async function refreshLedger() {
    if (!UI.ledgerBox) return;
    try {
      const r = await fetchJSON(API.ledger);
      if (!r.ok) throw new Error(r.message || r.error || 'LEDGER_FAIL');
      const count = Array.isArray(r.rows) ? r.rows.length : 0;
      const storageHint = typeof r.storage_unclaimed_wems !== 'undefined'
        ? ` · Storage Unclaimed: ${fmt(r.storage_unclaimed_wems, 8)}`
        : '';
      UI.ledgerBox.textContent = count ? `OK · ${count} rows${storageHint}` : 'No mining records yet.';
      if (typeof r.storage_unclaimed_wems !== 'undefined' && UI.storageUnclaimedWems) {
        UI.storageUnclaimedWems.textContent = fmt(r.storage_unclaimed_wems, 8) + ' wEMS';
      }
    } catch (e) {
      UI.ledgerBox.textContent = 'Ledger unavailable: ' + String(e.message || e);
    }
  }

  async function sendHeartbeat() {
    if (!st.isMining) return;
    try {
      const r = await fetchJSON(API.heartbeat, { method: 'POST' });
      if (!r.ok) throw new Error(r.message || r.error || 'HEARTBEAT_FAIL');
      if (typeof r.is_mining !== 'undefined' || typeof r.running !== 'undefined') {
        renderStatus(r);
      }
    } catch (e) {
      if (Date.now() > startGraceUntil) {
        showErr(`HEARTBEAT: ${String(e.message || e)} (open ${API.heartbeat})`);
      }
    }
  }

  function startLoops() {
    stopLoops();
    heartbeatTimer = setInterval(() => { sendHeartbeat(); }, HEARTBEAT_MS);
    statusTimer = setInterval(() => {
      refreshStatus();
      refreshLedger();
    }, STATUS_REFRESH_MS);
  }

  function stopLoops() {
    if (heartbeatTimer) clearInterval(heartbeatTimer);
    if (statusTimer) clearInterval(statusTimer);
    heartbeatTimer = null;
    statusTimer = null;
  }

  function refreshBindingCard() {
    const bindCode = 'BND-' + String(Number(boot.userId || 0)).padStart(4, '0');
    const bindLink = 'https://adoptgold.app/rwa/register?bind=' + encodeURIComponent(bindCode);
    if (UI.bindingQr) UI.bindingQr.textContent = bindCode;
    if (UI.bindingLink) UI.bindingLink.textContent = bindLink;
  }

  async function doStart() {
    showErr('');
    startGraceUntil = Date.now() + START_GRACE_MS;
    setRunningUi(true);
    setBattery(0);
    setRPM();
    renderNonce(false);
    if (UI.statusText) UI.statusText.textContent = 'Status: STARTING…';

    try {
      const r = await fetchJSON(API.start, { method:'POST' });
      if (!r.ok) throw new Error(r.message || r.error || 'START_FAIL');

      renderStatus(r);
      setRunningUi(true);
      startLoops();
      SFX.play('start');
      SFX.startLoop(LOOP_VOLUME);

      setTimeout(async () => {
        await refreshStatus();
        await sendHeartbeat();
      }, 400);
    } catch (e) {
      setRunningUi(false);
      stopLoops();
      if (UI.statusText) UI.statusText.textContent = 'Status: OFFLINE';
      setBattery(0);
      showErr(`START: ${String(e.message || e)} (open ${API.start})`);
      renderNonce(false);
      SFX.stopLoop();
      SFX.play('error');
    }
  }

  async function doStop() {
    showErr('');
    try {
      const r = await fetchJSON(API.stop, { method:'POST' });
      if (!r.ok) throw new Error(r.message || r.error || 'STOP_FAIL');
      renderStatus(r);
      setRunningUi(false);
      stopLoops();
      setBattery(0);
      renderNonce(false);
      SFX.stopLoop();
      SFX.play('stop');
    } catch (e) {
      showErr(`STOP: ${String(e.message || e)} (open ${API.stop})`);
      setRunningUi(false);
      stopLoops();
      setBattery(0);
      renderNonce(false);
      SFX.stopLoop();
      SFX.play('error');
    }
  }

  async function doTickIfReady() {
    if (!st.isMining || tickGuard || st.batteryPct < 100) return;
    tickGuard = true;

    try {
      renderNonce(true);
      const r = await fetchJSON(API.tick, { method:'POST' });
      if (!r.ok) throw new Error(r.message || r.error || 'TICK_FAIL');
      setBattery(0);
      renderStatus(r);
      await refreshLedger();
      await sendHeartbeat();
    } catch (e) {
      showErr(`TICK: ${String(e.message || e)} (open ${API.tick})`);
      if (Date.now() > startGraceUntil) {
        setRunningUi(false);
        stopLoops();
        if (UI.statusText) UI.statusText.textContent = 'Status: OFFLINE';
      }
      setBattery(0);
      renderNonce(false);
      SFX.stopLoop();
      SFX.play('error');
    } finally {
      tickGuard = false;
    }
  }

  function anim(now) {
    const dt = now - last;
    last = now;

    if (st.isMining) {
      const pctPerMs = 100 / TICK_MS;
      setBattery(Math.min(100, st.batteryPct + dt * pctPerMs));
    } else {
      if (st.batteryPct !== 0) setBattery(0);
      renderNonce(false);
    }

    setRPM();
    doTickIfReady();
    requestAnimationFrame(anim);
  }

  function openModal() {
    if (UI.modal) UI.modal.classList.add('show');
  }

  function closeModal() {
    if (UI.modal) UI.modal.classList.remove('show');
  }

  document.addEventListener('DOMContentLoaded', async () => {
    bindSfxUnlock();

    UI.startBtn?.addEventListener('click', (e) => {
      e.preventDefault();
      SFX.play('click');
      doStart();
    });

    UI.stopBtn?.addEventListener('click', (e) => {
      e.preventDefault();
      SFX.play('click');
      doStop();
    });

    UI.refreshBtn?.addEventListener('click', async (e) => {
      e.preventDefault();
      SFX.play('click');
      await refreshStatus();
      await refreshLedger();
      refreshBindingCard();
      if (st.isMining) startLoops();
    });

    UI.upgradeBtn?.addEventListener('click', (e) => {
      e.preventDefault();
      SFX.play('click');
      openModal();
    });

    UI.modalClose?.addEventListener('click', (e) => {
      e.preventDefault();
      SFX.play('click');
      closeModal();
    });

    UI.modal?.addEventListener('click', (e) => {
      if (e.target === UI.modal) {
        SFX.play('click');
        closeModal();
      }
    });

    UI.bindingListingBtn?.addEventListener('click', () => {
      SFX.play('click');
      location.href = '/rwa/mining/binding-dashboard.php';
    });

    UI.extraBoostBtn?.addEventListener('click', () => {
      SFX.play('click');
      location.href = '/rwa/mining/extra-boost.php';
    });

    refreshBindingCard();
    const s = await refreshStatus();
    await refreshLedger();
    if (s && (Number(s.is_mining || s.running || 0) === 1)) {
      setRunningUi(true);
      startLoops();
      SFX.startLoop(LOOP_VOLUME);
    }
    renderNonce(false);
    requestAnimationFrame((t) => {
      last = t;
      anim(t);
    });
  });
})();
