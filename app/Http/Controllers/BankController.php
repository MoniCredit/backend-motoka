<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BankController extends Controller
{
    /**
     * Get list of banks from Paystack
     */
    public function getBanks()
    {
        try {
            $secretKey = env('PAYSTACK_SECRET_KEY');
            
            if (empty($secretKey)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Paystack configuration not found'
                ], 500);
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $secretKey,
                'Content-Type' => 'application/json'
            ])->get('https://api.paystack.co/bank');

            if ($response->successful()) {
                $data = $response->json();
                
                return response()->json([
                    'status' => true,
                    'message' => 'Banks retrieved successfully',
                    'data' => $data['data'] ?? []
                ]);
            } else {
                Log::error('Paystack bank list API failed', [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to retrieve banks from Paystack'
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Bank list error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while retrieving banks'
            ], 500);
        }
    }

    /**
     * Verify bank account number with Paystack
     */
    public function verifyAccount(Request $request)
    {
        $request->validate([
            'account_number' => 'required|string|max:20',
            'bank_code' => 'required|string|max:10'
        ]);

        try {
            $secretKey = env('PAYSTACK_SECRET_KEY');
            
            if (empty($secretKey)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Paystack configuration not found'
                ], 500);
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $secretKey,
                'Content-Type' => 'application/json'
            ])->get('https://api.paystack.co/bank/resolve', [
                'account_number' => $request->account_number,
                'bank_code' => $request->bank_code
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if ($data['status']) {
                    return response()->json([
                        'status' => true,
                        'message' => 'Account verified successfully',
                        'data' => [
                            'account_number' => $data['data']['account_number'],
                            'account_name' => $data['data']['account_name'],
                            'bank_code' => $request->bank_code
                        ]
                    ]);
                } else {
                    return response()->json([
                        'status' => false,
                        'message' => 'Account verification failed'
                    ], 400);
                }
            } else {
                $errorData = $response->json();
                $errorMessage = $errorData['message'] ?? 'Account verification failed';
                
                Log::error('Paystack account verification failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'account_number' => $request->account_number,
                    'bank_code' => $request->bank_code
                ]);
                
                return response()->json([
                    'status' => false,
                    'message' => $errorMessage
                ], 400);
            }
        } catch (\Exception $e) {
            Log::error('Account verification error: ' . $e->getMessage(), [
                'account_number' => $request->account_number,
                'bank_code' => $request->bank_code,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while verifying account'
            ], 500);
        }
    }
}
