// Avisos: o mesmo sino da web (GET /api/notifications): fila, transferências, não lidas, agendamentos e avaliações.
import { useCallback, useEffect, useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Talk } from '../../lib/api';
import { useDebounced, useRealtime } from '../../lib/realtime';
import { colors } from '../../lib/theme';
import { Empty, LargeTitle } from '../../components/ui';

const ICON = {
  queue: 'people-outline',
  transfer: 'swap-horizontal',
  unread: 'chatbubble-ellipses-outline',
  schedules: 'alarm-outline',
  channels: 'cloud-offline-outline',
  rating: 'star-outline',
};

/** O link do aviso na web vira uma tela do app. */
function openHref(href) {
  const c = /[?&]c=(\d+)/.exec(href || '');
  if (c) return router.push(`/conversa/${c[1]}`);
  if ((href || '').startsWith('/fila')) return router.navigate('/');
  if ((href || '').includes('aba=mine')) return router.navigate('/meus');
  return null;
}

export default function Avisos() {
  const insets = useSafeAreaInsets();
  const [items, setItems] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      setItems((await Talk.notifications()).items || []);
      setError(null);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, []);
  const later = useDebounced(load, 800);
  useEffect(() => {
    load();
  }, [load]);
  useRealtime((type) => {
    if (type !== 'message.ack') later();
  });

  return (
    <View style={{ flex: 1, backgroundColor: colors.bg, paddingTop: insets.top + 52 }}>
      <LargeTitle>Avisos</LargeTitle>
      <FlatList
        data={items || []}
        keyExtractor={(i) => i.key}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={load} tintColor={colors.primary} />}
        contentContainerStyle={{ paddingTop: 8, paddingBottom: 110 + insets.bottom }}
        ListEmptyComponent={
          error ? <Empty title="Sem conexão" text={error} /> : items ? <Empty title="Tudo em dia" text="Nenhum aviso agora." /> : null
        }
        renderItem={({ item }) => (
          <Pressable onPress={() => openHref(item.href)} style={({ pressed }) => [styles.row, pressed && { backgroundColor: '#F4F6F5' }]}>
            <View style={styles.icon}>
              <Ionicons name={ICON[item.kind] || 'notifications-outline'} size={22} color={colors.primaryDark} />
            </View>
            <View style={styles.body}>
              <Text style={styles.title}>{item.title}</Text>
              {item.text ? <Text style={styles.text}>{item.text}</Text> : null}
            </View>
          </Pressable>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', gap: 14, paddingLeft: 18, paddingTop: 12 },
  icon: { width: 44, height: 44, borderRadius: 22, backgroundColor: colors.primarySoft, alignItems: 'center', justifyContent: 'center' },
  body: { flex: 1, paddingRight: 18, paddingBottom: 12, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: '#CDD5D0', gap: 2 },
  title: { fontSize: 16, fontWeight: '600', color: colors.ink },
  text: { fontSize: 15, lineHeight: 20, color: colors.muted },
});
