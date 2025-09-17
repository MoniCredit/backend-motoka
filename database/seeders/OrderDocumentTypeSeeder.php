<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\OrderDocumentType;

class OrderDocumentTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $documentTypes = [
            // Vehicle License Documents
            [
                'order_type' => 'vehicle_license',
                'document_name' => 'Insurance',
                'document_key' => 'insurance',
                'is_required' => true,
                'sort_order' => 1,
            ],
            [
                'order_type' => 'vehicle_license',
                'document_name' => 'Vehicle License',
                'document_key' => 'vehicle_license',
                'is_required' => true,
                'sort_order' => 2,
            ],
            [
                'order_type' => 'vehicle_license',
                'document_name' => 'Proof Of Ownership',
                'document_key' => 'proof_of_ownership',
                'is_required' => true,
                'sort_order' => 3,
            ],
            [
                'order_type' => 'vehicle_license',
                'document_name' => 'Road Worthiness',
                'document_key' => 'road_worthiness',
                'is_required' => true,
                'sort_order' => 4,
            ],
            [
                'order_type' => 'vehicle_license',
                'document_name' => 'Hackney Permit',
                'document_key' => 'hackney_permit',
                'is_required' => false,
                'sort_order' => 5,
            ],
            
            // Driver's License Documents
            [
                'order_type' => 'drivers_license',
                'document_name' => 'Medical Certificate',
                'document_key' => 'medical_certificate',
                'is_required' => true,
                'sort_order' => 1,
            ],
            [
                'order_type' => 'drivers_license',
                'document_name' => 'Eye Test Certificate',
                'document_key' => 'eye_test_certificate',
                'is_required' => true,
                'sort_order' => 2,
            ],
            [
                'order_type' => 'drivers_license',
                'document_name' => 'Passport Photograph',
                'document_key' => 'passport_photograph',
                'is_required' => true,
                'sort_order' => 3,
            ],
            
            // Road Worthiness Documents
            [
                'order_type' => 'road_worthiness',
                'document_name' => 'Vehicle Inspection Report',
                'document_key' => 'inspection_report',
                'is_required' => true,
                'sort_order' => 1,
            ],
            [
                'order_type' => 'road_worthiness',
                'document_name' => 'Insurance Certificate',
                'document_key' => 'insurance_certificate',
                'is_required' => true,
                'sort_order' => 2,
            ],
            [
                'order_type' => 'road_worthiness',
                'document_name' => 'Proof of Ownership',
                'document_key' => 'proof_of_ownership',
                'is_required' => true,
                'sort_order' => 3,
            ],
            [
                'order_type' => 'road_worthiness',
                'document_name' => 'Vehicle Registration',
                'document_key' => 'vehicle_registration',
                'is_required' => true,
                'sort_order' => 4,
            ],
            
            // Proof of Ownership Documents
            [
                'order_type' => 'proof_of_ownership',
                'document_name' => 'Vehicle Registration Certificate',
                'document_key' => 'vehicle_registration_certificate',
                'is_required' => true,
                'sort_order' => 1,
            ],
            [
                'order_type' => 'proof_of_ownership',
                'document_name' => 'Purchase Receipt/Invoice',
                'document_key' => 'purchase_receipt',
                'is_required' => true,
                'sort_order' => 2,
            ],
            [
                'order_type' => 'proof_of_ownership',
                'document_name' => 'Insurance Certificate',
                'document_key' => 'insurance_certificate',
                'is_required' => true,
                'sort_order' => 3,
            ],
            [
                'order_type' => 'proof_of_ownership',
                'document_name' => 'Valid ID Card',
                'document_key' => 'valid_id_card',
                'is_required' => true,
                'sort_order' => 4,
            ],
            [
                'order_type' => 'proof_of_ownership',
                'document_name' => 'Affidavit of Ownership',
                'document_key' => 'affidavit_of_ownership',
                'is_required' => false,
                'sort_order' => 5,
            ],
        ];

        foreach ($documentTypes as $type) {
            OrderDocumentType::updateOrCreate(
                ['order_type' => $type['order_type'], 'document_key' => $type['document_key']],
                $type
            );
        }
    }
}
