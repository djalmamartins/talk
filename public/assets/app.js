// Connect Talk — caixa de entrada (JS puro, sem build)
const csrf = document.querySelector('meta[name=csrf]').content;
const isLab = document.body.dataset.lab === '1';
const $ = (s, el = document) => el.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const hhmm = (d) => (d ? new Date(d.replace(' ', 'T')).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '');

const state = { tab: 'pending', sector: '', current: null, me: null, sectors: [], users: {}, search: '' };

async function api(path, opts = {}) {
  const res = await fetch(path, {
    method: opts.method || 'GET',
    headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf },
    body: opts.body ? JSON.stringify(opts.body) : undefined,
  });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401) location.href = '/login';
  if (!res.ok) { const e = new Error(data.error || 'Erro'); e.status = res.status; throw e; }
  return data;
}
const toast = (msg) => alert(msg);
const statusLabel = { bot: 'no menu', pending: 'na fila', open: 'em atendimento', closed: 'encerrado' };
const sectorName = (id) => state.sectors.find((s) => s.id == id)?.name || '—';

// ------------------------------------------------------------ lista
async function loadList() {
  if (state.search) return runSearch();
  const rows = await api(`/api/conversations?tab=${state.tab}&sector=${state.sector}`);
  $('#conversations').innerHTML = rows.map((c) => `
    <li data-id="${c.id}" class="${state.current == c.id ? 'active' : ''}">
      <span class="name">${esc(c.contact_name || c.phone)}</span>
      ${c.unread > 0 && c.status !== 'closed' ? `<span class="unread">${c.unread}</span>` : `<span class="meta">${hhmm(c.last_message_at)}</span>`}
      <span class="meta"><span class="tag">${esc(c.sector_name || 'menu')}</span> ${esc(c.protocol)}</span><span></span>
      <span class="last">${esc(c.last_body || '')}</span>
    </li>`).join('') || '<li class="meta">Nada por aqui.</li>';
  if (state.tab === 'pending') $('#badge-pending').textContent = rows.length || '';
}

async function runSearch() {
  const rows = await api(`/api/search?q=${encodeURIComponent(state.search)}&contact=${encodeURIComponent(/\d{6,}|^\d{8}-/.test(state.search) ? state.search : '')}`);
  $('#conversations').innerHTML = rows.map((r) => `
    <li data-id="${r.conversation_id}" class="search-result">
      <span class="name">${esc(r.contact_name || r.phone)}</span><span class="meta">${hhmm(r.created_at)}</span>
      <span class="last"><span class="dir">${r.direction === 'in' ? '⬅ chegou' : '➡ saiu'}</span> ${esc(r.body)}</span>
    </li>`).join('') || '<li class="meta">Nenhuma mensagem encontrada.</li>';
}

// ------------------------------------------------------------ conversa
async function openConversation(id) {
  state.current = id;
  document.querySelector('.layout').classList.add('has-chat');
  const { conversation: c, contact, messages, can_act } = await api(`/api/conversations/${id}`);
  const actions = [];
  if (can_act && c.status === 'pending') actions.push('<button class="primary" data-act="accept">Aceitar</button>');
  if (can_act && c.status !== 'closed' && c.status !== 'bot') actions.push('<button data-act="transfer">Transferir</button>', '<button data-act="close">Encerrar</button>');
  if (can_act && c.status !== 'closed') actions.push('<button data-act="note">Nota interna</button>');
  $('#chat').innerHTML = `
    <div class="chat-head">
      <div><div class="who">${esc(contact.name || contact.phone)}</div>
      <div class="meta">${esc(contact.phone)} · protocolo <b>${esc(c.protocol)}</b> · ${esc(sectorName(c.sector_id))} · ${statusLabel[c.status]}
      ${c.assigned_user_id ? ' · ' + esc(state.users[c.assigned_user_id] || '#' + c.assigned_user_id) : ''}</div></div>
      <div class="actions">${actions.join('')}</div>
    </div>
    <div class="msgs" id="msgs">${messages.map(renderMsg).join('')}</div>
    ${can_act && (c.status === 'open' || c.status === 'pending')
      ? `<form class="composer" id="composer"><textarea id="reply" placeholder="Escreva a resposta… (Enter envia, Shift+Enter quebra linha)"></textarea><button class="primary">Enviar</button></form>`
      : `<div class="banner">${c.status === 'closed' ? 'Atendimento encerrado.' : c.status === 'bot' ? 'O cliente ainda está escolhendo o setor.' : 'Somente leitura.'}</div>`}`;
  const box = $('#msgs');
  box.scrollTop = box.scrollHeight;
  renderContact(contact, c);
  if (c.unread > 0 && can_act) api(`/api/conversations/${id}/read`, { method: 'POST' }).catch(() => {});
  document.querySelectorAll('#conversations li').forEach((li) => li.classList.toggle('active', li.dataset.id == id));
}

function renderMsg(m) {
  const who = m.author_user_id ? `<b>${esc(state.users[m.author_user_id] || '')}</b><br>` : '';
  const ack = m.direction === 'out' ? { pending: '🕓', sent: '✓', delivered: '✓✓', read: '✓✓ lido', failed: '⚠ falhou' }[m.ack] || '' : '';
  const label = m.direction === 'internal' ? (m.type === 'transfer' ? '🔀 ' : m.type === 'resolution' ? '✅ ' : '📝 ') : '';
  return `<div class="msg ${m.direction}">${m.direction === 'internal' ? who : ''}${label}${esc(m.body)}<time>${hhmm(m.created_at)} ${ack}</time></div>`;
}

function renderContact(contact, c) {
  let erp = '';
  try {
    const d = JSON.parse(contact.erp_snapshot || 'null');
    if (d) erp = Object.entries(d).map(([k, v]) => `<div><span class="meta">${esc(k)}</span><br>${esc(v)}</div>`).join('');
  } catch {}
  $('#contact-panel').innerHTML = `<h3>Contato</h3>
    <div><b>${esc(contact.name || 'Sem nome')}</b></div><div class="meta">${esc(contact.phone)}</div>
    ${contact.erp_person_id ? `<p class="tag">Identificado no ERP #${contact.erp_person_id}</p>${erp}` : '<p class="meta">Não identificado no cadastro do ERP.</p>'}
    <p class="meta">Chamado: <b>${esc(c.protocol)}</b></p>`;
}

// ------------------------------------------------------------ ações
document.addEventListener('click', async (e) => {
  const li = e.target.closest('#conversations li[data-id]');
  if (li) return openConversation(li.dataset.id);
  const tab = e.target.closest('#tabs button');
  if (tab) {
    document.querySelectorAll('#tabs button').forEach((b) => b.classList.toggle('active', b === tab));
    state.tab = tab.dataset.tab; state.search = ''; $('#search').value = '';
    return loadList();
  }
  const act = e.target.closest('[data-act]')?.dataset.act;
  if (!act || !state.current) return;
  try {
    if (act === 'accept') await api(`/api/conversations/${state.current}/accept`, { method: 'POST' });
    if (act === 'note') {
      const note = prompt('Nota interna (o cliente não vê):');
      if (note) await api(`/api/conversations/${state.current}/notes`, { method: 'POST', body: { note } });
    }
    if (act === 'transfer') {
      $('#transfer-sector').innerHTML = state.sectors.map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
      $('#transfer-note').value = '';
      $('#transfer-dialog').showModal();
      return;
    }
    if (act === 'close') { $('#close-resolution').value = ''; $('#close-dialog').showModal(); return; }
    await openConversation(state.current); loadList();
  } catch (err) { toast(err.message); }
});

$('#transfer-dialog').addEventListener('close', async () => {
  if ($('#transfer-dialog').returnValue !== 'ok') return;
  try {
    await api(`/api/conversations/${state.current}/transfer`, { method: 'POST', body: { sector_id: +$('#transfer-sector').value, note: $('#transfer-note').value } });
    await openConversation(state.current); loadList();
  } catch (err) { toast(err.message); }
});
$('#close-dialog').addEventListener('close', async () => {
  if ($('#close-dialog').returnValue !== 'ok') return;
  try {
    await api(`/api/conversations/${state.current}/close`, { method: 'POST', body: { resolution: $('#close-resolution').value } });
    await openConversation(state.current); loadList();
  } catch (err) { toast(err.message); }
});

document.addEventListener('submit', async (e) => {
  if (e.target.id === 'composer') {
    e.preventDefault();
    const text = $('#reply').value.trim();
    if (!text) return;
    $('#reply').value = '';
    try { await api(`/api/conversations/${state.current}/reply`, { method: 'POST', body: { text } }); await openConversation(state.current); }
    catch (err) { toast(err.message); $('#reply').value = text; }
  }
  if (e.target.id === 'search-form') { e.preventDefault(); state.search = $('#search').value.trim(); loadList(); }
  if (e.target.id === 'sim-form') {
    e.preventDefault();
    const text = $('#sim-text').value.trim();
    if (!text) return;
    $('#sim-text').value = '';
    try { await api('/lab/simulate', { method: 'POST', body: { phone: $('#sim-phone').value, name: $('#sim-name').value, text } }); loadSim(); }
    catch (err) { toast(err.message); }
  }
});
document.addEventListener('keydown', (e) => {
  if (e.target.id === 'reply' && e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); e.target.form.requestSubmit(); }
});
$('#sector-filter').addEventListener('change', (e) => { state.sector = e.target.value; loadList(); });

// ------------------------------------------------------------ simulador
async function loadSim() {
  if (!isLab) return;
  const rows = await api(`/lab/customer?phone=${encodeURIComponent($('#sim-phone').value)}`);
  const box = $('#sim-thread');
  box.innerHTML = rows.map((m) => `<div class="msg ${m.direction}">${esc(m.body)}</div>`).join('');
  box.scrollTop = box.scrollHeight;
}
if (isLab) $('#sim-phone').addEventListener('change', loadSim);

// ------------------------------------------------------------ tempo real (SSE)
let refreshTimer;
function scheduleRefresh(convId) {
  clearTimeout(refreshTimer);
  refreshTimer = setTimeout(() => {
    loadList();
    if (state.current && (convId == null || convId == state.current)) {
      openConversation(state.current).catch((err) => {
        // perdeu o acesso (ex.: transferida para outro setor): fecha a conversa sem alarde
        if (err.status === 403) { state.current = null; $('#chat').innerHTML = '<div class="empty">Conversa transferida para outro setor.</div>'; $('#contact-panel').innerHTML = ''; }
      });
    }
    loadSim();
  }, 150);
}
function connect() {
  const es = new EventSource('/events');
  es.onopen = () => { $('#live').textContent = '● ao vivo'; $('#live').classList.add('on'); };
  es.onerror = () => { $('#live').textContent = '● reconectando'; $('#live').classList.remove('on'); };
  ['conversation.created', 'conversation.updated', 'conversation.transferred', 'message.created', 'message.ack'].forEach((t) =>
    es.addEventListener(t, (ev) => {
      const d = JSON.parse(ev.data);
      if (t === 'message.created' && d.direction === 'in' && document.hidden) document.title = '(nova) Connect Talk';
      scheduleRefresh(d.conversation_id);
    }));
}
document.addEventListener('visibilitychange', () => { if (!document.hidden) document.title = 'Connect Talk'; });

// ------------------------------------------------------------ início
(async function init() {
  const me = await api('/api/me');
  Object.assign(state, { me: me.user, sectors: me.sectors, users: me.users });
  $('#sector-filter').innerHTML += me.sectors.map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
  await loadList();
  loadSim();
  connect();
})();
