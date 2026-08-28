<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\services;

use Craft;
use craft\base\Component;
use kernpfad\cleverreach\events\BeforeApplyTagsEvent;
use kernpfad\cleverreach\Plugin;
use kernpfad\cleverreach\util\TagListParser;
use Throwable;

/**
 * Applies CleverReach receiver tags for automations (CR-10).
 *
 * Never creates a receiver from tags alone — consent + group required.
 * Failures are logged and do not abort the caller (subscribe / order job).
 */
class TagService extends Component
{
    public const CONTEXT_ORDER_COMPLETE = 'orderComplete';
    public const CONTEXT_SUBSCRIBE = 'subscribe';
    public const CONTEXT_USER_SYNC = 'userSync';

    /**
     * @param list<string>|array<int|string, mixed> $tags
     */
    public function apply(string $email, array $tags, string $context, ?int $groupId = null): void
    {
        $tags = TagListParser::normalize($tags);
        if ($tags === [] || $email === '') {
            return;
        }

        $consent = Plugin::getInstance()->consent->getLatestConsent(null, $email);
        if ($consent === null || $consent->unsubscribedAt !== null) {
            return;
        }

        $groupId ??= $consent->groupId ?? Plugin::getInstance()->getSettings()->defaultGroupId;
        if ($groupId === null) {
            return;
        }

        $plugin = Plugin::getInstance();
        if ($plugin->hasEventHandlers(Plugin::EVENT_BEFORE_APPLY_TAGS)) {
            $event = new BeforeApplyTagsEvent($email, $tags, $context, (int) $groupId);
            $plugin->trigger(Plugin::EVENT_BEFORE_APPLY_TAGS, $event);
            if (!$event->isValid) {
                return;
            }
            $tags = TagListParser::normalize($event->tags);
            if ($tags === []) {
                return;
            }
            $groupId = $event->groupId ?? $groupId;
        }

        try {
            $plugin->cleverReachApi->addTags((int) $groupId, $email, $tags);
        } catch (Throwable $e) {
            Craft::error('CleverReach tag apply failed: ' . $e->getMessage(), __METHOD__);
        }
    }

    public function applyFromSettings(string $context, string $email, ?int $groupId = null): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $raw = match ($context) {
            self::CONTEXT_ORDER_COMPLETE => $settings->orderCompleteTags,
            self::CONTEXT_SUBSCRIBE => $settings->subscribeTags,
            self::CONTEXT_USER_SYNC => $settings->userSyncTags,
            default => '',
        };

        $this->apply($email, TagListParser::parse($raw), $context, $groupId);
    }
}
