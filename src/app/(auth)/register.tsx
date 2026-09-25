import React, { useMemo, useState } from 'react';
import { Link, router } from 'expo-router';
import { isApiError } from '../../api/errors';
import type { Gender } from '../../api/types';
import { useSession } from '../../auth/SessionProvider';
import { useConfig } from '../../config/ConfigProvider';
import { ageFromBirthdate } from '../../lib/age';
import { GENDERS } from '../../lib/options';
import { Banner, Body, Button, ChipGroup, Gap, Label, RadioGroup, Screen, TextField, Title } from '../../ui';

export default function Register() {
  const { register } = useSession();
  const { config } = useConfig();
  const [form, setForm] = useState({ display_name: '', email: '', password: '', birthdate: '' });
  const [gender, setGender] = useState<Gender | null>(null);
  const [interestedIn, setInterestedIn] = useState<Gender[]>([]);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState<string | null>(null);

  const set = (key: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [key]: value }));

  // Mirrors the server's rule so the member hears it before submitting. The
  // server remains the authority: it checks the same thing and refuses.
  const age = useMemo(() => ageFromBirthdate(form.birthdate), [form.birthdate]);
  const tooYoung = form.birthdate.length === 10 && (age === null || age < config.min_age);

  const ready =
    form.display_name.trim().length >= 2 &&
    form.email.includes('@') &&
    form.password.length >= 8 &&
    form.birthdate.length === 10 &&
    !tooYoung &&
    gender !== null &&
    interestedIn.length > 0;

  async function submit() {
    if (!gender) return;
    setBusy(true);
    setErrors({});
    setBanner(null);

    try {
      await register({
        display_name: form.display_name.trim(),
        email: form.email.trim().toLowerCase(),
        password: form.password,
        birthdate: form.birthdate,
        gender,
        interested_in: interestedIn,
      });
      router.replace('/onboarding/photos');
    } catch (e) {
      if (isApiError(e) && e.code === 'validation_failed') {
        const next: Record<string, string> = {};
        for (const [field, messages] of Object.entries(e.errors)) next[field] = messages[0] ?? '';
        setErrors(next);
        if (!Object.keys(next).length) setBanner(e.message);
      } else if (isApiError(e)) {
        setBanner(e.message);
      } else {
        setBanner('Could not create your account.');
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen scroll>
      <Gap size="lg" />
      <Title>Create your account</Title>
      <Body muted>You must be {config.min_age} or older.</Body>
      <Gap size="lg" />
      {banner ? <Banner tone="danger">{banner}</Banner> : null}
      <TextField label="Name" value={form.display_name} onChangeText={set('display_name')} error={errors.display_name} autoComplete="name" />
      <TextField
        label="Email"
        value={form.email}
        onChangeText={set('email')}
        error={errors.email}
        autoCapitalize="none"
        keyboardType="email-address"
        autoComplete="email"
      />
      <TextField
        label="Password"
        value={form.password}
        onChangeText={set('password')}
        error={errors.password}
        secureTextEntry
        autoComplete="new-password"
        placeholder="At least 8 characters, letters and numbers"
      />
      <TextField
        label="Date of birth"
        value={form.birthdate}
        onChangeText={set('birthdate')}
        error={errors.birthdate ?? (tooYoung ? `You must be at least ${config.min_age} to use this service.` : null)}
        placeholder="YYYY-MM-DD"
        keyboardType="numbers-and-punctuation"
        maxLength={10}
      />
      <Label>I am a</Label>
      <RadioGroup options={GENDERS} value={gender} onChange={(v) => setGender(v as Gender)} />
      {errors.gender ? <Body>{errors.gender}</Body> : null}
      <Gap />
      <Label>I want to meet</Label>
      <ChipGroup options={GENDERS} value={interestedIn} onChange={(v) => setInterestedIn(v as Gender[])} />
      {errors.interested_in ? <Body>{errors.interested_in}</Body> : null}
      <Gap size="xl" />
      <Button title="Create account" onPress={submit} loading={busy} disabled={!ready} />
      <Gap size="lg" />
      <Body center muted>
        Already a member? <Link href="/(auth)/sign-in">Sign in</Link>
      </Body>
    </Screen>
  );
}
