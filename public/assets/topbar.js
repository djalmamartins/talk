// Connect Talk — topo de todas as telas: busca (Ctrl K), notificações e menus suspensos.
const $ = (s) => document.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const ICON = {
  queue: '<path d="M4 5h16v10H8l-4 4z"/>',
  unread: '<path d="M4 6h16v12H4zM4 7l8 6 8-6"/>',
  schedules: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4M12 13v3M12 18.5v.5"/>',
  channels: '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>',
  transfer: '<path d="M4 8h13l-3-3M20 16H7l3 3"/>',
  rating: '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>',
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

// ------------------------------------------------------------ som de alerta
// Um toque curto e suave (gerado pelo navegador, sem arquivo). Liga/desliga no menu do usuário;
// a escolha fica neste navegador. O navegador só deixa tocar depois do primeiro clique na página.
const SOUND_KEY = 'talk.sound';
let audio = null;
let lastAlert = 0;
const soundOn = () => { try { return localStorage.getItem(SOUND_KEY) !== 'off'; } catch { return true; } };
function unlockAudio() {
  if (!audio) { try { audio = new (window.AudioContext || window.webkitAudioContext)(); } catch { return; } }
  if (audio.state === 'suspended') audio.resume().then(renderUnlock).catch(() => {});
  renderUnlock();
}
// o navegador bloqueia o som até o primeiro clique: avisa no topo para a pessoa não ficar sem alerta
function renderUnlock() {
  let b = document.getElementById('sound-unlock');
  const blocked = soundOn() && (!audio || audio.state !== 'running');
  if (!blocked) { b?.remove(); return; }
  if (b) return;
  b = document.createElement('button');
  b.type = 'button'; b.id = 'sound-unlock'; b.className = 'sound-unlock';
  b.textContent = '🔔 Clique aqui para ativar o som dos avisos';
  b.addEventListener('click', () => { unlockAudio(); setTimeout(() => { if (audio?.state === 'running') chime(); renderUnlock(); }, 80); });
  document.body.appendChild(b);
}
setTimeout(renderUnlock, 1500);
['pointerdown', 'keydown'].forEach((ev) => document.addEventListener(ev, unlockAudio, { capture: true, passive: true }));
function chime() {
  if (!audio || audio.state !== 'running') return;
  const t = audio.currentTime;
  [[880, 0], [1318.5, 0.13]].forEach(([f, d]) => { // duas notas (lá e mi), como um "plim"
    const o = audio.createOscillator(); const g = audio.createGain();
    o.type = 'sine'; o.frequency.value = f;
    g.gain.setValueAtTime(0.0001, t + d);
    g.gain.exponentialRampToValueAtTime(0.22, t + d + 0.02);
    g.gain.exponentialRampToValueAtTime(0.0001, t + d + 0.55);
    o.connect(g).connect(audio.destination); o.start(t + d); o.stop(t + d + 0.6);
  });
}
/** Avisa que chegou mensagem: toca o som (se ligado) e balança o sino. No máximo uma vez a cada 3 s. */
window.talkAlert = function talkAlert() {
  const now = Date.now();
  if (now - lastAlert < 3000) return;
  lastAlert = now;
  if (soundOn()) chime();
  const bell = $('#tb-notif summary');
  bell.classList.remove('ring'); void bell.offsetWidth; bell.classList.add('ring');
};
function renderSound() {
  const on = soundOn();
  $('#sound-toggle').setAttribute('aria-pressed', String(on));
  $('#sound-state').textContent = on ? 'Ligado: toca quando chega mensagem ou alguém entra na fila' : 'Desligado';
}
$('#sound-toggle').addEventListener('click', () => {
  try { localStorage.setItem(SOUND_KEY, soundOn() ? 'off' : 'on'); } catch { /* sem armazenamento: fica ligado */ }
  renderSound(); unlockAudio(); renderUnlock();
  if (soundOn()) setTimeout(chime, 50); // toca uma vez para a pessoa ouvir como fica
});
renderSound();

let failures = 0;
// o que já foi avisado, para tocar só quando aparece algo novo (em qualquer tela, com ou sem tempo real)
let seen = null; // {queue, unread, transfers:Set}
let queueNow = 0;
const RATED_KEY = 'talk.rated';
const ratedSeen = () => { try { return new Set(JSON.parse(localStorage.getItem(RATED_KEY) || '[]')); } catch { return new Set(); } };
function markRated(ids) { try { localStorage.setItem(RATED_KEY, JSON.stringify([...ids].slice(-50))); } catch { /* sem armazenamento */ } }
function toastRating(it) {
  const t = document.createElement('a');
  t.className = `rating-toast ${it.score >= 4 ? 'good' : 'review'}`;
  t.href = it.href;
  t.innerHTML = `<span aria-hidden="true">${'★'.repeat(it.score)}${'☆'.repeat(5 - it.score)}</span><b>${esc(it.title)}</b><small>${esc(it.text)}</small>`;
  document.body.appendChild(t);
  setTimeout(() => t.classList.add('out'), 9000);
  setTimeout(() => t.remove(), 9600);
}
async function loadNotifications() {
  try {
    const res = await fetch('/api/notifications', { headers: { Accept: 'application/json' } });
    if (res.status === 401) { location.href = '/login'; return; }
    const d = await res.json();
    failures = 0;
    const n = $('#tb-notif-n');
    n.hidden = !d.count; n.textContent = d.count > 99 ? '99+' : d.count;
    $('#tb-notif summary').classList.toggle('has', !!d.count);
    const count = (k) => d.items.filter((it) => it.kind === k).reduce((a, it) => a + it.count, 0);
    // contador da fila no menu lateral (a aba Fila saiu de Atendimentos)
    const waiting = count('queue');
    queueNow = waiting;
    for (const id of ['#badge-nav', '#badge-queue']) { const b = $(id); if (b && !(id === '#badge-queue' && b.dataset.live)) b.textContent = waiting || ''; }
    const now = { queue: waiting, unread: count('unread'),
      transfers: new Set(d.items.filter((it) => it.kind === 'transfer').map((it) => it.href)) };
    if (seen !== null && (now.queue > seen.queue || now.unread > seen.unread || [...now.transfers].some((h) => !seen.transfers.has(h)))) window.talkAlert();
    seen = now;
    // avaliação nova: aviso na tela uma vez por avaliação (neste navegador)
    const rated = ratedSeen();
    const fresh = d.items.filter((it) => it.kind === 'rating' && !rated.has(it.id));
    if (fresh.length) {
      fresh.slice(0, 2).forEach(toastRating);
      window.talkAlert();
      fresh.forEach((it) => rated.add(it.id)); markRated(rated);
    }
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
// telas com tempo real chamam isto quando muda alguma conversa: o sino confere na hora (com folga para juntar eventos)
let checkTimer;
window.talkCheck = function talkCheck() { clearTimeout(checkTimer); checkTimer = setTimeout(loadNotifications, 600); };
// enquanto houver alguém esperando na fila, lembra com o som a cada minuto
setInterval(() => { if (queueNow > 0) window.talkAlert(); }, 60000);
$('#tb-notif').addEventListener('toggle', (e) => { if (e.target.open) loadNotifications(); });
