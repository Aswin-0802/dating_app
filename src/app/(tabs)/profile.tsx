import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { Image } from 'expo-image';
import { router, useFocusEffect, type Href } from 'expo-router';
import { useMe, useSession } from '../../auth/SessionProvider';
import { Banner, Body, Button, CompletionMeter, Gap, Screen, Title } from '../../ui';
import { colors, spacing } from '../../ui/theme';

export default function Profile() {
  const me = useMe();
  const { signOut, refreshMe } = useSession();

  useFocusEffect(
    React.useCallback(() => {
      void refreshMe();
    }, [refreshMe]),
  );

  const primary = me.photos?.find((p) => p.is_primary) ?? me.photos?.[0];

  return (
    <Screen scroll>
      <View style={styles.hero}>
        {primary ? <Image source={{ uri: primary.thumb_url }} style={styles.avatar} contentFit="cover" /> : <View style={[styles.avatar, { backgroundColor: colors.wash }]} />}
        <View style={{ flex: 1 }}>
          <Title>
            {me.display_name}
            {me.age ? `, ${me.age}` : ''}
          </Title>
          <Body muted>
            {me.verification_status === 'approved' ? '✓ Verified' : 'Not verified'}
            {me.is_premium ? ` · Premium${me.premium_tier ? ` (${me.premium_tier})` : ''}` : ' · Free plan'}
          </Body>
        </View>
      </View>

      <Gap />
      <CompletionMeter percent={me.profile_completion} />

      {me.restrictions.length ? (
        <>
          <Gap />
          <Banner tone="warning">Some features are limited on your account: {me.restrictions.join(', ')}.</Banner>
        </>
      ) : null}

      <Gap size="lg" />
      <Item label="Edit profile" href="/profile/edit" />
      <Item label="Photos" href="/onboarding/photos" />
      <Item label="Interests" href="/onboarding/interests" />
      <Item label="Preferences" href="/profile/preferences" />
      <Item label="Verification" href="/profile/verification" detail={me.verification_status} />
      <Item label="Phone number" href="/profile/phone" detail={me.phone ?? 'Not added'} />
      <Item label="Blocked people" href="/profile/blocks" />
      <Item label="Premium" href="/profile/premium" detail={me.is_premium ? 'Active' : undefined} />
      <Item label="Account" href="/profile/account" detail={me.email} />

      <Gap size="xl" />
      <Button title="Sign out" variant="secondary" onPress={() => void signOut()} />
    </Screen>
  );
}

function Item({ label, href, detail }: { label: string; href: Href; detail?: string }) {
  return (
    <Pressable style={styles.item} accessibilityRole="button" onPress={() => router.push(href)}>
      <Text style={styles.itemLabel}>{label}</Text>
      {detail ? (
        <Text style={styles.itemDetail} numberOfLines={1}>
          {detail}
        </Text>
      ) : null}
      <Text style={styles.chevron}>›</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  hero: { flexDirection: 'row', alignItems: 'center', gap: spacing.lg },
  avatar: { width: 84, height: 84, borderRadius: 42 },
  item: { flexDirection: 'row', alignItems: 'center', paddingVertical: spacing.md, borderBottomWidth: 1, borderBottomColor: colors.line, gap: spacing.sm },
  itemLabel: { flex: 1, fontSize: 16, color: colors.ink },
  itemDetail: { color: colors.muted, maxWidth: '45%' },
  chevron: { color: colors.muted, fontSize: 22 },
});
