<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Order;
use App\Models\AgentPayment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaystackTransferService
{
    /**
     * Create a transfer recipient for an agent
     */
    public static function createTransferRecipient(Agent $agent)
    {
        try {
            $secretKey = env('PAYSTACK_SECRET_KEY');
            
            if (empty($secretKey)) {
                throw new \Exception('Paystack secret key not configured');
            }

            // Check if agent already has a recipient code
            if (!empty($agent->recipient_code)) {
                return [
                    'success' => true,
                    'recipient_code' => $agent->recipient_code,
                    'message' => 'Recipient already exists'
                ];
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $secretKey,
                'Content-Type' => 'application/json'
            ])->post('https://api.paystack.co/transferrecipient', [
                'type' => 'nuban',
                'name' => $agent->account_name ?: $agent->first_name . ' ' . $agent->last_name,
                'account_number' => $agent->account_number,
                'bank_code' => $agent->bank_code,
                'currency' => 'NGN'
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if ($data['status']) {
                    $recipientCode = $data['data']['recipient_code'];
                    
                    // Save recipient code to agent
                    $agent->update(['recipient_code' => $recipientCode]);
                    
                    Log::info('Transfer recipient created successfully', [
                        'agent_id' => $agent->id,
                        'recipient_code' => $recipientCode
                    ]);
                    
                    return [
                        'success' => true,
                        'recipient_code' => $recipientCode,
                        'message' => 'Transfer recipient created successfully'
                    ];
                } else {
                    throw new \Exception('Failed to create transfer recipient: ' . ($data['message'] ?? 'Unknown error'));
                }
            } else {
                $errorData = $response->json();
                throw new \Exception('Paystack API error: ' . ($errorData['message'] ?? 'Unknown error'));
            }
        } catch (\Exception $e) {
            Log::error('Failed to create transfer recipient', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Initiate transfer to agent
     */
    public static function initiateTransfer(Order $order, Agent $agent, $amount, $commissionRate = 10)
    {
        try {
            $secretKey = env('PAYSTACK_SECRET_KEY');
            
            if (empty($secretKey)) {
                throw new \Exception('Paystack secret key not configured');
            }

            // Calculate commission amount
            $commissionAmount = ($amount * $commissionRate) / 100;
            $transferAmount = $amount - $commissionAmount;

            // Create or get transfer recipient
            $recipientResult = self::createTransferRecipient($agent);
            if (!$recipientResult['success']) {
                throw new \Exception('Failed to create transfer recipient: ' . $recipientResult['message']);
            }

            $recipientCode = $recipientResult['recipient_code'];

            // Generate unique transfer reference
            $transferReference = 'TRF_' . $order->slug . '_' . time();

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $secretKey,
                'Content-Type' => 'application/json'
            ])->post('https://api.paystack.co/transfer', [
                'source' => 'balance',
                'amount' => $transferAmount * 100, // Convert to kobo
                'recipient' => $recipientCode,
                'reason' => "Payment for Order #{$order->slug} - {$order->order_type}",
                'reference' => $transferReference
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if ($data['status']) {
                    $transferData = $data['data'];
                    
                    // Create agent payment record
                    $agentPayment = AgentPayment::create([
                        'agent_id' => $agent->id,
                        'order_id' => $order->id,
                        'amount' => $transferAmount,
                        'commission_rate' => $commissionRate,
                        'commission_amount' => $commissionAmount,
                        'status' => 'pending',
                        'transfer_reference' => $transferReference,
                        'paystack_transfer_id' => $transferData['id'] ?? null,
                        'notes' => "Payment for Order #{$order->slug}"
                    ]);

                    Log::info('Transfer initiated successfully', [
                        'order_id' => $order->id,
                        'agent_id' => $agent->id,
                        'transfer_reference' => $transferReference,
                        'amount' => $transferAmount,
                        'agent_payment_id' => $agentPayment->id
                    ]);
                    
                    return [
                        'success' => true,
                        'transfer_reference' => $transferReference,
                        'amount' => $transferAmount,
                        'commission_amount' => $commissionAmount,
                        'agent_payment_id' => $agentPayment->id,
                        'message' => 'Transfer initiated successfully'
                    ];
                } else {
                    throw new \Exception('Failed to initiate transfer: ' . ($data['message'] ?? 'Unknown error'));
                }
            } else {
                $errorData = $response->json();
                $errorMessage = $errorData['message'] ?? 'Unknown error';
                
                // Check if it's a starter business limitation
                if (strpos($errorMessage, 'starter business') !== false || 
                    strpos($errorMessage, 'third party payouts') !== false) {
                    
                    // Create agent payment record with manual status
                    $agentPayment = AgentPayment::create([
                        'agent_id' => $agent->id,
                        'order_id' => $order->id,
                        'amount' => $transferAmount,
                        'commission_rate' => $commissionRate,
                        'commission_amount' => $commissionAmount,
                        'status' => 'manual_payment_required',
                        'transfer_reference' => $transferReference,
                        'paystack_transfer_id' => null,
                        'notes' => "Manual payment required - Paystack starter account limitation. Order #{$order->slug}"
                    ]);

                    Log::info('Manual payment required due to Paystack limitation', [
                        'order_id' => $order->id,
                        'agent_id' => $agent->id,
                        'transfer_reference' => $transferReference,
                        'amount' => $transferAmount,
                        'agent_payment_id' => $agentPayment->id,
                        'error' => $errorMessage
                    ]);
                    
                    return [
                        'success' => false,
                        'message' => 'Manual payment required due to Paystack account limitations. Please process payment manually.',
                        'manual_payment_required' => true,
                        'transfer_reference' => $transferReference,
                        'amount' => $transferAmount,
                        'commission_amount' => $commissionAmount,
                        'agent_payment_id' => $agentPayment->id,
                        'agent_details' => [
                            'name' => $agent->first_name . ' ' . $agent->last_name,
                            'phone' => $agent->phone,
                            'bank_name' => $agent->bank_name,
                            'account_number' => $agent->account_number,
                            'account_name' => $agent->account_name
                        ]
                    ];
                }
                
                throw new \Exception('Paystack API error: ' . $errorMessage);
            }
        } catch (\Exception $e) {
            Log::error('Failed to initiate transfer', [
                'order_id' => $order->id,
                'agent_id' => $agent->id,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Verify transfer status
     */
    public static function verifyTransfer($transferReference)
    {
        try {
            $secretKey = env('PAYSTACK_SECRET_KEY');
            
            if (empty($secretKey)) {
                throw new \Exception('Paystack secret key not configured');
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $secretKey,
                'Content-Type' => 'application/json'
            ])->get("https://api.paystack.co/transfer/verify/{$transferReference}");

            if ($response->successful()) {
                $data = $response->json();
                
                if ($data['status']) {
                    return [
                        'success' => true,
                        'data' => $data['data']
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => $data['message'] ?? 'Transfer verification failed'
                    ];
                }
            } else {
                $errorData = $response->json();
                return [
                    'success' => false,
                    'message' => $errorData['message'] ?? 'API error'
                ];
            }
        } catch (\Exception $e) {
            Log::error('Failed to verify transfer', [
                'transfer_reference' => $transferReference,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Handle transfer webhook
     */
    public static function handleTransferWebhook($webhookData)
    {
        try {
            $event = $webhookData['event'] ?? null;
            $transferData = $webhookData['data'] ?? null;

            if (!$event || !$transferData) {
                Log::warning('Invalid webhook data received', ['data' => $webhookData]);
                return false;
            }

            $transferReference = $transferData['reference'] ?? null;
            if (!$transferReference) {
                Log::warning('No transfer reference in webhook data', ['data' => $transferData]);
                return false;
            }

            // Find agent payment by transfer reference
            $agentPayment = AgentPayment::where('transfer_reference', $transferReference)->first();
            if (!$agentPayment) {
                Log::warning('Agent payment not found for transfer reference', [
                    'transfer_reference' => $transferReference
                ]);
                return false;
            }

            // Update agent payment status based on event
            switch ($event) {
                case 'transfer.success':
                    $agentPayment->update([
                        'status' => 'completed',
                        'paid_at' => now()
                    ]);
                    
                    // Send payment receipt to agent with complete order details
                    $order = $agentPayment->order;
                    $agent = $agentPayment->agent;
                    $paymentDetails = [
                        'transfer_reference' => $agentPayment->transfer_reference,
                        'amount' => $agentPayment->amount,
                        'commission_amount' => $agentPayment->commission_amount,
                        'status' => 'Completed'
                    ];
                    
                    // Use AdminController's notifyAgent method for consistent messaging
                    $adminController = new \App\Http\Controllers\AdminController();
                    $adminController->notifyAgent($order, $agent, $paymentDetails);
                    
                    Log::info('Transfer completed successfully', [
                        'agent_payment_id' => $agentPayment->id,
                        'transfer_reference' => $transferReference
                    ]);
                    break;

                case 'transfer.failed':
                    $agentPayment->update(['status' => 'failed']);
                    
                    Log::info('Transfer failed', [
                        'agent_payment_id' => $agentPayment->id,
                        'transfer_reference' => $transferReference
                    ]);
                    break;

                case 'transfer.reversed':
                    $agentPayment->update(['status' => 'reversed']);
                    
                    Log::info('Transfer reversed', [
                        'agent_payment_id' => $agentPayment->id,
                        'transfer_reference' => $transferReference
                    ]);
                    break;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to handle transfer webhook', [
                'webhook_data' => $webhookData,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Send payment receipt to agent
     */
    private static function sendPaymentReceipt(AgentPayment $agentPayment)
    {
        try {
            $agent = $agentPayment->agent;
            $order = $agentPayment->order;

            // Send email receipt
            $emailData = [
                'agent_name' => $agent->first_name . ' ' . $agent->last_name,
                'order_id' => $order->slug,
                'order_type' => $order->order_type,
                'amount' => $agentPayment->amount,
                'commission_amount' => $agentPayment->commission_amount,
                'transfer_reference' => $agentPayment->transfer_reference,
                'paid_at' => $agentPayment->paid_at->format('Y-m-d H:i:s'),
                'customer_name' => $order->user->firstName . ' ' . $order->user->lastName,
                'customer_phone' => $order->user->phone,
                'delivery_address' => $order->delivery_address
            ];

            // TODO: Implement email sending
            Log::info('Payment receipt data prepared', [
                'agent_payment_id' => $agentPayment->id,
                'email_data' => $emailData
            ]);

            // Send WhatsApp notification
            $whatsappMessage = "🎉 *Payment Received!*\n\n";
            $whatsappMessage .= "Order ID: #{$order->slug}\n";
            $whatsappMessage .= "Service: " . ucwords(str_replace('_', ' ', $order->order_type)) . "\n";
            $whatsappMessage .= "Amount: ₦" . number_format($agentPayment->amount, 2) . "\n";
            $whatsappMessage .= "Commission: ₦" . number_format($agentPayment->commission_amount, 2) . "\n";
            $whatsappMessage .= "Transfer Ref: {$agentPayment->transfer_reference}\n";
            $whatsappMessage .= "Paid At: {$agentPayment->paid_at->format('Y-m-d H:i:s')}\n\n";
            $whatsappMessage .= "Customer Details:\n";
            $whatsappMessage .= "Name: {$order->user->firstName} {$order->user->lastName}\n";
            $whatsappMessage .= "Phone: {$order->user->phone}\n";
            $whatsappMessage .= "Address: {$order->delivery_address}\n\n";
            $whatsappMessage .= "Please process this order and deliver to the customer.";

            // TODO: Implement WhatsApp sending
            Log::info('WhatsApp message prepared', [
                'agent_phone' => $agent->phone,
                'message' => $whatsappMessage
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send payment receipt', [
                'agent_payment_id' => $agentPayment->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
