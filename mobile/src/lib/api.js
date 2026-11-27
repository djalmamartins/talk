// Conversa com o servidor do Talk (o mesmo PHP da web). O app manda o token do aparelho em
// Authorization: Bearer; sem cookie e sem CSRF (ver docs/app-movel.md no repositório).
import * as SecureStore from 'expo-secure-store';

const KEY_TOKEN = 'talk.token';
const KEY_SERVER = 'talk.server';

let state = { server: null, token: null };
let onUnauthorized = () => {};

export function setUnauthorizedHandler(fn) {
  onUnauthorized = fn;
}

export async function loadSession() {
  const [server, token] = await Promise.all([
    SecureStore.getItemAsync(KEY_SERVER),
    SecureStore.getItemAsync(KEY_TOKEN),
  ]);
  state = { server, token };
  return state;
}

export async function saveServer(server) {
  state.server = normalizeServer(server);
  await SecureStore.setItemAsync(KEY_SERVER, state.server);
  return state.server;
}

export async function saveToken(token) {
  state.token = token;
  await SecureStore.setItemAsync(KEY_TOKEN, token);
}

export async function clearToken() {
  state.token = null;
  await SecureStore.deleteItemAsync(KEY_TOKEN);
}

export function session() {
  return state;
}

/** https://talk.connect.com.br, sem barra no fim. Endereço sem https só é aceito no laboratório (.lab, IP local). */
export function normalizeServer(input) {
  let s = String(input || '').trim().replace(/\/+$/, '');
  if (!/^https?:\/\//i.test(s)) s = 'https://' + s;
  return s;
}

export class ApiError extends Error {
  constructor(message, status) {
    super(message);
    this.status = status;
  }
}

export async function api(path, { method = 'GET', body, form, auth = true } = {}) {
  if (!state.server) throw new ApiError('Informe o endereço do Talk.', 0);
  const headers = { Accept: 'application/json' };
  if (auth && state.token) headers.Authorization = `Bearer ${state.token}`;
  let payload;
  if (form) {
    payload = form; // multipart: o fetch põe o Content-Type com o boundary
  } else if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }
  let res;
  try {
    res = await fetch(state.server + path, { method, headers, body: payload });
  } catch {
    throw new ApiError('Sem conexão com o Talk. Confira a internet.', 0);
  }
  let data = null;
  try {
    data = await res.json();
  } catch {
    data = null;
  }
  if (res.status === 401 && auth) {
    onUnauthorized();
  }
  if (!res.ok) {
    throw new ApiError((data && data.error) || `Erro ${res.status}`, res.status);
  }
  return data;
}

/** Endereço de um arquivo da conversa, com o token (para <Image source={...}>). */
export function mediaSource(messageId) {
  return {
    uri: `${state.server}/media/${messageId}`,
    headers: { Authorization: `Bearer ${state.token}` },
  };
}

export const Talk = {
  config: () => api('/api/app/config', { auth: false }),
  redeem: (code, st, device, platform) =>
    api('/api/app/token', { method: 'POST', auth: false, body: { code, state: st, device, platform } }),
  passwordLogin: (email, password, device, platform) =>
    api('/api/app/login', { method: 'POST', auth: false, body: { email, password, device, platform } }),
  logout: () => api('/api/app/logout', { method: 'POST' }),
  registerPush: (pushToken) => api('/api/app/push', { method: 'POST', body: { push_token: pushToken } }),
  me: () => api('/api/me'),
  queue: () => api('/api/queue'),
  conversations: (tab) => api(`/api/conversations?tab=${encodeURIComponent(tab)}`),
  notifications: () => api('/api/notifications'),
  conversation: (id) => api(`/api/conversations/${id}`),
  accept: (id) => api(`/api/conversations/${id}/accept`, { method: 'POST', body: {} }),
  reply: (id, text) => api(`/api/conversations/${id}/reply`, { method: 'POST', body: { text } }),
  note: (id, note) => api(`/api/conversations/${id}/notes`, { method: 'POST', body: { note } }),
  read: (id) => api(`/api/conversations/${id}/read`, { method: 'POST', body: {} }),
  close: (id) => api(`/api/conversations/${id}/close`, { method: 'POST', body: {} }),
  setStatus: (id, status) => api(`/api/conversations/${id}/status`, { method: 'POST', body: { status } }),
  transfer: (id, sectorId, note, userId) =>
    api(`/api/conversations/${id}/transfer`, {
      method: 'POST',
      body: { sector_id: sectorId, note, user_id: userId || null },
    }),
  quickMessages: () => api('/api/quick-messages'),
  attach: (id, file) => {
    const form = new FormData();
    form.append('file', { uri: file.uri, name: file.name, type: file.mimeType || 'application/octet-stream' });
    return api(`/api/conversations/${id}/attach`, { method: 'POST', form });
  },
};
