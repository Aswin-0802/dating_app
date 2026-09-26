import React, { createContext, useContext, useMemo, type PropsWithChildren } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api, APP_VERSION } from '../api';
import { isApiError, type ApiError } from '../api/errors';
import type { Config } from '../api/types';
import { isOlderThan } from '../lib/version';

/**
 * GET /config on launch. Everything the operator can tune — minimum age,
 * photo limit, like limit, distance cap, minimum app version — comes from
 * here and is never hard-coded in a screen.
 */
type State =
  | { status: 'loading' }
  | { status: 'ready'; config: Config }
  | { status: 'error'; error: ApiError | null };

interface ConfigContextValue {
  state: State;
  /** Ready config, or sensible fallbacks until it arrives — never a hard-coded product rule. */
  config: Config;
  updateRequired: boolean;
  reload(): void;
}

const FALLBACK: Config = {
  min_supported_version: '0.0.0',
  maintenance_mode: false,
  min_age: 18,
  max_photos: 6,
  max_distance_km: 160,
  daily_like_limit: 100,
  appeal_window_days: 30,
  support_email: null,
};

const ConfigContext = createContext<ConfigContextValue | null>(null);

export function ConfigProvider({ children }: PropsWithChildren) {
  // react-query owns the fetch: no effect, no mirrored state. A failed
  // fetch is retried only for network errors (see lib/queryClient).
  const query = useQuery({ queryKey: ['config'], queryFn: () => api.config(), staleTime: 5 * 60_000 });
  const { refetch } = query;

  const value = useMemo<ConfigContextValue>(() => {
    const state: State = query.isSuccess
      ? { status: 'ready', config: query.data }
      : query.isError && !query.isFetching
        ? { status: 'error', error: isApiError(query.error) ? query.error : null }
        : { status: 'loading' };
    const config = state.status === 'ready' ? state.config : FALLBACK;

    return {
      state,
      config,
      updateRequired: state.status === 'ready' && isOlderThan(APP_VERSION, config.min_supported_version),
      reload: () => void refetch(),
    };
  }, [query.isSuccess, query.isError, query.isFetching, query.data, query.error, refetch]);

  return <ConfigContext.Provider value={value}>{children}</ConfigContext.Provider>;
}

export function useConfig(): ConfigContextValue {
  const context = useContext(ConfigContext);
  if (!context) throw new Error('useConfig must be used inside ConfigProvider');
  return context;
}
