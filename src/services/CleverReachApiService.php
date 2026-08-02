<?php

namespace kernpfad\cleverreach\services;

use Craft;
use craft\base\Component;
use kernpfad\cleverreach\Plugin;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
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

    private ?Client $httpClient = null;

    public function getHttpClient(): Client
    {
        return $this->httpClient ??= new Client(['base_uri' => self::API_BASE_URL]);
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
     */
    public function createReceiverForDoubleOptIn(int $groupId, string $email, array $attributes = []): array
    {
        return $this->request('POST', "groups/{$groupId}/receivers/upsert", [
            [
                'email' => $email,
                'activated' => false,
                'attributes' => $attributes,
            ],
        ]);
    }

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
     */
    public function pushOrderToReceiver(int $groupId, string $email, array $orderPayload): array
    {
        return $this->request('POST', "groups/{$groupId}/receivers/upsert", [
            [
                'email' => $email,
                'activated' => true,
                'orders' => [$orderPayload],
            ],
        ]);
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
     */
    public function activateReceiver(int $groupId, string $email, array $attributes = []): array
    {
        return $this->request('POST', "groups/{$groupId}/receivers/upsert", [
            [
                'email' => $email,
                'activated' => true,
                'attributes' => $attributes,
            ],
        ]);
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
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $body = []): array
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

        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function getAccessToken(): string
    {
        $cache = Craft::$app->getCache();
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
