<?php

namespace App\Domain\Billing\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Thrown when an organization has used every invoice its plan allows for the
 * current billing period.
 *
 * It renders the stable 402 payload the frontend branches on:
 *
 *   { "error": { "code": "plan_limit_reached", "message": ..., "limit": N,
 *                "used": N, "plan": "free" } }
 *
 * The nested "error" object is the agreed shape; the client also tolerates the
 * flat form for backwards compatibility.
 */
class PlanLimitReachedException extends Exception
{
    public function __construct(
        public readonly string $plan,
        public readonly int $limit,
        public readonly int $used,
    ) {
        parent::__construct(sprintf(
            'You have used all %d invoices included in the %s plan this month.',
            $limit,
            $plan,
        ));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'plan_limit_reached',
                'message' => $this->getMessage(),
                'limit' => $this->limit,
                'used' => $this->used,
                'plan' => $this->plan,
            ],
        ], 402);
    }
}
