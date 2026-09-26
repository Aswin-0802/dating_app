import Constants from 'expo-constants';
import { ApiClient } from './client';
import { makeApi } from './endpoints';
import type { ApiError } from './errors';
import { tokenStore } from '../auth/tokenStore';

/**
 * The app's single API instance.
 *
 * Base URL comes from EXPO_PUBLIC_API_URL (see .env.example). On a physical
 * device that must be the machine's LAN address, not localhost.
 */
export const API_BASE_URL = (process.env.EXPO_PUBLIC_API_URL ?? 'http://10.0.2.2:8000/api/v1').replace(/\/+$/, '');

export const APP_VERSION = Constants.expoConfig?.version ?? '0.0.0';

type ErrorListener = (error: ApiError) => void;

const listeners = new Set<ErrorListener>();

/** The session provider subscribes here to route 401 / 403 / 503 / 429 globally. */
export function onApiError(listener: ErrorListener): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

export const client = new ApiClient({
  baseUrl: API_BASE_URL,
  appVersion: APP_VERSION,
  getToken: () => tokenStore.getToken(),
  onError: (error) => listeners.forEach((listener) => listener(error)),
});

export const api = makeApi(client);
