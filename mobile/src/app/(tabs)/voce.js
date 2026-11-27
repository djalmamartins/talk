// Você: quem está no app, setores, papel, endereço do Talk e Sair.
import { Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import Constants from 'expo-constants';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useAuth } from '../../lib/auth';
import { colors } from '../../lib/theme';
import { Avatar, LargeTitle } from '../../components/ui';

export default function Voce() {
  const insets = useSafeAreaInsets();
  const { me, server, signOut } = useAuth();
  const user = me?.user || {};
  const sectorName = (id) => (me?.sectors || []).find((s) => Number(s.id) === Number(id))?.name;
  const role = user.supervisor ? 'Supervisor' : (user.manager_sectors || []).length ? 'Gerente de setor' : 'Atendente';
  const sectors = [...new Set([...(user.agent_sectors || []), ...(user.manager_sectors || [])])].map(sectorName).filter(Boolean);

  const confirmOut = () =>
    Alert.alert('Sair do Talk?', 'Este celular para de receber os avisos de atendimento.', [
      { text: 'Cancelar', style: 'cancel' },
      { text: 'Sair', style: 'destructive', onPress: signOut },
    ]);

  return (
    <ScrollView style={{ flex: 1, backgroundColor: colors.bg }} contentContainerStyle={{ paddingTop: insets.top + 52, paddingBottom: 120 + insets.bottom }}>
      <LargeTitle>Você</LargeTitle>
      <View style={styles.card}>
        <Avatar name={user.name} size={64} />
        <View style={{ flex: 1 }}>
          <Text style={styles.name}>{user.name}</Text>
          <Text style={styles.muted}>{role}</Text>
        </View>
      </View>
      <View style={styles.group}>
        <Line label="Setores" value={user.supervisor ? 'Todos' : sectors.join(', ') || 'Nenhum ainda'} />
        <Line label="Talk" value={server || ''} />
        <Line label="Versão do app" value={Constants.expoConfig?.version || ''} last />
      </View>
      <Pressable accessibilityRole="button" onPress={confirmOut} style={({ pressed }) => [styles.out, pressed && { opacity: 0.7 }]}>
        <Text style={styles.outText}>Sair</Text>
      </Pressable>
    </ScrollView>
  );
}

function Line({ label, value, last }) {
  return (
    <View style={[styles.line, last && { borderBottomWidth: 0 }]}>
      <Text style={styles.lineLabel}>{label}</Text>
      <Text style={styles.lineValue} numberOfLines={2}>
        {value}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  card: { flexDirection: 'row', alignItems: 'center', gap: 16, margin: 18, marginBottom: 8 },
  name: { fontSize: 20, fontWeight: '700', color: colors.ink },
  muted: { fontSize: 15, color: colors.muted },
  group: { marginHorizontal: 18, marginTop: 12, borderRadius: 16, borderWidth: 1, borderColor: colors.line, paddingHorizontal: 16 },
  line: { paddingVertical: 14, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: '#CDD5D0', gap: 2 },
  lineLabel: { fontSize: 13, color: colors.muted },
  lineValue: { fontSize: 16, color: colors.ink },
  out: { margin: 18, marginTop: 24, height: 52, borderRadius: 14, borderWidth: 1, borderColor: '#E7C3BE', alignItems: 'center', justifyContent: 'center' },
  outText: { fontSize: 17, fontWeight: '600', color: colors.danger },
});
