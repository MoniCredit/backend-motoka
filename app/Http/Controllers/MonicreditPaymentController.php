<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\TransactionSplit;

class MonicreditPaymentController extends Controller
{
    public function initializePayment(Request $request)
    {
        $response = Http::post(env('MONICREDIT_BASE_URL') . '/payment/transactions/init-transaction', [
            'order_id' => $request->order_id,
            'public_key' => env('MONICREDIT_PUBLIC_KEY'),
            'customer' => [
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => $request->email,
                'phone' => $request->phone,
                'bvn' => $request->bvn,
                'nin' => $request->nin,
            ],
            'items' => $request->items,
            'currency' => 'NGN',
            'paytype' => 'standard',
        ]);

        return $response->json();
    }

    public function verifyPayment(Request $request)
    {
        $transactionId = $request->transaction_id;
        $privateKey = env('MONICREDIT_PRIVATE_KEY');

        $response = Http::post(env('MONICREDIT_BASE_URL') . '/payment/transactions/verify-transaction', [
            'transaction_id' => $transactionId,
            'private_key' => $privateKey,
        ]);

        $responseData = $response->json();

        // Check if the response contains the 'status' key
        if (isset($responseData['status']) && $responseData['status'] === 'success') {
            // Extract the transaction data
            $transactionData = $responseData['data'];
            
            // Check if the transaction already exists using composite key
            $existingTransaction = TransactionSplit::where('transaction_id', $transactionId)
                ->where('revenue_head_id', $transactionData['revenue_head_id'])
                ->where('settlement_bank_id', $transactionData['settlement_bank_id'])
                ->first();

            if (!$existingTransaction) {
                // Insert the new transaction split
                try {
                    TransactionSplit::create([
                        'transaction_id' => $transactionId,
                        'revenue_head_id' => $transactionData['revenue_head_id'],
                        'settlement_bank_id' => $transactionData['settlement_bank_id'],
                        'account_id' => $transactionData['account_id'],
                        'percent' => $transactionData['percent'],
                        'settlement_batch' => $transactionData['settlement_batch'],
                        // 'split_amount' => $transactionData['split_amount'],
                        'settlement' => $transactionData['settlement'],
                    ]);
                    
                    Log::info('Transaction split created successfully', [
                        'transaction_id' => $transactionId,
                    ]);
                    
                } catch (\Exception $e) {
                    Log::error('Failed to insert transaction split', [
                        'error' => $e->getMessage(),
                        'transaction_id' => $transactionId,
                        'transaction_data' => $transactionData,
                    ]);
                    return response()->json([
                        'message' => 'Failed to insert transaction split', 
                        'error' => $e->getMessage()
                    ], 500);
                }
            } else {
                // Update the existing record if needed
                try {
                    $existingTransaction->update([
                        'account_id' => $transactionData['account_id'],
                        'percent' => $transactionData['percent'],
                        'settlement_batch' => $transactionData['settlement_batch'],
                        // 'split_amount' => $transactionData['split_amount'],
                        'settlement' => $transactionData['settlement'],
                    ]);
                    
                    Log::info('Transaction split updated successfully', [
                        'transaction_id' => $transactionId,
                    ]);
                    
                } catch (\Exception $e) {
                    // Log::error('Failed to update transaction split', [
                    //     'error' => $e->getMessage(),
                    //     'transaction_id' => $transactionId,
                    // ]);
                    return response()->json([
                        'message' => 'Failed to update transaction split', 
                        'error' => $e->getMessage()
                    ], 500);
                }
            }
        } else {
            // Handle the case where the status is not 'success'
            // Log::error('Payment verification failed', [
            //     'response' => $responseData,
            //     'transaction_id' => $transactionId,
            // ]);
            return response()->json([
                // 'message' => 'Payment verification failed', 
                'data' => $responseData
            ], 200);
        }

        return response()->json([
            'message' => 'Payment verification successful',
            'data' => $responseData
        ], 200);
    }
}