import React, { useState } from 'react';
import { Modal, StyleSheet, View } from 'react-native';
import { isApiError } from '../api/errors';
import { useSession } from '../auth/SessionProvider';
import { Body, Button, Gap, Subtitle, TextField } from './index';
import { colors, radius, spacing } from './theme';

/**
 * Shown when the account was promoted to active but the token could not be
 * swapped silently — the onboarding password was not kept, or it changed.
 * One password entry, and swiping unlocks. Without this the member would
 * see 403s on a perfectly good account.
 */
export function ReauthModal() {
  const { reauth, signOut } = useSession();
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      await reauth(password);
    } catch (e) {
      setError(isApiError(e) ? (e.firstError ?? e.message) : 'Could not sign in.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal transparent animationType="fade" visible>
      <View style={styles.backdrop}>
        <View style={styles.sheet}>
          <Subtitle>Your profile is live</Subtitle>
          <Body muted>Enter your password once more to unlock swiping and messages.</Body>
          <Gap />
          <TextField
            label="Password"
            value={password}
            onChangeText={setPassword}
            secureTextEntry
            autoComplete="current-password"
            error={error}
            onSubmitEditing={submit}
          />
          <Button title="Unlock" onPress={submit} loading={busy} disabled={!password} />
          <Gap size="sm" />
          <Button title="Sign out instead" variant="ghost" onPress={signOut} />
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: { flex: 1, backgroundColor: 'rgba(42, 32, 48, 0.55)', justifyContent: 'flex-end' },
  sheet: { backgroundColor: colors.card, borderTopLeftRadius: radius.lg, borderTopRightRadius: radius.lg, padding: spacing.xl },
});
