// Entrar: endereço do Talk (uma vez só) e o botão "Entrar pelo Connect ERP".
// No laboratório (TALK_LOGIN=senha) aparecem e-mail e senha do Talk.
import { useEffect, useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Constants from 'expo-constants';
import { useAuth } from '../lib/auth';
import { colors } from '../lib/theme';
import { ConnectMark } from '../components/ConnectMark';

export default function Login() {
  const insets = useSafeAreaInsets();
  const { server, connectServer, signInWithErp, signInWithPassword } = useAuth();
  const [address, setAddress] = useState(server || Constants.expoConfig?.extra?.talkUrl || '');
  const [mode, setMode] = useState(null); // 'erp' | 'senha' | null (ainda não conferiu o endereço)
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  const check = async (value = address) => {
    setError(null);
    setBusy(true);
    try {
      const cfg = await connectServer(value);
      setMode(cfg.login);
    } catch (e) {
      setMode(null);
      setError(e.message || 'Não encontrei o Talk neste endereço.');
    } finally {
      setBusy(false);
    }
  };

  useEffect(() => {
    if (address) check(address);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const run = async (fn) => {
    setError(null);
    setBusy(true);
    try {
      await fn();
    } catch (e) {
      setError(e.message || 'Não foi possível entrar.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: colors.bg }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView contentContainerStyle={{ flexGrow: 1 }} keyboardShouldPersistTaps="handled">
        <View style={[styles.hero, { paddingTop: insets.top + 56 }]}>
          <View style={styles.logo}>
            <ConnectMark size={52} color={colors.primary} />
          </View>
          <Text style={styles.brand}>Connect Talk</Text>
          <Text style={styles.tagline}>Atendimento da Connect</Text>
        </View>

        <View style={styles.form}>
          <View style={styles.field}>
            <Text style={styles.label} nativeID="lbl-server">
              Endereço do Talk
            </Text>
            <TextInput
              accessibilityLabelledBy="lbl-server"
              value={address}
              onChangeText={(t) => {
                setAddress(t);
                setMode(null);
              }}
              onSubmitEditing={() => check()}
              placeholder="talk.connect.com.br"
              autoCapitalize="none"
              autoCorrect={false}
              keyboardType="url"
              style={styles.input}
            />
          </View>

          {mode === null ? (
            <Pressable accessibilityRole="button" disabled={busy || !address} onPress={() => check()} style={[styles.button, (busy || !address) && styles.disabled]}>
              <Text style={styles.buttonText}>Continuar</Text>
            </Pressable>
          ) : null}

          {mode === 'erp' ? (
            <Pressable accessibilityRole="button" disabled={busy} onPress={() => run(signInWithErp)} style={[styles.button, busy && styles.disabled]}>
              <Text style={styles.buttonText}>Entrar pelo Connect ERP</Text>
            </Pressable>
          ) : null}

          {mode === 'senha' ? (
            <>
              <View style={styles.field}>
                <Text style={styles.label} nativeID="lbl-email">
                  E-mail
                </Text>
                <TextInput
                  accessibilityLabelledBy="lbl-email"
                  value={email}
                  onChangeText={setEmail}
                  autoCapitalize="none"
                  autoCorrect={false}
                  keyboardType="email-address"
                  textContentType="username"
                  style={styles.input}
                />
              </View>
              <View style={styles.field}>
                <Text style={styles.label} nativeID="lbl-senha">
                  Senha
                </Text>
                <TextInput
                  accessibilityLabelledBy="lbl-senha"
                  value={password}
                  onChangeText={setPassword}
                  secureTextEntry
                  textContentType="password"
                  onSubmitEditing={() => run(() => signInWithPassword(email, password))}
                  style={styles.input}
                />
              </View>
              <Pressable
                accessibilityRole="button"
                disabled={busy}
                onPress={() => run(() => signInWithPassword(email, password))}
                style={[styles.button, busy && styles.disabled]}
              >
                <Text style={styles.buttonText}>Entrar</Text>
              </Pressable>
            </>
          ) : null}

          {busy ? <ActivityIndicator color={colors.primary} /> : null}
          {error ? (
            <Text accessibilityLiveRegion="polite" style={styles.error}>
              {error}
            </Text>
          ) : null}
        </View>

        <Text style={[styles.foot, { paddingBottom: insets.bottom + 24 }]}>
          Só para funcionários da Connect com acesso ao Talk liberado no ERP.
        </Text>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  hero: {
    backgroundColor: colors.primary,
    alignItems: 'center',
    paddingBottom: 44,
    borderBottomLeftRadius: 32,
    borderBottomRightRadius: 32,
    gap: 6,
  },
  logo: {
    width: 88,
    height: 88,
    borderRadius: 22,
    backgroundColor: '#FFFFFF',
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 10,
  },
  brand: { fontSize: 28, fontWeight: '700', color: '#FFFFFF' },
  tagline: { fontSize: 15, color: colors.primarySoft },
  form: { padding: 28, gap: 18 },
  field: { gap: 6 },
  label: { fontSize: 14, fontWeight: '600', color: colors.text },
  input: {
    height: 50,
    borderWidth: 1,
    borderColor: '#CFD8D3',
    borderRadius: 12,
    paddingHorizontal: 14,
    fontSize: 16,
    color: colors.text,
  },
  button: {
    height: 52,
    borderRadius: 12,
    backgroundColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
  },
  disabled: { opacity: 0.5 },
  buttonText: { color: '#FFFFFF', fontSize: 17, fontWeight: '600' },
  error: { color: colors.danger, fontSize: 15, textAlign: 'center' },
  foot: { marginTop: 'auto', paddingHorizontal: 32, fontSize: 13, lineHeight: 20, color: colors.muted, textAlign: 'center' },
});
