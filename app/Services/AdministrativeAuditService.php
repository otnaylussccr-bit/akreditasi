<?php

namespace App\Services;

use App\Models\AdministrativeAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AdministrativeAuditService
{
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'token',
        'session',
        'payload',
        'hash',
        'secret',
    ];

    public function record(
        User $actor,
        string $event,
        Model $subject,
        ?string $subjectLabel = null,
        array $oldValues = [],
        array $newValues = [],
    ): AdministrativeAuditLog {
        return AdministrativeAuditLog::create([
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'event' => $event,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'subject_label' => $subjectLabel,
            'old_values' => $this->sanitize($oldValues) ?: null,
            'new_values' => $this->sanitize($newValues) ?: null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }

    private function sanitize(array $values): array
    {
        return collect($values)
            ->reject(function (mixed $value, string|int $key): bool {
                $normalizedKey = strtolower((string) $key);

                return collect(self::SENSITIVE_KEY_FRAGMENTS)
                    ->contains(fn (string $fragment): bool => str_contains($normalizedKey, $fragment));
            })
            ->map(fn (mixed $value): mixed => is_array($value) ? $this->sanitize($value) : $value)
            ->all();
    }
}
