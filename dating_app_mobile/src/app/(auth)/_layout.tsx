import React from 'react';
import { Redirect, Stack } from 'expo-router';
import { useSession } from '../../auth/SessionProvider';

export default function AuthLayout() {
  const { status } = useSession();

  if (status === 'signedIn') return <Redirect href="/" />;

  return <Stack screenOptions={{ headerShown: false }} />;
}
