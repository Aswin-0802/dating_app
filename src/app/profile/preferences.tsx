import React, { useState } from 'react';
import { Switch } from 'react-native';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../../api';
import { isApiError } from '../../api/errors';
import type { Gender } from '../../api/types';
import { useMe, useSession } from '../../auth/SessionProvider';
import { useConfig } from '../../config/ConfigProvider';
import { GENDERS } from '../../lib/options';
import { Banner, Body, Button, ChipGroup, Gap, Label, Row, Screen, TextField } from '../../ui';

/**
 * Discover preferences. age_min and age_max are ALWAYS sent together: the
 * server validates the pair against what it has stored and refuses a lone
 * value that would invert the range.
 */
export default function Preferences() {
  const me = useMe();
  const { refreshMe } = useSession();
  const { config } = useConfig();
  const queryClient = useQueryClient();
  const prefs = me.preferences;

  const [interestedIn, setInterestedIn] = useState<Gender[]>(prefs?.interested_in ?? []);
  const [ageMin, setAgeMin] = useState(String(prefs?.age_min ?? config.min_age));
  const [ageMax, setAgeMax] = useState(String(prefs?.age_max ?? 99));
  const [distance, setDistance] = useState(String(prefs?.max_distance_km ?? config.max_distance_km));
  const [globalMode, setGlobalMode] = useState(prefs?.global_mode ?? false);
  const [verifiedOnly, setVerifiedOnly] = useState(prefs?.show_verified_only ?? false);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  const min = Number.parseInt(ageMin, 10);
  const max = Number.parseInt(ageMax, 10);
  const km = Number.parseInt(distance, 10);
  const rangeOk = Number.isFinite(min) && Number.isFinite(max) && min >= config.min_age && max >= min && max <= 99;
  const distanceOk = Number.isFinite(km) && km >= 1 && km <= config.max_distance_km;

  async function save() {
    setBusy(true);
    setErrors({});
    setBanner(null);
    try {
      await api.updatePreferences({
        interested_in: interestedIn,
        age_min: min,
        age_max: max,
        max_distance_km: km,
        global_mode: globalMode,
        show_verified_only: verifiedOnly,
      });
      await refreshMe();
      await queryClient.invalidateQueries({ queryKey: ['deck'] });
      setBanner({ tone: 'success', text: 'Saved. Discover will use these from now on.' });
    } catch (e) {
      if (isApiError(e) && e.code === 'validation_failed') {
        const next: Record<string, string> = {};
        for (const [field, messages] of Object.entries(e.errors)) next[field] = messages[0] ?? '';
        setErrors(next);
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
      <Label>Show me</Label>
      <ChipGroup options={GENDERS} value={interestedIn} onChange={(v) => setInterestedIn(v as Gender[])} />
      {errors.interested_in ? <Body>{errors.interested_in}</Body> : null}
      <Gap />
      <Row>
        <TextField label="Age from" value={ageMin} onChangeText={setAgeMin} error={errors.age_min} keyboardType="number-pad" maxLength={2} style={{ flex: 1 }} />
        <TextField label="Age to" value={ageMax} onChangeText={setAgeMax} error={errors.age_max} keyboardType="number-pad" maxLength={2} style={{ flex: 1 }} />
      </Row>
      {!rangeOk ? <Body muted>Ages must be {config.min_age} to 99, and the range cannot be inverted.</Body> : null}
      <Gap />
      <TextField label={`Distance (km, up to ${config.max_distance_km})`} value={distance} onChangeText={setDistance} error={errors.max_distance_km} keyboardType="number-pad" maxLength={3} />
      <Row style={{ justifyContent: 'space-between' }}>
        <Body>Global mode — no distance limit</Body>
        <Switch value={globalMode} onValueChange={setGlobalMode} accessibilityLabel="Global mode" />
      </Row>
      <Gap />
      <Row style={{ justifyContent: 'space-between' }}>
        <Body>Verified people only</Body>
        <Switch value={verifiedOnly} onValueChange={setVerifiedOnly} accessibilityLabel="Verified people only" />
      </Row>
      <Gap size="xl" />
      <Button title="Save" onPress={save} loading={busy} disabled={!rangeOk || !distanceOk || interestedIn.length === 0} />
    </Screen>
  );
}
