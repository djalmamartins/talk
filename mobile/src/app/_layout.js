// Raiz do app: quem não entrou vê o login; quem entrou vê as abas. Tocar numa notificação abre a conversa.
import { useEffect } from 'react';
import { ActivityIndicator, View } from 'react-native';
import { Stack, router } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import * as Notifications from 'expo-notifications';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { AuthProvider, useAuth } from '../lib/auth';
import { colors } from '../lib/theme';

function openFromNotification(response) {
  const id = response?.notification?.request?.content?.data?.conversation_id;
  if (id) router.push(`/conversa/${id}`);
}

function Root() {
  const { ready, me } = useAuth();

  useEffect(() => {
    if (!me) return undefined;
    // app fechado: a notificação que abriu o app
    const last = Notifications.getLastNotificationResponse();
    if (last) openFromNotification(last);
    const sub = Notifications.addNotificationResponseReceivedListener(openFromNotification);
    return () => sub.remove();
  }, [me]);

  if (!ready) {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.bg }}>
        <ActivityIndicator color={colors.primary} />
      </View>
    );
  }
  return (
    <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.bg } }}>
      <Stack.Protected guard={!me}>
        <Stack.Screen name="login" />
      </Stack.Protected>
      <Stack.Protected guard={!!me}>
        <Stack.Screen name="(tabs)" />
        <Stack.Screen name="conversa/[id]" />
      </Stack.Protected>
    </Stack>
  );
}

export default function Layout() {
  return (
    <SafeAreaProvider>
      <AuthProvider>
        <StatusBar style="dark" />
        <Root />
      </AuthProvider>
    </SafeAreaProvider>
  );
}
