<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\PaystackTransferService;

class PaystackWebhookController extends Controller
{
    /**
     * Handle Paystack transfer webhooks
     */
    public function handleTransferWebhook(Request $request)
    {
        try {
            // Verify webhook signature (implement based on Paystack documentation)
            $signature = $request->header('X-Paystack-Signature');
            $payload = $request->getContent();
            
            // TODO: Implement signature verification
            // $this->verifyWebhookSignature($signature, $payload);

            $webhookData = $request->all();
            
            Log::info('Paystack transfer webhook received', [
                'event' => $webhookData['event'] ?? 'unknown',
                'data' => $webhookData['data'] ?? null
            ]);

            // Handle the webhook using the transfer service
            $result = PaystackTransferService::handleTransferWebhook($webhookData);
            
            if ($result) {
                return response()->json(['status' => 'success'], 200);
            } else {
                return response()->json(['status' => 'failed'], 400);
            }

        } catch (\Exception $e) {
            Log::error('Paystack transfer webhook error', [
                'error' => $e->getMessage(),
                'payload' => $request->all()
            ]);
            
            return response()->json(['status' => 'error'], 500);
        }
    }

    /**
     * Verify webhook signature (implement based on Paystack documentation)
     */
    private function verifyWebhookSignature($signature, $payload)
    {
        $secret = env('PAYSTACK_SECRET_KEY');
        $expectedSignature = hash_hmac('sha512', $payload, $secret);
        
        if (!hash_equals($expectedSignature, $signature)) {
            throw new \Exception('Invalid webhook signature');
        }
    }
}