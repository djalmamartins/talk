// Notificações no celular. O servidor do Talk manda pelo serviço do Expo (que entrega pela Apple e pelo Google);
// aqui o app pede a permissão, pega o endereço do aparelho e avisa o Talk.
import { Platform } from 'react-native';
import Constants from 'expo-constants';
import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import { Talk } from './api';

// com o app aberto, mostra o aviso no topo e toca o som (o atendente precisa ver a fila)
Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowBanner: true,
    shouldShowList: true,
    shouldPlaySound: true,
    shouldSetBadge: true,
  }),
});

export async function registerForPush() {
  if (!Device.isDevice) {
    return null; // simulador não recebe push
  }
  if (Platform.OS === 'android') {
    await Notifications.setNotificationChannelAsync('atendimento', {
      name: 'Atendimento',
      importance: Notifications.AndroidImportance.HIGH,
      sound: 'default',
      vibrationPattern: [0, 250, 250, 250],
    });
  }
  let { status } = await Notifications.getPermissionsAsync();
  if (status !== 'granted') {
    ({ status } = await Notifications.requestPermissionsAsync());
  }
  if (status !== 'granted') {
    return null;
  }
  const projectId = Constants.expoConfig?.extra?.eas?.projectId ?? Constants.easConfig?.projectId;
  if (!projectId) {
    console.warn('[talk] sem projectId do EAS: rode "npx eas-cli init" (docs/app-movel.md)');
    return null;
  }
  const { data } = await Notifications.getExpoPushTokenAsync({ projectId });
  await Talk.registerPush(data);
  return data;
}
