// Abas do app: Fila, Meus, Avisos e Você, numa barra flutuante arredondada (padrão escolhido pelo Djalma em 27/11/2026).
import { useCallback, useEffect, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { Tabs } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Talk } from '../../lib/api';
import { useDebounced, useRealtime } from '../../lib/realtime';
import { colors } from '../../lib/theme';
import { Badge } from '../../components/ui';

const ICONS = {
  index: ['list', 'list-outline'],
  meus: ['chatbubble', 'chatbubble-outline'],
  avisos: ['notifications', 'notifications-outline'],
  voce: ['person-circle', 'person-circle-outline'],
};

function FloatingBar({ state, descriptors, navigation, counts }) {
  const insets = useSafeAreaInsets();
  return (
    <View style={[styles.bar, { bottom: Math.max(insets.bottom, 12) }]} accessibilityRole="tablist">
      {state.routes.map((route, i) => {
        const focused = state.index === i;
        const { options } = descriptors[route.key];
        const [on, off] = ICONS[route.name] || ['ellipse', 'ellipse-outline'];
        const count = counts[route.name] || 0;
        const press = () => {
          const ev = navigation.emit({ type: 'tabPress', target: route.key, canPreventDefault: true });
          if (!focused && !ev.defaultPrevented) navigation.navigate(route.name);
        };
        return (
          <Pressable
            key={route.key}
            accessibilityRole="tab"
            accessibilityState={{ selected: focused }}
            accessibilityLabel={count ? `${options.title}, ${count}` : options.title}
            onPress={press}
            style={[styles.item, focused && styles.itemOn]}
          >
            <Ionicons name={focused ? on : off} size={24} color={focused ? colors.primaryDark : colors.text} />
            <Text style={[styles.label, focused && styles.labelOn]}>{options.title}</Text>
            {count ? (
              <View style={styles.badge}>
                <Badge count={count} color={route.name === 'index' ? colors.late : colors.primary} />
              </View>
            ) : null}
          </Pressable>
        );
      })}
    </View>
  );
}

export default function TabsLayout() {
  const [counts, setCounts] = useState({});

  // números da barra: quem espera na fila, mensagens não lidas nas minhas e avisos do sino
  const refresh = useCallback(async () => {
    try {
      const [queue, mine, bell] = await Promise.all([Talk.queue(), Talk.conversations('mine'), Talk.notifications()]);
      setCounts({
        index: queue?.stats?.waiting || 0,
        meus: (mine || []).reduce((n, c) => n + (Number(c.unread) > 0 ? 1 : 0), 0),
        avisos: bell?.count || 0,
      });
    } catch {
      // sem rede: mantém os números
    }
  }, []);
  const later = useDebounced(refresh, 600);
  useEffect(() => {
    refresh();
  }, [refresh]);
  useRealtime(() => later());

  return (
    <Tabs
      screenOptions={{ headerShown: false, sceneStyle: { backgroundColor: colors.bg } }}
      tabBar={(props) => <FloatingBar {...props} counts={counts} />}
    >
      <Tabs.Screen name="index" options={{ title: 'Fila' }} />
      <Tabs.Screen name="meus" options={{ title: 'Meus' }} />
      <Tabs.Screen name="avisos" options={{ title: 'Avisos' }} />
      <Tabs.Screen name="voce" options={{ title: 'Você' }} />
    </Tabs>
  );
}

const styles = StyleSheet.create({
  bar: {
    position: 'absolute',
    left: 14,
    right: 14,
    height: 68,
    padding: 6,
    borderRadius: 34,
    backgroundColor: '#FFFFFF',
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: colors.line,
    flexDirection: 'row',
    gap: 4,
    shadowColor: '#111512',
    shadowOpacity: 0.14,
    shadowRadius: 24,
    shadowOffset: { width: 0, height: 8 },
    elevation: 10,
  },
  item: { flex: 1, borderRadius: 28, alignItems: 'center', justifyContent: 'center', gap: 2 },
  itemOn: { backgroundColor: colors.primarySoft },
  label: { fontSize: 12, fontWeight: '500', color: colors.text },
  labelOn: { color: colors.primaryDark, fontWeight: '600' },
  badge: { position: 'absolute', top: 2, left: '54%' },
});
