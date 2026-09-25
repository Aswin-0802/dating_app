import React, { useState } from 'react';
import { FlatList, StyleSheet, Text, View } from 'react-native';
import { Image } from 'expo-image';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../../api';
import { isApiError } from '../../api/errors';
import { Banner, Button, EmptyState, Loading, Screen } from '../../ui';
import { colors, spacing } from '../../ui/theme';

export default function Blocks() {
  const queryClient = useQueryClient();
  const blocks = useQuery({ queryKey: ['blocks'], queryFn: () => api.blocks() });
  const [busy, setBusy] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  async function unblock(uuid: string) {
    setBusy(uuid);
    setNotice(null);
    try {
      await api.unblock(uuid);
      await blocks.refetch();
      await queryClient.invalidateQueries({ queryKey: ['deck'] });
    } catch (e) {
      setNotice(isApiError(e) ? e.message : 'Could not unblock.');
    } finally {
      setBusy(null);
    }
  }

  if (blocks.isLoading) return <Loading />;

  return (
    <Screen padded={false}>
      {notice ? (
        <View style={{ padding: spacing.md }}>
          <Banner tone="danger">{notice}</Banner>
        </View>
      ) : null}
      <FlatList
        data={blocks.data?.data ?? []}
        keyExtractor={(p) => p.id}
        contentContainerStyle={{ flexGrow: 1 }}
        ListEmptyComponent={<EmptyState title="Nobody blocked" body="People you block disappear from each other's Discover and any conversation closes." />}
        renderItem={({ item }) => (
          <View style={styles.row}>
            {item.photos?.[0]?.thumb_url ? <Image source={{ uri: item.photos[0].thumb_url }} style={styles.avatar} contentFit="cover" /> : <View style={[styles.avatar, { backgroundColor: colors.wash }]} />}
            <Text style={styles.name}>{item.display_name}</Text>
            <Button title="Unblock" variant="secondary" onPress={() => void unblock(item.id)} loading={busy === item.id} />
          </View>
        )}
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, paddingHorizontal: spacing.lg, paddingVertical: spacing.md, borderBottomWidth: 1, borderBottomColor: colors.line },
  avatar: { width: 44, height: 44, borderRadius: 22 },
  name: { flex: 1, fontSize: 16, color: colors.ink },
});
