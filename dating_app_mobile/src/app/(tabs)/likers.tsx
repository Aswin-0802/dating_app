import React from 'react';
import { FlatList, Pressable, StyleSheet, Text, View } from 'react-native';
import { Image } from 'expo-image';
import { router } from 'expo-router';
import { api } from '../../api';
import { isApiError } from '../../api/errors';
import type { Person } from '../../api/types';
import { useMe } from '../../auth/SessionProvider';
import { useCursorList } from '../../lib/pagination';
import { Body, Button, Card, EmptyState, Gap, Loading, Screen, Subtitle, Title } from '../../ui';
import { colors, radius, spacing } from '../../ui/theme';

/**
 * Who liked you. Premium only — and that is the server's decision, read from
 * /me and enforced by the endpoint. A free member's 403 carries the count,
 * which is exactly what the upsell shows: how many, never who.
 */
export default function Likers() {
  const me = useMe();
  const list = useCursorList<Person>(['likers'], (cursor) => api.likers(cursor));

  if (list.isLoading) return <Loading />;

  if (list.isError && isApiError(list.error) && list.error.code === 'premium_required') {
    const count = list.error.count ?? 0;
    return (
      <Screen>
        <Title>Likes</Title>
        <Card>
          <Subtitle>{count === 0 ? 'Nobody has liked you yet' : count === 1 ? '1 person liked you' : `${count} people liked you`}</Subtitle>
          <Body muted>See who they are with Premium.</Body>
          <Gap />
          <Button title="About Premium" onPress={() => router.push('/profile/premium')} />
        </Card>
      </Screen>
    );
  }

  if (list.isError) {
    return (
      <Screen>
        <EmptyState title="Couldn’t load likes" body={isApiError(list.error) ? list.error.message : undefined} action={<Button title="Try again" onPress={() => void list.refetch()} />} />
      </Screen>
    );
  }

  return (
    <Screen padded={false}>
      <View style={styles.header}>
        <Title>Likes</Title>
        <Body muted>{me.is_premium ? 'People waiting for your answer.' : ''}</Body>
      </View>
      <FlatList
        data={list.items}
        keyExtractor={(p) => p.id}
        numColumns={2}
        columnWrapperStyle={{ gap: spacing.md }}
        contentContainerStyle={styles.list}
        onEndReached={() => list.hasNextPage && !list.isFetchingNextPage && void list.fetchNextPage()}
        refreshing={list.isRefetching}
        onRefresh={() => void list.refetch()}
        ListEmptyComponent={<EmptyState title="No likes waiting" body="When someone likes you, they appear here until you answer." />}
        renderItem={({ item }) => (
          <Pressable
            style={styles.tile}
            accessibilityRole="button"
            onPress={() => router.push({ pathname: '/person/[uuid]', params: { uuid: item.id, person: JSON.stringify(item), source: 'likes_you' } })}
          >
            {item.photos?.[0]?.thumb_url ? <Image source={{ uri: item.photos[0].thumb_url }} style={styles.photo} contentFit="cover" /> : <View style={[styles.photo, { backgroundColor: colors.wash }]} />}
            <Text style={styles.name} numberOfLines={1}>
              {item.display_name}
              {item.age ? `, ${item.age}` : ''}
            </Text>
          </Pressable>
        )}
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  header: { paddingHorizontal: spacing.lg, paddingTop: spacing.md },
  list: { padding: spacing.md, flexGrow: 1 },
  tile: { flex: 1, marginBottom: spacing.md, borderRadius: radius.lg, overflow: 'hidden', backgroundColor: colors.card, borderWidth: 1, borderColor: colors.line },
  photo: { width: '100%', aspectRatio: 4 / 5 },
  name: { fontWeight: '600', color: colors.ink, padding: spacing.sm },
});
