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
        <h1>🚗 New Order Assigned - Motoka</h1>
        <p>You have been assigned a new car registration order</p>
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
            <div class="detail-row">
                <span class="detail-label">Amount:</span>
                <span class="detail-value">₦{{ number_format($order->amount, 2) }}</span>
            </div>
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
                <span class="detail-value">{{ $order->state }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">LGA:</span>
                <span class="detail-value">{{ $order->lga }}</span>
            </div>
            @if($order->notes)
            <div class="detail-row">
                <span class="detail-label">Notes:</span>
                <span class="detail-value">{{ $order->notes }}</span>
            </div>
            @endif
        </div>

        <div class="whatsapp-section">
            <h3>📱 WhatsApp Message</h3>
            <p>Copy this message to send via WhatsApp:</p>
            <textarea readonly style="width: 100%; height: 120px; border: none; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 12px;">{{ $whatsapp_message }}</textarea>
        </div>

        <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 8px; margin: 20px 0;">
            <h4 style="margin: 0 0 10px 0; color: #856404;">⚠️ Important Instructions</h4>
            <ul style="margin: 0; padding-left: 20px; color: #856404;">
                <li>Contact the customer within 24 hours</li>
                <li>Verify the delivery address and contact details</li>
                <li>Update the order status once work begins</li>
                <li>Mark as completed when the task is finished</li>
            </ul>
        </div>
    </div>

    <div class="footer">
        <p>This is an automated notification from Motoka Admin System</p>
        <p>Please do not reply to this email</p>
    </div>
</body>
</html>
