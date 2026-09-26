import React from 'react';
import { Redirect, Tabs } from 'expo-router';
import { Text, type ColorValue } from 'react-native';
import { useSession } from '../../auth/SessionProvider';
import { colors } from '../../ui/theme';

/**
 * The main app. A pending member's token cannot use most of this, so they are
 * kept in onboarding until the server promotes them.
 */
export default function TabsLayout() {
  const { status, me } = useSession();

  if (status === 'signedOut') return <Redirect href="/(auth)/sign-in" />;
  if (me?.account_status === 'pending') return <Redirect href="/onboarding/photos" />;

  return (
    <Tabs
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: colors.primary,
        tabBarInactiveTintColor: colors.muted,
        tabBarStyle: { borderTopColor: colors.line },
      }}
    >
      <Tabs.Screen name="discover" options={{ title: 'Discover', tabBarIcon: icon('◎') }} />
      <Tabs.Screen name="matches" options={{ title: 'Matches', tabBarIcon: icon('♥') }} />
      <Tabs.Screen name="messages" options={{ title: 'Messages', tabBarIcon: icon('✉') }} />
      <Tabs.Screen name="likers" options={{ title: 'Likes', tabBarIcon: icon('★') }} />
      <Tabs.Screen name="profile" options={{ title: 'Profile', tabBarIcon: icon('●') }} />
    </Tabs>
  );
}

function icon(glyph: string) {
  return function TabIcon({ color }: { color: ColorValue }) {
    return <Text style={{ color, fontSize: 18 }}>{glyph}</Text>;
  };
}
