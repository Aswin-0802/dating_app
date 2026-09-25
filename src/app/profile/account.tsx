import React, { useState } from 'react';
import { Alert } from 'react-native';
import { api } from '../../api';
import { isApiError } from '../../api/errors';
import { useMe, useSession } from '../../auth/SessionProvider';
import { Banner, Body, Button, Card, Gap, Screen, Subtitle, TextField } from '../../ui';

/**
 * Account: sign out everywhere, and deletion.
 *
 * Deletion is permanent — the server anonymises the account and removes the
 * photos — and Apple guideline 5.1.1(v) requires that it be offered in-app.
 * The member is warned twice and must type their password; the server
 * checks it.
 */
export default function Account() {
  const me = useMe();
  const { signOut } = useSession();
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  async function signOutEverywhere() {
    setBusy(true);
    try {
      await api.logoutAll();
    } catch {
      /* local sign-out still happens */
    }
    await signOut();
  }

  function confirmDelete() {
    Alert.alert(
      'Delete your account?',
      `This cannot be undone. Your name, photos, email and phone number are removed. Messages you sent stay visible to the people you sent them to. If you only want a break, sign out instead.${
        me.premium_source === 'apple' || me.premium_source === 'google'
          ? '\n\nYour Premium subscription is billed by the store and is NOT cancelled by deleting your account. Cancel it in your phone\u2019s subscription settings, or the store keeps charging you.'
          : ''
      }`,
      [
        { text: 'Cancel', style: 'cancel' },
        { text: 'Delete permanently', style: 'destructive', onPress: () => void deleteAccount() },
      ],
    );
  }

  async function deleteAccount() {
    setBusy(true);
    setError(null);
    try {
      await api.deleteAccount(password);
      // The server has revoked every token; clear ours and leave.
      await signOut();
    } catch (e) {
      if (isApiError(e) && e.code === 'validation_failed') setError(e.fieldError('password') ?? e.firstError);
      else setError(isApiError(e) ? e.message : 'Could not delete the account.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen scroll>
      {notice ? <Banner tone="info">{notice}</Banner> : null}
      <Card>
        <Subtitle>Signed in as</Subtitle>
        <Body>{me.email}</Body>
        <Body muted>Member since {me.created_at ? new Date(me.created_at).toLocaleDateString() : '—'}</Body>
      </Card>
      <Gap />
      <Button title="Sign out of every device" variant="secondary" onPress={() => void signOutEverywhere()} loading={busy} />
      <Gap size="xxl" />
      <Subtitle>Delete account</Subtitle>
      <Body muted>Permanent and irreversible. Enter your password to confirm.</Body>
      {me.premium_source === 'apple' || me.premium_source === 'google' ? (
        <>
          <Gap size="sm" />
          <Banner tone="warning">
            Your Premium subscription is billed by {me.premium_source === 'apple' ? 'the App Store' : 'Google Play'} and deleting your account
            does not cancel it. Cancel it in your phone’s subscription settings first, or the store will keep charging you.
          </Banner>
        </>
      ) : null}
      <Gap />
      <TextField label="Password" value={password} onChangeText={setPassword} secureTextEntry autoComplete="current-password" error={error} />
      <Button title="Delete my account" variant="danger" onPress={confirmDelete} loading={busy} disabled={!password} />
      <Gap size="sm" />
      <Button title="Never mind" variant="ghost" onPress={() => setNotice('Nothing was changed.')} />
    </Screen>
  );
}
