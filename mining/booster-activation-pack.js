/**
 * /var/www/html/public/rwa/mining/booster-activation-pack.js
 * Drop-in helper for mining booster modal activation.
 *
 * Usage:
 * - load after mining page UI exists
 * - call window.POADO_MINING_BOOSTER_PACK.init({...})
 */
(function () {
  const BoosterPack = {
    init(opts = {}) {
      const fetchJSON = opts.fetchJSON;
      const API = opts.API || {};
      const UI = opts.UI || {};
      const SFX = opts.SFX || {};
      const refreshStatus = opts.refreshStatus;
      const refreshBoosterModal = opts.refreshBoosterModal;
      const showErr = opts.showErr || function () {};

      if (typeof fetchJSON !== 'function') return;

      const tierButtons = [
        ['sub', UI.tierBtnSub],
        ['core', UI.tierBtnCore],
        ['nodes', UI.tierBtnNodes],
        ['super_node', UI.tierBtnSuper]
      ];

      tierButtons.forEach(([tier, btn]) => {
        if (!btn) return;

        btn.addEventListener('click', async (e) => {
          e.preventDefault();

          if (btn.disabled) return;

          try {
            if (typeof SFX.click === 'function') {
              SFX.click(tier === 'super_node' || tier === 'nodes' ? 'gold' : 'default');
            }

            btn.disabled = true;
            const originalText = btn.textContent;
            btn.textContent = 'VERIFYING...';

            const body = new URLSearchParams();
            body.set('tier', tier);

            const res = await fetchJSON(API.activate, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
              },
              body: body.toString()
            });

            if (!res.ok) {
              throw new Error(res.message || res.error || 'ACTIVATION_FAIL');
            }

            if (typeof SFX.success === 'function') SFX.success();
            if (typeof refreshStatus === 'function') await refreshStatus();
            if (typeof refreshBoosterModal === 'function') await refreshBoosterModal();

            btn.textContent = res.already_active ? 'ACTIVE NOW' : 'ACTIVATED';
            setTimeout(() => {
              btn.textContent = originalText;
            }, 1200);
          } catch (err) {
            if (typeof SFX.error === 'function') SFX.error();
            showErr('BOOSTER: ' + String(err.message || err));
            btn.textContent = 'VERIFY FAILED';
            setTimeout(() => {
              btn.textContent = 'SELECT (backend verify)';
              btn.disabled = false;
            }, 1200);
          }
        });
      });
    }
  };

  window.POADO_MINING_BOOSTER_PACK = BoosterPack;
})();
