/**
 * Shapes of what the API returns, mirrored from app/Http/Resources/Api/V1/*
 * in the Laravel app. Nothing here is invented: every field exists on the
 * corresponding Resource, and fields the Resources deliberately withhold
 * (email of other members, birthdate, coordinates, risk) are absent here too.
 */

export type Gender = 'woman' | 'man' | 'non_binary' | 'other';

export type PublicAccountStatus = 'active' | 'pending' | 'limited' | 'suspended' | 'banned' | 'deactivated';

export type VerificationStatus =
  | 'unverified'
  | 'pending'
  | 'in_review'
  | 'approved'
  | 'rejected'
  | 'escalated'
  | 'expired';

export interface Photo {
  id: string;
  url: string;
  thumb_url: string;
  position: number;
  is_primary: boolean;
  /** Present only on the member's own photos. */
  moderation_status?: string;
}

export interface Profile {
  bio: string | null;
  height_cm: number | null;
  job_title: string | null;
  company: string | null;
  school: string | null;
  education: string | null;
  relationship_goal: string | null;
  drinking: string | null;
  smoking: string | null;
  children: string | null;
  languages: string[];
  prompts: unknown[];
}

export interface Preferences {
  interested_in: Gender[];
  age_min: number;
  age_max: number;
  max_distance_km: number | null;
  global_mode: boolean;
  show_verified_only: boolean;
}

/** MeResource — the signed-in member. */
export interface Me {
  id: string;
  display_name: string;
  email: string;
  phone: string | null;
  birthdate: string | null;
  age: number | null;
  gender: Gender | null;
  pronouns: string | null;
  /** A shadow ban is reported as `active` by design. */
  account_status: PublicAccountStatus;
  verification_status: VerificationStatus;
  is_premium: boolean;
  premium_tier: string | null;
  premium_until: string | null;
  /** Where the plan came from decides where it is managed. null on the free plan. */
  premium_source: 'manual' | 'payment' | 'apple' | 'google' | null;
  /** The store's word on whether it renews; null for anything not from a store. */
  auto_renewing: boolean | null;
  profile_completion: number;
  city?: { name: string; state: string | null; country: string | null } | null;
  /** Feature keys switched off by a feature limit; empty otherwise. */
  restrictions: string[];
  profile?: Profile;
  preferences?: Preferences;
  photos?: Photo[];
  interests?: string[];
  created_at: string | null;
}

/** AppUserResource — another member, as a profile card. */
export interface Person {
  id: string;
  display_name: string;
  age: number | null;
  gender: Gender | null;
  pronouns: string | null;
  is_verified: boolean;
  city?: string | null;
  distance_km?: number;
  photos?: Photo[];
  profile?: Profile;
  interests?: string[];
}

export interface Match {
  id: string;
  matched_at: string | null;
  status: 'active' | 'unmatched' | 'blocked' | 'expired';
  other: Person | null;
  last_message_at: string | null;
  messages_count: number;
  has_conversation: boolean;
}

export interface Conversation {
  id: string;
  other: Person | null;
  messages_count: number;
  last_message_at: string | null;
  started_at: string | null;
  status: 'open' | 'closed' | 'frozen';
}

export interface Message {
  id: string;
  type: 'text' | 'image' | 'gif' | 'voice' | 'system';
  body: string | null;
  removed: boolean;
  media_url: string | null;
  is_mine: boolean;
  read_at: string | null;
  sent_at: string | null;
}

export interface Verification {
  id: string;
  status: VerificationStatus;
  submitted_at: string | null;
  attempts_used: number;
  attempts_remaining: number;
  rejection_reason?: string | null;
}

export interface Config {
  min_supported_version: string;
  maintenance_mode: boolean;
  min_age: number;
  max_photos: number;
  max_distance_km: number;
  daily_like_limit: number;
  appeal_window_days: number;
  support_email: string | null;
}

/** GET /plans: what is on sale, with the store product ids to ask StoreKit / Play Billing about. */
export interface Plan {
  slug: string;
  name: string;
  tagline: string | null;
  features: string[];
  perks: string[];
  is_featured: boolean;
  products: {
    ios?: { monthly?: string; yearly?: string };
    android?: { monthly?: string; yearly?: string };
  };
}

export interface Interest {
  slug: string;
  name: string;
  category: string;
}

export interface Country {
  iso2: string;
  name: string;
  dial_code: string | null;
}

export interface State {
  id: number;
  name: string;
  code: string | null;
  country_id: number;
}

export interface City {
  id: number;
  name: string;
  country_id: number;
  state: string | null;
  state_id: number | null;
}

/** Every list endpoint pages by cursor. There are no page numbers anywhere. */
export interface CursorPage<T> {
  data: T[];
  meta: { next_cursor: string | null; count?: number };
}

export interface AuthResponse {
  token: string;
  user: Me;
}

export interface SwipeResponse {
  is_match: boolean;
  match: Match | null;
}

/** What `appuser.active` returns with a 403 account_restricted. */
export interface Restriction {
  type: string | null;
  expires_at: string | null;
  policy_clause: string | null;
  appealable: boolean;
}

export type SwipeAction = 'like' | 'pass' | 'superlike';

export type ReportCategory =
  | 'harassment'
  | 'sexual_content'
  | 'nudity'
  | 'fake_profile'
  | 'impersonation'
  | 'scam_fraud'
  | 'spam_promotion'
  | 'off_platform_solicitation'
  | 'prostitution'
  | 'hate_speech'
  | 'violence_threats'
  | 'self_harm'
  | 'underage_suspected'
  | 'minor_safety'
  | 'other';
