// Fila de atendimento: quem espera, por setor, com o tempo de espera e o botão Atender (GET /api/queue).
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Alert, FlatList, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Talk } from '../../lib/api';
import { useDebounced, useRealtime } from '../../lib/realtime';
import { colors } from '../../lib/theme';
import { displayName, plain, waitLabel } from '../../lib/format';
import { Chip, ConversationRow, Empty, LargeTitle, RoundButton } from '../../components/ui';

export default function Fila() {
  const insets = useSafeAreaInsets();
  const [data, setData] = useState(null);
  const [sector, setSector] = useState(null);
  const [loading, setLoading] = useState(false);
  const [live, setLive] = useState(false);
  const [error, setError] = useState(null);
  const [tick, setTick] = useState(0); // o tempo de espera anda sozinho

  const load = useCallback(async () => {
    setLoading(true);
    try {
      setData(await Talk.queue());
      setError(null);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, []);
  const later = useDebounced(load);

  useEffect(() => {
    load();
    const t = setInterval(() => setTick((n) => n + 1), 30000);
    return () => clearInterval(t);
  }, [load]);
  useRealtime((type) => {
    if (type === 'open') setLive(true);
    if (type === 'open' || type.startsWith('conversation.') || type === 'message.created') later();
  });

  const waiting = useMemo(() => {
    const all = data?.waiting || [];
    return sector ? all.filter((w) => Number(w.sector_id) === sector) : all;
  }, [data, sector]);
  const sectors = (data?.distribution || []).filter((d) => d.waiting > 0 || d.sector_id === sector);
  const stats = data?.stats;
  const online = (data?.attendants || []).filter((a) => a.online).length;
  const elapsed = Math.floor((tick * 30000) / 1000);

  const take = async (item) => {
    try {
      await Talk.accept(item.id);
      router.push(`/conversa/${item.id}`);
    } catch (e) {
      Alert.alert('Não deu para atender', e.message);
      load();
    }
  };

  const subtitle = stats
    ? `${stats.waiting} esperando · espera média ${waitLabel(stats.avg_wait_seconds)} · ${online} online`
    : ' ';

  return (
    <View style={{ flex: 1, backgroundColor: colors.bg, paddingTop: insets.top }}>
      <View style={styles.top}>
        <RoundButton label="Atualizar" onPress={load}>
          <Ionicons name="refresh" size={20} color={colors.ink} />
        </RoundButton>
        <RoundButton label={live ? 'Conectado em tempo real' : 'Conectando'} wide>
          <View style={[styles.dot, { backgroundColor: live ? colors.primary : '#B8C1BC' }]} />
          <Text style={styles.status}>{live ? 'Online' : 'Conectando'}</Text>
        </RoundButton>
      </View>
      <LargeTitle subtitle={subtitle}>Fila</LargeTitle>

      <View>
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
          <Chip label="Todos" selected={sector === null} onPress={() => setSector(null)} />
          {sectors.map((s) => (
            <Chip
              key={s.sector_id}
              label={`${s.name} ${s.waiting}`}
              selected={sector === s.sector_id}
              onPress={() => setSector(sector === s.sector_id ? null : s.sector_id)}
            />
          ))}
        </ScrollView>
      </View>

      <FlatList
        data={waiting}
        keyExtractor={(w) => String(w.id)}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={load} tintColor={colors.primary} />}
        contentContainerStyle={{ paddingBottom: 110 + insets.bottom }}
        ListEmptyComponent={
          error ? (
            <Empty title="Sem conexão" text={error} />
          ) : data ? (
            <Empty title="Ninguém esperando" text="Quando alguém chegar, você recebe um aviso." />
          ) : null
        }
        renderItem={({ item }) => {
          const secs = Number(item.wait_seconds) + (item.paused ? 0 : elapsed);
          const late = data?.thresholds && secs >= data.thresholds.warn_minutes * 60;
          return (
            <ConversationRow
              name={displayName(item.contact_name, item.phone)}
              phone={item.phone}
              time={waitLabel(secs)}
              timeStyle={late ? { color: colors.late, fontWeight: '700' } : null}
              preview={plain(item.subject) || 'Sem mensagem'}
              footer={`${item.sector_name || 'Sem setor'} · ${item.who}`}
              onPress={() => router.push(`/conversa/${item.id}`)}
              right={
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel={`Atender ${displayName(item.contact_name, item.phone)}`}
                  onPress={() => take(item)}
                  style={({ pressed }) => [styles.take, pressed && { opacity: 0.7 }]}
                >
                  <Text style={styles.takeText}>Atender</Text>
                </Pressable>
              }
            />
          );
        }}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', justifyContent: 'space-between', paddingHorizontal: 18, paddingTop: 8 },
  dot: { width: 10, height: 10, borderRadius: 5 },
  status: { fontSize: 15, fontWeight: '600', color: colors.ink },
  chips: { gap: 8, paddingHorizontal: 18, paddingTop: 14, paddingBottom: 8 },
  take: { height: 34, paddingHorizontal: 14, borderRadius: 17, backgroundColor: colors.primary, justifyContent: 'center', alignSelf: 'center' },
  takeText: { color: '#FFFFFF', fontSize: 14, fontWeight: '600' },
});
