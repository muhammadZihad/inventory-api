<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notifies a customer that their order reached a new status.
 */
class OrderStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Bind the order and the status being announced.
     */
    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $status,
    ) {}

    /**
     * Build the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Order %s is now %s', $this->order->order_number, $this->status->value),
        );
    }

    /**
     * Build the message content.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.orders.status',
            with: [
                'orderNumber' => $this->order->order_number,
                'status' => $this->status->value,
                'total' => Money::centsToDollars($this->order->total_amount),
            ],
        );
    }
}
