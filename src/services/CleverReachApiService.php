<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\services;

use Craft;
use craft\base\Component;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use kernpfad\cleverreach\events\ModifyReceiverPayloadEvent;
use kernpfad\cleverreach\Plugin;
use RuntimeException;

/**
 * Thin client for the CleverReach REST API v3.
 *
 * Authenticates via the OAuth2 Client Credentials grant: the plugin only
 * ever needs to act as the account that created the OAuth app (a single
 * CleverReach account per Craft install), so there's no browser-based
 * authorize/redirect dance and no refresh token to persist — an access
 * token is cheap to re-request from client_id/client_secret whenever it's
 * missing or expired.
 */
class CleverReachApiService extends Component
{
    private const TOKEN_URL = 'https://rest.cleverreach.com/oauth/token.php';
    private const API_BASE_URL = 'https://rest.cleverreach.com/v3/';
    private const CACHE_KEY = 'cleverreach_access_token';
    private const LAST_ERROR_CACHE_KEY = 'cleverreach_last_error';
    private const LAST_ERROR_TTL = 60 * 60 * 24 * 30;

    private ?Client $httpClient = null;

    public function getHttpClient(): Client
    {
        return $this->httpClient ??= new Client(['base_uri' => self::API_BASE_URL]);
    }

    /**
     * A single lightweight, read-only call (the same one the Formie
     * integration's list picker already relies on) used to verify the
     * configured OAuth credentials actually work, without side effects on
     * the CleverReach account. Clears any previously recorded error on
     * success, so the settings screen reflects current reality rather
     * than a stale failure from before the credentials were fixed.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        try {
            $this->getGroups();
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $this->clearLastError();

        return ['success' => true, 'message' => Craft::t('cleverreach', 'Connection successful.')];
    }

    /**
     * @return array{message: string, at: int}|null
     */
    public function getLastError(): ?array
    {
        $cache = Craft::$app->getCache();
        $stored = $cache?->get(self::LAST_ERROR_CACHE_KEY);

        return is_array($stored) && isset($stored['message'], $stored['at']) ? $stored : null;
    }

    public function clearLastError(): void
    {
        Craft::$app->getCache()?->delete(self::LAST_ERROR_CACHE_KEY);
    }

    /**
     * Records a sanitized (already secret-free — see request()/
     * requestAccessToken(), neither ever puts the client secret or access
     * token into an exception message) error for CP display, in addition
     * to the existing Craft::error() log entry. A site visitor's failed
     * subscribe attempt is the most likely time this fires, so an admin
     * needs a way to see it without log access.
     */
    private function recordError(string $message): void
    {
        Craft::$app->getCache()?->set(self::LAST_ERROR_CACHE_KEY, [
            'message' => $message,
            'at' => time(),
        ], self::LAST_ERROR_TTL);
    }

    /**
     * Creates a new receiver as inactive (double opt-in pending). Never call
     * this with an "activated" override — activation only ever happens via
     * the CleverReach-side DOI mail triggered by {@see sendDoubleOptInMail()},
     * so an already-subscribed receiver is never re-deactivated by accident.
     *
     * Posts to `receivers/upsert` (not the plain `receivers` collection) so
     * this is a true upsert-by-email rather than a blind create. Endpoint
     * shape and payload wrapper (`[[...]]`, a batch of one) verified against
     * Formie's own bundled `verbb\formie\integrations\emailmarketing\CleverReach`
     * integration source — see also
     * {@see \kernpfad\cleverreach\integrations\formie\CleverReachEmailMarketing}.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function createReceiverForDoubleOptIn(int $groupId, string $email, array $attributes = []): array
    {
        return $this->upsertReceiver($groupId, $email, false, ['attributes' => $attributes]);
    }

    /** @return array<string, mixed> */
    public function sendDoubleOptInMail(int $doiFormId, string $email): array
    {
        return $this->request('POST', "forms/{$doiFormId}/send/activate", ['email' => $email]);
    }

    /**
     * Attaches an order to a receiver who is assumed to already be an
     * active, consenting subscriber (callers must verify consent before
     * calling this — see ConsentService/SubscriberService). `activated`
     * is sent as `true` here specifically so this call can never flip an
     * existing receiver back into pending-DOI state.
     *
     * @param array<string, mixed> $orderPayload
     * @return array<string, mixed>
     */
    public function pushOrderToReceiver(int $groupId, string $email, array $orderPayload): array
    {
        return $this->upsertReceiver($groupId, $email, true, ['orders' => [$orderPayload]]);
    }

    /**
     * Creates or updates a receiver as immediately active — no DOI
     * round-trip. Only ever call this when the caller already holds
     * evidence of (or explicitly accepts responsibility for) prior
     * consent; see SubscriberService::activateWithAttributes() and the
     * import console command's `require-consent`/`activate` modes. Never
     * used by the generic subscribe endpoint or the Formie integration.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function activateReceiver(int $groupId, string $email, array $attributes = []): array
    {
        return $this->upsertReceiver($groupId, $email, true, ['attributes' => $attributes]);
    }

    /**
     * Updates receiver attributes without forcing activation (CR-06 soft-sync).
     * Used while a DOI confirmation is still pending so profile data is not
     * lost until the subscriber confirms — CleverReach keeps `activated: false`.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function updateReceiverAttributes(int $groupId, string $email, array $attributes = []): array
    {
        return $this->upsertReceiver($groupId, $email, false, ['attributes' => $attributes]);
    }

    /**
     * All groups (lists) in the connected CleverReach account. Used to
     * populate the list picker in the Formie email marketing integration
     * (see integrations/formie/CleverReachEmailMarketing.php).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getGroups(): array
    {
        $result = $this->request('GET', 'groups');

        return array_values(array_filter($result, 'is_array'));
    }

    /**
     * DOI / opt-in forms in the connected CleverReach account (CR-04).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getForms(): array
    {
        $result = $this->request('GET', 'forms');

        return array_values(array_filter($result, 'is_array'));
    }

    /**
     * A single receiver's current state in a group — most importantly
     * whether they've actually completed double opt-in (`activated`), not
     * just whether this plugin created them as pending (CR-06). Returns
     * null both when the receiver genuinely doesn't exist yet and when the
     * lookup itself fails (network error, bad credentials) - callers that
     * need to tell those apart should call {@see getGroups()} or
     * {@see testConnection()} separately to check connectivity first.
     *
     * Endpoint shape follows the same `groups/{id}/receivers/...` REST
     * convention already verified for {@see upsertReceiver()} against
     * Formie's bundled CleverReach integration.
     *
     * @return array<string, mixed>|null
     */
    public function getReceiver(int $groupId, string $email): ?array
    {
        try {
            $result = $this->request('GET', "groups/{$groupId}/receivers/" . rawurlencode($email));
        } catch (\Throwable $e) {
            return null;
        }

        return $result === [] ? null : $result;
    }

    /**
     * Adds tags to a single receiver in a group (CR-10). CleverReach only
     * fires THEA/automation triggers when a group id is supplied — hence the
     * group-scoped endpoint. Call once per receiver; batch tagging does not
     * trigger automations.
     *
     * @param list<string> $tags
     * @return array<string, mixed>
     */
    public function addTags(int $groupId, string $email, array $tags): array
    {
        if ($tags === []) {
            return [];
        }

        return $this->request(
            'POST',
            "groups/{$groupId}/receivers/" . rawurlencode($email) . '/tags',
            ['tags' => $tags]
        );
    }

    /**
     * Account-wide receiver attributes (not per-group — CleverReach's
     * `attributes` endpoint is global). Used the same way as
     * {@see getGroups()}, to build the Formie field-mapping UI.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getReceiverAttributes(): array
    {
        $result = $this->request('GET', 'attributes');

        return array_values(array_filter($result, 'is_array'));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function upsertReceiver(int $groupId, string $email, bool $activated, array $extra = []): array
    {
        $payload = array_merge(
            [
                'email' => $email,
                'activated' => $activated,
            ],
            $extra
        );

        $plugin = Plugin::getInstance();

        if ($plugin->hasEventHandlers(Plugin::EVENT_MODIFY_RECEIVER_PAYLOAD)) {
            $event = new ModifyReceiverPayloadEvent($groupId, $email, $activated, $payload);
            $plugin->trigger(Plugin::EVENT_MODIFY_RECEIVER_PAYLOAD, $event);
            $payload = $event->payload;
        }

        return $this->request('POST', "groups/{$groupId}/receivers/upsert", [$payload]);
    }

    /**
     * @param array<mixed> $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $body = []): array
    {
        try {
            return $this->doRequest($method, $path, $body);
        } catch (RuntimeException $e) {
            $this->recordError($e->getMessage());
            throw $e;
        }
    }

    /**
     * @param array<mixed> $body
     * @return array<string, mixed>
     */
    private function doRequest(string $method, string $path, array $body = []): array
    {
        try {
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                    'Accept' => 'application/json',
                ],
            ];

            if ($method !== 'GET') {
                $options['json'] = $body;
            }

            $response = $this->getHttpClient()->request($method, $path, $options);
        } catch (GuzzleException $e) {
            Craft::error('CleverReach API request failed: ' . $e->getMessage(), __METHOD__);
            throw new RuntimeException('CleverReach API request failed: ' . $e->getMessage(), 0, $e);
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode >= 400) {
            $message = sprintf('CleverReach API returned HTTP %d for %s %s', $statusCode, $method, $path);
            Craft::error($message, __METHOD__);
            throw new RuntimeException($message);
        }

        $decoded = json_decode((string) $response->getBody(), true);

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('CleverReach API returned invalid JSON for %s %s', $method, $path));
        }

        return $decoded;
    }

    private function getAccessToken(): string
    {
        $cache = Craft::$app->getCache();
        if ($cache === null) {
            throw new RuntimeException('Cache component is not available.');
        }

        $cached = $cache->get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = $this->requestAccessToken();
        // Refresh a little before actual expiry to avoid races with in-flight requests.
        $ttl = max(0, $token['expires_in'] - 60);
        $cache->set(self::CACHE_KEY, $token['access_token'], $ttl);

        return $token['access_token'];
    }

    /**
     * @return array{access_token: string, expires_in: int}
     */
    private function requestAccessToken(): array
    {
        try {
            return $this->doRequestAccessToken();
        } catch (RuntimeException $e) {
            $this->recordError($e->getMessage());
            throw $e;
        }
    }

    /**
     * @return array{access_token: string, expires_in: int}
     */
    private function doRequestAccessToken(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $clientId = $settings->getOauthClientId();
        $clientSecret = $settings->getOauthClientSecret();

        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('CleverReach OAuth client ID/secret are not configured.');
        }

        try {
            $response = (new Client())->request('POST', self::TOKEN_URL, [
                'auth' => [$clientId, $clientSecret],
                'form_params' => [
                    'grant_type' => 'client_credentials',
                ],
            ]);
        } catch (GuzzleException $e) {
            Craft::error('CleverReach OAuth token request failed: ' . $e->getMessage(), __METHOD__);
            throw new RuntimeException('CleverReach OAuth token request failed: ' . $e->getMessage(), 0, $e);
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode >= 400) {
            throw new RuntimeException(sprintf('CleverReach OAuth token request returned HTTP %d', $statusCode));
        }

        $decoded = json_decode((string) $response->getBody(), true);

        if (!is_array($decoded) || !isset($decoded['access_token'], $decoded['expires_in'])) {
            throw new RuntimeException('Unexpected CleverReach OAuth token response.');
        }

        return [
            'access_token' => (string) $decoded['access_token'],
            'expires_in' => (int) $decoded['expires_in'],
        ];
    }
}
