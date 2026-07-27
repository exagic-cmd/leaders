<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\Charge;

class CustomerController extends Controller
{
    /**
     * Get all customers
     */
    public function index(Request $request)
    {
        // Only sync if explicitly requested or if database is empty to save performance
        if ($request->has('sync') || Customer::count() === 0) {
            $this->syncCustomersFromStripe();
        }

        // Use eager loading and select only needed fields for the list if index is large
        // For now, keep as is but optimized retrieval
        $customers = Customer::with(['user' => function($query) {
            $query->with('onboardings');
        }])->latest()->get();

        return response()->json(['customers' => $customers]);
    }

    /**
     * Sync customers from Stripe payments
     */
    private function syncCustomersFromStripe()
    {
        try {
            Stripe::setApiKey(config('services.stripe.secret'));

            // Fetch charges from Stripe
            $charges = Charge::all(['limit' => 100]);

            foreach ($charges->data as $charge) {
                if ($charge->status !== 'succeeded') continue;

                $email = strtolower($charge->receipt_email 
                    ?? $charge->billing_details->email 
                    ?? ($charge->customer->email ?? ''));

                if (!$email || $email === 'no email') continue;

                // Try to detect category
                $category = null;
                if ($charge->metadata) {
                    $category = $charge->metadata->product_id ?? $charge->metadata->category ?? null;
                }

                if (!$category && $charge->description) {
                    $desc = strtolower($charge->description);
                    $category = match (true) {
                        str_contains($desc, 'healthcare') => 'healthcare',
                        str_contains($desc, 'tech') => 'tech',
                        str_contains($desc, 'business') => 'business',
                        str_contains($desc, 'impact') => 'impact',
                        str_contains($desc, 'speaker') => 'speaker',
                        str_contains($desc, 'research') => 'research',
                        default => null,
                    };
                }

                if ($category) {
                    $category = str_replace('prod_', '', strtolower($category));
                }

                // Use updateOrCreate with optimized data mapping
                Customer::updateOrCreate(
                    ['email' => $email],
                    [
                        'name' => $charge->billing_details->name ?? ($charge->customer->name ?? null),
                        'phone' => $charge->billing_details->phone ?? ($charge->customer->phone ?? null),
                        'stripe_customer_id' => is_object($charge->customer) ? $charge->customer->id : $charge->customer,
                        'category' => $category,
                    ]
                );
            }
        } catch (\Exception $e) {
            \Log::error("Stripe Sync Error: {$e->getMessage()}");
        }
    }

    /**
     * Get a single customer by ID
     */
    public function show($id)
    {
        $customer = Customer::with('user.onboardings')->findOrFail($id);
        $payments = [];

        try {
            Stripe::setApiKey(config('services.stripe.secret'));
            
            $charges_data = [];

            // 1. Try fetching by Stripe Customer ID first (most reliable)
            if ($customer->stripe_customer_id) {
                $charges = Charge::all(['customer' => $customer->stripe_customer_id, 'limit' => 100]);
                $charges_data = $charges->data;
            } 
            
            // 2. If no ID or no charges found, try searching by email (for guest checkouts)
            if (empty($charges_data) && $customer->email) {
                // First try standard search (matches receipt_email)
                try {
                    $searchResults = Charge::search([
                        'query' => 'email:"' . $customer->email . '"',
                        'limit' => 100,
                    ]);
                    $charges_data = $searchResults->data;
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("Stripe search failed: " . $e->getMessage());
                }

                // 3. Fallback: Manual filtering of recent charges (if search misses billing_details.email)
                if (empty($charges_data)) {
                    try {
                        $recentCharges = Charge::all(['limit' => 100, 'expand' => ['data.customer']]); // Expand to check customer email too
                        foreach ($recentCharges->data as $c) {
                            $c_email = strtolower($c->receipt_email 
                                ?? $c->billing_details->email 
                                ?? ($c->customer->email ?? ''));
                            
                            if ($c_email === strtolower($customer->email)) {
                                $charges_data[] = $c;
                            }
                        }
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::error("Manual charge filtering failed: " . $e->getMessage());
                    }
                }
            }

            foreach ($charges_data as $charge) {
                $items = [];
                
                // Fetch line items from Checkout Session (exact logic from Payment)
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
                                    'id' => $charge->id . '_' . $li->id, // Composite ID for matching
                                    'description' => $li->description,
                                    'amount' => $li->amount_total / 100,
                                    'quantity' => $li->quantity
                                ];
                            }
                        }
                    } catch (\Exception $e) { /* Ignore */ }
                }

                // Fallback to description if no line items found
                if (empty($items) && $charge->description) {
                    $items[] = [
                        'id' => $charge->id,
                        'description' => $charge->description,
                        'amount' => $charge->amount / 100,
                        'quantity' => 1
                    ];
                }

                $payments[] = [
                    'id' => $charge->id,
                    'amount' => $charge->amount / 100,
                    'currency' => strtoupper($charge->currency),
                    'status' => $charge->status,
                    'date' => gmdate('Y-m-d\TH:i:s\Z', $charge->created),
                    'items' => $items,
                    'receipt_url' => $charge->receipt_url,
                ];
            }
        } catch (\Exception $e) {
            // Log error but don't fail the request
            \Illuminate\Support\Facades\Log::error("Failed to fetch customer payments: " . $e->getMessage());
        }

        return response()->json([
            'customer' => $customer,
            'purchases' => $payments
        ]);
    }

    /**
     * Update a customer
     */
    public function update(Request $request, $id)
    {
        $customer = Customer::findOrFail($id);
        
        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255|unique:customers,email,' . $customer->id,
            'phone' => 'nullable|string|max:50',
            'category' => 'nullable|string|in:healthcare,tech,business,impact,speaker,research',
            'status' => 'nullable|string|in:pending,active,completed',
        ]);

        $customer->update($data);

        // Sync with User account if it exists
        if ($customer->user_id) {
            $user = User::find($customer->user_id);
            if ($user) {
                $userUpdateData = [];
                if (isset($data['name'])) $userUpdateData['name'] = $data['name'];
                if (isset($data['email'])) $userUpdateData['email'] = $data['email'];
                if (isset($data['category'])) $userUpdateData['category'] = $data['category'];
                
                if (!empty($userUpdateData)) {
                    $user->update($userUpdateData);
                }
            }
        }

        return response()->json([
            'message' => 'Customer updated successfully',
            'customer' => $customer->load('user')
        ]);
    }

    /**
     * Create a user account for a customer
     */
    public function createAccount(Request $request, $id)
    {
        $customer = Customer::findOrFail($id);

        if ($customer->user_id) {
            $existingUser = User::find($customer->user_id);
            if (!$existingUser) {
                $customer->update(['user_id' => null, 'status' => 'pending']);
            } else {
                return response()->json(['message' => 'Customer already has an account'], 400);
            }
        }

        $plainPassword = \Illuminate\Support\Str::random(12);
        
        // Use updateOrCreate for cleaner logic
        // Note: Do NOT use Hash::make() here - the User model has a 'hashed' cast 
        // on the password field which auto-hashes it. Double-hashing breaks login.
        $user = User::updateOrCreate(
            ['email' => $customer->email],
            [
                'name' => $customer->name ?? 'User',
                'password' => $plainPassword,
                'role' => 'user',
                'category' => $customer->category ?? null,
            ]
        );

        // Assign Role
        if (method_exists($user, 'assignRole')) {
            $user->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']));
        }

        // Link Customer to User
        $customer->update([
            'user_id' => $user->id,
            'status' => 'active'
        ]);

        // Send credentials email
        \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\WelcomeCredentialsMail($user, $plainPassword));

        return response()->json([
            'message' => "Account created for {$user->email}. Credentials have been emailed.",
            'user' => $user,
        ]);
    }

    /**
     * Get dashboard statistics
     */
    public function getStats(Request $request)
    {
        $totalCustomers = Customer::count();
        $activeUsers = Customer::whereNotNull('user_id')->count();
        $recentCustomers = Customer::with(['user' => function($query) {
            $query->with('onboardings');
        }])->latest()->limit(5)->get();

        $totalRevenue = 0;
        $successPayments = 0;
        $stripeError = null;

        try {
            Stripe::setApiKey(config('services.stripe.secret'));
            
            // Sync if empty or if explicitly requested
            if ($totalCustomers === 0 || $request->has('sync')) {
                $this->syncCustomersFromStripe();
                $totalCustomers = Customer::count();
                $recentCustomers = Customer::with(['user' => function($query) {
                    $query->with('onboardings');
                }])->latest()->limit(5)->get();
            }

            // Fetch charges for calculations
            $charges = Charge::all(['limit' => 100]);
            
            foreach ($charges->data as $charge) {
                if ($charge->status === 'succeeded') {
                    $totalRevenue += ($charge->amount / 100);
                    $successPayments++;
                }
            }
        } catch (\Exception $e) {
            \Log::error("Dashboard Stats Stripe Error: " . $e->getMessage());
            $stripeError = config('app.debug') ? $e->getMessage() : 'Unable to connect to payment service';
        }
        
        return response()->json([
            'stats' => [
                'total_customers' => $totalCustomers,
                'active_users' => $activeUsers,
                'total_revenue' => round($totalRevenue, 2),
                'total_payments' => $successPayments,
                'currency' => 'USD',
                'recent_customers' => $recentCustomers,
                'stripe_error' => $stripeError
            ]
        ]);
    }
}
