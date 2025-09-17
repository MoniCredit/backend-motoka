<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;
use App\Models\Order;
use App\Models\OrderDocument;

class SendOrderDocuments extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $documents;
    public $subject;
    public $adminMessage;

    /**
     * Create a new message instance.
     */
    public function __construct(Order $order, $documents, $subject, $message)
    {
        $this->order = $order;
        $this->documents = $documents;
        $this->subject = $subject;
        $this->adminMessage = $message;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.order-documents',
            with: [
                'order' => $this->order,
                'documents' => $this->documents,
                'adminMessage' => $this->adminMessage,
            ]
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        $attachments = [];
        
        foreach ($this->documents as $document) {
            $filePath = public_path($document->file_path);
            
            if (file_exists($filePath)) {
                // Create unique filename by combining document type with original filename
                $extension = pathinfo($document->original_filename, PATHINFO_EXTENSION);
                $cleanDocumentType = str_replace(' ', '_', $document->document_type);
                $uniqueFilename = $cleanDocumentType . '_' . $document->original_filename;
                
                $attachments[] = Attachment::fromPath($filePath)
                    ->as($uniqueFilename)
                    ->withMime($document->mime_type);
            }
        }
        
        return $attachments;
    }
}
