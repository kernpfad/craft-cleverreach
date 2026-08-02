<?php

namespace fipschen95\cleverreach\services;

use Craft;
use craft\base\Component;
use fipschen95\cleverreach\Plugin;

/**
 * Maps a Craft signup form submission to a CleverReach double-opt-in
 * subscription: creates the (inactive) receiver, triggers the DOI mail,
 * and logs an independent consent proof.
 */
class SubscriberService extends Component
{
    /**
     * @param string $email
     * @param array<string, mixed> $formData Raw Craft field values, keyed by field handle
     * @param string $source Free-text description of where the signup came from, e.g. "footer-newsletter-form"
     * @param string $ipAddress
     * @param int|null $groupId Overrides the configured default group, if given
     * @param string|null $consentTextVersion Version identifier of the consent text shown to the user
     * @param int|null $userId Craft User to link the consent record to; auto-resolved by email if omitted
     */
    public function subscribe(
        string $email,
        array $formData,
        string $source,
        string $ipAddress,
        ?int $groupId = null,
        ?string $consentTextVersion = null,
        ?int $userId = null
    ): bool {
        return $this->subscribeWithAttributes(
            $email,
            $this->mapAttributes($formData),
            $source,
            $ipAddress,
            $groupId,
            $consentTextVersion,
            $userId
        );
    }

    /**
     * Same as {@see subscribe()}, but for callers that already have a
     * resolved CleverReach attribute payload (e.g. the Formie email
     * marketing integration, whose field mapping is configured in Formie's
     * own UI rather than this plugin's attributeMapping setting).
     *
     * @param array<string, mixed> $attributes Already resolved CleverReach attribute handle => value
     */
    public function subscribeWithAttributes(
        string $email,
        array $attributes,
        string $source,
        string $ipAddress,
        ?int $groupId = null,
        ?string $consentTextVersion = null,
        ?int $userId = null
    ): bool {
        $settings = Plugin::getInstance()->getSettings();
        $groupId ??= $settings->defaultGroupId;

        if ($groupId === null) {
            return false;
        }

        Plugin::getInstance()->cleverReachApi->createReceiverForDoubleOptIn($groupId, $email, $attributes);

        if ($settings->doiFormId !== null) {
            Plugin::getInstance()->cleverReachApi->sendDoubleOptInMail($settings->doiFormId, $email);
        }

        Plugin::getInstance()->consent->logConsent(
            $email,
            $ipAddress,
            $source,
            $consentTextVersion,
            $groupId,
            $userId ?? $this->resolveUserId($email)
        );

        return true;
    }

    /**
     * Activates a receiver directly, without a DOI round-trip — for callers
     * who already hold evidence of prior consent (e.g. the import command's
     * `require-consent` mode) or who explicitly accept responsibility for
     * the batch (`activate` mode). Never used by the generic subscribe
     * endpoint or the Formie integration, which always go through DOI.
     *
     * @param array<string, mixed> $attributes
     */
    public function activateWithAttributes(
        string $email,
        array $attributes,
        string $source,
        string $ipAddress,
        ?int $groupId = null,
        ?string $consentTextVersion = null,
        ?int $userId = null
    ): bool {
        $settings = Plugin::getInstance()->getSettings();
        $groupId ??= $settings->defaultGroupId;

        if ($groupId === null) {
            return false;
        }

        Plugin::getInstance()->cleverReachApi->activateReceiver($groupId, $email, $attributes);

        Plugin::getInstance()->consent->logConsent(
            $email,
            $ipAddress,
            $source,
            $consentTextVersion,
            $groupId,
            $userId ?? $this->resolveUserId($email)
        );

        return true;
    }

    private function resolveUserId(string $email): ?int
    {
        return Craft::$app->getUsers()->getUserByUsernameOrEmail($email)?->id;
    }

    /**
     * Maps raw field values (Craft field handle => value) to CleverReach
     * attributes via the plugin's configured attributeMapping setting.
     * Public so the import console command can reuse the exact same
     * mapping semantics for the Craft User source.
     *
     * @param array<string, mixed> $formData
     * @return array<string, mixed>
     */
    public function mapAttributes(array $formData): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $attributes = [];

        foreach ($settings->attributeMapping as $mapping) {
            $craftField = $mapping['craftField'] ?? null;
            $cleverReachAttribute = $mapping['cleverReachAttribute'] ?? null;

            if ($craftField === null || $cleverReachAttribute === null || !array_key_exists($craftField, $formData)) {
                continue;
            }

            $attributes[$cleverReachAttribute] = $formData[$craftField];
        }

        return $attributes;
    }
}
