import { useEffect } from 'react';
import { Platform } from 'react-native';
import * as Notifications from 'expo-notifications';
import * as Device from 'expo-device';
import * as SecureStore from 'expo-secure-store';
import { router } from 'expo-router';
import { api } from '../api';
import { onBeforeSignOut } from '../auth/SessionProvider';

/**
 * Push registration and deep links.
 *
 * The server sends through Firebase Cloud Messaging (HTTP v1) straight to a
 * DEVICE token, so that is what is registered here — never an Expo push
 * token, which FCM would not recognise. On Android that is the FCM token and
 * works once google-services.json is in place. On iOS getDevicePushTokenAsync
 * returns an APNs token, which FCM v1 does not deliver to on its own: iOS
 * needs the Firebase iOS SDK to mint an FCM registration token, or a
 * server-side APNs sender. See README → Push.
 *
 * Tokens rotate on reinstall, restore and cleared data; a stale one means
 * the app goes silent. So the token is compared with the last one registered
 * on every launch and on the OS's own rotation event, and re-sent when it
 * differs. It is removed on sign-out.
 *
 * Only match.new and message.new are sent by the server today.
 */

const LAST_REGISTERED = 'push_token_registered';

Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowBanner: true,
    shouldShowList: true,
    shouldPlaySound: true,
    shouldSetBadge: false,
  }),
});

export function PushRegistrar() {
  useEffect(() => {
    // Push is a phone feature; expo-notifications has no web implementation.
    if (Platform.OS === 'web') return;

    let cancelled = false;

    async function register(tokenOverride?: string) {
      try {
        if (!Device.isDevice) return; // simulators have no push token

        const existing = await Notifications.getPermissionsAsync();
        const granted = existing.granted || (existing.canAskAgain && (await Notifications.requestPermissionsAsync()).granted);
        if (!granted) return;

        const token = tokenOverride ?? String((await Notifications.getDevicePushTokenAsync()).data);
        if (!token || cancelled) return;

        const last = await SecureStore.getItemAsync(LAST_REGISTERED);
        if (last === token) return;

        await api.registerPushToken({
          token,
          platform: Platform.OS === 'ios' ? 'ios' : 'android',
          label: [Device.brand, Device.modelName].filter(Boolean).join(' ') || undefined,
          device_fingerprint: Device.osBuildId ?? undefined,
        });

        await SecureStore.setItemAsync(LAST_REGISTERED, token);
      } catch {
        // Registration is best-effort; the next launch tries again.
      }
    }

    void register();

    const rotation = Notifications.addPushTokenListener((token) => void register(String(token.data)));

    const taps = Notifications.addNotificationResponseReceivedListener((response) => {
      openFromNotification(response.notification.request.content.data);
    });

    // Cold start from a tap: the response is waiting before any listener ran.
    void Notifications.getLastNotificationResponseAsync().then((response) => {
      if (response) {
        openFromNotification(response.notification.request.content.data);
        void Notifications.clearLastNotificationResponseAsync();
      }
    });

    const unhook = onBeforeSignOut(async () => {
      const token = await SecureStore.getItemAsync(LAST_REGISTERED);
      if (!token) return;
      await api.removePushToken(token);
      await SecureStore.deleteItemAsync(LAST_REGISTERED);
    });

    return () => {
      cancelled = true;
      rotation.remove();
      taps.remove();
      unhook();
    };
  }, []);

  return null;
}

/**
 * Where a tap lands. The server's `link` is the website URL for the same
 * thing; its path tells us the screen. The template is the fallback.
 */
function openFromNotification(data: Record<string, unknown> | undefined) {
  const link = typeof data?.link === 'string' ? data.link : '';
  const template = typeof data?.template === 'string' ? data.template : '';

  const thread = /\/app\/messages\/([0-9a-f-]{36})/i.exec(link);
  if (thread) {
    router.push({ pathname: '/thread/[uuid]', params: { uuid: thread[1] as string } });
    return;
  }

  if (link.includes('/app/matches') || template === 'match.new') {
    router.push('/(tabs)/matches');
    return;
  }

  if (template === 'message.new') {
    router.push('/(tabs)/messages');
  }
}
