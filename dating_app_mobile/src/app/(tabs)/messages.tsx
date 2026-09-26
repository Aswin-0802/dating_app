import React from 'react';
import { FlatList, Pressable, StyleSheet, Text, View } from 'react-native';
import { Image } from 'expo-image';
import { router, useFocusEffect } from 'expo-router';
import { api } from '../../api';
import type { Conversation } from '../../api/types';
import { useCursorList } from '../../lib/pagination';
import { Body, EmptyState, Loading, Screen, Title } from '../../ui';
import { colors, spacing } from '../../ui/theme';

export default function Messages() {
  const list = useCursorList<Conversation>(['conversations'], (cursor) => api.conversations(cursor));

  // The list only ever contains open threads: the server filters closed
  // ones out, so a block or unmatch removes a thread on the next refresh.
  const { refetch } = list;
  useFocusEffect(
    React.useCallback(() => {
      void refetch();
    }, [refetch]),
  );

  if (list.isLoading) return <Loading />;

  return (
    <Screen padded={false}>
      <View style={styles.header}>
        <Title>Messages</Title>
      </View>
      <FlatList
        data={list.items}
        keyExtractor={(c) => c.id}
        contentContainerStyle={styles.list}
        onEndReached={() => list.hasNextPage && !list.isFetchingNextPage && void list.fetchNextPage()}
        onEndReachedThreshold={0.5}
        refreshing={list.isRefetching}
        onRefresh={() => void list.refetch()}
        ListEmptyComponent={<EmptyState title="No conversations yet" body="Match with someone and say hello — it will show up here." />}
        renderItem={({ item }) => (
          <Pressable
            style={styles.row}
            accessibilityRole="button"
            onPress={() => router.push({ pathname: '/thread/[uuid]', params: { uuid: item.id, name: item.other?.display_name ?? '' } })}
          >
            {item.other?.photos?.[0]?.thumb_url ? (
              <Image source={{ uri: item.other.photos[0].thumb_url }} style={styles.avatar} contentFit="cover" />
            ) : (
              <View style={[styles.avatar, { backgroundColor: colors.wash }]} />
            )}
            <View style={{ flex: 1 }}>
              <Text style={styles.name}>{item.other?.display_name ?? 'Member'}</Text>
              <Body muted>{item.last_message_at ? `Last message ${relative(item.last_message_at)}` : 'No messages yet'}</Body>
            </View>
          </Pressable>
        )}
      />
    </Screen>
  );
}

export function relative(iso: string): string {
  const diff = Math.max(0, Date.now() - Date.parse(iso));
  const minutes = Math.round(diff / 60_000);
  if (minutes < 1) return 'just now';
  if (minutes < 60) return `${minutes}m ago`;
  const hours = Math.round(minutes / 60);
  if (hours < 24) return `${hours}h ago`;
  return `${Math.round(hours / 24)}d ago`;
}

const styles = StyleSheet.create({
  header: { paddingHorizontal: spacing.lg, paddingTop: spacing.md },
  list: { flexGrow: 1 },
  row: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, paddingHorizontal: spacing.lg, paddingVertical: spacing.md, borderBottomWidth: 1, borderBottomColor: colors.line },
  avatar: { width: 52, height: 52, borderRadius: 26 },
  name: { fontWeight: '600', fontSize: 16, color: colors.ink },
});
