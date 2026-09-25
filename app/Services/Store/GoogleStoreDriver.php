<?php

declare(strict_types=1);

namespace App\Services\Store;

use App\Models\PaymentGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Google Play: Play Billing purchase tokens and real-time developer
 * notifications delivered by Pub/Sub push.
 *
 * A purchase token proves nothing on its own; the Play Developer API is asked
 * for the subscription's current state every time. A notification carries
 * only the token, so the handler asks again on receipt. Licence-tester
 * purchases are reported by the API as test purchases and are honoured like
 * any other, with the environment recorded on the order.
 */
final class GoogleStoreDriver implements StoreDriver
{
    private const API = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications';

    private const SCOPE = 'https://www.googleapis.com/auth/androidpublisher';

    private const CERTS = 'https://www.googleapis.com/oauth2/v1/certs';

    /** RTDN notificationType values. */
    private const TYPES = [
        1 => 'SUBSCRIPTION_RECOVERED', 2 => 'SUBSCRIPTION_RENEWED', 3 => 'SUBSCRIPTION_CANCELED',
        4 => 'SUBSCRIPTION_PURCHASED', 5 => 'SUBSCRIPTION_ON_HOLD', 6 => 'SUBSCRIPTION_IN_GRACE_PERIOD',
        7 => 'SUBSCRIPTION_RESTARTED', 8 => 'SUBSCRIPTION_PRICE_CHANGE_CONFIRMED', 9 => 'SUBSCRIPTION_DEFERRED',
        10 => 'SUBSCRIPTION_PAUSED', 11 => 'SUBSCRIPTION_PAUSE_SCHEDULE_CHANGED', 12 => 'SUBSCRIPTION_REVOKED',
        13 => 'SUBSCRIPTION_EXPIRED', 19 => 'SUBSCRIPTION_PENDING_PURCHASE_CANCELED', 20 => 'SUBSCRIPTION_PRICE_STEP_UP_CONSENT_UPDATED',
    ];

    private const STATES = [
        'SUBSCRIPTION_STATE_ACTIVE' => 'active',
        'SUBSCRIPTION_STATE_CANCELED' => 'active',   // cancelled but paid up: entitled until expiry
        'SUBSCRIPTION_STATE_IN_GRACE_PERIOD' => 'grace',
        'SUBSCRIPTION_STATE_ON_HOLD' => 'hold',
        'SUBSCRIPTION_STATE_PAUSED' => 'hold',
        'SUBSCRIPTION_STATE_EXPIRED' => 'expired',
        'SUBSCRIPTION_STATE_PENDING' => 'pending',
    ];

    public function __construct(private readonly PaymentGateway $gateway) {}

    public function slug(): string
    {
        return 'google';
    }

    public function verify(string $token): StoreTransaction
    {
        $package = $this->package();
        $response = $this->request(fn (): Response => $this->api()->get("/{$package}/purchases/subscriptionsv2/tokens/{$token}"));

        if (in_array($response->status(), [400, 404, 410], true)) {
            throw new StoreFailed("Google Play does not recognise the purchase token ({$response->status()}).", 'receipt_invalid', 'That purchase could not be found.');
        }

        if (! $response->successful()) {
            throw new StoreFailed("Play Developer API answered {$response->status()}.", 'store_unavailable');
        }

        return $this->transaction($token, (array) $response->json());
    }

    public function readNotification(Request $request): StoreNotification
    {
        $this->verifyPushToken($request->bearerToken());

        $message = $request->input('message');
        $data = is_array($message) && is_string($message['data'] ?? null) ? json_decode(base64_decode($message['data']) ?: '', true) : null;

        if (! is_array($data)) {
            throw new StoreFailed('Pub/Sub message carries no decodable data.', 'bad_signature');
        }

        if (($data['packageName'] ?? null) !== $this->package()) {
            throw new StoreFailed('Notification is for package '.($data['packageName'] ?? '?').', not ours.', 'bad_signature');
        }

        $id = (string) ($message['messageId'] ?? $message['message_id'] ?? sha1((string) $message['data']));
        $subscription = is_array($data['subscriptionNotification'] ?? null) ? $data['subscriptionNotification'] : null;

        if ($subscription === null) {
            // A test ping from the Play Console, or a one-time product event.
            return new StoreNotification(id: $id, store: 'google', type: isset($data['testNotification']) ? 'TEST' : 'IGNORED', subtype: null, externalRef: null, raw: $data);
        }

        return new StoreNotification(
            id: $id,
            store: 'google',
            type: self::TYPES[(int) ($subscription['notificationType'] ?? 0)] ?? 'UNKNOWN',
            subtype: null,
            externalRef: isset($subscription['purchaseToken']) ? (string) $subscription['purchaseToken'] : null,
            transaction: null, // fetched by the handler: the notification is only a hint
            raw: $data,
        );
    }

    public function acknowledge(StoreTransaction $transaction): void
    {
        if (($transaction->raw['acknowledgementState'] ?? null) !== 'ACKNOWLEDGEMENT_STATE_PENDING') {
            return;
        }

        $package = $this->package();
        $response = $this->request(fn (): Response => $this->api()
            ->post("/{$package}/purchases/subscriptions/{$transaction->productId}/tokens/{$transaction->originalTransactionId}:acknowledge"));

        if (! $response->successful()) {
            throw new StoreFailed("Google Play refused the acknowledgement ({$response->status()}).", 'store_unavailable');
        }
    }

    public function check(): array
    {
        try {
            $package = $this->package();
            $response = $this->request(fn (): Response => $this->api()->get("/{$package}/purchases/subscriptionsv2/tokens/preflight-check"));
        } catch (StoreFailed $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return match (true) {
            in_array($response->status(), [400, 404, 410], true) => ['ok' => true], // reached the API with valid credentials; the token is nonsense on purpose
            in_array($response->status(), [401, 403], true) => ['ok' => false, 'error' => 'Google Play refused the service account. Check the JSON key and that the account has access to the app in Play Console.'],
            default => ['ok' => false, 'error' => "Play Developer API answered {$response->status()}."],
        };
    }

    // ---- internals ------------------------------------------------------------

    /** @param array<string, mixed> $data a SubscriptionPurchaseV2 */
    private function transaction(string $token, array $data): StoreTransaction
    {
        $line = is_array($data['lineItems'][0] ?? null) ? $data['lineItems'][0] : [];
        $expires = self::rfc3339($line['expiryTime'] ?? null);
        $state = self::STATES[$data['subscriptionState'] ?? ''] ?? 'unknown';

        return new StoreTransaction(
            store: 'google',
            originalTransactionId: $token,
            transactionId: (string) ($data['latestOrderId'] ?? $token),
            productId: (string) ($line['productId'] ?? ''),
            environment: isset($data['testPurchase']) ? 'sandbox' : 'production',
            state: $state,
            ownership: 'purchased',
            purchasedAt: self::rfc3339($data['startTime'] ?? null),
            expiresAt: $expires,
            autoRenewing: isset($line['autoRenewingPlan']) ? (bool) ($line['autoRenewingPlan']['autoRenewEnabled'] ?? false) : null,
            appAccountToken: isset($data['externalAccountIdentifiers']['obfuscatedExternalAccountId'])
                ? strtolower((string) $data['externalAccountIdentifiers']['obfuscatedExternalAccountId'])
                : null,
            amount: null,   // SubscriptionPurchaseV2 carries no price
            currency: null,
            raw: $data,
        );
    }

    /**
     * Pub/Sub push authenticates with an OIDC token from a Google service
     * account: RS256, signed by keys Google publishes, with the audience we
     * configured on the subscription.
     *
     * @throws StoreFailed reason bad_signature
     */
    private function verifyPushToken(?string $jwt): void
    {
        if ($jwt === null || $jwt === '') {
            throw new StoreFailed('Pub/Sub push carried no bearer token.', 'bad_signature');
        }

        $decoded = Jws::decode($jwt);

        if (($decoded['header']['alg'] ?? null) !== 'RS256' || ! is_string($decoded['header']['kid'] ?? null)) {
            throw new StoreFailed('Pub/Sub token is not RS256 with a key id.', 'bad_signature');
        }

        $pem = $this->googleCerts()[$decoded['header']['kid']] ?? null;
        $key = is_string($pem) ? openssl_pkey_get_public($pem) : false;

        if ($key === false || ! Jws::verifyRs256($decoded['input'], $decoded['signature'], $key)) {
            throw new StoreFailed('Pub/Sub token signature does not verify.', 'bad_signature');
        }

        $claims = $decoded['payload'];
        $credentials = $this->gateway->credentials ?? [];
        $audience = (string) ($credentials['pubsub_audience'] ?? route('webhooks.store', 'google'));
        $account = (string) ($credentials['pubsub_service_account'] ?? '');

        if (! in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)) {
            throw new StoreFailed('Pub/Sub token issuer is not Google.', 'bad_signature');
        }

        if ((int) ($claims['exp'] ?? 0) < time()) {
            throw new StoreFailed('Pub/Sub token has expired.', 'bad_signature');
        }

        if (($claims['aud'] ?? null) !== $audience) {
            throw new StoreFailed('Pub/Sub token audience is '.($claims['aud'] ?? '?').', expected '.$audience.'.', 'bad_signature');
        }

        if ($account !== '' && (($claims['email'] ?? null) !== $account || ($claims['email_verified'] ?? false) !== true)) {
            throw new StoreFailed('Pub/Sub token is from '.($claims['email'] ?? '?').', not the configured service account.', 'bad_signature');
        }
    }

    /** @return array<string, string> kid => PEM certificate */
    private function googleCerts(): array
    {
        return Cache::remember('store.google.oauth2-certs', now()->addHour(), function (): array {
            $response = $this->request(fn (): Response => Http::acceptJson()->timeout(10)->get(self::CERTS));

            if (! $response->successful()) {
                throw new StoreFailed("Could not fetch Google's signing certificates ({$response->status()}).", 'store_unavailable');
            }

            return array_filter((array) $response->json(), 'is_string');
        });
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl(self::API)->withToken($this->accessToken())->acceptJson()->timeout(10);
    }

    /** @param  callable(): Response  $call */
    private function request(callable $call): Response
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            throw new StoreFailed('Could not reach Google: '.$e->getMessage(), 'store_unavailable');
        }
    }

    /** A service-account access token: a self-signed RS256 JWT exchanged at Google's token endpoint. Cached under its lifetime. */
    private function accessToken(): string
    {
        $account = $this->serviceAccount();

        return Cache::remember('store.google.access-token.'.sha1($account['client_email']), now()->addMinutes(50), function () use ($account): string {
            $now = time();
            $assertion = Jws::signRs256(
                ['alg' => 'RS256', 'typ' => 'JWT'],
                ['iss' => $account['client_email'], 'scope' => self::SCOPE, 'aud' => $account['token_uri'], 'iat' => $now, 'exp' => $now + 3600],
                $account['private_key'],
            );

            $response = $this->request(fn (): Response => Http::asForm()->timeout(10)->post($account['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]));

            $token = $response->json('access_token');

            if (! $response->successful() || ! is_string($token)) {
                throw new StoreFailed("Google would not issue an access token ({$response->status()}).", 'store_unavailable');
            }

            return $token;
        });
    }

    /** @return array{client_email: string, private_key: string, token_uri: string} */
    private function serviceAccount(): array
    {
        $json = json_decode((string) ($this->gateway->credentials['service_account_json'] ?? ''), true);

        if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            throw new StoreFailed('The Google Play gateway has no usable service account JSON.', 'store_unavailable');
        }

        return [
            'client_email' => (string) $json['client_email'],
            'private_key' => (string) $json['private_key'],
            'token_uri' => (string) ($json['token_uri'] ?? 'https://oauth2.googleapis.com/token'),
        ];
    }

    private function package(): string
    {
        $package = (string) ($this->gateway->credentials['package_name'] ?? '');

        if ($package === '') {
            throw new StoreFailed('The Google Play gateway has no package name.', 'store_unavailable');
        }

        return $package;
    }

    private static function rfc3339(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }
}
