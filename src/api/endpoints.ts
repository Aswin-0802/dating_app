import type { ApiClient } from './client';
import type {
  AuthResponse,
  City,
  Config,
  Conversation,
  Country,
  CursorPage,
  Gender,
  Interest,
  Match,
  Me,
  Message,
  Person,
  Photo,
  Plan,
  Preferences,
  Profile,
  ReportCategory,
  State,
  SwipeAction,
  SwipeResponse,
  Verification,
} from './types';

/**
 * Every endpoint in routes/api.php, typed. Thirty-nine in all. Nothing here
 * decides anything: blocking, like limits, matching, promotion and
 * restrictions are all the server's call, and this file only asks.
 */
export function makeApi(client: ApiClient) {
  return {
    // ---- public ---------------------------------------------------------
    config: () => client.get<Config>('config', { anonymous: true }),
    plans: () => client.get<{ data: Plan[] }>('plans', { anonymous: true }),
    interests: () => client.get<{ data: Interest[] }>('interests', { anonymous: true }),
    countries: () => client.get<{ data: Country[] }>('countries', { anonymous: true }),
    states: (country: string) => client.get<{ data: State[] }>('states', { anonymous: true, query: { country } }),
    cities: (params: { country?: string; state?: number }) =>
      client.get<{ data: City[] }>('cities', { anonymous: true, query: params }),

    // ---- auth -----------------------------------------------------------
    register: (body: {
      display_name: string;
      email: string;
      password: string;
      birthdate: string;
      gender: Gender;
      interested_in: Gender[];
    }) => client.post<AuthResponse>('auth/register', { anonymous: true, body }),
    login: (email: string, password: string) =>
      client.post<AuthResponse>('auth/login', { anonymous: true, body: { email, password } }),
    logout: () => client.post<{ message: string }>('auth/logout'),
    logoutAll: () => client.post<{ message: string }>('auth/logout-all'),

    // ---- me -------------------------------------------------------------
    me: () => client.get<{ data: Me }>('me'),
    updateMe: (body: { display_name?: string; pronouns?: string | null; city_id?: number | null }) =>
      client.patch<{ data: Me }>('me', { body }),
    updateProfile: (body: Partial<Profile>) => client.patch<{ data: Me }>('me/profile', { body }),
    /**
     * age_min and age_max are always sent together: the server validates the
     * pair and refuses a lone value that would invert the range.
     */
    updatePreferences: (body: Partial<Preferences>) => client.patch<{ data: Me }>('me/preferences', { body }),
    syncInterests: (slugs: string[]) => client.put<{ data: Me }>('me/interests', { body: { slugs } }),
    deleteAccount: (password: string) => client.delete<{ message: string }>('me', { body: { password } }),

    /**
     * "I bought this in the store." The transaction is an identifier — iOS:
     * the StoreKit 2 signed transaction, Android: the purchase token — and
     * the server asks the store. Replaying it is a 200 that changes nothing.
     * Errors: product_unknown, receipt_invalid (422), receipt_owned_elsewhere
     * (409), store_unavailable (503).
     */
    redeemReceipt: (body: { platform: 'ios' | 'android'; product_id: string; transaction: string }) =>
      client.post<{ data: Me }>('me/premium/receipt', { body }),

    // ---- photos ---------------------------------------------------------
    uploadPhoto: (formData: FormData) => client.post<{ data: Photo }>('me/photos', { formData }),
    deletePhoto: (uuid: string) => client.delete<{ message: string }>(`me/photos/${uuid}`),
    reorderPhotos: (uuids: string[]) => client.patch<{ data: Photo[] }>('me/photos/reorder', { body: { uuids } }),
    makePrimaryPhoto: (uuid: string) => client.patch<{ data: Photo[] }>(`me/photos/${uuid}/primary`),

    // ---- discovery ------------------------------------------------------
    deck: (limit = 20) => client.get<{ data: Person[]; meta: { count: number } }>('deck', { query: { limit } }),
    swipe: (target_id: string, action: SwipeAction, source: 'deck' | 'likes_you' | 'profile' = 'deck') =>
      client.post<SwipeResponse>('swipes', { body: { target_id, action, source } }),

    // ---- matches --------------------------------------------------------
    matches: (cursor?: string | null) => client.get<CursorPage<Match>>('matches', { query: { cursor } }),
    unmatch: (uuid: string) => client.delete<{ message: string }>(`matches/${uuid}`),
    likers: (cursor?: string | null) => client.get<CursorPage<Person>>('me/likers', { query: { cursor } }),

    // ---- conversations --------------------------------------------------
    conversations: (cursor?: string | null) =>
      client.get<CursorPage<Conversation>>('conversations', { query: { cursor } }),
    messages: (uuid: string, cursor?: string | null) =>
      client.get<CursorPage<Message>>(`conversations/${uuid}/messages`, { query: { cursor } }),
    sendMessage: (uuid: string, body: string) =>
      client.post<{ data: Message }>(`conversations/${uuid}/messages`, { body: { body, type: 'text' } }),
    markRead: (uuid: string) => client.post<{ message: string }>(`conversations/${uuid}/read`),

    // ---- safety ---------------------------------------------------------
    report: (body: { reported_id: string; category: ReportCategory; description?: string; message_id?: string }) =>
      client.post<{ message: string; report_id: string }>('reports', { body }),
    blocks: () => client.get<{ data: Person[] }>('blocks'),
    block: (blocked_id: string, reason?: string) =>
      client.post<{ message: string }>('blocks', { body: { blocked_id, reason } }),
    unblock: (uuid: string) => client.delete<{ message: string }>(`blocks/${uuid}`),

    // ---- phone ----------------------------------------------------------
    sendPhoneCode: (phone: string) =>
      client.post<{ data: { status: string; expires_at: string } }>('phone/send-code', { body: { phone } }),
    verifyPhone: (code: string) => client.post<{ data: { status: string } }>('phone/verify', { body: { code } }),

    // ---- devices --------------------------------------------------------
    registerPushToken: (body: { token: string; platform: 'android' | 'ios' | 'web'; label?: string; device_fingerprint?: string }) =>
      client.post<{ data: { status: string } }>('devices/push-token', { body }),
    removePushToken: (token: string) => client.delete<{ data: { status: string } }>('devices/push-token', { body: { token } }),

    // ---- verification ---------------------------------------------------
    verification: () => client.get<{ data: Verification | null }>('verification'),
    gestureCode: () => client.get<{ gesture_code: string; expires_in: number }>('verification/gesture'),
    submitVerification: (formData: FormData) => client.post<{ data: Verification }>('verification', { formData }),
  };
}

export type Api = ReturnType<typeof makeApi>;
