import React, { useState } from 'react';
import { Modal, ScrollView, StyleSheet, View } from 'react-native';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../../api';
import { isApiError } from '../../api/errors';
import type { ReportCategory } from '../../api/types';
import { REPORT_CATEGORIES } from '../../lib/options';
import { Banner, Body, Button, Gap, RadioGroup, Subtitle, TextField } from '../../ui';
import { colors, radius, spacing } from '../../ui/theme';

/**
 * Report and block, from a thread or a profile.
 *
 * The category list is the server's; severity is derived from it server-side
 * and never chosen here. A block closes the thread on the server — the
 * caller drops it from the list on success rather than pretending.
 */
export function ReportBlockSheet({
  personId,
  personName,
  messageId,
  visible,
  onClose,
  onBlocked,
}: {
  personId: string;
  personName: string;
  messageId?: string;
  visible: boolean;
  onClose: () => void;
  onBlocked?: () => void;
}) {
  const queryClient = useQueryClient();
  const [mode, setMode] = useState<'menu' | 'report' | 'block'>('menu');
  const [category, setCategory] = useState<ReportCategory | null>(null);
  const [description, setDescription] = useState('');
  const [alsoBlock, setAlsoBlock] = useState(false);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger' | 'warning'; text: string } | null>(null);

  function reset() {
    setMode('menu');
    setCategory(null);
    setDescription('');
    setAlsoBlock(false);
    setNotice(null);
  }

  function close() {
    reset();
    onClose();
  }

  async function block() {
    setBusy(true);
    setNotice(null);
    try {
      await api.block(personId);
      await queryClient.invalidateQueries({ queryKey: ['conversations'] });
      await queryClient.invalidateQueries({ queryKey: ['matches'] });
      await queryClient.invalidateQueries({ queryKey: ['deck'] });
      onBlocked?.();
      close();
    } catch (e) {
      setNotice({ tone: 'danger', text: isApiError(e) ? (e.firstError ?? e.message) : 'Could not block.' });
    } finally {
      setBusy(false);
    }
  }

  async function report() {
    if (!category) return;
    setBusy(true);
    setNotice(null);
    try {
      const result = await api.report({ reported_id: personId, category, description: description.trim() || undefined, message_id: messageId });
      if (alsoBlock) {
        await block();
        return;
      }
      setNotice({ tone: 'success', text: result.message });
      setMode('menu');
    } catch (e) {
      // One report per person per day is a server rule; it comes back as a 422.
      setNotice({ tone: isApiError(e) && e.code === 'validation_failed' ? 'warning' : 'danger', text: isApiError(e) ? (e.firstError ?? e.message) : 'Could not send the report.' });
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal transparent animationType="slide" visible={visible} onRequestClose={close}>
      <View style={styles.backdrop}>
        <View style={styles.sheet}>
          <ScrollView keyboardShouldPersistTaps="handled">
            {notice ? <Banner tone={notice.tone}>{notice.text}</Banner> : null}

            {mode === 'menu' ? (
              <>
                <Subtitle>{personName}</Subtitle>
                <Gap />
                <Button title="Report" variant="secondary" onPress={() => setMode('report')} />
                <Gap size="sm" />
                <Button title="Block" variant="danger" onPress={() => setMode('block')} />
                <Gap size="sm" />
                <Button title="Cancel" variant="ghost" onPress={close} />
              </>
            ) : null}

            {mode === 'block' ? (
              <>
                <Subtitle>Block {personName}?</Subtitle>
                <Body muted>You will disappear from each other’s Discover and any conversation closes. They are not told.</Body>
                <Gap />
                <Button title="Block" variant="danger" onPress={block} loading={busy} />
                <Gap size="sm" />
                <Button title="Back" variant="ghost" onPress={() => setMode('menu')} />
              </>
            ) : null}

            {mode === 'report' ? (
              <>
                <Subtitle>Report {personName}</Subtitle>
                <Body muted>Our safety team reviews every report. {messageId ? 'This report points at the selected message.' : ''}</Body>
                <Gap />
                <RadioGroup options={REPORT_CATEGORIES} value={category} onChange={(v) => setCategory(v as ReportCategory)} />
                <Gap />
                <TextField label="Anything else? (optional)" value={description} onChangeText={setDescription} multiline maxLength={2000} style={{ minHeight: 80, textAlignVertical: 'top' }} />
                <Button title={alsoBlock ? 'Also block: on' : 'Also block: off'} variant="ghost" onPress={() => setAlsoBlock((v) => !v)} />
                <Gap size="sm" />
                <Button title="Send report" onPress={report} loading={busy} disabled={!category} />
                <Gap size="sm" />
                <Button title="Back" variant="ghost" onPress={() => setMode('menu')} />
              </>
            ) : null}
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: { flex: 1, backgroundColor: 'rgba(42, 32, 48, 0.55)', justifyContent: 'flex-end' },
  sheet: { maxHeight: '85%', backgroundColor: colors.card, borderTopLeftRadius: radius.lg, borderTopRightRadius: radius.lg, padding: spacing.xl },
});
