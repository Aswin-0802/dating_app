import React, { useState } from 'react';
import { api } from '../../api';
import { isApiError } from '../../api/errors';
import { useMe, useSession } from '../../auth/SessionProvider';
import { CHILDREN, DRINKING, EDUCATION, RELATIONSHIP_GOALS, SMOKING, toEntries } from '../../lib/options';
import { Banner, Button, Gap, Label, RadioGroup, Screen, TextField } from '../../ui';

export default function EditProfile() {
  const me = useMe();
  const { refreshMe } = useSession();
  const p = me.profile;

  const [name, setName] = useState(me.display_name);
  const [pronouns, setPronouns] = useState(me.pronouns ?? '');
  const [bio, setBio] = useState(p?.bio ?? '');
  const [job, setJob] = useState(p?.job_title ?? '');
  const [company, setCompany] = useState(p?.company ?? '');
  const [school, setSchool] = useState(p?.school ?? '');
  const [height, setHeight] = useState(p?.height_cm ? String(p.height_cm) : '');
  const [education, setEducation] = useState<string | null>(p?.education ?? null);
  const [goal, setGoal] = useState<string | null>(p?.relationship_goal ?? null);
  const [drinking, setDrinking] = useState<string | null>(p?.drinking ?? null);
  const [smoking, setSmoking] = useState<string | null>(p?.smoking ?? null);
  const [children, setChildren] = useState<string | null>(p?.children ?? null);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  async function save() {
    setBusy(true);
    setErrors({});
    setBanner(null);
    try {
      // Two endpoints, two concerns: the account row and the profile row.
      await api.updateMe({ display_name: name.trim(), pronouns: pronouns.trim() || null });
      await api.updateProfile({
        bio: bio.trim() || null,
        job_title: job.trim() || null,
        company: company.trim() || null,
        school: school.trim() || null,
        height_cm: height ? Number.parseInt(height, 10) : null,
        education,
        ...(goal ? { relationship_goal: goal } : {}),
        ...(drinking ? { drinking } : {}),
        ...(smoking ? { smoking } : {}),
        ...(children ? { children } : {}),
      });
      await refreshMe();
      setBanner({ tone: 'success', text: 'Saved.' });
    } catch (e) {
      if (isApiError(e) && e.code === 'validation_failed') {
        const next: Record<string, string> = {};
        for (const [field, messages] of Object.entries(e.errors)) next[field] = messages[0] ?? '';
        setErrors(next);
        if (!Object.keys(next).length) setBanner({ tone: 'danger', text: e.message });
      } else {
        setBanner({ tone: 'danger', text: isApiError(e) ? e.message : 'Could not save.' });
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen scroll>
      {banner ? <Banner tone={banner.tone}>{banner.text}</Banner> : null}
      <TextField label="Name" value={name} onChangeText={setName} error={errors.display_name} maxLength={60} />
      <TextField label="Pronouns" value={pronouns} onChangeText={setPronouns} error={errors.pronouns} maxLength={30} />
      <TextField label="Bio" value={bio} onChangeText={setBio} error={errors.bio} multiline maxLength={500} style={{ minHeight: 96, textAlignVertical: 'top' }} />
      <TextField label="Job" value={job} onChangeText={setJob} error={errors.job_title} maxLength={100} />
      <TextField label="Company" value={company} onChangeText={setCompany} error={errors.company} maxLength={100} />
      <TextField label="School" value={school} onChangeText={setSchool} error={errors.school} maxLength={100} />
      <TextField label="Height (cm)" value={height} onChangeText={setHeight} error={errors.height_cm} keyboardType="number-pad" maxLength={3} />
      <Label>Education</Label>
      <RadioGroup options={toEntries(EDUCATION)} value={education} onChange={setEducation} />
      <Gap />
      <Label>Looking for</Label>
      <RadioGroup options={toEntries(RELATIONSHIP_GOALS)} value={goal} onChange={setGoal} />
      <Gap />
      <Label>Drinking</Label>
      <RadioGroup options={toEntries(DRINKING)} value={drinking} onChange={setDrinking} />
      <Gap />
      <Label>Smoking</Label>
      <RadioGroup options={toEntries(SMOKING)} value={smoking} onChange={setSmoking} />
      <Gap />
      <Label>Children</Label>
      <RadioGroup options={toEntries(CHILDREN)} value={children} onChange={setChildren} />
      <Gap size="xl" />
      <Button title="Save" onPress={save} loading={busy} disabled={name.trim().length < 2} />
    </Screen>
  );
}
