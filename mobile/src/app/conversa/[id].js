// Conversa: mensagens, resposta (com "/" para respostas rápidas), anexo, nota interna, Atender, Transferir,
// Aguardando cliente e Encerrar. Usa as mesmas rotas da web: /api/conversations/{id}/…
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  FlatList,
  Image,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import * as DocumentPicker from 'expo-document-picker';
import * as ImagePicker from 'expo-image-picker';
import { Talk, mediaSource } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { useDebounced, useRealtime } from '../../lib/realtime';
import { colors } from '../../lib/theme';
import { clock, displayName } from '../../lib/format';
import { Avatar, Chip, RoundButton } from '../../components/ui';

const FILE_LABEL = { image: 'Foto', audio: 'Áudio', video: 'Vídeo', document: 'Documento' };

export default function Conversa() {
  const { id } = useLocalSearchParams();
  const convId = Number(id);
  const insets = useSafeAreaInsets();
  const { me } = useAuth();
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [text, setText] = useState('');
  const [noteMode, setNoteMode] = useState(false);
  const [sending, setSending] = useState(false);
  const [quick, setQuick] = useState([]);
  const [transferOpen, setTransferOpen] = useState(false);
  const readSent = useRef(false);

  const load = useCallback(async () => {
    try {
      const d = await Talk.conversation(convId);
      setData(d);
      setError(null);
      if (!readSent.current && d.conversation?.assigned_user_id === me?.user?.id && Number(d.conversation?.unread) > 0) {
        readSent.current = true;
        Talk.read(convId).catch(() => {});
      }
    } catch (e) {
      setError(e.message);
    }
  }, [convId, me]);
  const later = useDebounced(load, 250);

  useEffect(() => {
    load();
    Talk.quickMessages()
      .then((q) => setQuick(Array.isArray(q) ? q : q?.items || []))
      .catch(() => {});
  }, [load]);
  useRealtime((type, ev) => {
    if (type === 'open' || (ev && Number(ev.conversation_id) === convId)) {
      readSent.current = false;
      later();
    }
  });

  const conv = data?.conversation;
  const contact = data?.contact;
  const name = displayName(contact?.name, contact?.phone);
  const mine = conv && conv.status === 'open' && Number(conv.assigned_user_id) === Number(me?.user?.id);
  const canAct = !!data?.can_act;
  const messages = useMemo(() => [...(data?.messages || [])].reverse(), [data]); // lista invertida: a mais nova embaixo
  const userName = (uid) => (me?.users || {})[uid] || '';

  const act = async (fn, after) => {
    try {
      await fn();
      if (after) after();
      load();
    } catch (e) {
      Alert.alert('Não deu certo', e.message);
    }
  };

  const send = async () => {
    const t = text.trim();
    if (!t || sending) return;
    setSending(true);
    try {
      if (noteMode) await Talk.note(convId, t);
      else await Talk.reply(convId, t);
      setText('');
      setNoteMode(false);
      load();
    } catch (e) {
      Alert.alert('Mensagem não enviada', e.message);
    } finally {
      setSending(false);
    }
  };

  const attach = () =>
    Alert.alert('Enviar arquivo', undefined, [
      {
        text: 'Foto da galeria',
        onPress: async () => {
          const r = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], quality: 0.8 });
          if (!r.canceled) upload({ uri: r.assets[0].uri, name: r.assets[0].fileName || 'foto.jpg', mimeType: r.assets[0].mimeType || 'image/jpeg' });
        },
      },
      {
        text: 'Documento',
        onPress: async () => {
          const r = await DocumentPicker.getDocumentAsync({ copyToCacheDirectory: true });
          if (!r.canceled) upload(r.assets[0]);
        },
      },
      { text: 'Cancelar', style: 'cancel' },
    ]);

  const upload = async (file) => {
    setSending(true);
    try {
      await Talk.attach(convId, file);
      load();
    } catch (e) {
      Alert.alert('Arquivo não enviado', e.message);
    } finally {
      setSending(false);
    }
  };

  const close = () =>
    Alert.alert('Encerrar o atendimento?', 'O cliente recebe a mensagem de encerramento e a avaliação.', [
      { text: 'Cancelar', style: 'cancel' },
      { text: 'Encerrar', style: 'destructive', onPress: () => act(() => Talk.close(convId), () => router.back()) },
    ]);

  // respostas rápidas: "/" no começo filtra pelo atalho
  const slash = text.startsWith('/') ? text.slice(1).toLowerCase() : null;
  const quickHits =
    slash === null
      ? []
      : quick.filter((q) => `${q.shortcut || ''} ${q.title || ''}`.toLowerCase().includes(slash)).slice(0, 6);

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: colors.chatBg }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <View style={[styles.header, { paddingTop: insets.top + 6 }]}>
        <Pressable accessibilityRole="button" accessibilityLabel="Voltar" onPress={() => router.back()} style={styles.back}>
          <Ionicons name="chevron-back" size={28} color={colors.primary} />
        </Pressable>
        <Avatar name={contact?.name} phone={contact?.phone} size={40} />
        <View style={{ flex: 1, minWidth: 0 }}>
          <Text numberOfLines={1} style={styles.hName}>
            {name}
          </Text>
          <Text numberOfLines={1} style={styles.hSub}>
            {[conv?.sector_name || sectorLabel(me, conv?.sector_id), conv?.protocol].filter(Boolean).join(' · ')}
          </Text>
        </View>
      </View>

      <View style={styles.actionsBox}>
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.actions}>
          {conv?.status === 'pending' && canAct ? (
            <Chip label="Atender" selected onPress={() => act(() => Talk.accept(convId))} />
          ) : null}
          {canAct && conv?.status !== 'closed' ? <Chip label="Transferir" onPress={() => setTransferOpen(true)} /> : null}
          {mine && conv?.stage !== 'waiting_customer' ? (
            <Chip label="Aguardando cliente" onPress={() => act(() => Talk.setStatus(convId, 'waiting_customer'))} />
          ) : null}
          {mine ? <Chip label="Encerrar" onPress={close} /> : null}
          {conv?.status === 'closed' && canAct ? <Chip label="Reabrir" onPress={() => act(() => Talk.setStatus(convId, 'open'))} /> : null}
        </ScrollView>
      </View>

      {error && !data ? (
        <View style={styles.center}>
          <Text style={styles.hSub}>{error}</Text>
        </View>
      ) : !data ? (
        <View style={styles.center}>
          <ActivityIndicator color={colors.primary} />
        </View>
      ) : (
        <FlatList
          inverted
          data={messages}
          keyExtractor={(m) => String(m.id)}
          contentContainerStyle={{ padding: 12, gap: 8 }}
          renderItem={({ item }) => <Bubble m={item} author={userName(item.author_user_id)} />}
        />
      )}

      {quickHits.length ? (
        <View style={styles.quick}>
          {quickHits.map((q) => (
            <Pressable key={q.id} onPress={() => setText(q.body || '')} style={styles.quickRow}>
              <Text style={styles.quickKey}>/{q.shortcut}</Text>
              <Text numberOfLines={1} style={styles.quickBody}>
                {q.body}
              </Text>
            </Pressable>
          ))}
        </View>
      ) : null}

      {conv && (mine || (canAct && conv.status !== 'closed')) ? (
        <View style={[styles.composer, { paddingBottom: Math.max(insets.bottom, 10) }]}>
          <RoundButton label="Anexar arquivo ou foto" onPress={attach}>
            <Ionicons name="attach" size={22} color={colors.text} />
          </RoundButton>
          <TextInput
            value={text}
            onChangeText={setText}
            placeholder={noteMode ? 'Nota interna (só o time vê)' : 'Mensagem ou / para respostas rápidas'}
            accessibilityLabel={noteMode ? 'Nota interna' : 'Mensagem'}
            multiline
            style={[styles.input, noteMode && { backgroundColor: colors.note, borderColor: colors.noteLine }]}
          />
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={noteMode ? 'Escrever para o cliente' : 'Escrever nota interna'}
            onPress={() => setNoteMode((v) => !v)}
            style={styles.iconBtn}
          >
            <Ionicons name={noteMode ? 'lock-closed' : 'lock-closed-outline'} size={22} color={noteMode ? '#6B5A12' : colors.muted} />
          </Pressable>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Enviar"
            disabled={!text.trim() || sending}
            onPress={send}
            style={[styles.send, (!text.trim() || sending) && { opacity: 0.4 }]}
          >
            {sending ? <ActivityIndicator color="#FFFFFF" /> : <Ionicons name="send" size={20} color="#FFFFFF" />}
          </Pressable>
        </View>
      ) : (
        <View style={{ height: insets.bottom }} />
      )}

      <TransferSheet
        open={transferOpen}
        me={me}
        currentSector={conv?.sector_id}
        onClose={() => setTransferOpen(false)}
        onSend={(sectorId, userId, note) =>
          act(() => Talk.transfer(convId, sectorId, note, userId), () => {
            setTransferOpen(false);
            router.back();
          })
        }
      />
    </KeyboardAvoidingView>
  );
}

function sectorLabel(me, sectorId) {
  return (me?.sectors || []).find((s) => Number(s.id) === Number(sectorId))?.name;
}

function Bubble({ m, author }) {
  if (m.direction === 'internal') {
    if (m.type === 'note') {
      return (
        <View style={styles.note}>
          <Text style={styles.noteHead}>Nota interna{author ? ` · ${author}` : ''}</Text>
          <Text style={styles.msgText}>{m.body}</Text>
        </View>
      );
    }
    return (
      <View style={styles.system}>
        <Text style={styles.systemText}>{m.body}</Text>
      </View>
    );
  }
  const out = m.direction === 'out';
  const isImage = m.type === 'image' && m.media_path;
  return (
    <View style={[styles.bubble, out ? styles.out : styles.in]}>
      {isImage ? <Image source={mediaSource(m.id)} style={styles.image} resizeMode="cover" accessibilityLabel="Foto" /> : null}
      {!isImage && m.type !== 'text' && m.type !== 'rating' ? (
        <View style={styles.file}>
          <Ionicons name={m.type === 'audio' ? 'mic' : 'document-text-outline'} size={24} color={colors.late} />
          <Text numberOfLines={1} style={styles.fileName}>
            {m.media_name || FILE_LABEL[m.type] || 'Arquivo'}
          </Text>
        </View>
      ) : null}
      {m.body && !(m.type === 'document' && m.body === m.media_name) ? <Text style={styles.msgText}>{m.body}</Text> : null}
      <Text style={styles.time}>
        {clock(m.created_at)}
        {out ? (m.ack === 'read' ? '  ✓✓' : m.ack === 'delivered' ? '  ✓✓' : m.ack === 'failed' ? '  falhou' : m.ack === 'pending' ? '  …' : '  ✓') : ''}
      </Text>
    </View>
  );
}

function TransferSheet({ open, me, currentSector, onClose, onSend }) {
  const insets = useSafeAreaInsets();
  const [sector, setSector] = useState(null);
  const [user, setUser] = useState(null);
  const [note, setNote] = useState('');
  useEffect(() => {
    if (open) {
      setSector(null);
      setUser(null);
      setNote('');
    }
  }, [open]);
  const people = (me?.staff || []).filter((s) => Number(s.sector_id) === Number(sector) && Number(s.user_id) !== Number(me?.user?.id));
  const uniq = [...new Map(people.map((p) => [p.user_id, p])).values()];

  return (
    <Modal visible={open} animationType="slide" transparent onRequestClose={onClose}>
      <Pressable style={styles.backdrop} onPress={onClose} accessibilityLabel="Fechar" />
      <View style={[styles.sheet, { paddingBottom: insets.bottom + 16 }]}>
        <Text style={styles.sheetTitle}>Transferir</Text>
        <Text style={styles.sheetLabel}>Para o setor</Text>
        <View style={styles.wrap}>
          {(me?.sectors || [])
            .filter((s) => Number(s.id) !== Number(currentSector))
            .map((s) => (
              <Chip
                key={s.id}
                label={s.name}
                selected={Number(sector) === Number(s.id)}
                onPress={() => {
                  setSector(s.id);
                  setUser(null);
                }}
              />
            ))}
        </View>
        {sector && uniq.length ? (
          <>
            <Text style={styles.sheetLabel}>Para alguém (opcional)</Text>
            <View style={styles.wrap}>
              <Chip label="Fila do setor" selected={!user} onPress={() => setUser(null)} />
              {uniq.map((p) => (
                <Chip key={p.user_id} label={p.name} selected={Number(user) === Number(p.user_id)} onPress={() => setUser(p.user_id)} />
              ))}
            </View>
          </>
        ) : null}
        <Text style={styles.sheetLabel}>Motivo</Text>
        <TextInput value={note} onChangeText={setNote} maxLength={500} multiline placeholder="Conte para quem vai receber" style={styles.sheetInput} />
        <Pressable
          accessibilityRole="button"
          disabled={!sector}
          onPress={() => onSend(Number(sector), user ? Number(user) : null, note.trim() || null)}
          style={[styles.sheetButton, !sector && { opacity: 0.4 }]}
        >
          <Text style={styles.sheetButtonText}>Transferir</Text>
        </Pressable>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  header: { backgroundColor: '#FFFFFF', flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 6, paddingBottom: 8 },
  back: { width: 40, height: 44, alignItems: 'center', justifyContent: 'center' },
  hName: { fontSize: 17, fontWeight: '600', color: colors.ink },
  hSub: { fontSize: 13, color: colors.muted },
  actionsBox: { backgroundColor: '#FFFFFF', borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: '#CDD5D0' },
  actions: { gap: 8, paddingHorizontal: 14, paddingBottom: 10, paddingTop: 2 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 },
  bubble: { maxWidth: '80%', borderRadius: 14, paddingHorizontal: 12, paddingVertical: 8, gap: 4 },
  in: { alignSelf: 'flex-start', backgroundColor: colors.bubbleIn, borderTopLeftRadius: 4 },
  out: { alignSelf: 'flex-end', backgroundColor: colors.bubbleOut, borderTopRightRadius: 4 },
  msgText: { fontSize: 15, lineHeight: 21, color: colors.text },
  time: { alignSelf: 'flex-end', fontSize: 11, color: colors.muted },
  image: { width: 220, height: 220, borderRadius: 10, backgroundColor: '#DDE3E0' },
  file: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: '#FFFFFF', borderRadius: 10, padding: 10, maxWidth: 240 },
  fileName: { flexShrink: 1, fontSize: 14, fontWeight: '600', color: colors.text },
  note: { alignSelf: 'stretch', backgroundColor: colors.note, borderWidth: 1, borderStyle: 'dashed', borderColor: colors.noteLine, borderRadius: 12, padding: 10, gap: 4 },
  noteHead: { fontSize: 12, fontWeight: '600', color: '#6B5A12' },
  system: { alignSelf: 'center', backgroundColor: '#FFFFFF', borderRadius: 12, paddingHorizontal: 12, paddingVertical: 6, maxWidth: '90%' },
  systemText: { fontSize: 13, color: '#4F5A54', textAlign: 'center' },
  quick: { backgroundColor: '#FFFFFF', borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: '#CDD5D0' },
  quickRow: { paddingHorizontal: 16, paddingVertical: 10, flexDirection: 'row', gap: 10 },
  quickKey: { fontSize: 14, fontWeight: '700', color: colors.primaryDark },
  quickBody: { flex: 1, fontSize: 14, color: colors.text },
  composer: { backgroundColor: '#FFFFFF', flexDirection: 'row', alignItems: 'flex-end', gap: 6, paddingHorizontal: 10, paddingTop: 10, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: '#CDD5D0' },
  input: { flex: 1, minHeight: 44, maxHeight: 120, borderWidth: 1, borderColor: '#CFD8D3', borderRadius: 22, paddingHorizontal: 16, paddingTop: 11, paddingBottom: 11, fontSize: 15, color: colors.text, backgroundColor: '#F6F7F6' },
  iconBtn: { width: 40, height: 44, alignItems: 'center', justifyContent: 'center' },
  send: { width: 44, height: 44, borderRadius: 22, backgroundColor: colors.primary, alignItems: 'center', justifyContent: 'center' },
  backdrop: { flex: 1, backgroundColor: 'rgba(17,21,18,0.35)' },
  sheet: { backgroundColor: '#FFFFFF', borderTopLeftRadius: 24, borderTopRightRadius: 24, padding: 20, gap: 10 },
  sheetTitle: { fontSize: 22, fontWeight: '700', color: colors.ink },
  sheetLabel: { fontSize: 14, fontWeight: '600', color: colors.text, marginTop: 6 },
  wrap: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  sheetInput: { minHeight: 70, borderWidth: 1, borderColor: '#CFD8D3', borderRadius: 12, padding: 12, fontSize: 15, textAlignVertical: 'top' },
  sheetButton: { marginTop: 8, height: 52, borderRadius: 14, backgroundColor: colors.primary, alignItems: 'center', justifyContent: 'center' },
  sheetButtonText: { color: '#FFFFFF', fontSize: 17, fontWeight: '600' },
});
