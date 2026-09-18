<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Order lifecycle states and the transitions allowed between them.
 *
 * This enum is the single source of truth for the order workflow: validation
 * rules, the state machine, and stock side effects all derive from it.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Return every status value for validation rules and documentation.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Return the statuses a client may request through the status endpoint.
     *
     * Cancellation is deliberately excluded: it releases reserved stock and is
     * therefore only reachable through the dedicated cancel endpoint.
     *
     * @return array<int, string>
     */
    public static function clientTransitionableValues(): array
    {
        return array_values(array_diff(self::values(), [self::Cancelled->value]));
    }

    /**
     * Return the statuses this status may move to.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    /**
     * Determine whether this status may transition into the given status.
     */
    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    /**
     * Determine whether the order has reached a terminal state.
     */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Determine whether stock is still reserved while in this status.
     */
    public function holdsReservation(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed], true);
    }
}
