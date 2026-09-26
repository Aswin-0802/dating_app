import type { Gender, ReportCategory } from '../api/types';

/**
 * Fixed vocabularies, mirrored from the Laravel app.
 *
 * There is no endpoint that serves profile option keys, so these are copied
 * from app/Support/ProfileOptions.php and app/Enums/*. The KEYS are the
 * contract — they are what the server validates against. The labels are ours
 * and may be reworded freely. If the operator adds an option through the
 * Masters screen it will be accepted by the server but not offered here until
 * this file is updated; that is a known gap, noted in the README.
 */

export const GENDERS: { value: Gender; label: string }[] = [
  { value: 'woman', label: 'Woman' },
  { value: 'man', label: 'Man' },
  { value: 'non_binary', label: 'Non-binary' },
  { value: 'other', label: 'Other' },
];

export const RELATIONSHIP_GOALS: Record<string, string> = {
  long_term: 'A long-term relationship',
  short_term: 'Something casual',
  friends: 'New friends',
  figuring_out: 'Still figuring it out',
  unspecified: 'Prefer not to say',
};

export const EDUCATION: Record<string, string> = {
  'High school': 'High school',
  'Trade school': 'Trade school',
  Undergrad: 'Undergraduate degree',
  Postgrad: 'Postgraduate degree',
  PhD: 'PhD',
};

export const DRINKING: Record<string, string> = {
  never: 'Doesn’t drink',
  socially: 'Drinks socially',
  often: 'Drinks often',
  unspecified: 'Prefer not to say',
};

export const SMOKING: Record<string, string> = {
  never: 'Doesn’t smoke',
  socially: 'Smokes socially',
  often: 'Smokes',
  unspecified: 'Prefer not to say',
};

export const CHILDREN: Record<string, string> = {
  have: 'Has children',
  want: 'Wants children',
  dont_want: 'Doesn’t want children',
  unspecified: 'Prefer not to say',
};

export const REPORT_CATEGORIES: { value: ReportCategory; label: string }[] = [
  { value: 'harassment', label: 'Harassment or abuse' },
  { value: 'sexual_content', label: 'Unsolicited sexual content' },
  { value: 'nudity', label: 'Nudity' },
  { value: 'fake_profile', label: 'Fake profile' },
  { value: 'impersonation', label: 'Impersonation' },
  { value: 'scam_fraud', label: 'Romance scam or fraud' },
  { value: 'spam_promotion', label: 'Spam or promotion' },
  { value: 'off_platform_solicitation', label: 'Off-platform solicitation' },
  { value: 'prostitution', label: 'Solicitation / sex work' },
  { value: 'hate_speech', label: 'Hate speech' },
  { value: 'violence_threats', label: 'Violence or threats' },
  { value: 'self_harm', label: 'Self-harm concern' },
  { value: 'underage_suspected', label: 'Suspected underage user' },
  { value: 'minor_safety', label: 'Minor safety' },
  { value: 'other', label: 'Other' },
];

export const MAX_INTERESTS = 10;

export function toEntries(map: Record<string, string>): { value: string; label: string }[] {
  return Object.entries(map).map(([value, label]) => ({ value, label }));
}
