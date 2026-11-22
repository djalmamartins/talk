// Connect Talk — Serviço da Jack (JS puro, sem build). Só leitura: atualiza a cada 15 s.
const $ = (s, el = document) => el.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const state = { days: 1, filter: 'all', q: '', open: new Set() };

const when = (s) => {
  if (!s) return '';
  const d = new Date(s.replace(' ', 'T'));
  const today = new Date();
  const hm = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
  return d.toDateString() === today.toDateString() ? hm : `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')} ${hm}`;
};
const situ = {
  agora: ['Fazendo agora', 'now'],
  erro: ['Deu problema', 'err'],
  aviso: ['Não acertou', 'warn'],
  feito: ['Feito', 'ok'],
};
const jackText = (t) => esc(String(t ?? '').replace(/^\*Jack:\*\n?/, '')).replace(/\*([^*\n]+)\*/g, '<b>$1</b>').replace(/\n/g, '<br>');

function kpi(value, label, sub) {
  return `<div class="kpi"><div><b>${value}</b><span>${esc(label)}</span>${sub ? `<small>${esc(sub)}</small>` : ''}</div></div>`;
}

async function load() {
  const q = new URLSearchParams({ days: state.days, filter: state.filter, q: state.q });
  const res = await fetch(`/api/jack/service?${q}`);
  if (res.status === 401) { location.href = '/login'; return; }
  const d = await res.json();
  $('#sv-error').hidden = res.ok;
  if (!res.ok) { $('#sv-error').textContent = d.error || 'Erro ao carregar.'; return; }
  render(d);
  const now = new Date();
  $('#sv-updated').textContent = `Atualizado às ${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}:${String(now.getSeconds()).padStart(2, '0')} · atualiza sozinho a cada 15 s`;
}

function render(d) {
  $('#sv-off').hidden = d.modo !== 'off';
  const r = d.resumo;
  const periodo = d.dias === 1 ? 'hoje' : `últimos ${d.dias} dias`;
  $('#sv-kpis').innerHTML = [
    kpi(r.agora, 'Com a Jack agora', 'esperando ela encaminhar'),
    kpi(r.conversas, 'Conversas que ela atendeu', periodo),
    kpi(r.encaminhadas, 'Encaminhadas por ela', d.sac_ligado ? `${r.chamados} com chamado no SAC` : 'SAC do ERP não ligado'),
    kpi(r.respostas, 'Respostas enviadas', 'mensagens dela ao cliente'),
    kpi(r.pendencias, 'Não fez ou deu problema', r.pendencias ? 'veja na aba ao lado' : 'nada pendente'),
  ].join('');
  document.querySelectorAll('#sv-tabs button').forEach((b) => {
    b.classList.toggle('active', b.dataset.f === state.filter);
    const n = d.contagem[b.dataset.f];
    b.querySelector('b').textContent = b.dataset.f === 'problems' && n ? n : '';
  });
  const list = d.conversas;
  if (!list.length) {
    const empty = { all: `A Jack não atendeu nenhuma conversa ${periodo}.`, now: 'Nenhuma conversa com a Jack agora.', problems: `Nada pendente ${periodo}.`, replies: `A Jack não mandou mensagens ${periodo}.` };
    $('#sv-list').innerHTML = `<li class="sv-empty">${esc(state.q ? 'Nada encontrado com essa busca.' : empty[state.filter])}</li>`;
    return;
  }
  $('#sv-list').innerHTML = list.map(item).join('') + (list.length >= d.limite ? `<li class="sv-empty">Mostrando as ${d.limite} mais recentes. Use a busca para achar outras.</li>` : '');
  for (const id of state.open) {
    const li = $(`#sv-list li[data-id="${id}"]`);
    if (li) openDetail(li, false);
  }
}

function item(c) {
  const [label, cls] = situ[c.situacao] || situ.feito;
  const pend = c.pendencias.map((p) => `<li class="sv-p-${p.nivel}">${esc(p.texto)}</li>`).join('');
  const steps = c.procedimento.length ? c.procedimento.map((s) => `<span class="sv-step">${esc(s)}</span>`).join('<i aria-hidden="true">›</i>') : '<span class="meta">Nenhum passo ainda.</span>';
  const reply = c.ultima_resposta
    ? `<blockquote class="sv-reply${c.ultima_resposta.falhou ? ' failed' : ''}">${jackText(c.ultima_resposta.texto)}<small>${esc(c.ultima_resposta.tipo)} · ${when(c.ultima_resposta.quando)} · ${esc(c.ultima_resposta.entrega)}${c.respostas > 1 ? ` · ${c.respostas} respostas na conversa` : ''}</small></blockquote>`
    : '<p class="meta">Não mandou mensagem ao cliente (encaminhou calada).</p>';
  const chamado = c.chamado ? (c.chamado.url ? `<a class="link" href="${esc(c.chamado.url)}" target="_blank" rel="noopener">${esc(c.chamado.numero)}</a>` : esc(c.chamado.numero)) : null;
  const where = [c.setor && `Setor: ${esc(c.setor)}`, c.atendente && `com ${esc(c.atendente)}`, chamado && `Chamado ${chamado}`].filter(Boolean).join(' · ');
  return `<li class="sv-item" data-id="${c.conversation_id}">
    <header>
      <span class="sv-badge ${cls}">${label}</span>
      <b>${esc(c.contato)}</b>
      <a class="link" href="/?c=${c.conversation_id}">${esc(c.protocolo)}</a>
      <small class="meta">${when(c.desde)}</small>
      <button type="button" class="ghost sm sv-more" aria-expanded="false">Ver passo a passo</button>
    </header>
    ${c.fazendo ? `<p class="sv-doing"><b>Fazendo agora:</b> ${esc(c.fazendo)}${c.ultima_do_cliente ? ` <span class="meta">Última mensagem: "${esc(c.ultima_do_cliente)}"</span>` : ''}</p>` : ''}
    <dl class="sv-grid">
      <dt>Ideia</dt><dd>${esc(c.ideia.explicacao)}${c.ideia.termos.length ? `<div class="sv-terms">${c.ideia.termos.map((t) => `<span class="tag">${esc(t)}</span>`).join(' ')}</div>` : ''}</dd>
      <dt>Procedimento</dt><dd class="sv-steps">${steps}</dd>
      <dt>Resposta</dt><dd>${reply}</dd>
      ${pend ? `<dt>Não fez</dt><dd><ul class="sv-pend">${pend}</ul></dd>` : ''}
    </dl>
    ${where ? `<p class="meta sv-where">${where}</p>` : ''}
    <ol class="sv-timeline" hidden></ol>
  </li>`;
}

const kindLabel = { cliente: 'Cliente', ideia: 'Ideia', procedimento: 'Procedimento', resposta: 'Resposta', equipe: 'Equipe' };

async function openDetail(li, toggle = true) {
  const ol = $('.sv-timeline', li);
  const btn = $('.sv-more', li);
  const id = Number(li.dataset.id);
  if (toggle && !ol.hidden) {
    ol.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
    btn.textContent = 'Ver passo a passo';
    state.open.delete(id);
    return;
  }
  state.open.add(id);
  btn.setAttribute('aria-expanded', 'true');
  btn.textContent = 'Fechar passo a passo';
  const res = await fetch(`/api/jack/service/${id}`);
  const d = await res.json();
  ol.hidden = false;
  if (!res.ok) { ol.innerHTML = `<li>${esc(d.error || 'Erro ao carregar.')}</li>`; return; }
  ol.innerHTML = d.passos.map((p) => `<li class="sv-t-${p.tipo}${p.falhou ? ' failed' : ''}">
      <time>${when(p.quando)}</time>
      <div><b>${esc(p.tipo === 'resposta' || p.tipo === 'equipe' ? p.titulo : kindLabel[p.tipo])}</b>
      <p>${p.tipo === 'resposta' ? jackText(p.texto) : esc(p.texto).replace(/\n/g, '<br>')}</p>
      ${p.termos && p.termos.length ? `<div class="sv-terms">${p.termos.map((t) => `<span class="tag">${esc(t)}</span>`).join(' ')}</div>` : ''}
      ${p.entrega ? `<small class="meta">${esc(p.entrega)}</small>` : ''}</div></li>`).join('') || '<li>Sem passos registrados.</li>';
}

$('#sv-list').addEventListener('click', (e) => {
  const btn = e.target.closest('.sv-more');
  if (btn) openDetail(btn.closest('li'));
});
$('#sv-days').addEventListener('click', (e) => {
  const b = e.target.closest('[data-d]');
  if (!b) return;
  state.days = Number(b.dataset.d);
  document.querySelectorAll('#sv-days button').forEach((x) => x.classList.toggle('on', x === b));
  load();
});
$('#sv-tabs').addEventListener('click', (e) => {
  const b = e.target.closest('[data-f]');
  if (!b) return;
  state.filter = b.dataset.f;
  load();
});
let typing = null;
$('#sv-search').addEventListener('input', (e) => {
  clearTimeout(typing);
  typing = setTimeout(() => { state.q = e.target.value.trim(); load(); }, 300);
});

load();
setInterval(() => { if (!document.hidden) load(); }, 15000);
