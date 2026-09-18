<x-mail::message>
# Order {{ $orderNumber }}

Your order is now **{{ $status }}**.

Order total: {{ $total }}

Thanks for shopping with us.

{{ config('app.name') }}
</x-mail::message>
