import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Platform, StyleSheet, Text, View } from 'react-native';
import Constants from 'expo-constants';
import { useQuery } from '@tanstack/react-query';
import {
  deepLinkToSubscriptions,
  finishTransaction,
  getAvailablePurchases,
  isUserCancelledError,
  useIAP,
  type Purchase,
} from 'expo-iap';
import { api } from '../../api';
import { isApiError } from '../../api/errors';
import type { Plan } from '../../api/types';
import { useMe, useSession } from '../../auth/SessionProvider';
import { Banner, Body, Button, Card, Gap, Loading, Screen, Subtitle } from '../../ui';
import { colors, radius, spacing } from '../../ui/theme';

/**
 * Premium, bought through the store the app came from.
 *
 * The app never decides entitlement. It asks the store for the products the
 * server lists, lets the member buy one, and posts the store's transaction
 * to the server, which asks the store itself and answers with a fresh /me.
 * Only after that 200 is the store transaction finished; anything else
 * leaves it open so the store re-presents it and "Restore purchases" can
 * post it again. See docs/premium-receipt-contract.md §8.
 *
 * Feature labels mirror Plan::FEATURES on the server (same gap as
 * lib/options.ts): the server sends keys, the wording lives here.
 */
const FEATURE_LABELS: Record<string, string> = {
  unlimited_likes: 'Unlimited likes',
  see_likers: 'See who already liked you',
  profile_badge: 'A badge on your profile',
  priority_support: 'Priority support',
};

type Notice = { tone: 'success' | 'warning' | 'danger' | 'info'; text: string };

const platform: 'ios' | 'android' = Platform.OS === 'ios' ? 'ios' : 'android';

export default function Premium() {
  const me = useMe();
  const { refreshMe } = useSession();
  const plans = useQuery({ queryKey: ['plans'], queryFn: () => api.plans(), staleTime: 5 * 60_000 });
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState<string | null>(null); // the sku being bought, or 'restore'

  const skus = useMemo(
    () => (plans.data?.data ?? []).flatMap((plan) => Object.values(plan.products[platform] ?? {})).filter((sku): sku is string => typeof sku === 'string'),
    [plans.data],
  );
  const skuKey = skus.join(',');

  // §8.3: post the transaction, replace /me, and only then finish it with the store.
  const redeem = useCallback(
    async (purchase: Purchase): Promise<boolean> => {
      const token = purchase.purchaseToken;

      if (!token) {
        setNotice({ tone: 'danger', text: 'The store returned nothing to verify. Try “Restore purchases”.' });
        return false;
      }

      try {
        await api.redeemReceipt({ platform, product_id: purchase.productId, transaction: token });
        await refreshMe();
        await finishTransaction({ purchase, isConsumable: false });
        setNotice({ tone: 'success', text: 'Premium is active.' });
        return true;
      } catch (e) {
        if (isApiError(e) && e.code === 'receipt_owned_elsewhere') {
          // Not this member's purchase; finishing it stops the store re-presenting it.
          await finishTransaction({ purchase, isConsumable: false }).catch(() => undefined);
          setNotice({ tone: 'danger', text: e.message });
          return false;
        }

        const why = isApiError(e) ? e.message : 'The server could not be reached.';
        setNotice({ tone: e && isApiError(e) && e.code === 'store_unavailable' ? 'warning' : 'danger', text: `${why} Your purchase is safe: tap “Restore purchases” to try again.` });
        return false;
      }
    },
    [refreshMe],
  );

  // The hook's listeners are registered once; they read the latest redeem through a ref.
  const redeemRef = useRef(redeem);
  useEffect(() => {
    redeemRef.current = redeem;
  }, [redeem]);

  const { connected, subscriptions, fetchProducts, requestPurchase } = useIAP({
    onPurchaseSuccess: (purchase) => {
      void redeemRef.current(purchase).finally(() => setBusy(null));
    },
    onPurchaseError: (error) => {
      setBusy(null);
      if (!isUserCancelledError(error)) setNotice({ tone: 'danger', text: error.message });
    },
  });

  useEffect(() => {
    if (connected && skuKey !== '') void fetchProducts({ skus: skuKey.split(','), type: 'subs' });
    // fetchProducts is not referentially stable across renders; the sku list is the real dependency.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [connected, skuKey]);

  const priceOf = useCallback((sku: string): string | null => subscriptions.find((s) => s.id === sku)?.displayPrice ?? null, [subscriptions]);

  async function buy(sku: string) {
    setNotice(null);
    setBusy(sku);

    try {
      // Android needs the base plan's offer token, and — when the member
      // already has a store subscription — the purchase to replace, so the
      // store swaps it instead of adding a second one (§3).
      const product = subscriptions.find((s) => s.id === sku);
      const offer = product && 'subscriptionOffers' in product ? product.subscriptionOffers?.[0] : undefined;
      const offerToken = offerTokenOf(offer);
      const existing = platform === 'android' ? (await getAvailablePurchases()).find((p) => skus.includes(p.productId) && p.purchaseToken) : undefined;

      await requestPurchase({
        type: 'subs',
        request: {
          apple: { sku, appAccountToken: me.id },
          google: {
            skus: [sku],
            obfuscatedAccountId: me.id,
            ...(offerToken ? { subscriptionOffers: [{ sku, offerToken }] } : {}),
            ...(existing?.purchaseToken && existing.productId !== sku
              ? { purchaseToken: existing.purchaseToken, subscriptionProductReplacementParams: { oldProductId: existing.productId, replacementMode: 'with-time-proration' as const } }
              : {}),
          },
        },
      });
      // The outcome arrives through onPurchaseSuccess / onPurchaseError.
    } catch (e) {
      setBusy(null);
      if (!isUserCancelledError(e)) setNotice({ tone: 'danger', text: e instanceof Error ? e.message : 'The store could not start the purchase.' });
    }
  }

  // §8.4: every transaction the store still holds goes through the same endpoint.
  async function restore() {
    setNotice(null);
    setBusy('restore');

    try {
      const purchases = (await getAvailablePurchases()).filter((p) => skus.includes(p.productId));

      if (purchases.length === 0) {
        setNotice({ tone: 'info', text: 'The store has no Premium purchase for this account.' });
        return;
      }

      let restored = 0;
      for (const purchase of purchases) {
        if (await redeem(purchase)) restored++;
      }

      if (restored > 0) setNotice({ tone: 'success', text: 'Premium is active.' });
    } catch (e) {
      setNotice({ tone: 'danger', text: e instanceof Error ? e.message : 'Could not read purchases from the store.' });
    } finally {
      setBusy(null);
    }
  }

  async function manage() {
    try {
      const sku = skus.find((s) => plans.data?.data.some((p) => p.slug === me.premium_tier && Object.values(p.products[platform] ?? {}).includes(s)));
      await deepLinkToSubscriptions({ skuAndroid: sku ?? null, packageNameAndroid: Constants.expoConfig?.android?.package ?? null });
    } catch {
      setNotice({ tone: 'info', text: 'Open your phone’s subscription settings to manage it.' });
    }
  }

  if (plans.isLoading) return <Loading />;

  const fromThisStore = me.premium_source === platform.replace('ios', 'apple').replace('android', 'google');
  const fromElsewhere = me.is_premium && !fromThisStore;
  const storeName = platform === 'ios' ? 'the App Store' : 'Google Play';

  return (
    <Screen scroll>
      {notice ? <Banner tone={notice.tone}>{notice.text}</Banner> : null}

      {me.is_premium ? (
        <Banner tone="success">
          {me.premium_tier ? `${plans.data?.data.find((p) => p.slug === me.premium_tier)?.name ?? me.premium_tier} is active` : 'Premium is active'}
          {me.premium_until ? `${me.auto_renewing === false ? ', ends' : me.auto_renewing ? ', renews' : ', until'} ${new Date(me.premium_until).toLocaleDateString()}` : ''}.
        </Banner>
      ) : null}

      {/* §8.6: never invite a second charge for a plan that is already running elsewhere. */}
      {fromElsewhere ? (
        <Body muted>
          {me.premium_source === 'apple' || me.premium_source === 'google'
            ? `Your plan is managed in ${me.premium_source === 'apple' ? 'the App Store' : 'Google Play'} on the device you bought it with.`
            : 'Your plan was set up on the website and is managed there. Nothing to buy here while it runs.'}
        </Body>
      ) : null}

      {plans.isError ? (
        <Banner tone="danger">Plans could not be loaded. {isApiError(plans.error) ? plans.error.message : ''}</Banner>
      ) : (
        (plans.data?.data ?? []).map((plan) => (
          <PlanCard
            key={plan.slug}
            plan={plan}
            current={me.is_premium && me.premium_tier === plan.slug}
            offered={!fromElsewhere}
            connected={connected}
            busy={busy}
            priceOf={priceOf}
            onBuy={(sku) => void buy(sku)}
          />
        ))
      )}

      <Gap />
      {!fromElsewhere ? (
        <>
          {!connected ? <Body muted>Connecting to {storeName}… Purchases need a development build, not Expo Go.</Body> : null}
          <Button title="Restore purchases" variant="secondary" onPress={() => void restore()} loading={busy === 'restore'} disabled={!connected || busy !== null} />
          <Gap size="sm" />
        </>
      ) : null}
      {fromThisStore ? <Button title="Manage subscription" variant="ghost" onPress={() => void manage()} /> : null}
      <Gap size="sm" />
      <Button title="Refresh" variant="ghost" onPress={() => void refreshMe()} />
    </Screen>
  );
}

function PlanCard({
  plan,
  current,
  offered,
  connected,
  busy,
  priceOf,
  onBuy,
}: {
  plan: Plan;
  current: boolean;
  offered: boolean;
  connected: boolean;
  busy: string | null;
  priceOf: (sku: string) => string | null;
  onBuy: (sku: string) => void;
}) {
  const products = plan.products[platform] ?? {};
  const periods = [
    ['monthly', 'a month', products.monthly],
    ['yearly', 'a year', products.yearly],
  ] as const;

  return (
    <Card style={[styles.plan, plan.is_featured && styles.featured]}>
      <View style={styles.planHeader}>
        <Subtitle>{plan.name}</Subtitle>
        {current ? <Text style={styles.currentTag}>Your plan</Text> : plan.is_featured ? <Text style={styles.featuredTag}>Most popular</Text> : null}
      </View>
      {plan.tagline ? <Body muted>{plan.tagline}</Body> : null}
      <Gap size="sm" />
      {plan.perks.map((line) => (
        <Body key={line}>• {line}</Body>
      ))}
      {plan.features.map((key) => (
        <Body key={key}>• {FEATURE_LABELS[key] ?? key}</Body>
      ))}
      {offered && !current ? (
        <>
          <Gap />
          {periods.map(([period, per, sku]) =>
            sku ? (
              <View key={period} style={styles.buyRow}>
                <Button
                  title={priceOf(sku) ? `${priceOf(sku)} ${per}` : `Buy ${period}`}
                  onPress={() => onBuy(sku)}
                  loading={busy === sku}
                  disabled={!connected || busy !== null}
                  style={{ flex: 1 }}
                />
              </View>
            ) : null,
          )}
          {periods.every(([, , sku]) => !sku) ? <Body muted>Not available in {platform === 'ios' ? 'the App Store' : 'Google Play'} yet.</Body> : null}
        </>
      ) : null}
    </Card>
  );
}

/** expo-iap names the Android offer token differently across versions; the store needs it whatever it is called. */
function offerTokenOf(offer: unknown): string | null {
  if (!offer || typeof offer !== 'object') return null;
  const record = offer as Record<string, unknown>;
  const token = record.offerTokenAndroid ?? record.offerToken;
  return typeof token === 'string' && token !== '' ? token : null;
}

const styles = StyleSheet.create({
  plan: { marginBottom: spacing.md },
  featured: { borderColor: colors.primary },
  planHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  currentTag: { color: colors.success, fontWeight: '600', fontSize: 12 },
  featuredTag: { color: colors.primary, fontWeight: '600', fontSize: 12, backgroundColor: colors.primarySoft, paddingHorizontal: spacing.sm, paddingVertical: 2, borderRadius: radius.pill },
  buyRow: { flexDirection: 'row', marginBottom: spacing.sm },
});
