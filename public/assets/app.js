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
  if (state.tab === 'pending') { $('#badge-pending').textContent = rows.length || ''; $('#badge-nav').textContent = rows.length || ''; }
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
      ? `<form class="composer" id="composer"><div class="reply-wrap"><ul id="quick-pick" class="quick-pick" hidden></ul><textarea id="reply" placeholder="Escreva a resposta… (/ para mensagens rápidas, Enter envia)"></textarea></div><button class="primary">Enviar</button></form>`
      : `<div class="banner">${c.status === 'closed' ? 'Atendimento encerrado.' : c.status === 'bot' ? 'O cliente ainda está escolhendo o setor.' : 'Somente leitura.'}</div>`}`;
  const box = $('#msgs');
  box.scrollTop = box.scrollHeight;
  renderContact(contact, c);
  state.ctx = { nome: contact.name || '', protocolo: c.protocol, setor: sectorName(c.sector_id), atendente: (state.me.name || '').split(' (')[0] };
  if (c.unread > 0 && can_act) api(`/api/conversations/${id}/read`, { method: 'POST' }).catch(() => {});
  document.querySelectorAll('#conversations li').forEach((li) => li.classList.toggle('active', li.dataset.id == id));
}

function renderMsg(m) {
  const who = m.author_user_id ? `<b>${esc(state.users[m.author_user_id] || '')}</b><br>` : '';
  const ack = m.direction === 'out' ? { pending: '🕓', sent: '✓', delivered: '✓✓', read: '✓✓ lido', failed: '⚠ falhou' }[m.ack] || '' : '';
  if (m.type === 'jack') {
    return `<div class="sys-hint" title="Os termos que levaram à sugestão ficam no registro do supervisor">${esc(m.body)} · ${hhmm(m.created_at)}</div>`;
  }
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
      $('#transfer-note').value = ''; $('#transfer-count').textContent = '0/500';
      $('#transfer-history').checked = true;
      document.querySelector('input[name=xfer-mode][value=sector]').checked = true;
      fillTransferUsers(); toggleTransferMode();
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
    const toUser = document.querySelector('input[name=xfer-mode]:checked').value === 'user' ? +$('#transfer-user').value || null : null;
    const opt = $('#transfer-user').selectedOptions[0];
    const sectorId = toUser ? +opt.dataset.sector : +$('#transfer-sector').value;
    await api(`/api/conversations/${state.current}/transfer`, { method: 'POST', body: {
      sector_id: sectorId, user_id: toUser, note: $('#transfer-note').value, keep_history: $('#transfer-history').checked } });
    loadList();
    // quem transferiu pode ter perdido o acesso (outro setor): nesse caso só fecha a conversa
    await openConversation(state.current).catch((err) => {
      if (err.status !== 403) throw err;
      state.current = null; $('#chat').innerHTML = '<div class="empty">Conversa transferida para outro setor.</div>'; $('#contact-panel').innerHTML = '';
    });
  } catch (err) { toast(err.message); }
});
// Trocar atendente: atendentes de todos os setores (menos eu), agrupados por setor
function fillTransferUsers() {
  const groups = state.sectors.map((s) => {
    const people = state.staff.filter((p) => p.sector_id == s.id && p.user_id != state.me.id);
    return people.length ? `<optgroup label="${esc(s.name)}">${people.map((p) => `<option value="${p.user_id}" data-sector="${s.id}">${esc(p.name)}</option>`).join('')}</optgroup>` : '';
  }).join('');
  $('#transfer-user').innerHTML = '<option value="">Selecione um atendente</option>' + groups;
}
function toggleTransferMode() {
  const user = document.querySelector('input[name=xfer-mode]:checked').value === 'user';
  $('#transfer-user').disabled = !user; $('#transfer-sector').disabled = user;
  $('#transfer-user').required = user;
}
document.querySelectorAll('input[name=xfer-mode]').forEach((r) => r.addEventListener('change', toggleTransferMode));
$('#transfer-note').addEventListener('input', (e) => { $('#transfer-count').textContent = `${e.target.value.length}/500`; });

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
  if (e.target.id === 'reply' && quickPickKey(e)) return;
  if (e.target.id === 'reply' && e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); e.target.form.requestSubmit(); }
});

// ------------------------------------------------------------ mensagens rápidas
async function loadQuick() { state.quick = await api('/api/quick-messages'); return state.quick; }
const fillQuick = (body) => {
  const x = state.ctx || {};
  const first = (x.nome || '').trim().split(' ')[0] || '';
  return body.replace(/\{(primeiro_nome|nome|protocolo|setor|atendente)\}/g, (_, k) => (k === 'primeiro_nome' ? first : x[k] || ''))
    .replace(/ +([,!.?])/g, '$1').replace(/  +/g, ' ');
};
// "/termo" no começo do campo ou depois de um espaço/linha
const quickQuery = (ta) => { const m = ta.value.slice(0, ta.selectionStart).match(/(^|\s)\/([\w-]*)$/); return m ? m[2].toLowerCase() : null; };
function renderQuickPick() {
  const ta = $('#reply'); const pick = $('#quick-pick');
  if (!ta || !pick) return;
  const q = quickQuery(ta);
  // atalho que começa com o termo primeiro, depois atalho que contém, depois o texto
  const rank = (m) => (m.shortcut.startsWith(q) ? 0 : m.shortcut.includes(q) ? 1 : m.body.toLowerCase().includes(q) ? 2 : 9);
  const items = q === null ? [] : (state.quick || []).filter((m) => rank(m) < 9).sort((a, b) => rank(a) - rank(b)).slice(0, 8);
  pick.hidden = items.length === 0;
  pick.innerHTML = items.map((m, i) => `<li data-id="${m.id}" class="${i === 0 ? 'sel' : ''}"><b>/${esc(m.shortcut)}</b><span>${esc(fillQuick(m.body))}</span></li>`).join('');
}
function useQuick(id) {
  const m = (state.quick || []).find((x) => x.id == id); const ta = $('#reply');
  if (!m || !ta) return;
  const before = ta.value.slice(0, ta.selectionStart).replace(/\/[\w-]*$/, '');
  const text = fillQuick(m.body);
  ta.value = before + text + ta.value.slice(ta.selectionStart);
  ta.selectionStart = ta.selectionEnd = before.length + text.length;
  $('#quick-pick').hidden = true; ta.focus();
}
function quickPickKey(e) {
  const pick = $('#quick-pick');
  if (!pick || pick.hidden) return false;
  const li = [...pick.children]; let i = li.findIndex((x) => x.classList.contains('sel'));
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault(); i = (i + (e.key === 'ArrowDown' ? 1 : li.length - 1)) % li.length;
    li.forEach((x, j) => x.classList.toggle('sel', j === i)); li[i].scrollIntoView({ block: 'nearest' }); return true;
  }
  if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); useQuick(li[Math.max(0, i)].dataset.id); return true; }
  if (e.key === 'Escape') { e.preventDefault(); pick.hidden = true; return true; }
  return false;
}
document.addEventListener('input', (e) => { if (e.target.id === 'reply') renderQuickPick(); });
document.addEventListener('mousedown', (e) => { const li = e.target.closest('#quick-pick li'); if (li) { e.preventDefault(); useQuick(li.dataset.id); } });

// cadastro (supervisor: gerais e de todos os setores; gerente: do seu setor)
const canManageQuick = () => state.me.supervisor || (state.me.manager_sectors || []).length > 0;
function renderQuickList() {
  $('#quick-list').innerHTML = (state.quick || []).map((m) => `
    <li><div><b>/${esc(m.shortcut)}</b> <small>${esc(m.sector_name || 'Todos os setores')}</small><p>${esc(m.body)}</p></div>
    ${m.can_edit ? `<span><button type="button" data-qedit="${m.id}">Editar</button><button type="button" data-qdel="${m.id}">Excluir</button></span>` : ''}</li>`).join('')
    || '<li class="empty">Nenhuma mensagem rápida ainda.</li>';
  $('#quick-new').hidden = !canManageQuick();
}
function quickEdit(m) {
  const opts = [];
  if (state.me.supervisor) opts.push('<option value="">Todos os setores</option>');
  state.sectors.filter((s) => state.me.supervisor || (state.me.manager_sectors || []).includes(Number(s.id)))
    .forEach((s) => opts.push(`<option value="${s.id}">${esc(s.name)}</option>`));
  $('#quick-sector').innerHTML = opts.join('');
  $('#quick-id').value = m?.id || ''; $('#quick-shortcut').value = m?.shortcut || ''; $('#quick-body').value = m?.body || '';
  $('#quick-sector').value = m?.sector_id ?? (state.me.supervisor ? '' : $('#quick-sector').value);
  $('#quick-form').hidden = false; $('#quick-actions').hidden = true; $('#quick-shortcut').focus();
}
const quickFormClose = () => { $('#quick-form').hidden = true; $('#quick-actions').hidden = false; };
$('#nav-quick')?.addEventListener('click', async (e) => {
  e.preventDefault(); await loadQuick(); renderQuickList(); quickFormClose(); $('#quick-dialog').showModal();
});
$('#quick-new').addEventListener('click', () => quickEdit(null));
$('#quick-cancel').addEventListener('click', quickFormClose);
$('#quick-close').addEventListener('click', () => $('#quick-dialog').close());
$('#quick-list').addEventListener('click', async (e) => {
  const ed = e.target.dataset.qedit; const del = e.target.dataset.qdel;
  if (ed) quickEdit(state.quick.find((m) => m.id == ed));
  if (del && confirm('Excluir esta mensagem rápida?')) {
    try { await api(`/api/quick-messages/${del}/delete`, { method: 'POST' }); await loadQuick(); renderQuickList(); } catch (err) { toast(err.message); }
  }
});
$('#quick-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const id = $('#quick-id').value;
  try {
    await api(id ? `/api/quick-messages/${id}` : '/api/quick-messages', { method: 'POST',
      body: { shortcut: $('#quick-shortcut').value, body: $('#quick-body').value, sector_id: $('#quick-sector').value } });
    await loadQuick(); renderQuickList(); quickFormClose();
  } catch (err) { toast(err.message); }
});
$('#sector-filter').addEventListener('change', (e) => { state.sector = e.target.value; loadList(); });

// ------------------------------------------------------------ simulador
async function loadSim() {
  if (!isLab) return;
  const { messages: rows, typing } = await api(`/lab/customer?phone=${encodeURIComponent($('#sim-phone').value)}`);
  const box = $('#sim-thread');
  box.innerHTML = rows.map((m) => `<div class="msg ${m.direction}">${esc(m.body)}</div>`).join('')
    + (typing ? '<div class="msg out typing">digitando…</div>' : '');
  box.scrollTop = box.scrollHeight;
}
if (isLab) {
  $('#sim-phone').addEventListener('change', loadSim);
  setInterval(() => loadSim().catch(() => {}), 3000); // no laboratório isso também move a fila de envio
}

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
  es.onopen = () => { $('#live').textContent = '● Online'; $('#live').classList.add('on'); };
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
  Object.assign(state, { me: me.user, sectors: me.sectors, users: me.users, staff: me.staff || [] });
  $('#sector-filter').innerHTML += me.sectors.map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
  await loadList();
  loadQuick().catch(() => {});
  loadSim();
  connect();
})();

$('#nav-search')?.addEventListener('click', (e) => { e.preventDefault(); $('#search').focus(); });
