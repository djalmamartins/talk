// Tempo real com o app aberto: o mesmo /events (SSE) da web, com o token no cabeçalho.
// O servidor fecha o stream a cada ~25 s e o app reconecta. A cada evento as telas recarregam o que mostram.
import { useEffect, useRef } from 'react';
import { AppState } from 'react-native';
import EventSource from 'react-native-sse';
import { session } from './api';

const TYPES = [
  'conversation.created',
  'conversation.updated',
  'conversation.transferred',
  'message.created',
  'message.ack',
  'message.media',
];

/**
 * Chama onEvent(type, data) a cada novidade que a pessoa pode ver. Fica ligado só com o app na frente
 * (em segundo plano quem avisa é a notificação). Também chama onEvent('open') ao conectar, para recarregar.
 */
export function useRealtime(onEvent) {
  const cb = useRef(onEvent);
  cb.current = onEvent;

  useEffect(() => {
    let es = null;
    const start = () => {
      const { server, token } = session();
      if (!server || !token || es) return;
      es = new EventSource(`${server}/events`, {
        headers: { Authorization: `Bearer ${token}` },
        pollingInterval: 1000,
        timeout: 0,
      });
      es.addEventListener('open', () => cb.current('open', null));
      for (const t of TYPES) {
        es.addEventListener(t, (ev) => {
          let data = null;
          try {
            data = JSON.parse(ev.data);
          } catch {
            data = null;
          }
          cb.current(t, data);
        });
      }
    };
    const stop = () => {
      if (es) {
        es.removeAllEventListeners();
        es.close();
        es = null;
      }
    };
    start();
    const sub = AppState.addEventListener('change', (s) => (s === 'active' ? start() : stop()));
    return () => {
      sub.remove();
      stop();
    };
  }, []);
}

/** Junta vários eventos seguidos numa recarga só. */
export function useDebounced(fn, ms = 400) {
  const t = useRef(null);
  const f = useRef(fn);
  f.current = fn;
  useEffect(() => () => clearTimeout(t.current), []);
  return () => {
    clearTimeout(t.current);
    t.current = setTimeout(() => f.current(), ms);
  };
}
