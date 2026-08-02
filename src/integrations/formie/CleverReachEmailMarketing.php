<?php

namespace kernpfad\cleverreach\integrations\formie;

use Craft;
use craft\helpers\ArrayHelper;
use kernpfad\cleverreach\Plugin;
use Throwable;
use verbb\formie\base\EmailMarketing;
use verbb\formie\base\Integration;
use verbb\formie\elements\Submission;
use verbb\formie\models\IntegrationCollection;
use verbb\formie\models\IntegrationField;
use verbb\formie\models\IntegrationFormSettings;

/**
 * Native Formie "Email Marketing" integration for CleverReach — an
 * alternative front door to the same double-opt-in signup used by the
 * plugin's generic `actions/cleverreach/subscribe/subscribe` endpoint
 * (see SubscribeController). Site builders can use either: Formie's
 * built-in field-mapping UI here, or POST to the generic endpoint from any
 * other form. Both end up in SubscriberService, so behaviour (DOI,
 * consent logging) is identical either way.
 *
 * Formie already ships its own bundled `verbb\formie\integrations\
 * emailmarketing\CleverReach` integration. This one exists alongside it
 * on purpose, not as a redundant duplicate: the bundled integration
 * activates receivers immediately (`'activated' => time()`, no DOI at
 * all — confirmed by reading its source, installed at
 * vendor/verbb/formie/src/integrations/emailmarketing/CleverReach.php)
 * and authenticates via its own separate per-integration OAuth
 * connection. This one always creates receivers as DOI-pending and
 * writes to this plugin's own consent log,
 * reusing the single OAuth connection already configured on the
 * CleverReach plugin's own settings page — consistent with how it behaves
 * everywhere else in this plugin. Site builders who don't need the DOI/
 * consent-log guarantees can just use Formie's bundled one instead;
 * displayName() is deliberately distinct so both can be told apart in
 * Formie's integration picker.
 *
 * Deliberately has no `apiKey`-style settings of its own: authentication
 * is already configured once, centrally, on the CleverReach plugin's own
 * settings page (see models/Settings.php), and reused here via
 * Plugin::getInstance()->cleverReachApi.
 *
 * Only loaded/registered when verbb/formie is actually installed — see
 * Plugin::attachFormieEventHandlers().
 */
class CleverReachEmailMarketing extends EmailMarketing
{
    /** @var string|null The CleverReach group ID selected in Formie's list picker */
    public ?string $listId = null;

    public static function displayName(): string
    {
        return Craft::t('formie', 'CleverReach (Double-Opt-in)');
    }

    public function getDescription(): string
    {
        return Craft::t('formie', 'Meldet Formular-Einsendungen per Double-Opt-in bei CleverReach an (eigener Consent-Nachweis, geteilte Zugangsdaten mit dem CleverReach-Plugin). Für sofortige Aktivierung ohne DOI die eingebaute CleverReach-Integration von Formie nutzen.');
    }

    public function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['listId'], 'required'];

        return $rules;
    }

    public function fetchFormSettings(): IntegrationFormSettings
    {
        $lists = [];

        try {
            $api = Plugin::getInstance()->cleverReachApi;

            // CleverReach's `attributes` endpoint is account-wide, not per-group
            // (verified against Formie's own bundled CleverReach integration).
            $customFields = $this->buildCustomFields($api->getReceiverAttributes());

            foreach ($api->getGroups() as $group) {
                $groupId = (string) ($group['id'] ?? '');

                if ($groupId === '') {
                    continue;
                }

                $fields = array_merge([
                    new IntegrationField([
                        'handle' => 'email',
                        'name' => Craft::t('formie', 'Email'),
                        'required' => true,
                    ]),
                    new IntegrationField([
                        'handle' => 'consentTextVersion',
                        'name' => Craft::t('formie', 'Consent Text Version'),
                    ]),
                ], $customFields);

                $lists[] = new IntegrationCollection([
                    'id' => $groupId,
                    'name' => (string) ($group['name'] ?? $groupId),
                    'fields' => $fields,
                ]);
            }
        } catch (Throwable $e) {
            Integration::apiError($this, $e);
        }

        return new IntegrationFormSettings([
            'lists' => $lists,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $attributes
     * @return array<int, IntegrationField>
     */
    private function buildCustomFields(array $attributes): array
    {
        $fields = [];

        foreach ($attributes as $attribute) {
            $handle = (string) ($attribute['key'] ?? $attribute['name'] ?? '');

            if ($handle === '' || $handle === 'email') {
                continue;
            }

            $fields[] = new IntegrationField([
                'handle' => $handle,
                'name' => (string) ($attribute['description'] ?? $attribute['name'] ?? $handle),
            ]);
        }

        return $fields;
    }

    public function sendPayload(Submission $submission): bool
    {
        try {
            $fieldValues = $this->getFieldMappingValues($submission, $this->fieldMapping);

            $email = ArrayHelper::remove($fieldValues, 'email');

            if (!$email) {
                return true;
            }

            $consentTextVersion = ArrayHelper::remove($fieldValues, 'consentTextVersion');
            $form = $submission->getForm();

            Plugin::getInstance()->subscriber->subscribeWithAttributes(
                (string) $email,
                $fieldValues,
                'formie:' . ($form?->handle ?? 'unknown'),
                $submission->canGetProperty('ipAddress') ? (string) ($submission->ipAddress ?? '') : '',
                $this->listId !== null ? (int) $this->listId : null,
                $consentTextVersion !== null ? (string) $consentTextVersion : null
            );

            return true;
        } catch (Throwable $e) {
            Integration::apiError($this, $e);

            return false;
        }
    }
}
