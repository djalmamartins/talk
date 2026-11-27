// Quem está no app e como entra/sai.
// - Login pelo Connect ERP (produção): abre o navegador do sistema em {Talk}/app/entrar?state=…; o ERP confere senha,
//   2FA e a permissão do Talk e o Talk devolve connecttalk://entrar?code=…&state=…; o app troca o código pelo token.
// - Login com senha do Talk (laboratório, TALK_LOGIN=senha): e-mail e senha direto no app.
import { createContext, createElement, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { Platform } from 'react-native';
import * as WebBrowser from 'expo-web-browser';
import * as Linking from 'expo-linking';
import * as Crypto from 'expo-crypto';
import * as Device from 'expo-device';
import {
  Talk,
  clearToken,
  loadSession,
  saveServer,
  saveToken,
  session,
  setUnauthorizedHandler,
} from './api';
import { registerForPush } from './push';

const AuthContext = createContext(null);

function deviceName() {
  return [Device.manufacturer, Device.modelName].filter(Boolean).join(' ').slice(0, 100) || Platform.OS;
}

function randomState() {
  const bytes = Crypto.getRandomBytes(24);
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

export function AuthProvider({ children }) {
  const [ready, setReady] = useState(false);
  const [server, setServer] = useState(null);
  const [me, setMe] = useState(null); // resposta de /api/me: user, sectors, users, staff

  const signOutLocal = useCallback(async () => {
    await clearToken();
    setMe(null);
  }, []);

  const loadMe = useCallback(async () => {
    const data = await Talk.me();
    setMe(data);
    registerForPush().catch(() => {}); // sem permissão de notificação o app funciona do mesmo jeito
    return data;
  }, []);

  useEffect(() => {
    setUnauthorizedHandler(() => {
      signOutLocal();
    });
    (async () => {
      const s = await loadSession();
      setServer(s.server);
      if (s.token) {
        try {
          await loadMe();
        } catch {
          // sem rede: fica na tela de login só se o token não vale mais (401 já limpou)
        }
      }
      setReady(true);
    })();
  }, [loadMe, signOutLocal]);

  const connectServer = useCallback(async (address) => {
    const s = await saveServer(address);
    setServer(s);
    return Talk.config();
  }, []);

  const signInWithErp = useCallback(async () => {
    const st = randomState();
    const back = Linking.createURL('entrar');
    const result = await WebBrowser.openAuthSessionAsync(
      `${session().server}/app/entrar?state=${st}`,
      back,
    );
    if (result.type !== 'success') {
      return false; // a pessoa fechou o navegador
    }
    const { queryParams } = Linking.parse(result.url);
    if (!queryParams?.code || queryParams.state !== st) {
      throw new Error('O login não foi concluído. Tente de novo.');
    }
    const r = await Talk.redeem(String(queryParams.code), st, deviceName(), Platform.OS);
    await saveToken(r.token);
    await loadMe();
    return true;
  }, [loadMe]);

  const signInWithPassword = useCallback(
    async (email, password) => {
      const r = await Talk.passwordLogin(email, password, deviceName(), Platform.OS);
      await saveToken(r.token);
      await loadMe();
    },
    [loadMe],
  );

  const signOut = useCallback(async () => {
    try {
      await Talk.logout(); // o servidor esquece o token e o endereço de notificação deste aparelho
    } catch {
      // sem rede: sai do mesmo jeito; o token vence sozinho
    }
    await signOutLocal();
  }, [signOutLocal]);

  const value = useMemo(
    () => ({ ready, server, me, connectServer, signInWithErp, signInWithPassword, signOut, reloadMe: loadMe }),
    [ready, server, me, connectServer, signInWithErp, signInWithPassword, signOut, loadMe],
  );
  return createElement(AuthContext.Provider, { value }, children);
}

export function useAuth() {
  return useContext(AuthContext);
}
