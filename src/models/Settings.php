<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;

/**
 * CleverReach plugin settings.
 *
 * OAuth client credentials are stored as env var references (`$VAR_NAME`)
 * so the actual secrets never end up in project.yaml / version control.
 * Authentication uses the OAuth2 Client Credentials grant, so no
 * user-facing redirect flow or persisted refresh token is needed — see
 * CleverReachApiService.
 */
class Settings extends Model
{
    /** @var string Env var reference holding the CleverReach OAuth Client ID, e.g. "$CLEVERREACH_CLIENT_ID" */
    public string $oauthClientId = '';

    /** @var string Env var reference holding the CleverReach OAuth Client Secret, e.g. "$CLEVERREACH_CLIENT_SECRET" */
    public string $oauthClientSecret = '';

    /** @var int|null Default CleverReach group ID that new receivers are added to */
    public ?int $defaultGroupId = null;

    /** @var int|null CleverReach double-opt-in form ID used to trigger the confirmation mail */
    public ?int $doiFormId = null;

    /**
     * Craft field handle => CleverReach attribute name.
     *
     * @var array<int, array{craftField: string, cleverReachAttribute: string}>
     */
    public array $attributeMapping = [];

    /** @var bool Whether completed Craft Commerce orders should be pushed to CleverReach for existing subscribers */
    public bool $enableOrderPush = false;

    /**
     * Exposes Craft Commerce products to CleverReach's "My
     * Content" product-search interface, so editors can browse and insert
     * real shop products while composing a campaign.
     */
    public bool $enableCatalog = false;

    /** @var string Env var reference for the optional My-Content request password, e.g. "$CLEVERREACH_CATALOG_PASSWORD" */
    public string $catalogPassword = '';

    /** @var string|null Assets field handle on the Commerce Product used as the item image */
    public ?string $catalogImageFieldHandle = null;

    /** @var string|null Named Craft image transform handle applied to the catalog image */
    public ?string $catalogImageTransformHandle = null;

    /** @var string|null Plain-text/Table field handle on the Commerce Product used as the item description */
    public ?string $catalogDescriptionFieldHandle = null;

    /**
     * @var string Env var reference for the shared secret the unsubscribe/bounce
     * webhook endpoint (CR-07) requires, e.g. "$CLEVERREACH_WEBHOOK_SECRET".
     * Empty disables the endpoint entirely (returns 404) rather than accepting
     * unauthenticated requests.
     */
    public string $webhookSecret = '';

    public function rules(): array
    {
        return [
            [['oauthClientId', 'oauthClientSecret', 'catalogPassword', 'webhookSecret'], 'string'],
            [['defaultGroupId', 'doiFormId'], 'integer'],
            [['attributeMapping'], 'safe'],
            [['enableOrderPush', 'enableCatalog'], 'boolean'],
            [['catalogImageFieldHandle', 'catalogImageTransformHandle', 'catalogDescriptionFieldHandle'], 'string'],
            [['doiFormId'], 'validateDoiFormId'],
        ];
    }

    /**
     * A default group without a DOI form would create inactive receivers that
     * never receive a confirmation mail — catch that at save time.
     */
    public function validateDoiFormId(string $attribute): void
    {
        if ($this->defaultGroupId !== null && $this->doiFormId === null) {
            $this->addError(
                $attribute,
                Craft::t('cleverreach', 'A double opt-in form ID is required when a default group is configured.')
            );
        }
    }

    public function getOauthClientId(): string
    {
        return (string)(App::parseEnv($this->oauthClientId) ?: '');
    }

    public function getOauthClientSecret(): string
    {
        return (string)(App::parseEnv($this->oauthClientSecret) ?: '');
    }

    public function getCatalogPassword(): string
    {
        return (string)(App::parseEnv($this->catalogPassword) ?: '');
    }

    public function getWebhookSecret(): string
    {
        return (string)(App::parseEnv($this->webhookSecret) ?: '');
    }
}
