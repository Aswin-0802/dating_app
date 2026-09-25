import React from 'react';
import { Redirect, Stack } from 'expo-router';
import { useSession } from '../../auth/SessionProvider';
import { colors } from '../../ui/theme';

export default function ProfileLayout() {
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
      <Stack.Screen name="edit" options={{ title: 'Edit profile' }} />
      <Stack.Screen name="preferences" options={{ title: 'Preferences' }} />
      <Stack.Screen name="verification" options={{ title: 'Verification' }} />
      <Stack.Screen name="phone" options={{ title: 'Phone number' }} />
      <Stack.Screen name="blocks" options={{ title: 'Blocked people' }} />
      <Stack.Screen name="premium" options={{ title: 'Premium' }} />
      <Stack.Screen name="account" options={{ title: 'Account' }} />
    </Stack>
  );
}
