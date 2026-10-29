<?php

namespace App\Attendance;

/**
 * @phpstan-type Profile array{id: int, username: string, name: string, department_id: ?int, position: ?string, is_active: bool}
 */
final readonly class AuthResult
{
    /** @param  Profile|null  $employee */
    public function __construct(
        public AuthOutcome $outcome,
        public ?array $employee = null,
        public ?int $retryAfter = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->outcome === AuthOutcome::Authenticated;
    }
}
