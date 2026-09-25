import React, { useMemo, useState } from 'react';
import { Dimensions, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Image } from 'expo-image';
import { router, Stack, useLocalSearchParams } from 'expo-router';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../../api';
import { isApiError } from '../../api/errors';
import type { Conversation, CursorPage, Match, Person, SwipeAction } from '../../api/types';
import { ReportBlockSheet } from '../../features/safety/ReportBlockSheet';
import { CHILDREN, DRINKING, EDUCATION, RELATIONSHIP_GOALS, SMOKING } from '../../lib/options';
import { Banner, Body, Button, Chip, Gap, Row, Screen, Subtitle, Title } from '../../ui';
import { colors, radius, spacing } from '../../ui/theme';

const { width } = Dimensions.get('window');

/**
 * Somebody else's profile.
 *
 * There is no GET /people/{uuid}; a member only ever sees another member
 * through a list they are entitled to (the deck, their matches, their
 * conversations, who liked them). So this screen renders the person it was
 * handed — from a list, via params — and offers what that context allows.
 */
export default function PersonScreen() {
  const { uuid, person: raw, source, matchId } = useLocalSearchParams<{ uuid: string; person?: string; source?: string; matchId?: string }>();
  const queryClient = useQueryClient();
  const [sheet, setSheet] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'success' | 'warning' | 'danger'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [gone, setGone] = useState(false);

  const person = useMemo<Person | null>(() => {
    if (raw) {
      try {
        return JSON.parse(raw) as Person;
      } catch {
        /* fall through to caches */
      }
    }
    const fromDeck = queryClient.getQueryData<{ data: Person[] }>(['deck'])?.data.find((p) => p.id === uuid);
    if (fromDeck) return fromDeck;
    const fromMatches = queryClient.getQueryData<{ pages: CursorPage<Match>[] }>(['matches'])?.pages.flatMap((p) => p.data).find((m) => m.other?.id === uuid)?.other;
    if (fromMatches) return fromMatches;
    return queryClient.getQueryData<{ pages: CursorPage<Conversation>[] }>(['conversations'])?.pages.flatMap((p) => p.data).find((c) => c.other?.id === uuid)?.other ?? null;
  }, [raw, uuid, queryClient]);

  if (!person) {
    return (
      <Screen>
        <Stack.Screen options={{ headerShown: true, title: 'Profile' }} />
        <Body muted>This profile isn’t available.</Body>
      </Screen>
    );
  }

  const profile = person.profile;
  const canSwipe = (source === 'deck' || source === 'likes_you') && !gone;

  async function swipe(action: SwipeAction) {
    setBusy(true);
    setNotice(null);
    try {
      const result = await api.swipe(person!.id, action, source === 'likes_you' ? 'likes_you' : 'profile');
      await queryClient.invalidateQueries({ queryKey: ['deck'] });
      await queryClient.invalidateQueries({ queryKey: ['likers'] });
      setGone(true);
      setNotice(result.is_match ? { tone: 'success', text: `It’s a match with ${person!.display_name}!` } : { tone: 'success', text: action === 'like' ? 'Liked.' : 'Passed.' });
    } catch (e) {
      setNotice({ tone: isApiError(e) && (e.code === 'validation_failed' || e.code === 'rate_limited') ? 'warning' : 'danger', text: isApiError(e) ? (e.firstError ?? e.message) : 'That did not go through.' });
    } finally {
      setBusy(false);
    }
  }

  async function openThread() {
    const page = await api.conversations();
    const conversation = page.data.find((c) => c.other?.id === person!.id);
    if (conversation) router.push({ pathname: '/thread/[uuid]', params: { uuid: conversation.id, name: person!.display_name } });
    else setNotice({ tone: 'warning', text: 'No open conversation with this person.' });
  }

  async function unmatch() {
    if (!matchId) return;
    setBusy(true);
    try {
      await api.unmatch(matchId);
      await queryClient.invalidateQueries({ queryKey: ['matches'] });
      await queryClient.invalidateQueries({ queryKey: ['conversations'] });
      router.back();
    } catch (e) {
      setNotice({ tone: 'danger', text: isApiError(e) ? e.message : 'Could not unmatch.' });
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen padded={false} scroll>
      <Stack.Screen options={{ headerShown: true, title: person.display_name }} />

      <ScrollView horizontal pagingEnabled showsHorizontalScrollIndicator={false}>
        {(person.photos ?? []).map((photo) => (
          <Image key={photo.id} source={{ uri: photo.url }} style={styles.photo} contentFit="cover" />
        ))}
        {!person.photos?.length ? <View style={[styles.photo, { backgroundColor: colors.wash }]} /> : null}
      </ScrollView>

      <View style={styles.content}>
        {notice ? <Banner tone={notice.tone}>{notice.text}</Banner> : null}
        <Title>
          {person.display_name}
          {person.age ? `, ${person.age}` : ''} {person.is_verified ? '✓' : ''}
        </Title>
        <Body muted>{[person.pronouns, person.city, person.distance_km !== undefined ? `${person.distance_km} km away` : null].filter(Boolean).join(' · ')}</Body>

        {profile?.bio ? (
          <>
            <Gap />
            <Body>{profile.bio}</Body>
          </>
        ) : null}

        <Gap />
        <Row style={{ flexWrap: 'wrap' }}>
          {profile?.job_title ? <Fact text={profile.job_title} /> : null}
          {profile?.education ? <Fact text={EDUCATION[profile.education] ?? profile.education} /> : null}
          {profile?.height_cm ? <Fact text={`${profile.height_cm} cm`} /> : null}
          {profile?.relationship_goal && profile.relationship_goal !== 'unspecified' ? <Fact text={RELATIONSHIP_GOALS[profile.relationship_goal] ?? profile.relationship_goal} /> : null}
          {profile?.drinking && profile.drinking !== 'unspecified' ? <Fact text={DRINKING[profile.drinking] ?? profile.drinking} /> : null}
          {profile?.smoking && profile.smoking !== 'unspecified' ? <Fact text={SMOKING[profile.smoking] ?? profile.smoking} /> : null}
          {profile?.children && profile.children !== 'unspecified' ? <Fact text={CHILDREN[profile.children] ?? profile.children} /> : null}
        </Row>

        {person.interests?.length ? (
          <>
            <Gap />
            <Subtitle>Interests</Subtitle>
            <Row style={{ flexWrap: 'wrap' }}>
              {person.interests.map((name) => (
                <Chip key={name} label={name} selected={false} onPress={() => undefined} />
              ))}
            </Row>
          </>
        ) : null}

        <Gap size="xl" />
        {canSwipe ? (
          <Row>
            <Button title="Pass" variant="secondary" onPress={() => void swipe('pass')} loading={busy} style={{ flex: 1 }} />
            <Button title="Like" onPress={() => void swipe('like')} loading={busy} style={{ flex: 1 }} />
          </Row>
        ) : null}
        {source === 'match' ? (
          <>
            <Button title="Open conversation" onPress={() => void openThread()} />
            <Gap size="sm" />
            <Button title="Unmatch" variant="ghost" onPress={() => void unmatch()} loading={busy} />
          </>
        ) : null}
        <Gap size="sm" />
        <Button title="Report or block" variant="ghost" onPress={() => setSheet(true)} />
      </View>

      <ReportBlockSheet personId={person.id} personName={person.display_name} visible={sheet} onClose={() => setSheet(false)} onBlocked={() => router.back()} />
    </Screen>
  );
}

function Fact({ text }: { text: string }) {
  return (
    <View style={styles.fact}>
      <Text style={styles.factText}>{text}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  photo: { width, height: width * 1.2 },
  content: { padding: spacing.lg },
  fact: { backgroundColor: colors.wash, borderRadius: radius.pill, paddingHorizontal: spacing.md, paddingVertical: 6, marginRight: spacing.sm, marginBottom: spacing.sm },
  factText: { color: colors.ink, fontSize: 13 },
});
