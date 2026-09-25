import React from 'react';
import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { QueryClientProvider } from '@tanstack/react-query';
import { queryClient } from '../lib/queryClient';
import { ConfigProvider, useConfig } from '../config/ConfigProvider';
import { SessionProvider, useSession } from '../auth/SessionProvider';
import { APP_VERSION } from '../api';
import { Loading } from '../ui';
import {
  DeactivatedScreen,
  MaintenanceScreen,
  OfflineScreen,
  RateLimitStrip,
  RestrictedScreen,
  UpdateRequiredScreen,
} from '../ui/blockers';
import { ReauthModal } from '../ui/ReauthModal';
import { PushRegistrar } from '../push/PushRegistrar';

export default function RootLayout() {
  return (
    <GestureHandlerRootView style={{ flex: 1 }}>
      <SafeAreaProvider>
        <QueryClientProvider client={queryClient}>
          <ConfigProvider>
            <SessionProvider>
              <StatusBar style="dark" />
              <Gate />
            </SessionProvider>
          </ConfigProvider>
        </QueryClientProvider>
      </SafeAreaProvider>
    </GestureHandlerRootView>
  );
}

/**
 * What the whole app is allowed to show right now.
 *
 * Order matters: the server's config is read before anything else because it
 * can refuse this version outright; then the session; then the states the
 * server imposes on a signed-in member. Only when none of those apply does
 * navigation get to run.
 */
function Gate() {
  const { state, config, updateRequired, reload } = useConfig();
  const session = useSession();

  if (state.status === 'loading') return <Loading label="Connecting…" />;

  if (state.status === 'error') {
    if (state.error?.code === 'maintenance') {
      return <MaintenanceScreen message={state.error.message} retryAfter={state.error.retryAfter} onRetry={reload} />;
    }
    return <OfflineScreen onRetry={reload} />;
  }

  if (updateRequired) return <UpdateRequiredScreen minimum={config.min_supported_version} current={APP_VERSION} />;

  if (session.status === 'booting') return <Loading />;

  if (session.blocker?.kind === 'restricted') {
    return (
      <RestrictedScreen
        message={session.blocker.message}
        restriction={session.blocker.restriction}
        onRetry={session.retry}
        onSignOut={session.signOut}
      />
    );
  }

  if (session.blocker?.kind === 'deactivated') {
    return <DeactivatedScreen message={session.blocker.message} onSignOut={session.signOut} />;
  }

  if (session.blocker?.kind === 'maintenance') {
    return <MaintenanceScreen message={session.blocker.message} retryAfter={session.blocker.retryAfter} onRetry={session.retry} />;
  }

  return (
    <>
      <Stack screenOptions={{ headerShown: false }} />
      {session.status === 'signedIn' ? <PushRegistrar /> : null}
      {session.needsReauth ? <ReauthModal /> : null}
      {session.rateLimitedUntil ? <RateLimitStrip key={session.rateLimitedUntil} until={session.rateLimitedUntil} /> : null}
    </>
  );
}
