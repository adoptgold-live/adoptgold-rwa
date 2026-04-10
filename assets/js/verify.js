(function () {
  'use strict';

  const api = '/rwa/verify/api/verify.php';
  const elForm = document.getElementById('rvForm');
  const elQuery = document.getElementById('rvQuery');
  const elResult = document.getElementById('rvResult');
  const elWrap = document.getElementById('rvResultWrap');
  const elPill = document.getElementById('rvStatusPill');

  const quick = {
    rvBtnWems: 'WEMS',
    rvBtnEma: 'EMA',
    rvBtnEmx: 'EMX',
    rvBtnEms: 'EMS',
    rvBtnUsdt: 'USDT'
  };

  Object.keys(quick).forEach((id) => {
    const btn = document.getElementById(id);
    if (!btn) return;
    btn.addEventListener('click', function () {
      elQuery.value = quick[id];
      submitVerify();
    });
  });

  if (elForm) {
    elForm.addEventListener('submit', function (e) {
      e.preventDefault();
      submitVerify();
    });
  }

  async function submitVerify() {
    const q = (elQuery && elQuery.value ? elQuery.value : '').trim();
    if (!q) {
      render({ ok: false, status: 'invalid', message: 'Empty query.' });
      return;
    }

    setState('loading', 'loading...');
    try {
      const body = new URLSearchParams();
      body.set('q', q);

      const res = await fetch(api, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: body.toString()
      });

      const json = await res.json();
      render(json);
    } catch (err) {
      render({
        ok: false,
        status: 'error',
        message: err && err.message ? err.message : 'Request failed.'
      });
    }
  }

  function setState(kind, text) {
    if (elPill) {
      elPill.textContent = text || kind;
      elPill.className = 'rv-pill is-' + kind;
    }
    if (elWrap) {
      elWrap.className = 'rv-card rv-result';
    }
  }

  function render(payload) {
    const status = String(payload && payload.status ? payload.status : (payload && payload.verified ? 'verified' : 'invalid'));
    const kind = payload && payload.verified ? 'verified' : (status === 'partial' ? 'partial' : (status === 'not_found' ? 'not-found' : (status === 'error' ? 'error' : 'invalid')));
    setState(kind, status);

    if (elResult) {
      elResult.textContent = JSON.stringify(payload, null, 2);
    }
  }
})();
