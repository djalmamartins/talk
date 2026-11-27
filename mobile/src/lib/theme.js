// Cores do Connect Talk (as mesmas da web: public/assets/app.css), no padrão de lista de mensagens do iPhone.
export const colors = {
  primary: '#12804A',
  primaryDark: '#0B5C34',
  primarySoft: '#E2F3E9',
  primaryLine: '#B9DFC9',
  gold: '#C9A227',
  ink: '#111512',
  text: '#1B241F',
  muted: '#5D6862',
  line: '#E3E8E5',
  chipLine: '#D5DCD8',
  bg: '#FFFFFF',
  chatBg: '#EEF1EF',
  bubbleIn: '#FFFFFF',
  bubbleOut: '#E2F3E9',
  note: '#FFF6D6',
  noteLine: '#D9C26A',
  late: '#B4471F',
  danger: '#C0392B',
};

// fundo e cor das iniciais (sempre a mesma cor para a mesma pessoa)
const AVATARS = [
  ['#E2F3E9', '#0B5C34'],
  ['#FBECD3', '#7A4B00'],
  ['#DCEFEA', '#1D5C4F'],
  ['#E5E2F6', '#3F3580'],
  ['#F6E1E1', '#7D2A2A'],
];
export function avatarColors(seed) {
  let h = 0;
  for (const ch of String(seed ?? '')) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
  return AVATARS[h % AVATARS.length];
}
