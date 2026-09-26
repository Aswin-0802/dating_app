import * as SecureStore from 'expo-secure-store';

/**
 * The bearer token lives in the device keychain / keystore, never in
 * AsyncStorage. So does the one other secret the app keeps, briefly: the
 * password of a member who is still `pending`, held only until the server
 * promotes them and the token is swapped, then deleted (see session.ts).
 */
const TOKEN = 'auth_token';
const PENDING_PASSWORD = 'pending_password';
const PENDING_EMAIL = 'pending_email';

export const tokenStore = {
  getToken: () => SecureStore.getItemAsync(TOKEN),
  setToken: (token: string) => SecureStore.setItemAsync(TOKEN, token),
  clearToken: () => SecureStore.deleteItemAsync(TOKEN),

  getPendingCredentials: async (): Promise<{ email: string; password: string } | null> => {
    const [email, password] = await Promise.all([
      SecureStore.getItemAsync(PENDING_EMAIL),
      SecureStore.getItemAsync(PENDING_PASSWORD),
    ]);
    return email && password ? { email, password } : null;
  },
  setPendingCredentials: async (email: string, password: string) => {
    await Promise.all([SecureStore.setItemAsync(PENDING_EMAIL, email), SecureStore.setItemAsync(PENDING_PASSWORD, password)]);
  },
  clearPendingCredentials: async () => {
    await Promise.all([SecureStore.deleteItemAsync(PENDING_EMAIL), SecureStore.deleteItemAsync(PENDING_PASSWORD)]);
  },

  clearAll: async () => {
    await Promise.all([
      SecureStore.deleteItemAsync(TOKEN),
      SecureStore.deleteItemAsync(PENDING_EMAIL),
      SecureStore.deleteItemAsync(PENDING_PASSWORD),
    ]);
  },
};
