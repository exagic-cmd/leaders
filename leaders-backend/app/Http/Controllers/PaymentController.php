<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\Charge;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        try {
            Stripe::setApiKey(config('services.stripe.secret'));

            $limit = $request->query('limit', 8);
            $startingAfter = $request->query('starting_after');
            $endingBefore = $request->query('ending_before');

            $params = [
                'limit' => $limit,
                'expand' => ['data.customer'],
            ];

            if ($startingAfter) {
                $params['starting_after'] = $startingAfter;
            }

            if ($endingBefore) {
                $params['ending_before'] = $endingBefore;
            }

            // Fetch recent Charges with customer expansion
            $charges = Charge::all($params);

            $formattedPayments = collect($charges->data)->map(function ($charge) {
                $billing = $charge->billing_details;
                $customer = $charge->customer;
                
                $name = $billing->name ?? ($customer->name ?? 'No name provided');
                $email = $charge->receipt_email ?? ($billing->email ?? ($customer->email ?? 'No email'));

                $items = [];
                
                // Fetch line items from Checkout Session if available
                if ($charge->payment_intent) {
                    try {
                        $sessions = \Stripe\Checkout\Session::all([
                            'payment_intent' => $charge->payment_intent,
                            'limit' => 1
                        ]);
                        
                        if ($session = ($sessions->data[0] ?? null)) {
                            $lineItems = \Stripe\Checkout\Session::allLineItems($session->id);
                            foreach ($lineItems->data as $li) {
                                $items[] = [
                                    'description' => $li->description,
                                    'amount' => $li->amount_total / 100,
                                    'quantity' => $li->quantity
                                ];
                            }
                        }
                    } catch (\Exception $e) { /* Ignore mapping errors */ }
                }

                if (empty($items) && $charge->description) {
                    $items[] = [
                        'description' => $charge->description,
                        'amount' => $charge->amount / 100,
                        'quantity' => 1
                    ];
                }

                $card = $charge->payment_method_details->card ?? $charge->payment_method_details->card_present ?? null;

                return [
                    'id' => $charge->id,
                    'amount' => $charge->amount / 100,
                    'currency' => strtoupper($charge->currency),
                    'status' => $charge->status,
                    'items' => $items,
                    'customer' => [
                        'name' => $name,
                        'email' => $email,
                        'phone' => $billing->phone ?? ($customer->phone ?? null),
                    ],
                    'billing_address' => [
                        'line1' => $billing->address->line1 ?? null,
                        'line2' => $billing->address->line2 ?? null,
                        'city' => $billing->address->city ?? null,
                        'state' => $billing->address->state ?? null,
                        'postal_code' => $billing->address->postal_code ?? null,
                        'country' => $billing->address->country ?? null,
                    ],
                    'date' => gmdate('Y-m-d\TH:i:s\Z', $charge->created),
                    'created_timestamp' => $charge->created,
                    'method' => $charge->payment_method_details->type ?? 'card',
                    'card_brand' => $card->brand ?? null,
                    'card_last4' => $card->last4 ?? null,
                    'card_exp_month' => $card->exp_month ?? null,
                    'card_exp_year' => $card->exp_year ?? null,
                    'card_funding' => $card->funding ?? null,
                    'card_country' => $card->country ?? null,
                    'receipt_url' => $charge->receipt_url,
                    'description' => $charge->description,
                    'metadata' => (array) $charge->metadata,
                    'payment_intent' => $charge->payment_intent,
                    'refunded' => $charge->refunded,
                    'amount_refunded' => $charge->amount_refunded / 100,
                    'dispute' => $charge->dispute,
                    'risk_level' => $charge->outcome->risk_level ?? null,
                    'risk_score' => $charge->outcome->risk_score ?? null,
                    'seller_message' => $charge->outcome->seller_message ?? null,
                ];
            });

            return response()->json([
                'status' => 'success',
                'data' => $formattedPayments,
                'has_more' => $charges->has_more,
                'first_id' => !empty($charges->data) ? $charges->data[0]->id : null,
                'last_id' => !empty($charges->data) ? end($charges->data)->id : null,
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Stripe payment fetch error: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'An error occurred while fetching payments.'
            ], 500);
        }
    }
}
