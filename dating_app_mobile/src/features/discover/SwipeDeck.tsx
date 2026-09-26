import React, { useCallback, useEffect } from 'react';
import { Dimensions, StyleSheet, Text, View } from 'react-native';
import { Image } from 'expo-image';
import { Gesture, GestureDetector } from 'react-native-gesture-handler';
import Animated, {
  interpolate,
  runOnJS,
  useAnimatedStyle,
  useReducedMotion,
  useSharedValue,
  withSpring,
  withTiming,
} from 'react-native-reanimated';
import type { Person, SwipeAction } from '../../api/types';
import { Button, Row } from '../../ui';
import { colors, radius, spacing } from '../../ui/theme';

const { width: SCREEN_W } = Dimensions.get('window');
const THRESHOLD = SCREEN_W * 0.3;
const FLING_X = SCREEN_W * 1.4;

/**
 * The card and its gesture.
 *
 * THE SERVER DECIDES. Releasing past the threshold flings the card off the
 * screen and asks `onDecide`; if the server refuses — the daily like limit,
 * a 403, a 429 — the card comes straight back and the parent shows why. The
 * animation never commits an outcome on its own.
 *
 * With reduce-motion on, there is no drag at all: the buttons do the work.
 */
export function SwipeDeck({
  person,
  behind,
  onDecide,
  onOpenProfile,
  disabled,
}: {
  person: Person;
  behind?: Person;
  onDecide: (action: SwipeAction) => Promise<boolean>;
  onOpenProfile: (person: Person) => void;
  disabled?: boolean;
}) {
  const reduceMotion = useReducedMotion();
  const x = useSharedValue(0);
  const y = useSharedValue(0);
  const busy = useSharedValue(false);

  // Prefetch the next card's first photo so the reveal is instant.
  useEffect(() => {
    const url = behind?.photos?.[0]?.url;
    if (url) void Image.prefetch(url);
  }, [behind]);

  const settle = useCallback(
    async (action: SwipeAction) => {
      const accepted = await onDecide(action);
      if (!accepted) {
        x.set(withSpring(0));
        y.set(withSpring(0));
      }
      busy.set(false);
    },
    [onDecide, x, y, busy],
  );

  const decide = useCallback(
    (action: SwipeAction) => {
      if (busy.get() || disabled) return;
      busy.set(true);

      if (reduceMotion) {
        void settle(action);
        return;
      }

      const direction = action === 'pass' ? -1 : 1;
      x.set(withTiming(direction * FLING_X, { duration: 260 }, () => runOnJS(settle)(action)));
    },
    [busy, disabled, reduceMotion, x, settle],
  );

  const pan = Gesture.Pan()
    .enabled(!reduceMotion && !disabled)
    .onChange((event) => {
      if (busy.get()) return;
      x.set(event.translationX);
      y.set(event.translationY);
    })
    .onEnd(() => {
      if (busy.get()) return;
      if (x.get() > THRESHOLD) {
        runOnJS(decide)('like');
      } else if (x.get() < -THRESHOLD) {
        runOnJS(decide)('pass');
      } else {
        x.set(withSpring(0));
        y.set(withSpring(0));
      }
    });

  const cardStyle = useAnimatedStyle(() => ({
    transform: [
      { translateX: x.get() },
      { translateY: y.get() },
      { rotate: `${interpolate(x.get(), [-SCREEN_W, 0, SCREEN_W], [-12, 0, 12])}deg` },
    ],
  }));

  const likeTint = useAnimatedStyle(() => ({ opacity: interpolate(x.get(), [0, THRESHOLD], [0, 1]) }));
  const passTint = useAnimatedStyle(() => ({ opacity: interpolate(x.get(), [-THRESHOLD, 0], [1, 0]) }));

  const photo = person.photos?.[0]?.url;

  return (
    <View style={styles.stage}>
      {behind ? (
        <View style={[styles.card, styles.behind]}>
          {behind.photos?.[0]?.url ? <Image source={{ uri: behind.photos[0].url }} style={styles.photo} contentFit="cover" /> : null}
        </View>
      ) : null}

      <GestureDetector gesture={pan}>
        <Animated.View style={[styles.card, cardStyle]} accessibilityLabel={`${person.display_name}, ${person.age ?? ''}`}>
          {photo ? (
            <Image source={{ uri: photo }} style={styles.photo} contentFit="cover" transition={150} />
          ) : (
            <View style={[styles.photo, styles.noPhoto]}>
              <Text style={styles.noPhotoText}>No photo yet</Text>
            </View>
          )}

          <Animated.View style={[styles.stamp, styles.likeStamp, likeTint]} pointerEvents="none">
            <Text style={[styles.stampText, { color: colors.like }]}>LIKE</Text>
          </Animated.View>
          <Animated.View style={[styles.stamp, styles.passStamp, passTint]} pointerEvents="none">
            <Text style={[styles.stampText, { color: colors.pass }]}>PASS</Text>
          </Animated.View>

          <View style={styles.caption}>
            <Text style={styles.name}>
              {person.display_name}
              {person.age ? `, ${person.age}` : ''} {person.is_verified ? '✓' : ''}
            </Text>
            <Text style={styles.meta}>
              {[person.city, person.distance_km !== undefined ? `${person.distance_km} km away` : null].filter(Boolean).join(' · ')}
            </Text>
            <Text style={styles.link} onPress={() => onOpenProfile(person)} accessibilityRole="button">
              View profile
            </Text>
          </View>
        </Animated.View>
      </GestureDetector>

      {/* Buttons always: accessibility, and the only control under reduce-motion. */}
      <Row style={styles.buttons}>
        <Button title="Pass" variant="secondary" onPress={() => decide('pass')} disabled={disabled} style={styles.button} accessibilityLabel={`Pass on ${person.display_name}`} />
        <Button title="Like" onPress={() => decide('like')} disabled={disabled} style={styles.button} accessibilityLabel={`Like ${person.display_name}`} />
      </Row>
    </View>
  );
}

const styles = StyleSheet.create({
  stage: { flex: 1 },
  card: {
    position: 'absolute',
    top: 0,
    left: spacing.md,
    right: spacing.md,
    bottom: 96,
    borderRadius: radius.lg,
    overflow: 'hidden',
    backgroundColor: colors.wash,
    borderWidth: 1,
    borderColor: colors.line,
  },
  behind: { transform: [{ scale: 0.96 }], opacity: 0.9 },
  photo: { width: '100%', height: '100%' },
  noPhoto: { alignItems: 'center', justifyContent: 'center' },
  noPhotoText: { color: colors.muted },
  stamp: { position: 'absolute', top: 24, paddingHorizontal: 12, paddingVertical: 6, borderWidth: 3, borderRadius: radius.sm, backgroundColor: 'rgba(255,255,255,0.85)' },
  likeStamp: { left: 20, borderColor: colors.like, transform: [{ rotate: '-14deg' }] },
  passStamp: { right: 20, borderColor: colors.pass, transform: [{ rotate: '14deg' }] },
  stampText: { fontSize: 28, fontWeight: '800', letterSpacing: 2 },
  caption: { position: 'absolute', left: 0, right: 0, bottom: 0, padding: spacing.lg, backgroundColor: 'rgba(42,32,48,0.72)' },
  name: { color: '#fff', fontSize: 22, fontWeight: '700' },
  meta: { color: 'rgba(255,255,255,0.85)', marginTop: 2 },
  link: { color: '#fff', marginTop: spacing.sm, textDecorationLine: 'underline' },
  buttons: { position: 'absolute', left: spacing.md, right: spacing.md, bottom: spacing.xl, justifyContent: 'space-between' },
  button: { flex: 1 },
});
