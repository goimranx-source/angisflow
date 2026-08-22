<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\Models\Integration;

interface PushesRecords
{
    /** @return array{ok: bool, external_id: string|null, body: mixed, message: string|null} */
    public function create(Integration $integration, string $entity, array $payload): array;

    /** @return array{ok: bool, body: mixed, message: string|null} */
    public function update(Integration $integration, string $entity, string $externalId, array $payload): array;
}
