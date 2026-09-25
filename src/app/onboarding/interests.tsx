import React, { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../../api';
import { isApiError } from '../../api/errors';
import { useMe, useSession } from '../../auth/SessionProvider';
import { OnboardingFooter } from '../../features/onboarding/OnboardingFooter';
import { MAX_INTERESTS } from '../../lib/options';
import { Banner, Body, Button, ChipGroup, Gap, Label, Loading, Screen, Title } from '../../ui';

export default function OnboardingInterests() {
  const me = useMe();
  const { refreshMe } = useSession();
  const interests = useQuery({ queryKey: ['interests'], queryFn: () => api.interests(), staleTime: 60 * 60 * 1000 });

  // /me carries names; the API wants slugs. Map through the catalogue.
  const initial = useMemo(() => {
    const byName = new Map((interests.data?.data ?? []).map((i) => [i.name, i.slug]));
    return (me.interests ?? []).map((name) => byName.get(name)).filter((s): s is string => !!s);
  }, [interests.data, me.interests]);

  const [selected, setSelected] = useState<string[] | null>(null);
  const value = selected ?? initial;
  const [busy, setBusy] = useState(false);
  const [banner, setBanner] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  const groups = useMemo(() => {
    const map = new Map<string, { value: string; label: string }[]>();
    for (const i of interests.data?.data ?? []) {
      if (!map.has(i.category)) map.set(i.category, []);
      map.get(i.category)!.push({ value: i.slug, label: i.name });
    }
    return [...map.entries()];
  }, [interests.data]);

  async function save() {
    setBusy(true);
    setBanner(null);
    try {
      await api.syncInterests(value);
      await refreshMe();
      setBanner({ tone: 'success', text: 'Saved.' });
    } catch (e) {
      setBanner({ tone: 'danger', text: isApiError(e) ? (e.firstError ?? e.message) : 'Could not save.' });
    } finally {
      setBusy(false);
    }
  }

  if (interests.isLoading) return <Loading />;

  return (
    <Screen scroll>
      <Title>Interests</Title>
      <Body muted>Pick three or more — up to {MAX_INTERESTS}. Three count towards your profile.</Body>
      <Gap />
      {banner ? <Banner tone={banner.tone}>{banner.text}</Banner> : null}
      {groups.map(([category, options]) => (
        <React.Fragment key={category}>
          <Label>{category}</Label>
          <ChipGroup options={options} value={value} onChange={setSelected} max={MAX_INTERESTS} />
          <Gap />
        </React.Fragment>
      ))}
      <Body muted>
        {value.length} of {MAX_INTERESTS} selected
      </Body>
      <Gap size="sm" />
      <Button title="Save" onPress={save} loading={busy} disabled={value.length === 0} />
      <OnboardingFooter next="/onboarding/city" />
    </Screen>
  );
}
