import React, { useState } from 'react';
import { Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { Image } from 'expo-image';
import * as ImagePicker from 'expo-image-picker';
import { api } from '../../api';
import { isApiError } from '../../api/errors';
import type { Photo } from '../../api/types';
import { useSession } from '../../auth/SessionProvider';
import { useConfig } from '../../config/ConfigProvider';
import { Banner, Body, Button, Gap, Row } from '../../ui';
import { colors, radius, spacing } from '../../ui/theme';

/**
 * The member's photos: add, remove, choose the main one, reorder.
 *
 * Every change goes to the server and then re-reads /me, because a photo is
 * a completion item and completion is what promotes a pending account — the
 * re-read is what triggers the token swap in SessionProvider.
 *
 * The limit is whatever /config says (operator-set); the server enforces it
 * and answers 422 photo_limit_reached, which is shown verbatim.
 */
export function PhotoManager({ photos }: { photos: Photo[] }) {
  const { refreshMe } = useSession();
  const { config } = useConfig();
  const [busy, setBusy] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ tone: 'danger' | 'warning' | 'info'; text: string } | null>(null);

  const full = photos.length >= config.max_photos;

  async function run(label: string, work: () => Promise<unknown>) {
    setBusy(label);
    setNotice(null);
    try {
      await work();
      await refreshMe();
    } catch (e) {
      if (isApiError(e) && e.code === 'photo_limit_reached') {
        setNotice({ tone: 'warning', text: e.message });
      } else if (isApiError(e) && e.code === 'validation_failed') {
        setNotice({ tone: 'danger', text: e.fieldError('photo') ?? e.fieldError('uuids') ?? e.message });
      } else if (isApiError(e)) {
        setNotice({ tone: 'danger', text: e.message });
      } else {
        setNotice({ tone: 'danger', text: 'That did not work. Try again.' });
      }
    } finally {
      setBusy(null);
    }
  }

  async function pick(fromCamera: boolean) {
    const permission = fromCamera
      ? await ImagePicker.requestCameraPermissionsAsync()
      : await ImagePicker.requestMediaLibraryPermissionsAsync();

    if (!permission.granted) {
      setNotice({ tone: 'info', text: fromCamera ? 'Camera access is needed to take a photo.' : 'Photo library access is needed to choose a photo.' });
      return;
    }

    const options: ImagePicker.ImagePickerOptions = { mediaTypes: ['images'], quality: 0.9, allowsEditing: true, aspect: [4, 5] };
    const result = fromCamera ? await ImagePicker.launchCameraAsync(options) : await ImagePicker.launchImageLibraryAsync(options);

    if (result.canceled || !result.assets?.[0]) return;

    const asset = result.assets[0];
    const form = new FormData();
    // React Native's FormData takes a {uri, name, type} descriptor for files.
    form.append('photo', {
      uri: asset.uri,
      name: asset.fileName ?? `photo-${Date.now()}.jpg`,
      type: asset.mimeType ?? 'image/jpeg',
    } as unknown as Blob);

    await run('upload', () => api.uploadPhoto(form));
  }

  function remove(photo: Photo) {
    Alert.alert('Remove this photo?', undefined, [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Remove', style: 'destructive', onPress: () => void run(photo.id, () => api.deletePhoto(photo.id)) },
    ]);
  }

  function move(index: number, direction: -1 | 1) {
    const target = index + direction;
    if (target < 0 || target >= photos.length) return;
    const order = photos.map((p) => p.id);
    [order[index], order[target]] = [order[target] as string, order[index] as string];
    void run('reorder', () => api.reorderPhotos(order));
  }

  return (
    <View>
      {notice ? <Banner tone={notice.tone}>{notice.text}</Banner> : null}

      <View style={styles.grid}>
        {photos.map((photo, index) => (
          <View key={photo.id} style={styles.tile}>
            <Image source={{ uri: photo.thumb_url }} style={styles.image} contentFit="cover" accessibilityLabel={`Photo ${index + 1}`} />
            {photo.is_primary ? <Text style={styles.primaryBadge}>Main</Text> : null}
            {photo.moderation_status && photo.moderation_status !== 'approved' ? (
              <Text style={styles.pendingBadge}>{photo.moderation_status === 'pending' ? 'In review' : photo.moderation_status}</Text>
            ) : null}
            <Row style={styles.tileActions}>
              <Small label="◀" onPress={() => move(index, -1)} disabled={index === 0 || busy !== null} />
              <Small label="▶" onPress={() => move(index, 1)} disabled={index === photos.length - 1 || busy !== null} />
              {!photo.is_primary ? (
                <Small label="Main" onPress={() => void run(photo.id, () => api.makePrimaryPhoto(photo.id))} disabled={busy !== null} />
              ) : null}
              <Small label="✕" onPress={() => remove(photo)} disabled={busy !== null} danger />
            </Row>
          </View>
        ))}
      </View>

      <Gap />
      <Body muted>
        {photos.length} of {config.max_photos} photos.{' '}
        {full ? 'Remove one to add another.' : 'New photos show once the team has checked them.'}
      </Body>
      <Gap size="sm" />
      <Row>
        <Button title="Choose from library" variant="secondary" onPress={() => void pick(false)} loading={busy === 'upload'} disabled={full || busy !== null} style={styles.half} />
        <Button title="Take a photo" variant="secondary" onPress={() => void pick(true)} disabled={full || busy !== null} style={styles.half} />
      </Row>
    </View>
  );
}

function Small({ label, onPress, disabled, danger }: { label: string; onPress: () => void; disabled?: boolean; danger?: boolean }) {
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      accessibilityRole="button"
      accessibilityLabel={label === '◀' ? 'Move earlier' : label === '▶' ? 'Move later' : label === '✕' ? 'Remove' : 'Make main photo'}
      style={[styles.small, disabled && { opacity: 0.35 }]}
    >
      <Text style={[styles.smallText, danger && { color: colors.danger }]}>{label}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
  tile: { width: '31%', aspectRatio: 4 / 5, borderRadius: radius.md, overflow: 'hidden', backgroundColor: colors.wash },
  image: { width: '100%', height: '100%' },
  primaryBadge: { position: 'absolute', top: 6, left: 6, backgroundColor: colors.primary, color: '#fff', fontSize: 11, fontWeight: '700', paddingHorizontal: 6, paddingVertical: 2, borderRadius: radius.pill },
  pendingBadge: { position: 'absolute', top: 6, right: 6, backgroundColor: 'rgba(42,32,48,0.75)', color: '#fff', fontSize: 10, paddingHorizontal: 6, paddingVertical: 2, borderRadius: radius.pill },
  tileActions: { position: 'absolute', bottom: 0, left: 0, right: 0, backgroundColor: 'rgba(255,255,255,0.9)', justifyContent: 'space-around', paddingVertical: 4, gap: 0 },
  small: { paddingHorizontal: 6, paddingVertical: 4 },
  smallText: { fontSize: 13, fontWeight: '600', color: colors.ink },
  half: { flex: 1 },
});
