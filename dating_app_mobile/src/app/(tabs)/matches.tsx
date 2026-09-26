import React from 'react';
import { FlatList, Pressable, StyleSheet, Text, View } from 'react-native';
import { Image } from 'expo-image';
import { router } from 'expo-router';
import { api } from '../../api';
import type { Match } from '../../api/types';
import { useCursorList } from '../../lib/pagination';
import { Body, Button, EmptyState, Loading, Screen, Title } from '../../ui';
import { colors, radius, spacing } from '../../ui/theme';

export default function Matches() {
  const list = useCursorList<Match>(['matches'], (cursor) => api.matches(cursor));

  if (list.isLoading) return <Loading />;

  return (
    <Screen padded={false}>
      <View style={styles.header}>
        <Title>Matches</Title>
      </View>
      <FlatList
        data={list.items}
        keyExtractor={(m) => m.id}
        numColumns={2}
        columnWrapperStyle={styles.rowWrap}
        contentContainerStyle={styles.list}
        onEndReached={() => list.hasNextPage && !list.isFetchingNextPage && void list.fetchNextPage()}
        onEndReachedThreshold={0.5}
        refreshing={list.isRefetching}
        onRefresh={() => void list.refetch()}
        ListEmptyComponent={
          <EmptyState
            title="No matches yet"
            body="When someone you like likes you back, they appear here."
            action={<Button title="Go to Discover" onPress={() => router.push('/(tabs)/discover')} />}
          />
        }
        renderItem={({ item }) => <MatchTile match={item} />}
      />
    </Screen>
  );
}

function MatchTile({ match }: { match: Match }) {
  const other = match.other;
  const photo = other?.photos?.[0]?.thumb_url;

  return (
    <Pressable
      style={styles.tile}
      accessibilityRole="button"
      accessibilityLabel={`${other?.display_name ?? 'Match'}${match.has_conversation ? ', open conversation' : ', say hello'}`}
      onPress={() => {
        if (!other) return;
        router.push({ pathname: '/person/[uuid]', params: { uuid: other.id, person: JSON.stringify(other), source: 'match', matchId: match.id } });
      }}
    >
      {photo ? <Image source={{ uri: photo }} style={styles.photo} contentFit="cover" /> : <View style={[styles.photo, styles.noPhoto]} />}
      <View style={styles.tileCaption}>
        <Text style={styles.name} numberOfLines={1}>
          {other?.display_name ?? 'Member'}
          {other?.age ? `, ${other.age}` : ''}
        </Text>
        <Body muted>{match.has_conversation ? `${match.messages_count} messages` : 'Say hello'}</Body>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  header: { paddingHorizontal: spacing.lg, paddingTop: spacing.md },
  list: { padding: spacing.md, flexGrow: 1 },
  rowWrap: { gap: spacing.md },
  tile: { flex: 1, marginBottom: spacing.md, borderRadius: radius.lg, overflow: 'hidden', backgroundColor: colors.card, borderWidth: 1, borderColor: colors.line },
  photo: { width: '100%', aspectRatio: 4 / 5 },
  noPhoto: { backgroundColor: colors.wash },
  tileCaption: { padding: spacing.sm },
  name: { fontWeight: '600', color: colors.ink },
});
