<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $firstPayment = $this->whenLoaded('payments', fn () => $this->payments->first());

        return [
            'id' => $this->id,
            'type' => $this->type,
            'period_from' => $this->period_from?->toDateString(),
            'period_to' => $this->period_to?->toDateString(),
            'days_billed' => $this->days_billed,
            'base_amount' => $this->base_amount,
            'discount_percent' => $this->discount_percent,
            'discount_amount' => $this->discount_amount,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'subscription' => $this->whenLoaded('subscription', fn () => $this->subscription ? [
                'plan' => $this->subscription->plan ? [
                    'name' => $this->subscription->plan->name,
                ] : null,
            ] : null),
            'lines' => $this->whenLoaded('lines'),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p) => [
                'amount' => $p->amount,
                'currency' => $p->currency ?? $this->currency,
                'payment_date' => $p->payment_date?->toDateString(),
                'payment_reference' => $p->payment_reference,
                'payment_method' => $p->payment_method,
            ])),
        ];
    }
}
