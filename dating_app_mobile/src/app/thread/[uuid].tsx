import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { FlatList, KeyboardAvoidingView, Platform, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { Stack, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../../api';
import { isApiError } from '../../api/errors';
import type { Conversation, CursorPage, Message } from '../../api/types';
import { ReportBlockSheet } from '../../features/safety/ReportBlockSheet';
import { useCursorList } from '../../lib/pagination';
import { Banner, Button, Loading, Screen } from '../../ui';
import { colors, radius, spacing } from '../../ui/theme';

const POLL_MS = 6000;

type Pending = { localId: string; body: string; sentAt: string; failed: boolean };

/**
 * One conversation.
 *
 * Newest-first from the server, so the list is inverted and older pages load
 * when the reader scrolls to the top. Sends are optimistic — the bubble
 * appears at once — and reconciled against the server's answer; a failure
 * is shown on the bubble with a retry, never rolled back silently.
 *
 * A 403 here is legitimate: the thread was closed by a block or an unmatch.
 * The composer is disabled and the thread says so. There is no websocket,
 * so the thread polls every six seconds while it is on screen.
 */
export default function Thread() {
  const { uuid, name } = useLocalSearchParams<{ uuid: string; name?: string }>();
  const queryClient = useQueryClient();
  const [focused, setFocused] = useState(true);
  const [closedByAction, setClosed] = useState(false);
  const [draft, setDraft] = useState('');
  const [pending, setPending] = useState<Pending[]>([]);
  const [sheet, setSheet] = useState<{ messageId?: string } | null>(null);

  const list = useCursorList<Message>(['messages', uuid], (cursor) => api.messages(uuid, cursor), {
    // Poll only while on screen and while the thread is still open: a 403
    // from the server, or a block from here, stops it.
    refetchInterval: (query) => {
      const error = query.state.error;
      const forbidden = isApiError(error) && error.code === 'forbidden';
      return focused && !closedByAction && !forbidden ? POLL_MS : false;
    },
  });

  // The other person and the thread's status come from the conversations
  // list, which the server keeps to open threads only.
  const conversation = useMemo(() => {
    const cached = queryClient.getQueryData<{ pages: CursorPage<Conversation>[] }>(['conversations']);
    return cached?.pages.flatMap((p) => p.data).find((c) => c.id === uuid) ?? null;
  }, [queryClient, uuid]);

  const otherName = conversation?.other?.display_name ?? name ?? 'Conversation';

  // A 403 from the thread itself means the conversation was closed (block or
  // unmatch). Derived, not mirrored into state.
  const closed = closedByAction || (list.isError && isApiError(list.error) && list.error.code === 'forbidden');

  useFocusEffect(
    useCallback(() => {
      setFocused(true);
      void api.markRead(uuid).catch(() => undefined);
      return () => setFocused(false);
    }, [uuid]),
  );

  // Mark read as new messages arrive while the thread is open.
  useEffect(() => {
    if (focused && list.items.length) void api.markRead(uuid).catch(() => undefined);
  }, [focused, list.items.length, uuid]);

  const send = useCallback(
    async (body: string, existingLocalId?: string) => {
      const text = body.trim();
      if (!text || closed) return;

      const localId = existingLocalId ?? `local-${Date.now()}`;
      setPending((p) => (existingLocalId ? p.map((m) => (m.localId === localId ? { ...m, failed: false } : m)) : [{ localId, body: text, sentAt: new Date().toISOString(), failed: false }, ...p]));
      setDraft('');

      try {
        await api.sendMessage(uuid, text);
        setPending((p) => p.filter((m) => m.localId !== localId));
        await list.refetch();
      } catch (e) {
        if (isApiError(e) && e.code === 'forbidden') {
          // Closed under us. Say so; do not keep the bubble as if it might go.
          setClosed(true);
          setPending((p) => p.filter((m) => m.localId !== localId));
          return;
        }
        setPending((p) => p.map((m) => (m.localId === localId ? { ...m, failed: true } : m)));
      }
    },
    [closed, list, uuid],
  );

  const rows = useMemo<(Message | Pending)[]>(() => [...pending, ...list.items], [pending, list.items]);

  if (list.isLoading) return <Loading />;

  return (
    <Screen padded={false}>
      <Stack.Screen
        options={{
          headerShown: true,
          title: otherName,
          headerRight: () => (
            <Pressable onPress={() => setSheet({})} accessibilityRole="button" accessibilityLabel="Report or block" hitSlop={12}>
              <Text style={styles.headerAction}>⋯</Text>
            </Pressable>
          ),
        }}
      />

      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined} keyboardVerticalOffset={90}>
        <FlatList
          inverted
          data={rows}
          keyExtractor={(m) => ('localId' in m ? m.localId : m.id)}
          contentContainerStyle={styles.list}
          onEndReached={() => list.hasNextPage && !list.isFetchingNextPage && void list.fetchNextPage()}
          onEndReachedThreshold={0.4}
          renderItem={({ item }) =>
            'localId' in item ? (
              <Bubble mine body={item.body} at={item.sentAt} failed={item.failed} onRetry={() => void send(item.body, item.localId)} />
            ) : (
              <Bubble
                mine={item.is_mine}
                body={item.removed ? null : item.body}
                at={item.sent_at}
                removed={item.removed}
                onLongPress={!item.is_mine ? () => setSheet({ messageId: item.id }) : undefined}
              />
            )
          }
        />

        {closed ? (
          <View style={styles.composer}>
            <Banner tone="warning">This conversation is closed.</Banner>
          </View>
        ) : (
          <View style={[styles.composer, styles.composerRow]}>
            <TextInput
              style={styles.input}
              value={draft}
              onChangeText={setDraft}
              placeholder="Write a message"
              placeholderTextColor={colors.muted}
              multiline
              maxLength={2000}
              accessibilityLabel="Message"
            />
            <Button title="Send" onPress={() => void send(draft)} disabled={!draft.trim()} />
          </View>
        )}
      </KeyboardAvoidingView>

      {conversation?.other ? (
        <ReportBlockSheet
          personId={conversation.other.id}
          personName={conversation.other.display_name}
          messageId={sheet?.messageId}
          visible={sheet !== null}
          onClose={() => setSheet(null)}
          onBlocked={() => setClosed(true)}
        />
      ) : null}
    </Screen>
  );
}

function Bubble({
  mine,
  body,
  at,
  failed,
  removed,
  onRetry,
  onLongPress,
}: {
  mine: boolean;
  body: string | null;
  at: string | null;
  failed?: boolean;
  removed?: boolean;
  onRetry?: () => void;
  onLongPress?: () => void;
}) {
  return (
    <Pressable onLongPress={onLongPress} delayLongPress={350} style={[styles.bubbleWrap, mine ? styles.mineWrap : styles.theirsWrap]}>
      <View style={[styles.bubble, mine ? styles.mine : styles.theirs, failed && styles.failedBubble]}>
        {removed ? <Text style={styles.removed}>Message removed</Text> : <Text style={[styles.body, mine && styles.mineText]}>{body}</Text>}
      </View>
      {failed ? (
        <Pressable onPress={onRetry} accessibilityRole="button">
          <Text style={styles.failedText}>Not sent · tap to retry</Text>
        </Pressable>
      ) : at ? (
        <Text style={styles.time}>{new Date(at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</Text>
      ) : null}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  headerAction: { fontSize: 22, color: colors.ink, paddingHorizontal: spacing.sm },
  list: { padding: spacing.md, gap: spacing.sm },
  bubbleWrap: { maxWidth: '82%' },
  mineWrap: { alignSelf: 'flex-end', alignItems: 'flex-end' },
  theirsWrap: { alignSelf: 'flex-start' },
  bubble: { paddingHorizontal: spacing.md, paddingVertical: spacing.sm, borderRadius: radius.lg },
  mine: { backgroundColor: colors.primary },
  theirs: { backgroundColor: colors.wash },
  failedBubble: { opacity: 0.6 },
  body: { color: colors.ink, fontSize: 15, lineHeight: 20 },
  mineText: { color: '#fff' },
  removed: { color: colors.muted, fontStyle: 'italic' },
  time: { color: colors.muted, fontSize: 11, marginTop: 2 },
  failedText: { color: colors.danger, fontSize: 12, marginTop: 2 },
  composer: { padding: spacing.md, borderTopWidth: 1, borderTopColor: colors.line, backgroundColor: colors.card },
  composerRow: { flexDirection: 'row', alignItems: 'flex-end', gap: spacing.sm },
  input: { flex: 1, minHeight: 44, maxHeight: 120, borderWidth: 1, borderColor: colors.line, borderRadius: radius.md, paddingHorizontal: spacing.md, paddingVertical: spacing.sm, fontSize: 15, color: colors.ink },
});
