import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type PropsWithChildren } from 'react';
import { api, onApiError } from '../api';
import { isApiError, type ApiError } from '../api/errors';
import type { Gender, Me, Restriction } from '../api/types';
import { tokenStore } from './tokenStore';

/**
 * Who is signed in, what the server last said about them, and the handful of
 * server answers that must take over the whole screen.
 *
 * The one piece of logic here that is not obvious — and that makes or breaks
 * the sign-up experience — is the token swap. A new member is `pending` and
 * their token carries only profile abilities. When the server promotes them
 * to `active`, THE EXISTING TOKEN KEEPS THE OLD ABILITIES: /deck still
 * answers 403 until they sign in again. So after every write that can change
 * completion, the app re-reads /me, and when the status flips it swaps the
 * token by signing in again behind the scenes — with the password it kept in
 * the keychain for exactly this moment — or, failing that, asks for it once.
 */

export type SessionStatus = 'booting' | 'signedOut' | 'signedIn';

export type Blocker =
  | { kind: 'restricted'; message: string; restriction: Restriction | null }
  | { kind: 'deactivated'; message: string }
  | { kind: 'maintenance'; message: string; retryAfter: number | null }
  | null;

export interface RegisterPayload {
  display_name: string;
  email: string;
  password: string;
  birthdate: string;
  gender: Gender;
  interested_in: Gender[];
}

interface Session {
  status: SessionStatus;
  me: Me | null;
  blocker: Blocker;
  /** Epoch ms until which the server asked us to wait, from the last 429. */
  rateLimitedUntil: number | null;
  /** The account was promoted but the token could not be swapped silently. */
  needsReauth: boolean;
  signIn(email: string, password: string): Promise<Me>;
  register(payload: RegisterPayload): Promise<Me>;
  signOut(): Promise<void>;
  /** Re-read /me and swap the token if the account was promoted. */
  refreshMe(): Promise<Me | null>;
  reauth(password: string): Promise<void>;
  /** Try again after maintenance or a restriction check. */
  retry(): Promise<void>;
}

const SessionContext = createContext<Session | null>(null);

type SignOutHook = () => Promise<void> | void;
const signOutHooks = new Set<SignOutHook>();

/** Register work to run before the token is revoked — the push module removes its device token here. */
export function onBeforeSignOut(hook: SignOutHook): () => void {
  signOutHooks.add(hook);
  return () => signOutHooks.delete(hook);
}

export function SessionProvider({ children }: PropsWithChildren) {
  const [status, setStatus] = useState<SessionStatus>('booting');
  const [me, setMe] = useState<Me | null>(null);
  const [blocker, setBlocker] = useState<Blocker>(null);
  const [rateLimitedUntil, setRateLimitedUntil] = useState<number | null>(null);
  const [needsReauth, setNeedsReauth] = useState(false);
  const meRef = useRef<Me | null>(null);

  const setMember = useCallback((next: Me | null) => {
    meRef.current = next;
    setMe(next);
  }, []);

  const clearSession = useCallback(async () => {
    await tokenStore.clearAll();
    setMember(null);
    setNeedsReauth(false);
    setStatus('signedOut');
  }, [setMember]);

  /* ---- global error routing --------------------------------------------- */

  useEffect(() => {
    return onApiError((error: ApiError) => {
      switch (error.code) {
        case 'unauthenticated':
          // The token is dead: revoked, expired, or the account is gone.
          void clearSession();
          break;
        case 'account_restricted':
          setBlocker({ kind: 'restricted', message: error.message, restriction: error.restriction });
          break;
        case 'account_deactivated':
          setBlocker({ kind: 'deactivated', message: error.message });
          break;
        case 'maintenance':
          setBlocker({ kind: 'maintenance', message: error.message, retryAfter: error.retryAfter });
          break;
        case 'rate_limited':
          setRateLimitedUntil(Date.now() + (error.retryAfter ?? 60) * 1000);
          break;
        default:
          break;
      }
    });
  }, [clearSession]);

  /* ---- boot --------------------------------------------------------------- */

  const boot = useCallback(async () => {
    const token = await tokenStore.getToken();

    if (!token) {
      setStatus('signedOut');
      return;
    }

    try {
      const { data } = await api.me();
      setMember(data);
      setBlocker(null);
      setStatus('signedIn');
    } catch (error) {
      if (isApiError(error) && (error.code === 'account_restricted' || error.code === 'account_deactivated' || error.code === 'maintenance')) {
        // The blocker is already set by the global handler; keep the token so
        // retry() can re-check without a fresh sign-in.
        setStatus('signedIn');
        return;
      }

      if (isApiError(error) && error.code === 'network') {
        // Offline at launch with a token: stay signed in on what we last knew.
        setStatus('signedIn');
        return;
      }

      await clearSession();
    }
  }, [clearSession, setMember]);

  useEffect(() => {
    // Deferred a tick: boot() reads SecureStore and then sets state, which
    // is asynchronous by nature, but is kept out of the effect body itself.
    let active = true;
    void Promise.resolve().then(() => (active ? boot() : undefined));
    return () => {
      active = false;
    };
  }, [boot]);

  /* ---- the token swap ----------------------------------------------------- */

  const swapTokenIfPromoted = useCallback(
    async (previous: Me | null, next: Me): Promise<void> => {
      const promoted = previous?.account_status === 'pending' && next.account_status === 'active';
      const credentials = await tokenStore.getPendingCredentials();

      // Either we just watched the flip, or the flip happened elsewhere (the
      // website) and we still hold onboarding credentials for an active account.
      if (!promoted && !(next.account_status === 'active' && credentials)) return;

      if (credentials) {
        try {
          const auth = await api.login(credentials.email, credentials.password);
          await tokenStore.setToken(auth.token);
          await tokenStore.clearPendingCredentials();
          setMember(auth.user);
          setNeedsReauth(false);
          return;
        } catch {
          // Password changed since, or the network is down. Fall through and ask.
        }
      }

      setNeedsReauth(true);
    },
    [setMember],
  );

  const refreshMe = useCallback(async (): Promise<Me | null> => {
    try {
      const { data } = await api.me();
      const previous = meRef.current;
      setMember(data);
      await swapTokenIfPromoted(previous, data);
      return data;
    } catch {
      return meRef.current;
    }
  }, [setMember, swapTokenIfPromoted]);

  /* ---- sign in / register / out ------------------------------------------- */

  const finishAuth = useCallback(
    async (token: string, user: Me, password: string) => {
      await tokenStore.setToken(token);

      // Kept only while pending, only for the swap. Deleted the moment it is used.
      if (user.account_status === 'pending') {
        await tokenStore.setPendingCredentials(user.email, password);
      } else {
        await tokenStore.clearPendingCredentials();
      }

      setMember(user);
      setBlocker(null);
      setNeedsReauth(false);
      setStatus('signedIn');
    },
    [setMember],
  );

  const signIn = useCallback(
    async (email: string, password: string) => {
      const auth = await api.login(email, password);
      await finishAuth(auth.token, auth.user, password);
      return auth.user;
    },
    [finishAuth],
  );

  const register = useCallback(
    async (payload: RegisterPayload) => {
      const auth = await api.register(payload);
      await finishAuth(auth.token, auth.user, payload.password);
      return auth.user;
    },
    [finishAuth],
  );

  const reauth = useCallback(
    async (password: string) => {
      const email = meRef.current?.email;
      if (!email) throw new Error('No signed-in member.');
      const auth = await api.login(email, password);
      await tokenStore.setToken(auth.token);
      await tokenStore.clearPendingCredentials();
      setMember(auth.user);
      setNeedsReauth(false);
    },
    [setMember],
  );

  const signOut = useCallback(async () => {
    for (const hook of signOutHooks) {
      try {
        await hook();
      } catch {
        // A failed push-token removal must not stop the sign-out.
      }
    }

    try {
      await api.logout();
    } catch {
      // The token may already be dead; the local state is what matters.
    }

    setBlocker(null);
    await clearSession();
  }, [clearSession]);

  const retry = useCallback(async () => {
    setBlocker(null);
    setStatus('booting');
    await boot();
  }, [boot]);

  const value = useMemo<Session>(
    () => ({ status, me, blocker, rateLimitedUntil, needsReauth, signIn, register, signOut, refreshMe, reauth, retry }),
    [status, me, blocker, rateLimitedUntil, needsReauth, signIn, register, signOut, refreshMe, reauth, retry],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): Session {
  const context = useContext(SessionContext);
  if (!context) throw new Error('useSession must be used inside SessionProvider');
  return context;
}

/** The signed-in member. Throws if used on a screen that can render signed out. */
export function useMe(): Me {
  const { me } = useSession();
  if (!me) throw new Error('useMe used without a signed-in member');
  return me;
}
