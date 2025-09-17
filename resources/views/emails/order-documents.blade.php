<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your Order Documents - Motoka</title>
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
            background-color: #1e40af;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 8px 8px 0 0;
        }
        .content {
            background-color: #f8fafc;
            padding: 30px;
            border-radius: 0 0 8px 8px;
        }
        .order-info {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #1e40af;
        }
        .documents-list {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .document-item {
            padding: 10px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .document-item:last-child {
            border-bottom: none;
        }
        .document-name {
            font-weight: 600;
            color: #1e40af;
        }
        .document-status {
            background-color: #10b981;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
        .message {
            background-color: #eff6ff;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #3b82f6;
            margin-bottom: 20px;
        }
        .footer {
            text-align: center;
            color: #6b7280;
            font-size: 14px;
            margin-top: 30px;
        }
        .btn {
            display: inline-block;
            background-color: #1e40af;
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 6px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📄 Your Order Documents</h1>
        <p>Documents for your {{ str_replace('_', ' ', strtoupper($order->order_type)) }} order are ready!</p>
    </div>

    <div class="content">
        <div class="order-info">
            <h2>Order Information</h2>
            <p><strong>Order ID:</strong> {{ $order->slug }}</p>
            <p><strong>Order Type:</strong> {{ str_replace('_', ' ', strtoupper($order->order_type)) }}</p>
            <p><strong>Amount:</strong> ₦{{ number_format($order->amount, 2) }}</p>
            <p><strong>Status:</strong> {{ strtoupper($order->status) }}</p>
            <p><strong>Date:</strong> {{ $order->created_at->format('F j, Y \a\t g:i A') }}</p>
        </div>

        @if($adminMessage)
        <div class="message">
            <h3>Message from Admin:</h3>
            <p>{{ $adminMessage }}</p>
        </div>
        @endif

        <div class="documents-list">
            <h2>📎 Attached Documents ({{ $documents->count() }})</h2>
            @foreach($documents as $document)
            <div class="document-item">
                <span class="document-name">{{ $document->document_type }}</span>
                <span class="document-status">✓ Ready</span>
            </div>
            @endforeach
        </div>

        <div style="text-align: center;">
            <p>All documents are attached to this email. Please download and save them for your records.</p>
            <p>If you have any questions about these documents, please contact our support team.</p>
        </div>
    </div>

    <div class="footer">
        <p>Thank you for using Motoka!</p>
        <p>This is an automated message. Please do not reply to this email.</p>
    </div>
</body>
</html>
