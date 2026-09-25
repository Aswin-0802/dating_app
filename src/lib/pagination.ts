import { useInfiniteQuery, type QueryKey, type UseInfiniteQueryOptions } from '@tanstack/react-query';
import type { CursorPage } from '../api/types';

/**
 * Every list the API returns is cursor-paginated: {data, meta.next_cursor}.
 * There are no page numbers, and this helper never invents one — it hands
 * the last cursor back and stops when the server sends null.
 */
export function useCursorList<T>(
  queryKey: QueryKey,
  fetchPage: (cursor: string | null) => Promise<CursorPage<T>>,
  options: Partial<Pick<UseInfiniteQueryOptions<CursorPage<T>, Error, unknown, QueryKey, string | null>, 'enabled' | 'refetchInterval' | 'staleTime'>> = {},
) {
  const query = useInfiniteQuery({
    queryKey,
    queryFn: ({ pageParam }) => fetchPage(pageParam),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.meta.next_cursor ?? undefined,
    ...options,
  });

  const items: T[] = query.data?.pages.flatMap((page) => page.data) ?? [];

  return { ...query, items };
}
