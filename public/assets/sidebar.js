// Connect Talk — menu lateral igual ao do Connect ERP: recolher no computador (fica guardado neste navegador),
// já recolhido no tablet (901 a 1100 px) e gaveta no celular (até 900 px), com fundo escuro e Esc para fechar.
const shell = document.querySelector('.shell');
const sidebar = document.getElementById('sidebar');
const collapse = document.getElementById('sb-collapse');
const openBtn = document.querySelector('[data-sb-open]');
const KEY = 'talk.sidebar';
const mobile = matchMedia('(max-width: 900px)');
const tablet = matchMedia('(min-width: 901px) and (max-width: 1100px)');
const store = {
  get: () => { try { return localStorage.getItem(KEY); } catch { return null; } },
  set: (v) => { try { localStorage.setItem(KEY, v); } catch { /* modo privado */ } },
};

if (shell && sidebar) {
  const setCollapsed = (value, save) => {
    shell.classList.toggle('is-collapsed', value);
    collapse?.setAttribute('aria-expanded', String(!value));
    const label = value ? 'Expandir menu' : 'Recolher menu';
    collapse?.setAttribute('aria-label', label);
    collapse?.setAttribute('title', label);
    if (save) store.set(value ? '1' : '0'); // no tablet a escolha vale só para esta página
  };
  const setOpen = (value) => {
    shell.classList.toggle('is-nav-open', value);
    openBtn?.setAttribute('aria-expanded', String(value));
    if (value) { sidebar.removeAttribute('inert'); sidebar.querySelector('a, button')?.focus(); }
    else if (mobile.matches) { sidebar.setAttribute('inert', ''); openBtn?.focus(); }
  };
  const sync = () => {
    shell.classList.remove('is-nav-open');
    if (mobile.matches) { sidebar.setAttribute('inert', ''); setCollapsed(false, false); return; }
    sidebar.removeAttribute('inert');
    setCollapsed(tablet.matches || store.get() === '1', false);
  };
  sync();
  mobile.addEventListener('change', sync);
  tablet.addEventListener('change', sync);
  collapse?.addEventListener('click', () => setCollapsed(!shell.classList.contains('is-collapsed'), !tablet.matches));
  openBtn?.addEventListener('click', () => setOpen(true));
  document.querySelectorAll('[data-sb-close]').forEach((el) => el.addEventListener('click', () => setOpen(false)));
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && shell.classList.contains('is-nav-open')) setOpen(false); });
  // no celular, escolher uma página (ou abrir um diálogo do menu) fecha a gaveta
  sidebar.querySelectorAll('.sb-nav a').forEach((a) => a.addEventListener('click', () => { if (mobile.matches) setOpen(false); }));
}
