<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\console\controllers;

use craft\console\Controller;
use craft\elements\User;
use kernpfad\cleverreach\Plugin;
use kernpfad\cleverreach\util\CsvMappingParser;
use RuntimeException;
use Throwable;
use yii\console\ExitCode;

/**
 * Bulk-imports pre-existing contacts into CleverReach: Craft User accounts,
 * Craft Commerce customers, or an arbitrary CSV export.
 *
 * Consent handling is a deliberate per-run choice for the operator, not a
 * fixed policy:
 *
 *   --consentMode=require-consent  Only import contacts with existing,
 *                                  verifiable consent evidence (a Craft
 *                                  field for users/customers, a mapped
 *                                  column for CSV). Everyone else is
 *                                  skipped — no DOI mail sent to them.
 *   --consentMode=doi              Every contact goes through the normal
 *                                  double-opt-in flow (created inactive,
 *                                  CleverReach sends the confirmation
 *                                  mail). Nobody ends up subscribed
 *                                  without a fresh, verifiable action.
 *   --consentMode=activate         Every contact is activated immediately,
 *                                  no DOI. Requires --acceptResponsibility
 *                                  as an explicit safety gate, since this
 *                                  mode has no per-contact verification —
 *                                  the operator is attesting a lawful
 *                                  basis exists outside the plugin.
 *
 * Nothing is written unless --confirm is passed; without it, every action
 * runs as a dry run (counts only, no API calls, no consent log entries).
 *
 * Examples:
 *   php craft cleverreach/import/users --consentMode=require-consent --consentField=newsletterOptIn --confirm
 *   php craft cleverreach/import/customers --consentMode=doi --confirm
 *   php craft cleverreach/import/csv --file=/path/legacy.csv --mapping="E-Mail:email,Vorname:firstname,Opt-In:consent" --consentMode=require-consent --confirm
 */
class ImportController extends Controller
{
    public const CONSENT_MODE_REQUIRE_CONSENT = 'require-consent';
    public const CONSENT_MODE_DOI = 'doi';
    public const CONSENT_MODE_ACTIVATE = 'activate';

    /** @var string One of require-consent|doi|activate */
    public string $consentMode = self::CONSENT_MODE_DOI;

    /** @var int|null Overrides the plugin's configured default group */
    public ?int $groupId = null;

    /** @var bool Without this, the command only reports counts and writes nothing */
    public bool $confirm = false;

    /** @var bool Required in addition to --confirm when --consentMode=activate */
    public bool $acceptResponsibility = false;

    /** @var string|null Craft field handle checked for pre-existing consent (users/customers sources) */
    public ?string $consentField = null;

    /** @var string|null Path to the CSV file (csv source) */
    public ?string $file = null;

    /** @var string|null Column-to-target mapping, e.g. "E-Mail:email,Vorname:firstname,Opt-In:consent" */
    public ?string $mapping = null;

    /** @var bool Whether the CSV has a header row naming the mapped columns */
    public bool $hasHeader = true;

    public function options($actionID): array
    {
        $common = ['consentMode', 'groupId', 'confirm', 'acceptResponsibility'];

        return match ($actionID) {
            'users', 'customers' => array_merge($common, ['consentField']),
            'csv' => array_merge($common, ['file', 'mapping', 'hasHeader']),
            default => $common,
        };
    }

    public function actionUsers(): int
    {
        if (!$this->validateConsentMode()) {
            return ExitCode::USAGE;
        }

        return $this->processContacts('craft-users', $this->collectUsers());
    }

    public function actionCustomers(): int
    {
        if (!$this->validateConsentMode()) {
            return ExitCode::USAGE;
        }

        if (!class_exists(\craft\commerce\Plugin::class)) {
            $this->stderr("Craft Commerce is not installed.\n");

            return ExitCode::UNAVAILABLE;
        }

        return $this->processContacts('commerce-customers', $this->collectCustomers());
    }

    public function actionCsv(): int
    {
        if (!$this->validateConsentMode()) {
            return ExitCode::USAGE;
        }

        if ($this->file === null || !is_readable($this->file)) {
            $this->stderr("--file must point to a readable CSV file.\n");

            return ExitCode::USAGE;
        }

        if ($this->mapping === null) {
            $this->stderr("--mapping is required, e.g. --mapping=\"E-Mail:email,First name:firstname\".\n");

            return ExitCode::USAGE;
        }

        try {
            $contacts = $this->collectCsv();
        } catch (Throwable $e) {
            $this->stderr('Error reading CSV: ' . $e->getMessage() . "\n");

            return ExitCode::DATAERR;
        }

        return $this->processContacts('csv:' . basename($this->file), $contacts);
    }

    private function validateConsentMode(): bool
    {
        $valid = [self::CONSENT_MODE_REQUIRE_CONSENT, self::CONSENT_MODE_DOI, self::CONSENT_MODE_ACTIVATE];

        if (!in_array($this->consentMode, $valid, true)) {
            $this->stderr('--consentMode must be one of ' . implode('|', $valid) . ".\n");

            return false;
        }

        if ($this->consentMode === self::CONSENT_MODE_ACTIVATE && !$this->acceptResponsibility) {
            $this->stderr(
                "--consentMode=activate additionally requires --acceptResponsibility=1 as a deliberate confirmation\n" .
                "that a legal basis exists outside the plugin (no automatic verification).\n"
            );

            return false;
        }

        return true;
    }

    /**
     * @return iterable<array{email: string, attributes: array<string, mixed>, hasExistingConsent: bool, userId: int|null}>
     */
    private function collectUsers(): iterable
    {
        foreach (User::find()->each() as $user) {
            /** @var User $user */
            if ($user->email === null) {
                continue;
            }

            $fieldValues = $user->getFieldValues();

            yield [
                'email' => $user->email,
                'attributes' => Plugin::getInstance()->subscriber->mapAttributes($fieldValues),
                'hasExistingConsent' => $this->consentField !== null && (bool) ($fieldValues[$this->consentField] ?? false),
                'userId' => $user->id,
            ];
        }
    }

    /**
     * Distinct customer emails from completed Commerce orders. Attributes
     * are left empty for this source — Commerce order fields don't map
     * onto CleverReach attributes the way Craft User fields do via the
     * plugin's attributeMapping setting.
     *
     * @return iterable<array{email: string, attributes: array<string, mixed>, hasExistingConsent: bool, userId: int|null}>
     */
    private function collectCustomers(): iterable
    {
        $seen = [];

        foreach (\craft\commerce\elements\Order::find()->isCompleted(true)->each() as $order) {
            /** @var \craft\commerce\elements\Order $order */
            $email = $order->getEmail();

            if ($email === null || isset($seen[strtolower($email)])) {
                continue;
            }

            $seen[strtolower($email)] = true;
            $customer = $order->getCustomer();

            $hasExistingConsent = false;
            if ($this->consentField !== null && $customer !== null) {
                $hasExistingConsent = (bool) ($customer->getFieldValue($this->consentField) ?? false);
            }

            yield [
                'email' => $email,
                'attributes' => [],
                'hasExistingConsent' => $hasExistingConsent,
                'userId' => $customer?->id,
            ];
        }
    }

    /**
     * @return array<int, array{email: string, attributes: array<string, mixed>, hasExistingConsent: bool, userId: int|null}>
     */
    private function collectCsv(): array
    {
        $columnMap = CsvMappingParser::parse((string) $this->mapping);

        $handle = fopen((string) $this->file, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Could not open file: {$this->file}");
        }

        try {
            $header = $this->hasHeader ? fgetcsv($handle) : null;

            if ($this->hasHeader && $header === false) {
                throw new RuntimeException('CSV file is empty.');
            }

            $contacts = [];

            while (($row = fgetcsv($handle)) !== false) {
                if ($header !== null) {
                    $normalizedHeader = array_map(static fn($column) => (string)($column ?? ''), $header);
                    if (count($normalizedHeader) !== count($row)) {
                        continue;
                    }
                    $rowByColumn = array_combine($normalizedHeader, $row);
                } else {
                    $rowByColumn = $row;
                }

                $email = null;
                $attributes = [];
                $hasExistingConsent = false;

                foreach ($columnMap as $column => $target) {
                    $value = $rowByColumn[$column] ?? null;

                    if ($target === 'email') {
                        $email = $value !== null ? trim((string) $value) : null;
                    } elseif ($target === 'consent') {
                        $hasExistingConsent = CsvMappingParser::parseConsentValue($value);
                    } else {
                        $attributes[$target] = $value;
                    }
                }

                if ($email === null || $email === '') {
                    continue;
                }

                $contacts[] = [
                    'email' => $email,
                    'attributes' => $attributes,
                    'hasExistingConsent' => $hasExistingConsent,
                    'userId' => null,
                ];
            }

            return $contacts;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param iterable<array{email: string, attributes: array<string, mixed>, hasExistingConsent: bool, userId: int|null}> $contacts
     */
    private function processContacts(string $sourceLabel, iterable $contacts): int
    {
        $dryRun = !$this->confirm;
        $imported = 0;
        $skipped = 0;
        $failed = [];

        if ($dryRun) {
            $this->stdout("Dry run (no --confirm) — nothing will be written, counts only.\n");
        }

        foreach ($contacts as $contact) {
            $email = $contact['email'];

            if ($this->consentMode === self::CONSENT_MODE_REQUIRE_CONSENT && !$contact['hasExistingConsent']) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $imported++;

                continue;
            }

            try {
                $source = sprintf('import:%s:%s', $sourceLabel, $this->consentMode);

                if ($this->consentMode === self::CONSENT_MODE_DOI) {
                    Plugin::getInstance()->subscriber->subscribeWithAttributes(
                        $email,
                        $contact['attributes'],
                        $source,
                        'console-import',
                        $this->groupId,
                        null,
                        $contact['userId']
                    );
                } else {
                    Plugin::getInstance()->subscriber->activateWithAttributes(
                        $email,
                        $contact['attributes'],
                        $source,
                        'console-import',
                        $this->groupId,
                        null,
                        $contact['userId']
                    );
                }

                $imported++;
            } catch (Throwable $e) {
                $failed[$email] = $e->getMessage();
            }
        }

        $this->stdout(sprintf(
            "%s: %d (%s), %d skipped (no consent), %d failed.\n",
            $dryRun ? 'Would import' : 'Imported',
            $imported,
            $this->consentMode === self::CONSENT_MODE_DOI ? 'DOI mail triggered' : 'activated directly',
            $skipped,
            count($failed)
        ));

        foreach ($failed as $failedEmail => $message) {
            $this->stderr("  Error for {$failedEmail}: {$message}\n");
        }

        if ($dryRun && $imported > 0) {
            $this->stdout("Re-run with --confirm to execute.\n");
        }

        return $failed === [] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
