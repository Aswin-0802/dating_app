import React from 'react';
import { Redirect } from 'expo-router';
import { useSession } from '../auth/SessionProvider';
import { Loading } from '../ui';

/**
 * Where a launch lands. Pending members go to onboarding because that is the
 * only thing their token can do; everybody else goes to Discover.
 */
export default function Index() {
  const { status, me } = useSession();

  if (status === 'booting') return <Loading />;
  if (status === 'signedOut' || !me) return <Redirect href="/(auth)/sign-in" />;
  if (me.account_status === 'pending') return <Redirect href="/onboarding/photos" />;

  return <Redirect href="/(tabs)/discover" />;
}
