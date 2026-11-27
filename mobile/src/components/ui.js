// Peças repetidas das telas: foto (iniciais), filtro em pílula, botão redondo do topo, título grande, linha da lista.
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { avatarColors, colors } from '../lib/theme';
import { initials } from '../lib/format';

export function Avatar({ name, phone, size = 54 }) {
  const [bg, ink] = avatarColors(name || phone);
  return (
    <View style={[styles.avatar, { width: size, height: size, borderRadius: size / 2, backgroundColor: bg }]}>
      <Text style={[styles.avatarText, { color: ink, fontSize: size * 0.33 }]}>{initials(name, phone)}</Text>
    </View>
  );
}

export function Chip({ label, selected, onPress }) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ selected }}
      onPress={onPress}
      style={[styles.chip, selected ? styles.chipOn : null]}
    >
      <Text style={[styles.chipText, selected ? styles.chipTextOn : null]}>{label}</Text>
    </Pressable>
  );
}

export function RoundButton({ label, onPress, children, wide }) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      onPress={onPress}
      style={({ pressed }) => [styles.round, wide ? styles.roundWide : null, pressed ? { opacity: 0.6 } : null]}
    >
      {children}
    </Pressable>
  );
}

export function LargeTitle({ children, subtitle }) {
  return (
    <View style={styles.titleBox}>
      <Text accessibilityRole="header" style={styles.title}>
        {children}
      </Text>
      {subtitle ? <Text style={styles.subtitle}>{subtitle}</Text> : null}
    </View>
  );
}

export function Badge({ count, color = colors.primary }) {
  if (!count) return null;
  return (
    <View style={[styles.badge, { backgroundColor: color }]}>
      <Text style={styles.badgeText}>{count > 99 ? '99+' : count}</Text>
    </View>
  );
}

export function Empty({ title, text }) {
  return (
    <View style={styles.empty}>
      <Text style={styles.emptyTitle}>{title}</Text>
      {text ? <Text style={styles.emptyText}>{text}</Text> : null}
    </View>
  );
}

/** Linha da lista: foto, nome e hora, prévia em até duas linhas, rodapé e um elemento à direita. */
export function ConversationRow({ name, phone, time, timeStyle, preview, footer, right, onPress }) {
  return (
    <Pressable onPress={onPress} style={({ pressed }) => [styles.row, pressed ? { backgroundColor: '#F4F6F5' } : null]}>
      <Avatar name={name} phone={phone} />
      <View style={styles.rowBody}>
        <View style={styles.rowTop}>
          <Text numberOfLines={1} style={styles.rowName}>
            {name}
          </Text>
          <Text style={[styles.rowTime, timeStyle]}>{time}</Text>
        </View>
        <View style={styles.rowMid}>
          <Text numberOfLines={2} style={styles.rowPreview}>
            {preview}
          </Text>
          {right}
        </View>
        {footer ? (
          <Text numberOfLines={1} style={styles.rowFooter}>
            {footer}
          </Text>
        ) : null}
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  avatar: { alignItems: 'center', justifyContent: 'center' },
  avatarText: { fontWeight: '700' },
  chip: {
    height: 36,
    paddingHorizontal: 16,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.chipLine,
    backgroundColor: colors.bg,
    justifyContent: 'center',
  },
  chipOn: { backgroundColor: colors.primarySoft, borderColor: colors.primaryLine },
  chipText: { fontSize: 15, color: '#4F5A54', fontWeight: '500' },
  chipTextOn: { color: colors.primaryDark, fontWeight: '600' },
  round: {
    minWidth: 44,
    height: 44,
    borderRadius: 22,
    borderWidth: 1,
    borderColor: colors.line,
    backgroundColor: colors.bg,
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: '#111512',
    shadowOpacity: 0.08,
    shadowRadius: 6,
    shadowOffset: { width: 0, height: 2 },
    elevation: 2,
  },
  roundWide: { paddingHorizontal: 16, flexDirection: 'row', gap: 8 },
  titleBox: { paddingHorizontal: 18, paddingTop: 14 },
  title: { fontSize: 34, fontWeight: '800', color: colors.ink, letterSpacing: -0.5 },
  subtitle: { marginTop: 4, fontSize: 15, color: colors.muted },
  badge: {
    minWidth: 22,
    height: 22,
    paddingHorizontal: 6,
    borderRadius: 11,
    alignItems: 'center',
    justifyContent: 'center',
  },
  badgeText: { color: '#FFFFFF', fontSize: 12, fontWeight: '700' },
  empty: { padding: 40, alignItems: 'center', gap: 6 },
  emptyTitle: { fontSize: 17, fontWeight: '600', color: colors.text },
  emptyText: { fontSize: 15, color: colors.muted, textAlign: 'center' },
  row: { flexDirection: 'row', gap: 14, paddingLeft: 18, paddingTop: 12 },
  rowBody: {
    flex: 1,
    minWidth: 0,
    paddingRight: 18,
    paddingBottom: 12,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: '#CDD5D0',
    gap: 3,
  },
  rowTop: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'baseline', gap: 8 },
  rowName: { flex: 1, fontSize: 17, fontWeight: '600', color: colors.ink },
  rowTime: { fontSize: 14, color: colors.muted },
  rowMid: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', gap: 10 },
  rowPreview: { flex: 1, fontSize: 15, lineHeight: 20, color: colors.muted },
  rowFooter: { marginTop: 2, fontSize: 13, color: '#4F5A54' },
});
