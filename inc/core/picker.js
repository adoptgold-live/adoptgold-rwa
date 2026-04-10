/* /dashboard/inc/picker.js
 * POAdo Picker Engine — DEVICE TIME LOCK (FINAL)
 * - Rules source: /dashboard/book/api/picker-rules.php
 * - Device time is the ONLY truth for:
 *   - "today"
 *   - disabling past dates
 *   - disabling past times when date=today
 * - Locked IDs:
 *   #meeting_date (DD/MM/YYYY)
 *   #meeting_time (HH:MM)
 */

(function () {
  "use strict";

  const DEFAULT_RULES = {
    ids: { date: "meeting_date", time: "meeting_time" },
    date_format: "DD/MM/YYYY",
    disable_past_date: true,
    date_min: "today_device",
    time_min: "10:00",
    time_max: "22:00",
    time_step_min: 15,
    disable_past_time_if_today: true,
    highlight_next_slot: true,
    auto_select_next_slot: true,
    auto_scroll_to_slot: true,
    time_source: "client_device",
    tz_mode: "device_only",
  };

  function $(id) { return document.getElementById(id); }

  function parseDDMMYYYY(s) {
    const m = String(s || "").trim().match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    if (!m) return null;
    const dd = Number(m[1]), mm = Number(m[2]), yyyy = Number(m[3]);
    if (!dd || !mm || !yyyy) return null;
    const d = new Date(yyyy, mm - 1, dd);
    if (d.getFullYear() !== yyyy || d.getMonth() !== (mm - 1) || d.getDate() !== dd) return null;
    return d;
  }

  function fmtDDMMYYYY(d) {
    const dd = String(d.getDate()).padStart(2, "0");
    const mm = String(d.getMonth() + 1).padStart(2, "0");
    const yyyy = d.getFullYear();
    return `${dd}/${mm}/${yyyy}`;
  }

  function hhmmToMin(hhmm) {
    const m = String(hhmm || "").match(/^(\d{1,2}):(\d{2})$/);
    if (!m) return null;
    const h = Number(m[1]), mi = Number(m[2]);
    if (h < 0 || h > 23 || mi < 0 || mi > 59) return null;
    return h * 60 + mi;
  }

  function minToHHMM(mins) {
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return String(h).padStart(2, "0") + ":" + String(m).padStart(2, "0");
  }

  function ceilToStep(mins, step) {
    return Math.ceil(mins / step) * step;
  }

  function sameLocalDay(a, b) {
    return a.getFullYear() === b.getFullYear() &&
      a.getMonth() === b.getMonth() &&
      a.getDate() === b.getDate();
  }

  function buildSlots(minStr, maxStr, step) {
    const minM = hhmmToMin(minStr);
    const maxM = hhmmToMin(maxStr);
    const out = [];
    if (minM == null || maxM == null || !step || step < 1) return out;
    for (let t = minM; t <= maxM; t += step) out.push(minToHHMM(t));
    return out;
  }

  function injectCssOnce() {
    const id = "poado-picker-css";
    if (document.getElementById(id)) return;
    const s = document.createElement("style");
    s.id = id;
    s.textContent = `option.poado-next-slot{font-weight:900;}`;
    document.head.appendChild(s);
  }

  function applyRules(rules) {
    rules = Object.assign({}, DEFAULT_RULES, rules || {});
    rules.ids = Object.assign({}, DEFAULT_RULES.ids, (rules.ids || {}));

    const dateEl = $(rules.ids.date);
    const timeEl = $(rules.ids.time);

    const now = new Date(); // 🔒 DEVICE NOW

    // Date default + past-date block (device time)
    if (dateEl) {
      if (!String(dateEl.value || "").trim()) {
        dateEl.value = fmtDDMMYYYY(now);
      }

      let picked = parseDDMMYYYY(dateEl.value);
      if (!picked) {
        dateEl.value = fmtDDMMYYYY(now);
        picked = parseDDMMYYYY(dateEl.value);
      }

      if (rules.disable_past_date && picked) {
        const today0 = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const pick0 = new Date(picked.getFullYear(), picked.getMonth(), picked.getDate());
        if (pick0 < today0) {
          dateEl.value = fmtDDMMYYYY(today0);
        }
      }
    }

    if (!timeEl) return;

    // Time rebuild
    const step = parseInt(rules.time_step_min || 15, 10);
    const slots = buildSlots(rules.time_min || "10:00", rules.time_max || "22:00", step);
    const prev = String(timeEl.value || "").trim();

    // isToday (device local)
    let isToday = true;
    if (dateEl) {
      const picked = parseDDMMYYYY(dateEl.value);
      if (picked) isToday = sameLocalDay(picked, now);
    }

    let earliestAllowed = null;
    if (rules.disable_past_time_if_today && isToday) {
      earliestAllowed = ceilToStep(now.getHours() * 60 + now.getMinutes(), step);
    }

    timeEl.innerHTML = "";
    let nextAvail = null;

    for (const t of slots) {
      const opt = document.createElement("option");
      opt.value = t;
      opt.textContent = t;

      const tm = hhmmToMin(t);

      if (earliestAllowed != null && tm != null && tm < earliestAllowed) {
        opt.disabled = true;
      } else if (!nextAvail) {
        nextAvail = t;
      }
      timeEl.appendChild(opt);
    }

    // Highlight next slot
    if (rules.highlight_next_slot) {
      for (const o of timeEl.options) o.classList.remove("poado-next-slot");
      if (nextAvail) {
        const hit = Array.from(timeEl.options).find(o => o.value === nextAvail);
        if (hit) hit.classList.add("poado-next-slot");
      }
    }

    // Preserve previous selection if still valid; else clamp
    let finalVal = "";
    if (prev && slots.includes(prev)) {
      const prevOpt = Array.from(timeEl.options).find(o => o.value === prev);
      if (prevOpt && !prevOpt.disabled) finalVal = prev;
    }
    if (!finalVal && rules.auto_select_next_slot) finalVal = nextAvail || "";
    if (!finalVal) finalVal = slots[0] || "";
    timeEl.value = finalVal;

    // Best-effort scroll/focus
    if (rules.auto_scroll_to_slot) {
      try {
        timeEl.focus({ preventScroll: false });
        const idx = Array.from(timeEl.options).findIndex(o => o.value === finalVal);
        if (idx >= 0) timeEl.selectedIndex = idx;
      } catch (e) {}
    }

    try { timeEl.dispatchEvent(new Event("change", { bubbles: true })); } catch (e) {}
  }

  async function loadRules() {
    const url = "/dashboard/book/api/picker-rules.php";
    try {
      const r = await fetch(url, { credentials: "same-origin" });
      const j = await r.json();
      if (j && j.ok === true && j.data) return Object.assign({}, DEFAULT_RULES, j.data);
    } catch (e) {}
    return Object.assign({}, DEFAULT_RULES);
  }

  // prevent double init
  let _initPromise = null;

  async function init() {
    if (_initPromise) return _initPromise;
    _initPromise = (async () => {
      injectCssOnce();
      const rules = await loadRules();
      applyRules(rules);

      const dateEl = $(rules.ids.date || "meeting_date");
      if (dateEl) {
        dateEl.addEventListener("change", () => applyRules(rules));
        dateEl.addEventListener("input", () => applyRules(rules));
      }
      return rules;
    })();
    return _initPromise;
  }

  window.POADO_PICKER = { init };

  // ✅ Auto-init when picker fields exist (booking form / any module)
  function autoInitIfFieldsPresent() {
    const d = document.getElementById(DEFAULT_RULES.ids.date);
    const t = document.getElementById(DEFAULT_RULES.ids.time);
    if (d && t) init();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", autoInitIfFieldsPresent);
  } else {
    autoInitIfFieldsPresent();
  }
})();
