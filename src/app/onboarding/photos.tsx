import React from 'react';
import { useMe } from '../../auth/SessionProvider';
import { PhotoManager } from '../../features/photos/PhotoManager';
import { OnboardingFooter } from '../../features/onboarding/OnboardingFooter';
import { Body, Gap, Screen, Title } from '../../ui';

export default function OnboardingPhotos() {
  const me = useMe();

  return (
    <Screen scroll>
      <Title>Add a photo</Title>
      <Body muted>A photo is the single biggest step towards going live. The first one becomes your main photo.</Body>
      <Gap />
      <PhotoManager photos={me.photos ?? []} />
      <OnboardingFooter next="/onboarding/about" />
    </Screen>
  );
}
