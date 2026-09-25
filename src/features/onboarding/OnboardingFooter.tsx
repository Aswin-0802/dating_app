import React from 'react';
import { router, type Href } from 'expo-router';
import { useMe } from '../../auth/SessionProvider';
import { Banner, Body, Button, CompletionMeter, Gap, Row } from '../../ui';

/**
 * The meter and the next step, on every onboarding screen. It reads
 * profile_completion straight off /me — the server's number, never a local
 * estimate — and says plainly what the number unlocks.
 */
export function OnboardingFooter({ next, nextLabel = 'Next', busy }: { next: Href | null; nextLabel?: string; busy?: boolean }) {
  const me = useMe();
  const active = me.account_status === 'active';

  return (
    <>
      <Gap size="lg" />
      <CompletionMeter percent={me.profile_completion} />
      <Gap />
      {active ? (
        <Banner tone="success">Your profile is live. You can finish the rest now or later from your profile.</Banner>
      ) : (
        <Body muted>Swiping and messages unlock once your profile is about half complete.</Body>
      )}
      <Gap />
      <Row>
        {next ? <Button title={nextLabel} onPress={() => router.push(next)} loading={busy} style={{ flex: 1 }} /> : null}
        {active ? (
          <Button title="Start discovering" variant={next ? 'secondary' : 'primary'} onPress={() => router.replace('/(tabs)/discover')} style={{ flex: 1 }} />
        ) : null}
      </Row>
    </>
  );
}
