// Connect Talk — Serviço da Jack (JS puro, sem build). Lista de atendimentos; clicar abre a janela com o
// atendimento completo e o campo de sugestão. Atualiza a lista a cada 15 s.
const $ = (s, el = document) => el.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const csrf = document.querySelector('meta[name=csrf]').content;
const state = { days: 1, filter: 'all', q: '', fbStatus: 'nova', current: null, sectorsFilled: false, isSupervisor: false };

const when = (s) => {
  if (!s) return '';
  const d = new Date(String(s).replace(' ', 'T'));
  const hm = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
  return d.toDateString() === new Date().toDateString() ? hm : `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')} ${hm}`;
};
const situ = { agora: ['Fazendo agora', 'now'], erro: ['Deu problema', 'err'], aviso: ['Não acertou', 'warn'], feito: ['Feito', 'ok'] };
const fbSitu = { nova: ['Nova', 'now'], aplicada: ['Aplicada', 'ok'], descartada: ['Descartada', 'off'] };
const jackText = (t) => esc(String(t ?? '').replace(/^\*Jack:\*\n?/, '')).replace(/\*([^*\n]+)\*/g, '<b>$1</b>').replace(/\n/g, '<br>');
const badge = (s) => { const [l, c] = situ[s] || situ.feito; return `<span class="sv-badge ${c}">${l}</span>`; };
const terms = (t) => (t && t.length ? `<div class="sv-terms">${t.map((x) => `<span class="tag">${esc(x)}</span>`).join(' ')}</div>` : '');

async function api(url, opts = {}) {
  const res = await fetch(url, { ...opts, headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf, ...(opts.headers || {}) } });
  if (res.status === 401) { location.href = '/login'; throw new Error('login'); }
  const d = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(d.error || 'Erro ao carregar.');
  return d;
}

function kpi(value, label, sub) {
  return `<div class="kpi"><div><b>${value}</b><span>${esc(label)}</span>${sub ? `<small>${esc(sub)}</small>` : ''}</div></div>`;
}

async function load() {
  let d;
  try {
    d = await api(`/api/jack/service?${new URLSearchParams({ days: state.days, filter: state.filter === 'feedback' ? 'all' : state.filter, q: state.q })}`);
  } catch (e) {
    $('#sv-error').hidden = false; $('#sv-error').textContent = e.message; return;
  }
  $('#sv-error').hidden = true;
  render(d);
  if (state.filter === 'feedback') loadFeedback();
  const now = new Date();
  $('#sv-updated').textContent = `Atualizado às ${now.toTimeString().slice(0, 8)} · atualiza sozinho a cada 15 s`;
}

function render(d) {
  $('#sv-off').hidden = d.modo !== 'off';
  if (!state.sectorsFilled) {
    state.sectorsFilled = true;
    $('#sv-fb-sector').insertAdjacentHTML('beforeend', d.setores.map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join(''));
  }
  const r = d.resumo;
  const periodo = d.dias === 1 ? 'hoje' : `últimos ${d.dias} dias`;
  $('#sv-kpis').innerHTML = [
    kpi(r.agora, 'Com a Jack agora', 'esperando ela encaminhar'),
    kpi(r.conversas, 'Atendimentos dela', periodo),
    kpi(r.encaminhadas, 'Encaminhados por ela', d.sac_ligado ? `${r.chamados} com chamado no SAC` : 'SAC do ERP não ligado'),
    kpi(r.respostas, 'Respostas enviadas', 'mensagens dela ao cliente'),
    kpi(r.pendencias, 'Não fez ou deu problema', `${d.sugestoes_novas} sugestão(ões) nova(s)`),
  ].join('');
  document.querySelectorAll('#sv-tabs button').forEach((b) => {
    b.classList.toggle('active', b.dataset.f === state.filter);
    const n = b.dataset.f === 'feedback' ? d.sugestoes_novas : (b.dataset.f === 'problems' ? d.contagem.problems : 0);
    b.querySelector('b').textContent = n || '';
  });
  const fb = state.filter === 'feedback';
  $('#sv-table-wrap').hidden = fb;
  $('#sv-feedback').hidden = !fb;
  if (fb) return;
  const list = d.conversas;
  if (!list.length) {
    const empty = { all: `A Jack não atendeu ninguém ${periodo}.`, now: 'Ninguém com a Jack agora.', problems: `Nada pendente ${periodo}.`, replies: `A Jack não mandou mensagens ${periodo}.` };
    $('#sv-list').innerHTML = `<tr><td colspan="7" class="sv-empty">${esc(state.q ? 'Nada encontrado com essa busca.' : empty[state.filter])}</td></tr>`;
    return;
  }
  $('#sv-list').innerHTML = list.map((c) => {
    const pend = c.pendencias.length ? `<small class="sv-pcount">${c.pendencias.length} pendência(s)</small>` : '';
    const last = c.fazendo || c.procedimento[c.procedimento.length - 1] || '—';
    return `<tr class="sv-row" data-id="${c.conversation_id}" tabindex="0" role="button" aria-label="Ver atendimento de ${esc(c.contato)}">
      <td>${badge(c.situacao)}${pend}</td><td class="nowrap">${when(c.desde)}</td><td><b>${esc(c.contato)}</b></td>
      <td class="nowrap">${esc(c.protocolo)}</td><td>${esc(c.ideia.rotulo)}</td><td class="sv-last">${esc(last)}</td><td class="r">${c.respostas}</td></tr>`;
  }).join('') + (list.length >= d.limite ? `<tr><td colspan="7" class="sv-empty">Mostrando os ${d.limite} mais recentes. Use a busca para achar outros.</td></tr>` : '');
}

// ------------------------------------------------------------------ janela do atendimento

const kindLabel = { cliente: 'Cliente', ideia: 'Ideia', procedimento: 'Procedimento', resposta: 'Resposta', equipe: 'Equipe' };

async function openDialog(id) {
  state.current = id;
  const dlg = $('#sv-dialog');
  $('#sv-d-title').textContent = 'Carregando…';
  $('#sv-d-sub').textContent = '';
  $('#sv-d-body').innerHTML = '';
  $('#sv-fb-error').hidden = true;
  if (!dlg.open) dlg.showModal();
  let c;
  try { c = await api(`/api/jack/service/${id}`); } catch (e) { $('#sv-d-title').textContent = e.message; return; }
  $('#sv-d-title').innerHTML = `${esc(c.contato)} ${badge(c.situacao)}`;
  const chamado = c.chamado ? (c.chamado.url ? `<a class="link" href="${esc(c.chamado.url)}" target="_blank" rel="noopener">${esc(c.chamado.numero)}</a>` : esc(c.chamado.numero)) : null;
  $('#sv-d-sub').innerHTML = [esc(c.protocolo), `início ${when(c.desde)}`, c.setor && `setor ${esc(c.setor)}`, c.atendente && `com ${esc(c.atendente)}`, chamado && `chamado ${chamado}`].filter(Boolean).join(' · ');
  $('#sv-d-open').href = `/?c=${c.conversation_id}`;
  const steps = c.procedimento.length ? c.procedimento.map((s) => `<span class="sv-step">${esc(s)}</span>`).join('<i aria-hidden="true">›</i>') : '<span class="meta">Nenhum passo ainda.</span>';
  const pend = c.pendencias.map((p) => `<li class="sv-p-${p.nivel}">${esc(p.texto)}</li>`).join('');
  const timeline = c.passos.map((p) => `<li class="sv-t-${p.tipo}${p.falhou ? ' failed' : ''}"><time>${when(p.quando)}</time>
      <div><b>${esc(p.tipo === 'resposta' || p.tipo === 'equipe' ? p.titulo : kindLabel[p.tipo])}</b>
      <p>${p.tipo === 'resposta' ? jackText(p.texto) : esc(p.texto).replace(/\n/g, '<br>')}</p>${terms(p.termos)}
      ${p.entrega ? `<small class="meta">${esc(p.entrega)}</small>` : ''}</div></li>`).join('');
  const sugg = c.sugestoes.map(fbItem).join('');
  $('#sv-d-body').innerHTML = `
    ${c.fazendo ? `<p class="sv-doing"><b>Fazendo agora:</b> ${esc(c.fazendo)}</p>` : ''}
    <dl class="sv-grid">
      <dt>Ideia</dt><dd>${esc(c.ideia.explicacao)}${terms(c.ideia.termos)}</dd>
      <dt>Procedimento</dt><dd class="sv-steps">${steps}</dd>
      ${pend ? `<dt>Não fez</dt><dd><ul class="sv-pend">${pend}</ul></dd>` : ''}
    </dl>
    <h3>Passo a passo</h3>
    <ol class="sv-timeline">${timeline || '<li>Sem passos registrados.</li>'}</ol>
    ${sugg ? `<h3>Sugestões já feitas</h3><ul class="sv-fb-list">${sugg}</ul>` : ''}`;
}

function fbItem(f) {
  const [l, cls] = fbSitu[f.situacao] || fbSitu.nova;
  const actions = state.isSupervisor
    ? `<span class="sv-fb-actions">${f.situacao !== 'aplicada' ? `<button type="button" class="ghost sm" data-fb="${f.id}" data-s="aplicada">Marcar aplicada</button>` : ''}${f.situacao !== 'descartada' ? `<button type="button" class="ghost sm" data-fb="${f.id}" data-s="descartada">Descartar</button>` : ''}${f.situacao !== 'nova' ? `<button type="button" class="ghost sm" data-fb="${f.id}" data-s="nova">Voltar para nova</button>` : ''}</span>`
    : '';
  const conv = f.conversation_id ? `<button type="button" class="link sv-fb-conv" data-id="${f.conversation_id}">${esc(f.protocolo)} · ${esc(f.contato)}</button>` : '';
  return `<li><header><span class="sv-badge ${cls}">${l}</span><b>${esc(f.tipo_rotulo)}</b>${f.setor_certo ? `<span class="tag">setor certo: ${esc(f.setor_certo)}</span>` : ''}
    <small class="meta">${esc(f.autor)} · ${when(f.quando)}</small>${conv}</header><p>${esc(f.texto)}</p>${actions}</li>`;
}

async function loadFeedback() {
  try {
    const list = await api(`/api/jack/feedback?status=${encodeURIComponent(state.fbStatus)}`);
    $('#sv-fb-list').innerHTML = list.map(fbItem).join('') || '<li class="sv-empty">Nenhuma sugestão aqui.</li>';
  } catch (e) { $('#sv-fb-list').innerHTML = `<li class="sv-empty">${esc(e.message)}</li>`; }
}

// ------------------------------------------------------------------ eventos

$('#sv-list').addEventListener('click', (e) => { const tr = e.target.closest('.sv-row'); if (tr) openDialog(Number(tr.dataset.id)); });
$('#sv-list').addEventListener('keydown', (e) => {
  const tr = e.target.closest('.sv-row');
  if (tr && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openDialog(Number(tr.dataset.id)); }
});
$('#sv-d-close').addEventListener('click', () => $('#sv-dialog').close());
$('#sv-dialog').addEventListener('click', (e) => { if (e.target === e.currentTarget) e.currentTarget.close(); });

$('#sv-fb-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.currentTarget;
  const data = Object.fromEntries(new FormData(f));
  try {
    await api(`/api/jack/service/${state.current}/feedback`, { method: 'POST', body: JSON.stringify(data) });
    f.reset();
    openDialog(state.current);
    load();
  } catch (err) { $('#sv-fb-error').hidden = false; $('#sv-fb-error').textContent = err.message; }
});

document.addEventListener('click', async (e) => {
  const b = e.target.closest('[data-fb]');
  if (b) {
    try { await api(`/api/jack/feedback/${b.dataset.fb}/status`, { method: 'POST', body: JSON.stringify({ status: b.dataset.s }) }); } catch (err) { alertInline(err.message); return; }
    if ($('#sv-dialog').open) openDialog(state.current);
    load();
    return;
  }
  const conv = e.target.closest('.sv-fb-conv');
  if (conv) openDialog(Number(conv.dataset.id));
});
function alertInline(msg) { $('#sv-error').hidden = false; $('#sv-error').textContent = msg; }

$('#sv-days').addEventListener('click', (e) => {
  const b = e.target.closest('[data-d]');
  if (!b) return;
  state.days = Number(b.dataset.d);
  document.querySelectorAll('#sv-days button').forEach((x) => x.classList.toggle('on', x === b));
  load();
});
$('#sv-tabs').addEventListener('click', (e) => { const b = e.target.closest('[data-f]'); if (b) { state.filter = b.dataset.f; load(); } });
$('#sv-fb-status').addEventListener('click', (e) => {
  const b = e.target.closest('[data-s]');
  if (!b) return;
  state.fbStatus = b.dataset.s;
  document.querySelectorAll('#sv-fb-status button').forEach((x) => x.classList.toggle('on', x === b));
  loadFeedback();
});
let typing = null;
$('#sv-search').addEventListener('input', (e) => { clearTimeout(typing); typing = setTimeout(() => { state.q = e.target.value.trim(); load(); }, 300); });

state.isSupervisor = document.body.dataset.supervisor === '1';
load();
setInterval(() => { if (!document.hidden && !$('#sv-dialog').open) load(); }, 15000);
