import React, { type PropsWithChildren, type ReactNode } from 'react';
import {
  ActivityIndicator,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
  type PressableProps,
  type StyleProp,
  type TextInputProps,
  type ViewStyle,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { colors, radius, spacing } from './theme';

/* ---- layout ---------------------------------------------------------------- */

export function Screen({
  children,
  scroll = false,
  padded = true,
  style,
}: PropsWithChildren<{ scroll?: boolean; padded?: boolean; style?: StyleProp<ViewStyle> }>) {
  const inner = [padded && styles.padded, style];

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      {scroll ? (
        <ScrollView contentContainerStyle={[inner, styles.scrollContent]} keyboardShouldPersistTaps="handled">
          {children}
        </ScrollView>
      ) : (
        <View style={[styles.fill, inner]}>{children}</View>
      )}
    </SafeAreaView>
  );
}

export function Title({ children }: PropsWithChildren) {
  return <Text style={styles.title}>{children}</Text>;
}

export function Subtitle({ children }: PropsWithChildren) {
  return <Text style={styles.subtitle}>{children}</Text>;
}

export function Body({ children, muted = false, center = false }: PropsWithChildren<{ muted?: boolean; center?: boolean }>) {
  return <Text style={[styles.body, muted && styles.muted, center && styles.center]}>{children}</Text>;
}

export function Label({ children }: PropsWithChildren) {
  return <Text style={styles.label}>{children}</Text>;
}

export function Card({ children, style }: PropsWithChildren<{ style?: StyleProp<ViewStyle> }>) {
  return <View style={[styles.card, style]}>{children}</View>;
}

export function Row({ children, style }: PropsWithChildren<{ style?: StyleProp<ViewStyle> }>) {
  return <View style={[styles.row, style]}>{children}</View>;
}

export function Gap({ size = 'md' }: { size?: keyof typeof spacing }) {
  return <View style={{ height: spacing[size] }} />;
}

export function Loading({ label }: { label?: string }) {
  return (
    <View style={styles.centered}>
      <ActivityIndicator color={colors.primary} />
      {label ? <Text style={[styles.body, styles.muted, { marginTop: spacing.md }]}>{label}</Text> : null}
    </View>
  );
}

/* ---- controls -------------------------------------------------------------- */

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger';

export function Button({
  title,
  variant = 'primary',
  loading = false,
  disabled,
  style,
  ...props
}: PressableProps & { title: string; variant?: Variant; loading?: boolean; style?: StyleProp<ViewStyle> }) {
  const isDisabled = disabled || loading;

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: !!isDisabled }}
      disabled={isDisabled}
      style={({ pressed }) => [
        styles.button,
        variants[variant],
        pressed && styles.pressed,
        isDisabled && styles.disabled,
        style,
      ]}
      {...props}
    >
      {loading ? (
        <ActivityIndicator color={variant === 'primary' || variant === 'danger' ? '#fff' : colors.primary} />
      ) : (
        <Text style={[styles.buttonText, variantText[variant]]}>{title}</Text>
      )}
    </Pressable>
  );
}

export function TextField({
  label,
  error,
  style,
  ...props
}: TextInputProps & { label?: string; error?: string | null }) {
  return (
    <View style={styles.field}>
      {label ? <Text style={styles.label}>{label}</Text> : null}
      <TextInput
        placeholderTextColor={colors.muted}
        style={[styles.input, error ? styles.inputError : null, style]}
        accessibilityLabel={label}
        {...props}
      />
      {error ? <Text style={styles.errorText}>{error}</Text> : null}
    </View>
  );
}

/** Tappable choice, single or multi. */
export function Chip({
  label,
  selected,
  onPress,
  disabled,
}: {
  label: string;
  selected: boolean;
  onPress: () => void;
  disabled?: boolean;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ selected, disabled: !!disabled }}
      onPress={onPress}
      disabled={disabled}
      style={[styles.chip, selected && styles.chipSelected, disabled && styles.disabled]}
    >
      <Text style={[styles.chipText, selected && styles.chipTextSelected]}>{label}</Text>
    </Pressable>
  );
}

export function ChipGroup({
  options,
  value,
  onChange,
  max,
}: {
  options: { value: string; label: string }[];
  value: string[];
  onChange: (next: string[]) => void;
  max?: number;
}) {
  return (
    <View style={styles.chips}>
      {options.map((option) => {
        const selected = value.includes(option.value);
        const full = max !== undefined && !selected && value.length >= max;

        return (
          <Chip
            key={option.value}
            label={option.label}
            selected={selected}
            disabled={full}
            onPress={() => onChange(selected ? value.filter((v) => v !== option.value) : [...value, option.value])}
          />
        );
      })}
    </View>
  );
}

export function RadioGroup({
  options,
  value,
  onChange,
}: {
  options: { value: string; label: string }[];
  value: string | null;
  onChange: (next: string) => void;
}) {
  return (
    <View style={styles.chips}>
      {options.map((option) => (
        <Chip key={option.value} label={option.label} selected={value === option.value} onPress={() => onChange(option.value)} />
      ))}
    </View>
  );
}

/* ---- feedback -------------------------------------------------------------- */

export function Banner({ tone = 'info', children }: PropsWithChildren<{ tone?: 'info' | 'success' | 'warning' | 'danger' }>) {
  return (
    <View style={[styles.banner, bannerTones[tone]]}>
      <Text style={[styles.body, bannerText[tone]]}>{children}</Text>
    </View>
  );
}

export function EmptyState({ title, body, action }: { title: string; body?: string; action?: ReactNode }) {
  return (
    <View style={styles.centered}>
      <Text style={[styles.subtitle, styles.center]}>{title}</Text>
      {body ? <Text style={[styles.body, styles.muted, styles.center, { marginTop: spacing.sm }]}>{body}</Text> : null}
      {action ? <View style={{ marginTop: spacing.lg }}>{action}</View> : null}
    </View>
  );
}

export function CompletionMeter({ percent }: { percent: number }) {
  const clamped = Math.max(0, Math.min(100, percent));
  const unlocked = clamped >= 50;

  return (
    <View accessibilityRole="progressbar" accessibilityValue={{ min: 0, max: 100, now: clamped }}>
      <View style={styles.meterTrack}>
        <View style={[styles.meterFill, { width: `${clamped}%` }, unlocked && { backgroundColor: colors.success }]} />
        <View style={styles.meterHalfMark} />
      </View>
      <Text style={[styles.body, styles.muted, { marginTop: spacing.xs }]}>
        {clamped}% complete · {unlocked ? 'swiping is unlocked' : 'swiping unlocks at 50%'}
      </Text>
    </View>
  );
}

/* ---- styles ---------------------------------------------------------------- */

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.background },
  fill: { flex: 1 },
  padded: { padding: spacing.lg },
  scrollContent: { flexGrow: 1, paddingBottom: spacing.xxl },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl },
  title: { fontSize: 28, fontWeight: '700', color: colors.ink, letterSpacing: -0.4, marginBottom: spacing.sm },
  subtitle: { fontSize: 18, fontWeight: '600', color: colors.ink, marginBottom: spacing.xs },
  body: { fontSize: 15, lineHeight: 21, color: colors.ink },
  muted: { color: colors.muted },
  center: { textAlign: 'center' },
  label: { fontSize: 12, fontWeight: '600', color: colors.muted, textTransform: 'uppercase', letterSpacing: 0.6, marginBottom: spacing.xs },
  card: { backgroundColor: colors.card, borderRadius: radius.lg, borderWidth: 1, borderColor: colors.line, padding: spacing.lg },
  row: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  button: { minHeight: 48, borderRadius: radius.md, alignItems: 'center', justifyContent: 'center', paddingHorizontal: spacing.lg },
  buttonText: { fontSize: 16, fontWeight: '600' },
  pressed: { opacity: 0.85 },
  disabled: { opacity: 0.5 },
  field: { marginBottom: spacing.md },
  input: {
    minHeight: 48,
    borderWidth: 1,
    borderColor: colors.line,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    fontSize: 16,
    color: colors.ink,
    backgroundColor: colors.card,
  },
  inputError: { borderColor: colors.danger },
  errorText: { color: colors.danger, marginTop: spacing.xs, fontSize: 13 },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
  chip: { paddingHorizontal: spacing.md, paddingVertical: spacing.sm, borderRadius: radius.pill, borderWidth: 1, borderColor: colors.line, backgroundColor: colors.card },
  chipSelected: { backgroundColor: colors.primarySoft, borderColor: colors.primary },
  chipText: { color: colors.ink, fontSize: 14 },
  chipTextSelected: { color: colors.primary, fontWeight: '600' },
  banner: { padding: spacing.md, borderRadius: radius.md, borderLeftWidth: 4, marginBottom: spacing.md },
  meterTrack: { height: 10, backgroundColor: colors.wash, borderRadius: radius.pill, overflow: 'hidden', position: 'relative' },
  meterFill: { height: '100%', backgroundColor: colors.primary, borderRadius: radius.pill },
  meterHalfMark: { position: 'absolute', left: '50%', top: 0, bottom: 0, width: 2, backgroundColor: colors.ink, opacity: 0.35 },
});

const variants: Record<Variant, ViewStyle> = {
  primary: { backgroundColor: colors.primary },
  secondary: { backgroundColor: colors.primarySoft, borderWidth: 1, borderColor: colors.primary },
  ghost: { backgroundColor: 'transparent' },
  danger: { backgroundColor: colors.danger },
};

const variantText: Record<Variant, { color: string }> = {
  primary: { color: '#fff' },
  secondary: { color: colors.primary },
  ghost: { color: colors.primary },
  danger: { color: '#fff' },
};

const bannerTones = {
  info: { backgroundColor: colors.accentSoft, borderLeftColor: colors.accent },
  success: { backgroundColor: colors.successSoft, borderLeftColor: colors.success },
  warning: { backgroundColor: colors.warningSoft, borderLeftColor: colors.warning },
  danger: { backgroundColor: colors.dangerSoft, borderLeftColor: colors.danger },
} as const;

const bannerText = {
  info: { color: colors.ink },
  success: { color: colors.success },
  warning: { color: colors.warning },
  danger: { color: colors.danger },
} as const;
