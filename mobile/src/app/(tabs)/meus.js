// Meus atendimentos: as conversas que estão comigo (GET /api/conversations?tab=mine), como a lista de mensagens.
import { useCallback, useEffect, useMemo, useState } from 'react';
import { FlatList, RefreshControl, ScrollView, StyleSheet, View } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Talk } from '../../lib/api';
import { useDebounced, useRealtime } from '../../lib/realtime';
import { colors } from '../../lib/theme';
import { displayName, listTime, plain } from '../../lib/format';
import { Badge, Chip, ConversationRow, Empty, LargeTitle, RoundButton } from '../../components/ui';

const FILTERS = [
  ['todos', 'Todos'],
  ['naolidas', 'Não lidas'],
  ['aguardando', 'Aguardando'],
  ['encerrados', 'Encerrados'],
];

export default function Meus() {
  const insets = useSafeAreaInsets();
  const [filter, setFilter] = useState('todos');
  const [rows, setRows] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const load = useCallback(async (f = filter) => {
    setLoading(true);
    try {
      setRows(await Talk.conversations(f === 'encerrados' ? 'closed' : 'mine'));
      setError(null);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, [filter]);
  const later = useDebounced(() => load());

  useEffect(() => {
    load(filter);
  }, [filter, load]);
  useRealtime((type) => {
    if (type !== 'message.ack') later();
  });

  const list = useMemo(() => {
    const all = rows || [];
    if (filter === 'naolidas') return all.filter((c) => Number(c.unread) > 0);
    if (filter === 'aguardando') return all.filter((c) => c.stage === 'waiting_customer' || c.stage === 'waiting_internal');
    return all;
  }, [rows, filter]);
  const unread = (rows || []).filter((c) => Number(c.unread) > 0).length;

  return (
    <View style={{ flex: 1, backgroundColor: colors.bg, paddingTop: insets.top }}>
      <View style={styles.top}>
        <RoundButton label="Atualizar" onPress={() => load()}>
          <Ionicons name="refresh" size={20} color={colors.ink} />
        </RoundButton>
      </View>
      <LargeTitle>Meus atendimentos</LargeTitle>
      <View>
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
          {FILTERS.map(([key, label]) => (
            <Chip
              key={key}
              label={key === 'naolidas' && unread && filter !== 'encerrados' ? `${label} ${unread}` : label}
              selected={filter === key}
              onPress={() => setFilter(key)}
            />
          ))}
        </ScrollView>
      </View>
      <FlatList
        data={list}
        keyExtractor={(c) => String(c.id)}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={() => load()} tintColor={colors.primary} />}
        contentContainerStyle={{ paddingBottom: 110 + insets.bottom }}
        ListEmptyComponent={
          error ? (
            <Empty title="Sem conexão" text={error} />
          ) : rows ? (
            <Empty title="Nada por aqui" text="Pegue uma conversa na Fila para começar." />
          ) : null
        }
        renderItem={({ item }) => {
          const n = Number(item.unread) || 0;
          return (
            <ConversationRow
              name={displayName(item.contact_name, item.phone)}
              phone={item.phone}
              time={listTime(item.last_message_at)}
              timeStyle={n ? { color: colors.primary, fontWeight: '600' } : null}
              preview={plain(item.last_body) || 'Sem mensagem'}
              footer={`${item.sector_name || 'Sem setor'} · ${item.protocol}${item.stage === 'waiting_customer' ? ' · aguardando cliente' : item.stage === 'waiting_internal' ? ' · aguardando retorno interno' : ''}`}
              right={n ? <Badge count={n} /> : null}
              onPress={() => router.push(`/conversa/${item.id}`)}
            />
          );
        }}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', justifyContent: 'space-between', paddingHorizontal: 18, paddingTop: 8 },
  chips: { gap: 8, paddingHorizontal: 18, paddingTop: 14, paddingBottom: 8 },
});
