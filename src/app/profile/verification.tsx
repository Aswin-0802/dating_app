import React, { useRef, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { useQuery } from '@tanstack/react-query';
import { api } from '../../api';
import { isApiError } from '../../api/errors';
import { useSession } from '../../auth/SessionProvider';
import { Banner, Body, Button, Card, Gap, Loading, Screen, Subtitle } from '../../ui';
import { colors, radius, spacing } from '../../ui/theme';

/**
 * Selfie verification.
 *
 * The server issues a 4-character code, holds it for ten minutes, and the
 * selfie must show it. The photo goes to a private disk and a human reviews
 * it. Three attempts in total; after that the server answers
 * verification_attempts_exhausted and the only way on is support.
 */
export default function VerificationScreen() {
  const { refreshMe } = useSession();
  const status = useQuery({ queryKey: ['verification'], queryFn: () => api.verification() });
  const [permission, requestPermission] = useCameraPermissions();
  const camera = useRef<CameraView>(null);
  const [code, setCode] = useState<string | null>(null);
  const [capturing, setCapturing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'success' | 'warning' | 'danger' | 'info'; text: string } | null>(null);

  const current = status.data?.data ?? null;
  const open = current?.status === 'pending' || current?.status === 'in_review' || current?.status === 'escalated';
  const exhausted = current !== null && current.attempts_remaining === 0 && current.status !== 'approved';

  async function start() {
    setNotice(null);
    if (!permission?.granted) {
      const result = await requestPermission();
      if (!result.granted) {
        setNotice({ tone: 'info', text: 'Camera access is needed to take the selfie.' });
        return;
      }
    }
    try {
      const issued = await api.gestureCode();
      setCode(issued.gesture_code);
      setCapturing(true);
    } catch (e) {
      setNotice({ tone: 'danger', text: isApiError(e) ? e.message : 'Could not get a code.' });
    }
  }

  async function capture() {
    if (!camera.current || !code) return;
    setBusy(true);
    setNotice(null);
    try {
      const picture = await camera.current.takePictureAsync({ quality: 0.85 });
      if (!picture) throw new Error('No picture');

      const form = new FormData();
      form.append('selfie', { uri: picture.uri, name: 'selfie.jpg', type: 'image/jpeg' } as unknown as Blob);
      form.append('gesture_code', code);

      await api.submitVerification(form);
      setCapturing(false);
      setCode(null);
      await status.refetch();
      await refreshMe();
      setNotice({ tone: 'success', text: 'Sent. A reviewer will check it and you will hear back.' });
    } catch (e) {
      if (isApiError(e) && e.code === 'verification_attempts_exhausted') {
        setCapturing(false);
        setNotice({ tone: 'danger', text: e.message });
        await status.refetch();
      } else if (isApiError(e) && e.code === 'validation_failed') {
        // Usually the code expired while the camera was open. A fresh one is
        // one tap away.
        setNotice({ tone: 'warning', text: e.fieldError('gesture_code') ?? e.fieldError('selfie') ?? e.message });
        setCode(null);
        setCapturing(false);
      } else {
        setNotice({ tone: 'danger', text: isApiError(e) ? e.message : 'Could not send the selfie.' });
      }
    } finally {
      setBusy(false);
    }
  }

  if (status.isLoading) return <Loading />;

  if (capturing && code) {
    return (
      <Screen padded={false}>
        <CameraView ref={camera} style={styles.camera} facing="front" />
        <View style={styles.overlay}>
          <Text style={styles.codeLabel}>Hold up this code, clearly visible</Text>
          <Text style={styles.code} accessibilityLabel={`Code ${code.split('').join(' ')}`}>
            {code}
          </Text>
          <Body center>
            <Text style={{ color: '#fff' }}>Write it on paper or show it on another screen. The code lasts ten minutes.</Text>
          </Body>
          <Gap />
          <Button title="Take the selfie" onPress={capture} loading={busy} />
          <Gap size="sm" />
          <Button title="Cancel" variant="ghost" onPress={() => setCapturing(false)} />
        </View>
      </Screen>
    );
  }

  return (
    <Screen scroll>
      {notice ? <Banner tone={notice.tone}>{notice.text}</Banner> : null}
      <Card>
        <Subtitle>{label(current?.status)}</Subtitle>
        {current?.rejection_reason ? <Body muted>Reason: {current.rejection_reason}</Body> : null}
        {current ? <Body muted>{current.attempts_remaining} of {current.attempts_used + current.attempts_remaining} attempts left</Body> : null}
      </Card>
      <Gap />
      <Body>
        Verification adds a badge to your profile and lets people who choose “verified only” see you. You take a selfie holding a
        code the app shows you; a member of the team compares it with your photos.
      </Body>
      <Gap size="lg" />
      {current?.status === 'approved' ? (
        <Banner tone="success">You’re verified.</Banner>
      ) : open ? (
        <Banner tone="info">Your selfie is with the review team.</Banner>
      ) : exhausted ? (
        <Banner tone="danger">You have used all your attempts. Contact support to continue.</Banner>
      ) : (
        <Button title={current?.status === 'rejected' ? 'Try again' : 'Start verification'} onPress={start} />
      )}
    </Screen>
  );
}

function label(status: string | null | undefined): string {
  switch (status) {
    case 'approved':
      return 'Verified';
    case 'pending':
    case 'in_review':
      return 'In review';
    case 'escalated':
      return 'Under further review';
    case 'rejected':
      return 'Not approved';
    case 'expired':
      return 'Expired';
    default:
      return 'Not verified';
  }
}

const styles = StyleSheet.create({
  camera: { flex: 1 },
  overlay: { position: 'absolute', left: 0, right: 0, bottom: 0, padding: spacing.xl, backgroundColor: 'rgba(42,32,48,0.8)', borderTopLeftRadius: radius.lg, borderTopRightRadius: radius.lg },
  codeLabel: { color: '#fff', textAlign: 'center', marginBottom: spacing.sm },
  code: { color: '#fff', fontSize: 48, fontWeight: '800', letterSpacing: 8, textAlign: 'center', marginBottom: spacing.sm, backgroundColor: colors.primary, borderRadius: radius.md, paddingVertical: spacing.sm },
});
