/**
 * Colours and spacing. The same warm rose / plum family the website uses, as
 * hex because React Native does not speak oklch.
 */
export const colors = {
  background: '#fdfcfc',
  card: '#ffffff',
  ink: '#2a2030',
  muted: '#7d737f',
  line: '#e8e4e8',
  wash: '#f6f4f5',
  primary: '#c2265a',
  primarySoft: '#fdf2f6',
  accent: '#8f3aa1',
  accentSoft: '#f3e8f6',
  success: '#1c7a54',
  successSoft: '#eef7f2',
  warning: '#9a6a12',
  warningSoft: '#fdf6e7',
  danger: '#b4262c',
  dangerSoft: '#fdeceb',
  like: '#1c7a54',
  pass: '#b4262c',
} as const;

export const spacing = { xs: 4, sm: 8, md: 12, lg: 16, xl: 24, xxl: 32 } as const;

export const radius = { sm: 8, md: 12, lg: 18, pill: 999 } as const;
