import React, { useState } from 'react';
import { Link, router } from 'expo-router';
import { isApiError } from '../../api/errors';
import { useSession } from '../../auth/SessionProvider';
import { Banner, Body, Button, Gap, Screen, TextField, Title } from '../../ui';

export default function SignIn() {
  const { signIn } = useSession();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    setBusy(true);
    setError(null);

    try {
      await signIn(email.trim(), password);
      router.replace('/');
    } catch (e) {
      // The server answers a wrong password and an unknown email identically,
      // on purpose: nothing here may reveal whether an address is a member.
      if (isApiError(e)) setError(e.firstError ?? e.message);
      else setError('Could not sign in.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen scroll>
      <Gap size="xxl" />
      <Title>Welcome back</Title>
      <Body muted>Sign in to keep going.</Body>
      <Gap size="xl" />
      {error ? <Banner tone="danger">{error}</Banner> : null}
      <TextField
        label="Email"
        value={email}
        onChangeText={setEmail}
        autoCapitalize="none"
        autoComplete="email"
        keyboardType="email-address"
        textContentType="emailAddress"
      />
      <TextField
        label="Password"
        value={password}
        onChangeText={setPassword}
        secureTextEntry
        autoComplete="current-password"
        textContentType="password"
        onSubmitEditing={submit}
      />
      <Gap size="sm" />
      <Button title="Sign in" onPress={submit} loading={busy} disabled={!email || !password} />
      <Gap size="lg" />
      <Body center muted>
        New here? <Link href="/(auth)/register">Create an account</Link>
      </Body>
    </Screen>
  );
}
