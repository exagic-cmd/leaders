<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class WooCommerceController extends Controller
{
    /**
     * Fetch orders for the current user from WordPress.
     */
    public function getOrders()
    {
        $user = Auth::user();
        
        // Use config() instead of env() — env() returns null when config is cached
        $url = config('services.woocommerce.store_url') . '/wp-json/wc/v3/orders';
        $key = config('services.woocommerce.consumer_key');
        $secret = config('services.woocommerce.consumer_secret');

        if (!$key || !$secret) {
            return response()->json([
                'message' => 'WooCommerce is not configured',
            ], 503);
        }

        try {
            // Call the WooCommerce API
            $response = Http::withBasicAuth($key, $secret)
                ->get($url, [
                    'email' => $user->email, // Filter orders by the user's email
                    'status' => 'completed',  // Only show paid orders
                ]);

            if ($response->successful()) {
                return response()->json([
                    'orders' => $response->json(),
                ]);
            }

            Log::error('WooCommerce API error', ['status' => $response->status(), 'body' => $response->body()]);
            return response()->json([
                'message' => 'Failed to fetch orders',
            ], 500);

        } catch (\Exception $e) {
            Log::error('WooCommerce connection error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error connecting to order service',
            ], 500);
        }
    }
}

