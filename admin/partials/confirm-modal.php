<!-- Confirm Modal — include once per page, before </body> -->
<div id="sdcConfirm" class="fixed inset-0 z-[9999] hidden items-center justify-center p-4">
  <div data-sdc-confirm-close class="absolute inset-0 bg-slate-900/45 backdrop-blur-sm"></div>

  <div
    id="sdcConfirmPanel"
    class="relative w-full max-w-md rounded-[2rem] bg-white border border-slate-100 shadow-2xl shadow-slate-900/20 transform transition-all duration-150 scale-95 opacity-0"
    role="dialog"
    aria-modal="true"
    aria-labelledby="sdcConfirmTitle"
    aria-describedby="sdcConfirmDesc"
  >
    <div class="p-6">
      <div class="flex items-start gap-4">
        <div class="shrink-0 w-11 h-11 rounded-2xl bg-red-50 border border-red-100 flex items-center justify-center text-red-600">
          <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M19 7l-1 14H6L5 7m3 0V5a2 2 0 012-2h4a2 2 0 012 2v2m-9 0h10"/>
          </svg>
        </div>

        <div class="min-w-0">
          <h3 id="sdcConfirmTitle" class="text-lg font-black text-slate-900">Confirm</h3>
          <p id="sdcConfirmDesc" class="mt-1 text-sm font-semibold text-slate-500">Are you sure?</p>
        </div>
      </div>

      <div class="mt-6 flex items-center justify-end gap-3">
        <button type="button" data-sdc-confirm-cancel
          class="px-5 py-2.5 rounded-2xl font-black text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition">
          Cancel
        </button>

        <button type="button" data-sdc-confirm-ok
          class="px-6 py-2.5 rounded-2xl font-black bg-slate-900 text-white hover:bg-slate-800 transition shadow-lg active:scale-[0.98]">
          Confirm
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const modal = document.getElementById('sdcConfirm');
  const panel = document.getElementById('sdcConfirmPanel');
  if (!modal || !panel) return;

  const titleEl = document.getElementById('sdcConfirmTitle');
  const descEl  = document.getElementById('sdcConfirmDesc');
  const btnOk     = modal.querySelector('[data-sdc-confirm-ok]');
  const btnCancel = modal.querySelector('[data-sdc-confirm-cancel]');

  let pendingForm = null;
  let pendingCallback = null;
  let lastActive = null;

  const restoreY = sessionStorage.getItem('sdcScrollY');
  if (restoreY) {
    sessionStorage.removeItem('sdcScrollY');
    const y = parseInt(restoreY, 10);
    if (!Number.isNaN(y)) requestAnimationFrame(() => setTimeout(() => window.scrollTo(0, y), 0));
  }

  const open = (form, cb) => {
    pendingForm     = form;
    pendingCallback = cb || null;
    lastActive = document.activeElement;

    const title   = form ? form.getAttribute('data-confirm')      || 'Confirm'       : 'Confirm';
    const desc    = form ? form.getAttribute('data-confirm-desc') || 'Are you sure?' : 'Are you sure?';
    const okLabel = form ? form.getAttribute('data-confirm-ok')   || 'Confirm'       : 'Confirm';

    titleEl.textContent = title;
    descEl.textContent  = desc;
    btnOk.textContent   = okLabel;

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.documentElement.classList.add('overflow-hidden');
    requestAnimationFrame(() => {
      panel.classList.remove('opacity-0', 'scale-95');
      panel.classList.add('opacity-100', 'scale-100');
    });
    btnOk.focus();
  };

  const close = () => {
    panel.classList.remove('opacity-100', 'scale-100');
    panel.classList.add('opacity-0', 'scale-95');
    setTimeout(() => {
      modal.classList.add('hidden');
      modal.classList.remove('flex');
      document.documentElement.classList.remove('overflow-hidden');
      pendingForm = pendingCallback = null;
      if (lastActive && typeof lastActive.focus === 'function') lastActive.focus();
    }, 120);
  };

  // Form submit interception
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (!form.hasAttribute('data-confirm')) return;
    if (form.dataset.sdcConfirmPass === '1') { delete form.dataset.sdcConfirmPass; return; }
    e.preventDefault();
    open(form, null);
  }, true);

  btnOk.addEventListener('click', () => {
    if (pendingCallback) { close(); setTimeout(pendingCallback, 80); return; }
    if (!pendingForm) return;
    try { sessionStorage.setItem('sdcScrollY', String(window.scrollY)); } catch (_) {}
    const form = pendingForm;
    form.dataset.sdcConfirmPass = '1';
    close();
    setTimeout(() => {
      if (typeof form.requestSubmit === 'function') form.requestSubmit();
      else form.submit();
    }, 80);
  });

  btnCancel.addEventListener('click', close);
  modal.addEventListener('click', (e) => { if (e.target && e.target.hasAttribute('data-sdc-confirm-close')) close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) close(); });

  // Allow imperative use: sdcConfirm('msg', 'desc', 'OK label', callback)
  window.sdcConfirm = (title, desc, okLabel, cb) => {
    const fake = document.createElement('form');
    fake.setAttribute('data-confirm', title || 'Confirm');
    fake.setAttribute('data-confirm-desc', desc || 'Are you sure?');
    fake.setAttribute('data-confirm-ok', okLabel || 'Confirm');
    open(fake, cb);
  };
})();
</script>
