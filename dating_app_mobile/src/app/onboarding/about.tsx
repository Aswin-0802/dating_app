import React, { useState } from 'react';
import { api } from '../../api';
import { isApiError } from '../../api/errors';
import { useMe, useSession } from '../../auth/SessionProvider';
import { OnboardingFooter } from '../../features/onboarding/OnboardingFooter';
import { EDUCATION, RELATIONSHIP_GOALS, toEntries } from '../../lib/options';
import { Banner, Body, Button, Gap, Label, RadioGroup, Screen, TextField, Title } from '../../ui';

/**
 * bio · job · height · education · relationship goal — five checklist items
 * in one PATCH /me/profile. With a photo already up, saving this alone takes
 * a new account past 50%.
 */
export default function OnboardingAbout() {
  const me = useMe();
  const { refreshMe } = useSession();
  const profile = me.profile;

  const [bio, setBio] = useState(profile?.bio ?? '');
  const [job, setJob] = useState(profile?.job_title ?? '');
  const [height, setHeight] = useState(profile?.height_cm ? String(profile.height_cm) : '');
  const [education, setEducation] = useState<string | null>(profile?.education ?? null);
  const [goal, setGoal] = useState<string | null>(profile?.relationship_goal && profile.relationship_goal !== 'unspecified' ? profile.relationship_goal : null);
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState<string | null>(null);

  async function save() {
    setBusy(true);
    setErrors({});
    setBanner(null);
    setSaved(false);

    try {
      await api.updateProfile({
        bio: bio.trim() || null,
        job_title: job.trim() || null,
        height_cm: height ? Number.parseInt(height, 10) : null,
        education,
        ...(goal ? { relationship_goal: goal } : {}),
      });
      await refreshMe();
      setSaved(true);
    } catch (e) {
      if (isApiError(e) && e.code === 'validation_failed') {
        const next: Record<string, string> = {};
        for (const [field, messages] of Object.entries(e.errors)) next[field] = messages[0] ?? '';
        setErrors(next);
      } else {
        setBanner(isApiError(e) ? e.message : 'Could not save.');
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen scroll>
      <Title>About you</Title>
      <Body muted>Each of these counts towards your profile.</Body>
      <Gap />
      {banner ? <Banner tone="danger">{banner}</Banner> : null}
      {saved ? <Banner tone="success">Saved.</Banner> : null}
      <TextField label="Bio" value={bio} onChangeText={setBio} error={errors.bio} multiline numberOfLines={4} maxLength={500} style={{ minHeight: 96, textAlignVertical: 'top' }} />
      <TextField label="Job" value={job} onChangeText={setJob} error={errors.job_title} maxLength={100} />
      <TextField label="Height (cm)" value={height} onChangeText={setHeight} error={errors.height_cm} keyboardType="number-pad" maxLength={3} />
      <Label>Education</Label>
      <RadioGroup options={toEntries(EDUCATION)} value={education} onChange={setEducation} />
      {errors.education ? <Body>{errors.education}</Body> : null}
      <Gap />
      <Label>Looking for</Label>
      <RadioGroup options={toEntries(RELATIONSHIP_GOALS).filter((o) => o.value !== 'unspecified')} value={goal} onChange={setGoal} />
      {errors.relationship_goal ? <Body>{errors.relationship_goal}</Body> : null}
      <Gap size="lg" />
      <Button title="Save" onPress={save} loading={busy} />
      <OnboardingFooter next="/onboarding/interests" />
    </Screen>
  );
}
