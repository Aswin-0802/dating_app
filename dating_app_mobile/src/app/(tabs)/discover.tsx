import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Modal, StyleSheet, View } from 'react-native';
import { Image } from 'expo-image';
import { router } from 'expo-router';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../../api';
import { isApiError } from '../../api/errors';
import type { Match, Person, SwipeAction } from '../../api/types';
import { useMe, useSession } from '../../auth/SessionProvider';
import { useConfig } from '../../config/ConfigProvider';
import { SwipeDeck } from '../../features/discover/SwipeDeck';
import { Banner, Body, Button, EmptyState, Gap, Loading, Screen, Subtitle, Title } from '../../ui';
import { colors, radius, spacing } from '../../ui/theme';

export default function Discover() {
  const me = useMe();
  const { config } = useConfig();
  const { refreshMe } = useSession();
  const queryClient = useQueryClient();

  const deck = useQuery({ queryKey: ['deck'], queryFn: () => api.deck(20), staleTime: 0, refetchOnWindowFocus: false });
  const [swiped, setSwiped] = useState<Set<string>>(() => new Set());
  const [notice, setNotice] = useState<{ tone: 'warning' | 'danger' | 'info'; text: string } | null>(null);
  const [celebration, setCelebration] = useState<Match | null>(null);

  // What is left of the server's batch once the swiped cards are gone. The
  // server never returns someone already swiped, so `swiped` only matters
  // within one batch.
  const queue = useMemo<Person[]>(() => (deck.data?.data ?? []).filter((p) => !swiped.has(p.id)), [deck.data, swiped]);

  // Ask the server for the next batch when the local queue runs out — once
  // per batch, so an empty answer does not turn into a refetch loop.
  const refetchedFor = useRef<number | null>(null);
  useEffect(() => {
    const batchHadPeople = (deck.data?.data.length ?? 0) > 0;
    if (queue.length === 0 && deck.isSuccess && !deck.isFetching && batchHadPeople && refetchedFor.current !== deck.dataUpdatedAt) {
      refetchedFor.current = deck.dataUpdatedAt;
      void deck.refetch();
    }
  }, [queue.length, deck]);

  const decide = useCallback(
    async (action: SwipeAction): Promise<boolean> => {
      const target = queue[0];
      if (!target) return false;
      setNotice(null);

      try {
        const result = await api.swipe(target.id, action, 'deck');
        setSwiped((s) => new Set(s).add(target.id));
        if (result.is_match && result.match) setCelebration(result.match);
        return true;
      } catch (e) {
        if (isApiError(e)) {
          // 422 is the daily like limit; 403 a token without the swipe
          // ability or a restriction; 429 the rate limiter. All of them mean
          // "not this time", and the card comes back.
          setNotice({ tone: e.code === 'rate_limited' || e.code === 'validation_failed' ? 'warning' : 'danger', text: e.firstError ?? e.message });
          if (e.code === 'forbidden') void refreshMe();
        } else {
          setNotice({ tone: 'danger', text: 'That did not go through. Try again.' });
        }
        return false;
      }
    },
    [queue, refreshMe],
  );

  const openProfile = useCallback((person: Person) => {
    router.push({ pathname: '/person/[uuid]', params: { uuid: person.id, person: JSON.stringify(person), source: 'deck' } });
  }, []);

  if (deck.isLoading) return <Loading label="Finding people…" />;

  if (deck.isError) {
    return (
      <Screen>
        <EmptyState title="Couldn’t load Discover" body={isApiError(deck.error) ? deck.error.message : undefined} action={<Button title="Try again" onPress={() => void deck.refetch()} />} />
      </Screen>
    );
  }

  const top = queue[0];

  return (
    <Screen padded={false}>
      <View style={styles.header}>
        <Title>Discover</Title>
        {!me.is_premium ? <Body muted>{config.daily_like_limit} likes a day on the free plan.</Body> : null}
      </View>

      {notice ? (
        <View style={styles.notice}>
          <Banner tone={notice.tone}>{notice.text}</Banner>
        </View>
      ) : null}

      {top ? (
        <SwipeDeck key={top.id} person={top} behind={queue[1]} onDecide={decide} onOpenProfile={openProfile} />
      ) : (
        <EmptyState
          title="Nobody new right now"
          body={
            me.preferences?.global_mode
              ? 'You have seen everyone who matches your preferences. Widen your age range, or check back later.'
              : 'You have seen everyone nearby who matches your preferences. Try a wider distance, a wider age range, or global mode in Preferences.'
          }
          action={
            <>
              <Button title="Adjust preferences" variant="secondary" onPress={() => router.push('/profile/preferences')} />
              <Gap size="sm" />
              <Button title="Check again" variant="ghost" onPress={() => void deck.refetch()} loading={deck.isFetching} />
            </>
          }
        />
      )}

      <MatchCelebration
        match={celebration}
        onClose={() => setCelebration(null)}
        onSayHello={async () => {
          const match = celebration;
          setCelebration(null);
          if (!match?.other) return;
          // The swipe answer carries the match, not the conversation. Find it.
          await queryClient.invalidateQueries({ queryKey: ['conversations'] });
          const page = await api.conversations();
          const conversation = page.data.find((c) => c.other?.id === match.other?.id);
          if (conversation) {
            router.push({ pathname: '/thread/[uuid]', params: { uuid: conversation.id, name: match.other.display_name } });
          } else {
            router.push('/(tabs)/messages');
          }
        }}
      />
    </Screen>
  );
}

function MatchCelebration({ match, onClose, onSayHello }: { match: Match | null; onClose: () => void; onSayHello: () => void }) {
  const photo = match?.other?.photos?.[0]?.thumb_url;

  return (
    <Modal transparent animationType="fade" visible={match !== null} onRequestClose={onClose}>
      <View style={styles.backdrop}>
        <View style={styles.celebration}>
          {photo ? <Image source={{ uri: photo }} style={styles.celebrationPhoto} contentFit="cover" /> : null}
          <Subtitle>It’s a match!</Subtitle>
          <Body center>You and {match?.other?.display_name ?? 'they'} liked each other.</Body>
          <Gap />
          <Button title="Say hello" onPress={onSayHello} />
          <Gap size="sm" />
          <Button title="Keep discovering" variant="ghost" onPress={onClose} />
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  header: { paddingHorizontal: spacing.lg, paddingTop: spacing.md },
  notice: { paddingHorizontal: spacing.md },
  backdrop: { flex: 1, backgroundColor: 'rgba(42,32,48,0.7)', alignItems: 'center', justifyContent: 'center', padding: spacing.xl },
  celebration: { width: '100%', backgroundColor: colors.card, borderRadius: radius.lg, padding: spacing.xl, alignItems: 'center' },
  celebrationPhoto: { width: 120, height: 120, borderRadius: 60, marginBottom: spacing.md },
});
