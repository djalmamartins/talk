// Datas e textos curtos das listas, como no app de mensagens: "10:41", "Ontem", "domingo", "20/11/2026".
const DAYS = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];

/** "2026-11-27 10:41:00" (horário do servidor) → Date local. */
export function parseDb(value) {
  if (!value) return null;
  const d = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? null : d;
}

export function listTime(value, now = new Date()) {
  const d = parseDb(value);
  if (!d) return '';
  const day = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
  const diff = Math.round((day(now) - day(d)) / 86400000);
  if (diff <= 0) return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
  if (diff === 1) return 'Ontem';
  if (diff < 7) return DAYS[d.getDay()];
  return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;
}

export function clock(value) {
  const d = parseDb(value);
  return d ? `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}` : '';
}

/** Espera na fila: "agora", "4 min", "1 h 05". */
export function waitLabel(seconds) {
  const s = Math.max(0, Number(seconds) || 0);
  if (s < 60) return 'agora';
  const m = Math.floor(s / 60);
  if (m < 60) return `${m} min`;
  return `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')}`;
}

export function initials(name, phone) {
  const n = String(name || '').trim();
  if (!n) return String(phone || '').slice(-2) || '?';
  const parts = n.split(/\s+/);
  return ((parts[0]?.[0] || '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}

export function displayName(name, phone) {
  const n = String(name || '').trim();
  if (n) return n;
  const p = String(phone || '');
  return p ? `+${p}` : 'Cliente';
}

/** Tira a marcação do WhatsApp (*negrito*, _itálico_, ~riscado~) para a prévia da lista. */
export function plain(text) {
  return String(text || '').replace(/[*_~]([^*_~\n]+)[*_~]/g, '$1').replace(/\s+/g, ' ').trim();
}
