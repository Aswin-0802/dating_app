import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../../api';
import { isApiError } from '../../api/errors';
import { useMe, useSession } from '../../auth/SessionProvider';
import { OnboardingFooter } from '../../features/onboarding/OnboardingFooter';
import { Banner, Body, Button, Gap, Label, Loading, RadioGroup, Screen, TextField, Title } from '../../ui';

/**
 * Country → state → city, each list from the API. The city is what Discover
 * falls back to for members with no coordinates, and it is a checklist item.
 */
export default function OnboardingCity() {
  const me = useMe();
  const { refreshMe } = useSession();

  const [country, setCountry] = useState<string | null>(me.city?.country ?? null);
  const [stateId, setStateId] = useState<number | null>(null);
  const [cityId, setCityId] = useState<number | null>(null);
  const [filter, setFilter] = useState('');
  const [busy, setBusy] = useState(false);
  const [banner, setBanner] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  const countries = useQuery({ queryKey: ['countries'], queryFn: () => api.countries(), staleTime: Infinity });
  const states = useQuery({ queryKey: ['states', country], queryFn: () => api.states(country!), enabled: !!country, staleTime: Infinity });
  const cities = useQuery({
    queryKey: ['cities', country, stateId],
    queryFn: () => api.cities({ country: country ?? undefined, state: stateId ?? undefined }),
    enabled: !!country,
    staleTime: Infinity,
  });

  const hasStates = (states.data?.data.length ?? 0) > 0;

  const cityOptions = (cities.data?.data ?? [])
    .filter((c) => !filter || c.name.toLowerCase().includes(filter.toLowerCase()))
    .slice(0, 60)
    .map((c) => ({ value: String(c.id), label: c.state ? `${c.name}, ${c.state}` : c.name }));

  async function save() {
    if (!cityId) return;
    setBusy(true);
    setBanner(null);
    try {
      await api.updateMe({ city_id: cityId });
      await refreshMe();
      setBanner({ tone: 'success', text: 'Saved.' });
    } catch (e) {
      setBanner({ tone: 'danger', text: isApiError(e) ? (e.fieldError('city_id') ?? e.message) : 'Could not save.' });
    } finally {
      setBusy(false);
    }
  }

  if (countries.isLoading) return <Loading />;

  return (
    <Screen scroll>
      <Title>Where are you?</Title>
      <Body muted>{me.city ? `Currently: ${me.city.name}` : 'People near you are shown first.'}</Body>
      <Gap />
      {banner ? <Banner tone={banner.tone}>{banner.text}</Banner> : null}
      <Label>Country</Label>
      <RadioGroup
        options={(countries.data?.data ?? []).map((c) => ({ value: c.iso2, label: c.name }))}
        value={country}
        onChange={(v) => {
          setCountry(v);
          setStateId(null);
          setCityId(null);
        }}
      />
      {country && hasStates ? (
        <>
          <Gap />
          <Label>State or region</Label>
          <RadioGroup
            options={(states.data?.data ?? []).map((s) => ({ value: String(s.id), label: s.name }))}
            value={stateId ? String(stateId) : null}
            onChange={(v) => {
              setStateId(Number(v));
              setCityId(null);
            }}
          />
        </>
      ) : null}
      {country && (!hasStates || stateId) ? (
        <>
          <Gap />
          <Label>City</Label>
          <TextField placeholder="Search cities" value={filter} onChangeText={setFilter} />
          {cities.isLoading ? <Loading /> : <RadioGroup options={cityOptions} value={cityId ? String(cityId) : null} onChange={(v) => setCityId(Number(v))} />}
        </>
      ) : null}
      <Gap size="lg" />
      <Button title="Save" onPress={save} loading={busy} disabled={!cityId} />
      <OnboardingFooter next={null} />
    </Screen>
  );
}
