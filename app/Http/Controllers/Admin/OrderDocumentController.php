<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\OrderDocumentType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Mail\SendOrderDocuments;

class OrderDocumentController extends Controller
{
    /**
     * Get document types for a specific order type
     */
    public function getDocumentTypes(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'order_type' => 'required|string'
            ]);

            if ($validator->fails()) {
                Log::error('Document types validation failed', [
                    'errors' => $validator->errors(),
                    'request_data' => $request->all()
                ]);
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 400);
            }

            $orderType = $request->order_type;
            Log::info('Fetching document types for order_type', ['order_type' => $orderType]);

            $documentTypes = OrderDocumentType::forOrderType($orderType)->get();
            
            Log::info('Document types found', [
                'order_type' => $orderType,
                'count' => $documentTypes->count(),
                'types' => $documentTypes->toArray()
            ]);

            return response()->json([
                'status' => true,
                'data' => $documentTypes
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching document types', [
                'error' => $e->getMessage(),
                'order_type' => $request->order_type ?? 'unknown'
            ]);
            
            return response()->json([
                'status' => false,
                'message' => 'Error fetching document types',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload documents for an order (Admin only)
     */
    public function uploadDocuments(Request $request, $orderSlug)
    {
        try {
            $validator = Validator::make($request->all(), [
                'documents' => 'required|array|min:1|max:10', // Limit to 10 documents max
                'documents.*.document_type' => 'required|string|max:255',
                'documents.*.file' => 'required|file|mimes:pdf,jpg,jpeg,png,gif|max:10240', // 10MB max, added gif
            ], [
                'documents.required' => 'At least one document is required',
                'documents.array' => 'Documents must be an array',
                'documents.min' => 'At least one document is required',
                'documents.max' => 'Maximum 10 documents allowed',
                'documents.*.document_type.required' => 'Document type is required',
                'documents.*.document_type.string' => 'Document type must be a string',
                'documents.*.document_type.max' => 'Document type is too long',
                'documents.*.file.required' => 'File is required',
                'documents.*.file.file' => 'Must be a valid file',
                'documents.*.file.mimes' => 'File must be PDF, JPG, JPEG, PNG, or GIF',
                'documents.*.file.max' => 'File size must not exceed 10MB',
            ]);

            if ($validator->fails()) {
                Log::error('Document upload validation failed', [
                    'errors' => $validator->errors(),
                    'order_slug' => $orderSlug
                ]);
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 400);
            }

        $order = Order::where('slug', $orderSlug)->first();
        if (!$order) {
            return response()->json([
                'status' => false,
                'message' => 'Order not found'
            ], 404);
        }

        $uploadedDocuments = [];

        foreach ($request->documents as $index => $document) {
            try {
                $file = $document['file'];
                $documentType = $document['document_type'];

                // Additional security validations
                $originalFilename = $file->getClientOriginalName();
                $mimeType = $file->getMimeType();
                $fileSize = $file->getSize();

                // Validate file size (double check)
                if ($fileSize > 10485760) { // 10MB in bytes
                    throw new \Exception("File {$originalFilename} exceeds 10MB limit");
                }

                // Validate MIME type (double check)
                $allowedMimes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!in_array($mimeType, $allowedMimes)) {
                    throw new \Exception("File {$originalFilename} has invalid MIME type: {$mimeType}");
                }

                // Sanitize filename
                $sanitizedFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalFilename);
                $sanitizedDocumentType = preg_replace('/[^a-zA-Z0-9._-]/', '_', $documentType);

                // Generate unique filename with timestamp and random string
                $uniqueId = uniqid();
                $filename = time() . '_' . $uniqueId . '_' . $sanitizedDocumentType . '_' . $sanitizedFilename;
                
                // Create directory if it doesn't exist
                $directory = public_path('images/order_documents/' . $orderSlug);
                if (!file_exists($directory)) {
                    if (!mkdir($directory, 0755, true)) {
                        throw new \Exception("Failed to create directory: {$directory}");
                    }
                }
                
                // Move file to public/images directory
                $file->move($directory, $filename);
                $path = 'images/order_documents/' . $orderSlug . '/' . $filename;

                // Verify file was moved successfully
                if (!file_exists(public_path($path))) {
                    throw new \Exception("File move failed for: {$originalFilename}");
                }

                // Create document record
                $orderDocument = OrderDocument::create([
                    'order_slug' => $orderSlug,
                    'document_type' => $documentType,
                    'file_path' => $path,
                    'original_filename' => $originalFilename,
                    'mime_type' => $mimeType,
                    'file_size' => $fileSize,
                    'uploaded_by' => 'admin',
                    'status' => 'approved',
                ]);

                $uploadedDocuments[] = $orderDocument;
                
                Log::info('Document uploaded successfully', [
                    'order_slug' => $orderSlug,
                    'document_type' => $documentType,
                    'filename' => $filename,
                    'file_size' => $fileSize
                ]);

            } catch (\Exception $e) {
                Log::error('Document upload error', [
                    'error' => $e->getMessage(),
                    'order_slug' => $orderSlug,
                    'document_index' => $index,
                    'document_type' => $document['document_type'] ?? 'unknown'
                ]);
                return response()->json([
                    'status' => false,
                    'message' => 'Error uploading document: ' . $e->getMessage()
                ], 500);
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Documents uploaded successfully',
            'data' => $uploadedDocuments
        ]);
        } catch (\Exception $e) {
            Log::error('Document upload process error', [
                'error' => $e->getMessage(),
                'order_slug' => $orderSlug
            ]);
            return response()->json([
                'status' => false,
                'message' => 'Error processing document upload: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send documents to user via email
     */
    public function sendDocumentsToUser(Request $request, $orderSlug)
    {
        $validator = Validator::make($request->all(), [
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        $order = Order::where('slug', $orderSlug)->with('user')->first();
        if (!$order) {
            return response()->json([
                'status' => false,
                'message' => 'Order not found'
            ], 404);
        }

        $documents = OrderDocument::where('order_slug', $orderSlug)
            ->where('uploaded_by', 'admin')
            ->where('status', 'approved')
            ->get();

        if ($documents->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No approved documents found for this order'
            ], 400);
        }

        try {
            // Send email with document attachments
            $subject = $request->subject ?: "Your {$order->order_type} Documents - Motoka";
            $message = $request->message ?: "Please find attached the documents for your order. These documents are required for your {$order->order_type} application.";
            
            Mail::to($order->user->email)->send(new SendOrderDocuments(
                $order,
                $documents,
                $subject,
                $message
            ));

            return response()->json([
                'status' => true,
                'message' => 'Documents sent to user successfully',
                'data' => [
                    'user_email' => $order->user->email,
                    'documents_count' => $documents->count()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send documents email: ' . $e->getMessage());
            
            return response()->json([
                'status' => false,
                'message' => 'Failed to send email. Please try again.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get documents for an order
     */
    public function getOrderDocuments($orderSlug)
    {
        $order = Order::where('slug', $orderSlug)->first();
        if (!$order) {
            return response()->json([
                'status' => false,
                'message' => 'Order not found'
            ], 404);
        }

        $documents = OrderDocument::where('order_slug', $orderSlug)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $documents
        ]);
    }

    /**
     * View/download a specific document
     */
    public function viewDocument($orderSlug, $documentId)
    {
        $document = OrderDocument::where('order_slug', $orderSlug)
            ->where('id', $documentId)
            ->first();

        if (!$document) {
            return response()->json([
                'status' => false,
                'message' => 'Document not found'
            ], 404);
        }

        $filePath = storage_path('app/public/' . $document->file_path);

        if (!file_exists($filePath)) {
            return response()->json([
                'status' => false,
                'message' => 'File not found on server'
            ], 404);
        }

        return response()->file($filePath, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => 'inline; filename="' . $document->original_filename . '"'
        ]);
    }
}
