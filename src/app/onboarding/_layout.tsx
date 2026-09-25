import React from 'react';
import { Redirect, Stack } from 'expo-router';
import { useSession } from '../../auth/SessionProvider';
import { colors } from '../../ui/theme';

/**
 * Photos → about you → interests → city: the order that reaches 50% — and
 * therefore an active account — fastest. Signed-out visitors cannot be here.
 */
export default function OnboardingLayout() {
  const { status } = useSession();

  if (status === 'signedOut') return <Redirect href="/(auth)/sign-in" />;

  return (
    <Stack
      screenOptions={{
        headerShown: true,
        headerShadowVisible: false,
        headerStyle: { backgroundColor: colors.background },
        headerTintColor: colors.ink,
        headerBackButtonDisplayMode: 'minimal',
      }}
    >
      <Stack.Screen name="photos" options={{ title: 'Your photos' }} />
      <Stack.Screen name="about" options={{ title: 'About you' }} />
      <Stack.Screen name="interests" options={{ title: 'Interests' }} />
      <Stack.Screen name="city" options={{ title: 'Where you are' }} />
    </Stack>
  );
}
