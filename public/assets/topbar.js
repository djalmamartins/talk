// Connect Talk — topo de todas as telas: busca (Ctrl K), notificações e menus suspensos.
const $ = (s) => document.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const ICON = {
  queue: '<path d="M4 5h16v10H8l-4 4z"/>',
  unread: '<path d="M4 6h16v12H4zM4 7l8 6 8-6"/>',
  schedules: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4M12 13v3M12 18.5v.5"/>',
  channels: '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>',
};

// um menu aberto por vez; clique fora ou Esc fecha
const drops = [...document.querySelectorAll('.tb-drop, .envswitch')];
drops.forEach((d) => d.addEventListener('toggle', () => { if (d.open) drops.forEach((o) => { if (o !== d) o.open = false; }); }));
document.addEventListener('click', (e) => drops.forEach((d) => { if (d.open && !d.contains(e.target)) d.open = false; }));
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') drops.forEach((d) => { d.open = false; });
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); $('#search').focus(); $('#search').select(); }
});
if (/Mac|iPhone|iPad/.test(navigator.platform)) $('.tb-search kbd').textContent = '⌘ K';
document.getElementById('my-password')?.addEventListener('click', () => drops.forEach((d) => { d.open = false; }));

let failures = 0;
async function loadNotifications() {
  try {
    const res = await fetch('/api/notifications', { headers: { Accept: 'application/json' } });
    if (res.status === 401) { location.href = '/login'; return; }
    const d = await res.json();
    failures = 0;
    const n = $('#tb-notif-n');
    n.hidden = !d.count; n.textContent = d.count > 99 ? '99+' : d.count;
    $('#tb-notif summary').setAttribute('aria-label', d.count ? `Notificações (${d.count})` : 'Notificações');
    $('#tb-notif-list').innerHTML = d.items.map((it) => `<a class="tb-item" href="${esc(it.href)}">
        <span class="tb-ico k-${esc(it.kind)}"><svg viewBox="0 0 24 24">${ICON[it.kind] || ''}</svg></span>
        <span><b>${esc(it.title)}</b><small>${esc(it.text)}</small></span></a>`).join('')
      || '<p class="tb-empty">Tudo em dia. Nada pede sua atenção agora.</p>';
    // telas sem tempo real próprio: mostra que o Talk está respondendo
    const live = $('#live');
    if (live && !live.classList.contains('on') && !live.dataset.sse) { live.textContent = '● Online'; live.classList.add('on'); }
  } catch {
    failures += 1;
    if (failures > 1) { const live = $('#live'); if (live) { live.textContent = '● sem conexão'; live.classList.remove('on'); } }
  }
}
loadNotifications();
setInterval(loadNotifications, 20000);
$('#tb-notif').addEventListener('toggle', (e) => { if (e.target.open) loadNotifications(); });
