import React, { useEffect, useState } from 'react';
import { Linking, StyleSheet, Text, View } from 'react-native';
import { Banner, Body, Button, Card, Gap, Screen, Subtitle, Title } from './index';
import { colors, spacing } from './theme';
import type { Restriction } from '../api/types';
import { API_BASE_URL } from '../api';

/**
 * The screens that replace the app when the server says so. None of them
 * signs the member out: a restricted or deactivated member is still who they
 * are, and the way back is the appeal, not a fresh login.
 */

export function RestrictedScreen({
  message,
  restriction,
  onRetry,
  onSignOut,
}: {
  message: string;
  restriction: Restriction | null;
  onRetry: () => void;
  onSignOut: () => void;
}) {
  const expires = restriction?.expires_at ? new Date(restriction.expires_at) : null;
  const appealUrl = `${API_BASE_URL.replace(/\/api\/v1$/, '')}/app/restricted`;

  return (
    <Screen scroll>
      <Title>Your account is restricted</Title>
      <Body>{message}</Body>
      <Gap />
      <Card>
        <Subtitle>{label(restriction?.type)}</Subtitle>
        {restriction?.policy_clause ? (
          <Body muted>Rule: {restriction.policy_clause}</Body>
        ) : (
          <Body muted>The team did not attach a specific clause.</Body>
        )}
        <Gap size="sm" />
        {expires ? (
          <Body>Until {expires.toLocaleString()}</Body>
        ) : (
          <Body>This restriction has no end date.</Body>
        )}
      </Card>
      <Gap />
      {restriction?.appealable !== false ? (
        <>
          <Body muted>
            You can appeal once per restriction. The appeal form is on the website; a different moderator reviews it — never the one
            who made the original decision.
          </Body>
          <Gap size="sm" />
          <Button title="Open the appeal form" onPress={() => Linking.openURL(appealUrl)} />
        </>
      ) : null}
      <Gap size="sm" />
      <Button title="Check again" variant="secondary" onPress={onRetry} />
      <Gap size="sm" />
      <Button title="Sign out" variant="ghost" onPress={onSignOut} />
    </Screen>
  );
}

function label(type: string | null | undefined): string {
  switch (type) {
    case 'suspension':
      return 'Suspended';
    case 'permanent_ban':
    case 'device_ban':
      return 'Banned';
    case 'feature_limit':
      return 'Some features are limited';
    default:
      return 'Restricted';
  }
}

export function DeactivatedScreen({ message, onSignOut }: { message: string; onSignOut: () => void }) {
  return (
    <Screen scroll>
      <Title>This account is deactivated</Title>
      <Body>{message}</Body>
      <Gap />
      <Body muted>Contact support to reopen it.</Body>
      <Gap />
      <Button title="Sign out" onPress={onSignOut} />
    </Screen>
  );
}

export function MaintenanceScreen({ message, retryAfter, onRetry }: { message: string; retryAfter: number | null; onRetry: () => void }) {
  return (
    <Screen scroll>
      <Title>Back shortly</Title>
      <Body>{message}</Body>
      <Gap />
      {retryAfter ? <Countdown key={retryAfter} seconds={retryAfter} label="Suggested wait" /> : null}
      <Gap />
      <Button title="Try again" onPress={onRetry} />
    </Screen>
  );
}

export function UpdateRequiredScreen({ minimum, current }: { minimum: string; current: string }) {
  return (
    <Screen scroll>
      <Title>Update required</Title>
      <Body>
        This version ({current}) is no longer supported. Update to {minimum} or later from the app store to keep going.
      </Body>
    </Screen>
  );
}

export function OfflineScreen({ onRetry }: { onRetry: () => void }) {
  return (
    <Screen scroll>
      <Title>Can’t reach the server</Title>
      <Body muted>Check your connection and try again.</Body>
      <Gap />
      <Button title="Retry" onPress={onRetry} />
    </Screen>
  );
}

/**
 * Live countdown, driven by a deadline rather than a decrementing counter, so
 * it survives backgrounding. The deadline is fixed when the component mounts:
 * give it a `key` when the wait changes.
 */
export function Countdown({ seconds, until, label }: { seconds?: number; until?: number; label?: string }) {
  const [deadline] = useState(() => until ?? Date.now() + (seconds ?? 0) * 1000);
  const [left, setLeft] = useState(() => Math.max(0, Math.ceil((deadline - Date.now()) / 1000)));

  useEffect(() => {
    const id = setInterval(() => setLeft(Math.max(0, Math.ceil((deadline - Date.now()) / 1000))), 1000);
    return () => clearInterval(id);
  }, [deadline]);

  const minutes = Math.floor(left / 60);
  const secs = left % 60;

  return (
    <View style={styles.countdown}>
      {label ? <Text style={styles.countdownLabel}>{label}</Text> : null}
      <Text style={styles.countdownValue} accessibilityLiveRegion="polite">
        {minutes > 0 ? `${minutes}m ${secs.toString().padStart(2, '0')}s` : `${secs}s`}
      </Text>
    </View>
  );
}

/**
 * A non-blocking strip for 429s: the limit is a fact, not a failure. It
 * removes itself when the wait is over; the caller keys it by `until`.
 */
export function RateLimitStrip({ until }: { until: number }) {
  const [expired, setExpired] = useState(() => until <= Date.now());

  useEffect(() => {
    const id = setTimeout(() => setExpired(true), Math.max(0, until - Date.now()));
    return () => clearTimeout(id);
  }, [until]);

  if (expired) return null;

  return (
    <View style={styles.strip} pointerEvents="none">
      <Banner tone="warning">
        Slow down — you can try again in <Countdown until={until} />
      </Banner>
    </View>
  );
}

const styles = StyleSheet.create({
  countdown: { flexDirection: 'row', alignItems: 'baseline', gap: spacing.sm },
  countdownLabel: { color: colors.muted, fontSize: 14 },
  countdownValue: { color: colors.ink, fontSize: 18, fontVariant: ['tabular-nums'], fontWeight: '600' },
  strip: { position: 'absolute', left: spacing.md, right: spacing.md, bottom: spacing.xl },
});
