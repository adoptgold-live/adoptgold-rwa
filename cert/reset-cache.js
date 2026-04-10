(function(){
  console.log('FORCE RESET CACHE START');

  try {
    localStorage.clear();
    sessionStorage.clear();
  } catch(e){}

  if (window.caches) {
    caches.keys().then(keys => keys.forEach(k => caches.delete(k)));
  }

  if (navigator.serviceWorker) {
    navigator.serviceWorker.getRegistrations().then(regs => {
      regs.forEach(r => r.unregister());
    });
  }

  console.log('CACHE CLEARED, RELOADING...');
  setTimeout(() => location.reload(true), 500);
})();
