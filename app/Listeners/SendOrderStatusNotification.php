<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Orders\OrderCancelled;
use App\Events\Orders\OrderCreated;
use App\Events\Orders\OrderStatusChanged;
use App\Mail\OrderStatusMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails the customer whenever their order reaches a new state.
 *
 * Queued so that mail transport latency and failures never block, slow, or
 * fail the API request that triggered the transition.
 */
class SendOrderStatusNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /** Number of attempts before the job is marked failed. */
    public int $tries = 3;

    /** Seconds to wait between attempts. */
    public int $backoff = 10;

    /**
     * Send the notification for whichever order event was fired.
     */
    public function handle(OrderCreated|OrderStatusChanged|OrderCancelled $event): void
    {
        $order = $event->order->loadMissing('customer');
        // The status cast on the model already yields an OrderStatus, so the
        // transition event is only needed for its explicit target state.
        $status = $event instanceof OrderStatusChanged ? $event->to : $order->status;

        if (! $order->customer?->email) {
            Log::warning('Order status notification skipped: customer has no email.', [
                'order_id' => $order->id,
            ]);

            return;
        }

        Mail::to($order->customer->email)->send(new OrderStatusMail($order, $status));
    }

    /**
     * Log a permanently failed notification instead of swallowing it.
     */
    public function failed(OrderCreated|OrderStatusChanged|OrderCancelled $event, Throwable $exception): void
    {
        Log::error('Order status notification failed.', [
            'order_id' => $event->order->id,
            'exception' => $exception->getMessage(),
        ]);
    }
}
