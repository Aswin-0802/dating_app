<?php

declare(strict_types=1);

namespace App\Services\Store;

use App\Models\PaymentGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * The App Store: StoreKit 2 transactions and App Store Server Notifications V2.
 *
 * Everything Apple sends is a JWS whose certificate chain must end at Apple's
 * root, so nothing here trusts a decoded payload before verifyApple() has
 * accepted it. The `environment` inside a VERIFIED transaction decides which
 * App Store Server API host is asked — a TestFlight build makes Sandbox
 * purchases against the production server, and both must be honoured. The
 * gateway's test-mode toggle only picks credentials; it never decides
 * whether a sandbox transaction counts.
 */
final class AppleStoreDriver implements StoreDriver
{
    private const PRODUCTION = 'https://api.storekit.itunes.apple.com';

    private const SANDBOX = 'https://api.storekit-sandbox.itunes.apple.com';

    /** Apple's `status` on a subscription. */
    private const STATES = [
        1 => 'active',
        2 => 'expired',
        3 => 'hold',     // billing retry: entitlement lost until it recovers
        4 => 'grace',    // billing grace period: still entitled
        5 => 'revoked',
    ];

    public function __construct(
        private readonly PaymentGateway $gateway,
        private ?string $rootPem = null,
    ) {}

    public function slug(): string
    {
        return 'apple';
    }

    public function verify(string $token): StoreTransaction
    {
        try {
            $claimed = $this->payload($token);
        } catch (StoreFailed $e) {
            // A client blob that does not verify is an invalid receipt, not a
            // forged webhook.
            throw new StoreFailed($e->getMessage(), 'receipt_invalid', 'That purchase could not be verified.');
        }

        if (! $this->isOurs($claimed)) {
            throw new StoreFailed('The transaction is for bundle '.($claimed['bundleId'] ?? '?').', not ours.', 'receipt_invalid', 'That purchase is for a different app.');
        }

        $originalId = (string) ($claimed['originalTransactionId'] ?? '');

        if ($originalId === '') {
            throw new StoreFailed('The transaction has no originalTransactionId.', 'receipt_invalid');
        }

        $environment = $this->environment($claimed);
        $response = $this->request(fn (): Response => $this->api($environment)->get("/inApps/v1/subscriptions/{$originalId}"));

        if ($response->status() === 404) {
            throw new StoreFailed("Apple has no subscription {$originalId} in {$environment}.", 'receipt_invalid', 'That purchase could not be found.');
        }

        if (! $response->successful()) {
            throw new StoreFailed("App Store Server API answered {$response->status()} for {$originalId}.", 'store_unavailable');
        }

        $last = $response->json('data.0.lastTransactions.0');

        if (! is_array($last) || ! is_string($last['signedTransactionInfo'] ?? null)) {
            throw new StoreFailed("App Store Server API returned no transaction for {$originalId}.", 'receipt_invalid');
        }

        $transaction = $this->payload($last['signedTransactionInfo']);
        $renewal = is_string($last['signedRenewalInfo'] ?? null) ? $this->payload($last['signedRenewalInfo']) : [];

        return $this->transaction($transaction, $renewal, (int) ($last['status'] ?? 0));
    }

    public function readNotification(Request $request): StoreNotification
    {
        $signed = $request->input('signedPayload');

        if (! is_string($signed) || $signed === '') {
            throw new StoreFailed('No signedPayload in the notification.', 'bad_signature');
        }

        $payload = $this->payload($signed);
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        if (isset($data['bundleId']) && ! $this->isOurs($data)) {
            throw new StoreFailed('Notification is for bundle '.$data['bundleId'].', not ours.', 'bad_signature');
        }

        $transaction = null;

        if (is_string($data['signedTransactionInfo'] ?? null)) {
            $renewal = is_string($data['signedRenewalInfo'] ?? null) ? $this->payload($data['signedRenewalInfo']) : [];
            $transaction = $this->transaction($this->payload($data['signedTransactionInfo']), $renewal, (int) ($data['status'] ?? 0));
        }

        return new StoreNotification(
            id: (string) ($payload['notificationUUID'] ?? sha1($signed)),
            store: 'apple',
            type: (string) ($payload['notificationType'] ?? 'UNKNOWN'),
            subtype: isset($payload['subtype']) ? (string) $payload['subtype'] : null,
            externalRef: $transaction?->originalTransactionId,
            transaction: $transaction,
            raw: ['notificationType' => $payload['notificationType'] ?? null, 'subtype' => $payload['subtype'] ?? null,
                'notificationUUID' => $payload['notificationUUID'] ?? null, 'environment' => $data['environment'] ?? null,
                'originalTransactionId' => $transaction?->originalTransactionId, 'transactionId' => $transaction?->transactionId],
        );
    }

    public function acknowledge(StoreTransaction $transaction): void
    {
        // Apple has nothing to acknowledge.
    }

    public function check(): array
    {
        try {
            $response = $this->request(fn (): Response => $this->api('production')->post('/inApps/v1/notifications/test'));
        } catch (StoreFailed $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return match (true) {
            $response->successful() => ['ok' => true],
            $response->status() === 401 => ['ok' => false, 'error' => 'Apple refused the App Store Server API key. Check the issuer id, key id, private key and bundle id.'],
            default => ['ok' => false, 'error' => "App Store Server API answered {$response->status()}."],
        };
    }

    // ---- internals ------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $transaction  a verified JWSTransactionDecodedPayload
     * @param  array<string, mixed>  $renewal  a verified JWSRenewalInfoDecodedPayload, or []
     */
    private function transaction(array $transaction, array $renewal, int $status): StoreTransaction
    {
        $expires = self::ms($transaction['expiresDate'] ?? null);
        $state = self::STATES[$status] ?? match (true) {
            isset($transaction['revocationDate']) => 'revoked',
            $expires === null => 'unknown',
            $expires->isFuture() => 'active',
            default => 'expired',
        };

        if ($state === 'grace' && ($grace = self::ms($renewal['gracePeriodExpiresDate'] ?? null)) !== null) {
            $expires = $grace;
        }

        return new StoreTransaction(
            store: 'apple',
            originalTransactionId: (string) ($transaction['originalTransactionId'] ?? ''),
            transactionId: (string) ($transaction['transactionId'] ?? ''),
            productId: (string) ($transaction['productId'] ?? ''),
            environment: $this->environment($transaction),
            state: $state,
            ownership: ($transaction['inAppOwnershipType'] ?? '') === 'FAMILY_SHARED' ? 'family_shared' : 'purchased',
            purchasedAt: self::ms($transaction['purchaseDate'] ?? null),
            expiresAt: $expires,
            autoRenewing: array_key_exists('autoRenewStatus', $renewal) ? (int) $renewal['autoRenewStatus'] === 1 : null,
            appAccountToken: isset($transaction['appAccountToken']) ? strtolower((string) $transaction['appAccountToken']) : null,
            // Apple reports the price in milliunits of the currency.
            amount: isset($transaction['price']) ? round(((int) $transaction['price']) / 1000, 2) : null,
            currency: isset($transaction['currency']) ? strtoupper((string) $transaction['currency']) : null,
            raw: ['transaction' => $transaction, 'renewal' => $renewal, 'status' => $status],
        );
    }

    /** @param array<string, mixed> $payload */
    private function environment(array $payload): string
    {
        return strtolower((string) ($payload['environment'] ?? 'Production')) === 'sandbox' ? 'sandbox' : 'production';
    }

    /** @param array<string, mixed> $payload */
    private function isOurs(array $payload): bool
    {
        $bundle = (string) ($this->gateway->credentials['bundle_id'] ?? '');

        return $bundle === '' || ($payload['bundleId'] ?? null) === $bundle;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws StoreFailed reason bad_signature
     */
    private function payload(string $jws): array
    {
        return Jws::verifyApple($jws, $this->rootPem ??= (string) file_get_contents(resource_path('certs/AppleRootCA-G3.pem')));
    }

    private function api(string $environment): PendingRequest
    {
        return Http::baseUrl($environment === 'sandbox' ? self::SANDBOX : self::PRODUCTION)
            ->withToken($this->jwt())
            ->acceptJson()
            ->timeout(10);
    }

    /** @param  callable(): Response  $call */
    private function request(callable $call): Response
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            throw new StoreFailed('Could not reach the App Store Server API: '.$e->getMessage(), 'store_unavailable');
        }
    }

    /** The App Store Server API's bearer token: an ES256 JWT we sign with the .p8 key. */
    private function jwt(): string
    {
        $credentials = $this->gateway->credentials ?? [];

        foreach (['issuer_id', 'key_id', 'private_key', 'bundle_id'] as $field) {
            if (empty($credentials[$field])) {
                throw new StoreFailed("The App Store gateway has no {$field}.", 'store_unavailable');
            }
        }

        $now = time();

        return Jws::signEs256(
            ['alg' => 'ES256', 'kid' => $credentials['key_id'], 'typ' => 'JWT'],
            ['iss' => $credentials['issuer_id'], 'iat' => $now, 'exp' => $now + 20 * 60, 'aud' => 'appstoreconnect-v1', 'bid' => $credentials['bundle_id']],
            str_replace('\n', "\n", (string) $credentials['private_key']),
        );
    }

    private static function ms(mixed $milliseconds): ?Carbon
    {
        return is_numeric($milliseconds) ? Carbon::createFromTimestampMs((int) $milliseconds) : null;
    }
}
