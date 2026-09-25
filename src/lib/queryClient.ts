import { QueryClient } from '@tanstack/react-query';
import { isApiError } from '../api/errors';

/**
 * Retries are for the network, not for the server's answers. A 403, a 422 or
 * a 429 means the same request will get the same answer; retrying one is
 * how a client turns a rate limit into a ban.
 */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 15_000,
      retry: (count, error) => count < 2 && isApiError(error) && error.code === 'network',
    },
    mutations: {
      retry: false,
    },
  },
});
