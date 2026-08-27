<?php

declare(strict_types=1);

namespace kernpfad\cleverreach\tests\integration\fakes;

use kernpfad\cleverreach\services\CleverReachApiService;

/**
 * Drop-in replacement for {@see CleverReachApiService}, swapped onto the
 * plugin via `Plugin::getInstance()->set('cleverReachApi', ...)` so
 * integration tests never make a real network call. Records every
 * upsert-style call so tests can assert on what UserSyncService actually
 * sent, and lets a test control the receiver state
 * {@see \kernpfad\cleverreach\services\UserSyncService} sees back (CR-06
 * soft-sync decision).
 */
class FakeCleverReachApiService extends CleverReachApiService
{
    /** @var array<string, mixed>|null Returned by getReceiver(); null means "no such receiver". */
    public ?array $receiverToReturn = null;

    /** @var list<array{method: string, groupId: int, email: string, attributes: array<string, mixed>}> */
    public array $calls = [];

    public function getReceiver(int $groupId, string $email): ?array
    {
        return $this->receiverToReturn;
    }

    public function activateReceiver(int $groupId, string $email, array $attributes = []): array
    {
        $this->calls[] = ['method' => 'activateReceiver', 'groupId' => $groupId, 'email' => $email, 'attributes' => $attributes];

        return ['email' => $email, 'activated' => true];
    }

    public function updateReceiverAttributes(int $groupId, string $email, array $attributes = []): array
    {
        $this->calls[] = ['method' => 'updateReceiverAttributes', 'groupId' => $groupId, 'email' => $email, 'attributes' => $attributes];

        return ['email' => $email, 'activated' => false];
    }
}
