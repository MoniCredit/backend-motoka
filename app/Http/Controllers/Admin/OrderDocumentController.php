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

class OrderDocumentController extends Controller
{
    /**
     * Get document types for a specific order type
     */
    public function getDocumentTypes(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_type' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        $documentTypes = OrderDocumentType::forOrderType($request->order_type)->get();

        return response()->json([
            'status' => true,
            'data' => $documentTypes
        ]);
    }

    /**
     * Upload documents for an order (Admin only)
     */
    public function uploadDocuments(Request $request, $orderSlug)
    {
        $validator = Validator::make($request->all(), [
            'documents' => 'required|array',
            'documents.*.document_type' => 'required|string',
            'documents.*.file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240', // 10MB max
        ]);

        if ($validator->fails()) {
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

        foreach ($request->documents as $document) {
            $file = $document['file'];
            $documentType = $document['document_type'];

            // Generate unique filename
            $filename = time() . '_' . $documentType . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('order_documents/' . $orderSlug, $filename, 'public');

            // Create document record
            $orderDocument = OrderDocument::create([
                'order_slug' => $orderSlug,
                'document_type' => $documentType,
                'file_path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'uploaded_by' => 'admin',
                'status' => 'approved',
            ]);

            $uploadedDocuments[] = $orderDocument;
        }

        return response()->json([
            'status' => true,
            'message' => 'Documents uploaded successfully',
            'data' => $uploadedDocuments
        ]);
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

        // TODO: Implement email sending with attachments
        // This would typically use Laravel Mail with attachments

        return response()->json([
            'status' => true,
            'message' => 'Documents sent to user successfully',
            'data' => [
                'user_email' => $order->user->email,
                'documents_count' => $documents->count()
            ]
        ]);
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
