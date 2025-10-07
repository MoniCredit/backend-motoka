<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New Order Assigned - Motoka</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: #3B82F6;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 8px 8px 0 0;
        }
        .content {
            background: #f8f9fa;
            padding: 30px;
            border-radius: 0 0 8px 8px;
        }
        .order-details {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #3B82F6;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            margin: 10px 0;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .detail-label {
            font-weight: bold;
            color: #666;
        }
        .detail-value {
            color: #333;
        }
        .whatsapp-section {
            background: #25D366;
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            text-align: center;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            color: #666;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🚗 {{ $payment_details ? 'Order Assigned with Payment Receipt' : 'New Order Assigned' }} - Motoka</h1>
        <p>{{ $payment_details ? 'You have been assigned an order with payment confirmation' : 'You have been assigned a new car registration order' }}</p>
    </div>

    <div class="content">
        <h2>Order Details</h2>
        
        <div class="order-details">
            <div class="detail-row">
                <span class="detail-label">Order ID:</span>
                <span class="detail-value">{{ $order->slug }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Customer Name:</span>
                <span class="detail-value">{{ $order->user->name }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Vehicle:</span>
                <span class="detail-value">{{ $order->car->vehicle_make }} {{ $order->car->vehicle_model }}</span>
            </div>
            @if($payment_details)
            <div class="detail-row">
                <span class="detail-label">Amount Paid to You:</span>
                <span class="detail-value" style="color: #28a745; font-weight: bold;">₦{{ number_format($payment_details['amount'], 2) }}</span>
            </div>
            @endif
            <div class="detail-row">
                <span class="detail-label">Delivery Address:</span>
                <span class="detail-value">{{ $order->delivery_address }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Contact Number:</span>
                <span class="detail-value">{{ $order->delivery_contact }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">State:</span>
                <span class="detail-value">{{ $stateName ?? 'Unknown State' }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">LGA:</span>
                <span class="detail-value">{{ $lgaName ?? 'Unknown LGA' }}</span>
            </div>
            @if($order->notes)
            <div class="detail-row">
                <span class="detail-label">Notes:</span>
                <span class="detail-value">{{ $order->notes }}</span>
            </div>
            @endif
        </div>

        @if($payment_details)
        <h2>💰 Payment Receipt</h2>
        <div class="order-details" style="border-left-color: #28a745;">
            <div class="detail-row">
                <span class="detail-label">Transfer Reference:</span>
                <span class="detail-value" style="font-family: monospace;">{{ $payment_details['transfer_reference'] }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Amount Paid:</span>
                <span class="detail-value" style="color: #28a745; font-weight: bold;">₦{{ number_format($payment_details['amount'], 2) }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Status:</span>
                <span class="detail-value" style="color: #28a745; font-weight: bold;">{{ $payment_details['status'] ?? 'Completed' }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Paid At:</span>
                <span class="detail-value">{{ now()->format('Y-m-d H:i:s') }}</span>
            </div>
        </div>
        @endif

        <div class="whatsapp-section">
            <h3>📱 WhatsApp Message</h3>
            <p>Copy this message to send via WhatsApp:</p>
            <textarea readonly style="width: 100%; height: 120px; border: none; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 12px;">{{ $whatsapp_message }}</textarea>
        </div>

        <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 8px; margin: 20px 0;">
            <h4 style="margin: 0 0 10px 0; color: #856404;">⚠️ Important Instructions</h4>
            <ul style="margin: 0; padding-left: 20px; color: #856404;">
                <li>Process the order according to the service type</li>
                <li>Verify the delivery address and contact details</li>
                <li>Update the order status once work begins</li>
                <li>Return completed documents to admin when finished</li>
                @if($payment_details)
                <li style="color: #28a745; font-weight: bold;">✅ Payment confirmed - proceed with confidence!</li>
                @else
                <li>Payment details will be sent once payment is processed</li>
                @endif
            </ul>
        </div>
    </div>

    <div class="footer">
        <p>This is an automated notification from Motoka Admin System</p>
        <p>Please do not reply to this email</p>
    </div>
</body>
</html>
