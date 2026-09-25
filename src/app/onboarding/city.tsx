import React, { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../../api';
import { isApiError } from '../../api/errors';
import { useMe, useSession } from '../../auth/SessionProvider';
import { OnboardingFooter } from '../../features/onboarding/OnboardingFooter';
import { Banner, Body, Button, Gap, Label, Loading, RadioGroup, Screen, TextField, Title } from '../../ui';

/**
 * Country → state → city, with the city found by typing.
 *
 * The API never hands over a full city list: /cities is a search (two
 * letters or more, capped at 100) so a member in a city far down the
 * alphabet is found rather than silently cut off. The city is what Discover
 * falls back to for members with no coordinates, and a checklist item.
 */
export default function OnboardingCity() {
  const me = useMe();
  const { refreshMe } = useSession();

  const [country, setCountry] = useState<string | null>(me.city?.country ?? null);
  const [stateId, setStateId] = useState<number | null>(null);
  const [cityId, setCityId] = useState<number | null>(null);
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');
  const [busy, setBusy] = useState(false);
  const [banner, setBanner] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  // Type-ahead: wait for the member to pause before asking the server.
  useEffect(() => {
    const id = setTimeout(() => setDebounced(search.trim()), 300);
    return () => clearTimeout(id);
  }, [search]);

  const countries = useQuery({ queryKey: ['countries'], queryFn: () => api.countries(), staleTime: Infinity });
  const states = useQuery({ queryKey: ['states', country], queryFn: () => api.states(country!), enabled: !!country, staleTime: Infinity });
  const cities = useQuery({
    queryKey: ['cities', country, stateId, debounced],
    queryFn: () => api.cities({ country: country ?? undefined, state: stateId ?? undefined, search: debounced }),
    enabled: !!country && debounced.length >= 2,
    staleTime: 60_000,
  });

  const hasStates = (states.data?.data.length ?? 0) > 0;
  const cityOptions = (cities.data?.data ?? []).map((c) => ({ value: String(c.id), label: c.state ? `${c.name}, ${c.state}` : c.name }));

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
          setSearch('');
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
              setSearch('');
            }}
          />
        </>
      ) : null}
      {country && (!hasStates || stateId) ? (
        <>
          <Gap />
          <Label>City</Label>
          <TextField placeholder="Start typing your city" value={search} onChangeText={setSearch} autoCorrect={false} autoCapitalize="words" />
          {debounced.length > 0 && debounced.length < 2 ? <Body muted>Type at least two letters.</Body> : null}
          {cities.isLoading ? <Loading /> : null}
          {cities.isError ? <Banner tone="danger">{isApiError(cities.error) ? cities.error.message : 'Could not search cities.'}</Banner> : null}
          {cities.data && cityOptions.length === 0 ? (
            <Body muted>No city starts with “{debounced}”. Check the spelling, or tell support if your city is missing.</Body>
          ) : null}
          {cityOptions.length > 0 ? <RadioGroup options={cityOptions} value={cityId ? String(cityId) : null} onChange={(v) => setCityId(Number(v))} /> : null}
          {cities.data?.meta.truncated ? <Body muted>Showing the first {cities.data.meta.limit}. Keep typing to narrow it down.</Body> : null}
        </>
      ) : null}
      <Gap size="lg" />
      <Button title="Save" onPress={save} loading={busy} disabled={!cityId} />
      <OnboardingFooter next={null} />
    </Screen>
  );
}
