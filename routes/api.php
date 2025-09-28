<?php

use App\Http\Controllers\AclController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CarController;
use App\Http\Controllers\CarTypeController;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\DriverLicenseController;
use App\Http\Controllers\PlateController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\ReminderController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\MonicreditPaymentController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaystackPaymentController;
use App\Http\Controllers\PaymentScheduleController;
use App\Http\Controllers\KycController;
use App\Http\Controllers\Admin\OrderDocumentController;
use App\Models\Car;
use Carbon\Carbon;
use Illuminate\Support\Facades\Route;


// use Illuminate\Support\Facades\Mail;

// Public authentication routes
Route::controller(AuthController::class)->group(function () {
    Route::post('register', 'register')->name('register');
    Route::post('login', 'login')->name('login');
    Route::post('login2', 'login2')->name('login2');
    Route::post('send-otp', 'sendOtp');
    Route::post('verify-otp', 'verifyOtp');
    Route::post('reset-password', 'reset');
});

// OTP-based login routes (outside controller group)
Route::post('/send-login-otp', [AuthController::class, 'sendLoginOTP']);
Route::post('/verify-login-otp', [AuthController::class, 'verifyLoginOTP']);

    // Protected authentication routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');

        Route::post('/kyc', [KycController::class, 'store']);
        Route::get('/kyc', [KycController::class, 'index']);

        Route::prefix('licenses')->group(function () {
            Route::post('/apply', [DriverLicenseController::class, 'store']); // Apply for license
            Route::get('/', [DriverLicenseController::class, 'index']);       // List all licenses
            Route::get('/{slug}', [DriverLicenseController::class, 'show']);    // Get a single license
        });

        Route::prefix('plate-number')->group(function () {
            Route::post('/apply', [PlateController::class, 'store']); // Apply for plate
            Route::get('/', [PlateController::class, 'index']);       // List all plate applications
            Route::get('/{slug}', [PlateController::class, 'show']);    // Get a single plate application
            Route::get('/types', [PlateController::class, 'getPlateTypes']); // Get available plate types
        });


        Route::prefix('settings')->group(function () {
            Route::get('/profile', [ProfileController::class, 'show']);       // Get profile
            Route::put('/profile', [ProfileController::class, 'update']);     // Update profile
            Route::put('/change-password', [ProfileController::class, 'changePassword']);
            Route::delete('/delete-account', [ProfileController::class, 'deleteAccount']);
            Route::post('/kyc', [ProfileController::class, 'storeKyc']); // KYC endpoint
        });


        // Route::prefix('car')->group(function () {
            

        // });
        Route::post('reg-car', [CarController::class, 'register']);
        Route::get('get-cars/', [CarController::class, 'getMyCars']);
        Route::get('cars/{slug}', [CarController::class, 'show']);
        Route::put('cars/{slug}', [CarController::class, 'update']);
        Route::delete('cars/{slug}', [CarController::class, 'destroy']);
        Route::post('cars/{car_id}/add-plate', [CarController::class, 'addPlateToUnregisteredCar']);
        Route::post('initiate', [CarController::class, 'InsertDetail']);
        Route::post('verify', [CarController::class, 'Verification']);
        Route::get('get-all-state', [CarController::class, 'getAllState']);
        Route::get('get-lga/{state_id}', [CarController::class, 'getLgaByState']);


        Route::prefix('payment-schedule')->group(function () {
            Route::get('/', [PaymentScheduleController::class, 'getAllPaymentSchedule']);
            // Only admin should be able to create payment schedules
            // Route::post('/create', [PaymentScheduleController::class, 'store']);
            Route::get('/get-payment-head', [PaymentScheduleController::class, 'getAllPaymentHead']);
            Route::get('/get-payment-schedule', [PaymentScheduleController::class, 'getPaymentScheduleByPaymenthead']);

        });


        Route::middleware('auth:sanctum')->prefix('payment')->group(function () {
            // Monicredit payment routes
            Route::post('/initialize', [PaymentController::class, 'initializePayment']);
            Route::post('/verify-payment/{transaction_id}', [PaymentController::class, 'verifyPayment']);
            
            // Paystack payment routes
            Route::get('/paystack/reference/{transaction_id}', [PaystackPaymentController::class, 'getPaystackReference']);
            Route::post('/paystack/verify/{reference}', [PaystackPaymentController::class, 'verifyPayment']);
            
            // Common payment routes
            Route::get('/car-receipt/{car_slug}', [PaymentController::class, 'getCarPaymentReceipt']);
            Route::get('/receipt/{payment_slug}', [PaymentController::class, 'getPaymentReceipt']);
            Route::get('/wallet', [PaymentController::class, 'getWalletInfo']);
            Route::delete('/receipt/{payment_slug}', [PaymentController::class, 'deleteReceipt']);
            Route::get('/transactions', [PaymentController::class, 'listUserTransactions']);
            Route::get('/all-receipts', [PaymentController::class, 'getAllReceipts']);
        });


      


        // Route::post('/payment/initialize', [MonicreditPaymentController::class, 'initializePayment']);
        // Route::get('/payment/verify', [MonicreditPaymentController::class, 'verifyPayment']);


        Route::get('/car-types', [CarTypeController::class, 'index']);

        Route::post('/restore-account', [ProfileController::class, 'restoreAccount']);

        Route::middleware('auth:sanctum')->prefix('2fa')->group(function () {
            Route::post('/enable-google', [TwoFactorController::class, 'enableGoogle2fa']);
            Route::post('/verify-google', [TwoFactorController::class, 'verifyGoogle2fa']);
            Route::post('/enable-email', [TwoFactorController::class, 'enableEmail2fa']);
            Route::post('/verify-email', [TwoFactorController::class, 'verifyEmail2fa']);
            Route::post('/disable', [TwoFactorController::class, 'disable2fa']);
            Route::post('/check-2fa-status', [TwoFactorController::class, 'check2faStatus']);
        });



        // Delivery fee lookup endpoint (public)
        Route::get('/delivery-fee', [PaymentController::class, 'getDeliveryFee']);


        // Driver license payment endpoints
        Route::get('/driver-license/payment-options', [\App\Http\Controllers\DriverLicenseController::class, 'getDriverLicensePaymentOptions']);
        Route::post('/driver-license/initialize-payment', [\App\Http\Controllers\DriverLicenseController::class, 'initializePayment']);

        // Driver license CRUD and payment endpoints
        Route::post('/driver-license', [\App\Http\Controllers\DriverLicenseController::class, 'store']);
        Route::get('/driver-license', [\App\Http\Controllers\DriverLicenseController::class, 'index']);
        Route::post('/driver-license/{slug}/initialize-payment', [\App\Http\Controllers\DriverLicenseController::class, 'initializePaymentForLicense']);
        Route::post('/driver-license/{slug}/verify-payment', [\App\Http\Controllers\DriverLicenseController::class, 'verifyPaymentForLicense']);
        Route::get('/driver-license/{slug}/receipt', [\App\Http\Controllers\DriverLicenseController::class, 'getDriverLicenseReceipt']);
        Route::put('/driver-license/{slug}', [\App\Http\Controllers\DriverLicenseController::class, 'update']);
        Route::delete('/driver-license/{slug}', [\App\Http\Controllers\DriverLicenseController::class, 'destroy']);


        Route::get('/driver-license/receipts', [\App\Http\Controllers\DriverLicenseController::class, 'listAllDriverLicenseReceipts']);
       
    });

// Social authentication routes (public)
Route::get('auth/{provider}', [AuthController::class, 'redirectToProvider']);
Route::get('auth/{provider}/callback', [AuthController::class, 'handleProviderCallback']);

// Paystack payment routes (public)
Route::post('/paystack/initialize', [PaystackPaymentController::class, 'initializePayment']);
Route::get('/paystack/callback', [PaystackPaymentController::class, 'handleCallback']);
Route::post('/paystack/webhook', [PaystackPaymentController::class, 'handleWebhook']);


// Verification routes
// Route::controller(VerificationController::class)->group(function () {
//     Route::post('email/verify/send', 'sendEmailVerification');
//     Route::post('email/verify/resend', 'resendEmailVerification');
//     Route::post('user/verify', 'verifyUser');
//     Route::post('phone/verify/send', 'sendPhoneVerification');
// });

Route::prefix('verify')->group(function () {
    Route::post('email/verify/send', [VerificationController::class, 'sendVerification']);
    Route::post('email-resend', [VerificationController::class, 'resendEmailVerification']);
    Route::post('user', [VerificationController::class, 'verifyUser']);
});

// Car management routes
// Route::controller(CarController::class)->group(function () {
//     Route::post('reg', 'register');
//     Route::get('cars', 'getMyCars');
//     Route::get('cars/{id}', 'show');
//     Route::put('cars/{id}', 'update');
//     Route::delete('cars/{id}', 'destroy');
//     Route::post('initiate', 'InsertDetail');
//     Route::post('verify', 'Verification');

// });

  



// Route::prefix('licenses')->group(function () {
//     Route::post('/apply', [DriverLicenseController::class, 'store']); // Apply for license
//     Route::get('/', [DriverLicenseController::class, 'index']);       // List all licenses
//     Route::get('/{id}', [DriverLicenseController::class, 'show']);    // Get a single license
// });

// Add this outside the auth:sanctum group, since user is not authenticated yet
Route::post('/2fa/verify-login', [TwoFactorController::class, 'verifyLogin2fa']);

Route::get('/get-expiration', function () {
    $getAllCars = Car::where('user_id', 'J89SPg')->get();
    $mtd = [];

    foreach ($getAllCars as $car) {
        $expiration = Carbon::parse($car->registration_date - 1);

        if ($expiration->greaterThan(Carbon::now())) {
            $mtd[] = [
                'car_slug' => $car->slug,
                'expiration_date' => $expiration->toDateTimeString(), // format as 'Y-m-d H:i:s'
                'days_until_expiration' => Carbon::now()->diffInDays($expiration),
                'expires_in' => Carbon::now()->diffForHumans($expiration, [
                    'parts' => 2,
                    'short' => true,
                    'syntax' => Carbon::DIFF_RELATIVE_TO_NOW,
                ])
            ];
        }
    }

    return response()->json($mtd);
});

Route::middleware('auth:sanctum')->get('/reminder', [ReminderController::class, 'index']);




Route::middleware('auth:sanctum')->get('/notifications', [NotificationController::class, 'index']);
Route::middleware('auth:sanctum')->post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);



Route::prefix('acl')->name('acl.')->group(function () {
    Route::prefix('user')->name('user.')->group(function () {
        Route::match(['GET', 'POST'], '/', [AclController::class, 'get_paginated_user'])->name('index');
        Route::put('/{user_id}/permission', [AclController::class, 'attach_permission_to_user'])->name('permission');
        Route::get('/{user_id}/roles', [AclController::class, 'role_with_user_has_role'])->name('roles');
        Route::put('/{user_id}/role', [AclController::class, 'attach_role_to_user'])->name('role');
    });

    Route::prefix('role')->name('role.')->group(function () {
        Route::get('/', [AclController::class, 'getAllRoles'])->name('index');
        Route::post('/create', [AclController::class, 'create_role'])->name('create');
        Route::put('/{role_id}/permission', [AclController::class, 'attach_permission_to_role'])->name('attach_permission');
        Route::put('/{role_id}', [AclController::class, 'update_role'])->name('update');
        Route::get('/{role_id}/permissions', [AclController::class, 'get_role_permissions'])->name('permissions');
    });

    Route::prefix('permission')->name('permission.')->group(function () {
        Route::get('/all', [AclController::class, 'get_all_permission'])->name('index');
        Route::get('/permission_with_perm_has_role/{role_id}', [AclController::class, 'permission_with_perm_has_role'])->name('permission_with_perm_has_role');
        Route::get('/permission_with_user_has_perm/{user_id}', [AclController::class, 'permission_with_user_has_perm'])->name('permission_with_user_has_perm');
    });
});

// Route::post('/payment/initialize', [MonicreditPaymentController::class, 'initializePayment']);
// Route::get('/payment/verify', [MonicreditPaymentController::class, 'verifyPayment']);

// List all delivery fees (public)
Route::get('/delivery-fees', [PaymentController::class, 'listAllDeliveryFees']);

// Paystack webhook (public - no auth required)
Route::post('/payment/paystack/webhook', [PaystackPaymentController::class, 'handleWebhook']);

// Paystack callback (public - no auth required)
Route::match(['get', 'post'], '/payment/paystack/callback', [PaystackPaymentController::class, 'handleCallback']);

// Public document viewing (for users to view their documents)
Route::get('/orders/{orderSlug}/documents/{documentId}', [OrderDocumentController::class, 'viewDocument']);

// Test CORS endpoint
Route::get('/test-cors', function () {
    return response()->json(['message' => 'CORS is working!', 'timestamp' => now()]);
});

// Admin authentication routes (public)
Route::middleware(['cors'])->group(function () {
    Route::post('/admin/send-otp', [AdminController::class, 'sendAdminOTP']);
    Route::post('/admin/verify-otp', [AdminController::class, 'verifyAdminOTP']);
    Route::post('/admin/clear-rate-limiters', [AdminController::class, 'clearRateLimiters']); // For testing only
});

// Admin protected routes
Route::middleware(['cors', 'auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    // Dashboard
    Route::get('/dashboard/stats', [AdminController::class, 'getDashboardStats']);
    
    // Orders
    Route::get('/orders', [AdminController::class, 'getOrders']);
    Route::get('/orders/{slug}', [AdminController::class, 'getOrder']);
    Route::post('/orders/{slug}/process', [AdminController::class, 'processOrder']);
    Route::put('/orders/{slug}/status', [AdminController::class, 'updateOrderStatus']);
    
    // Agents
    Route::get('/agents', [AdminController::class, 'getAgents']);
    Route::get('/agents/{slug}', [AdminController::class, 'getAgent']);
    Route::post('/agents', [AdminController::class, 'createAgent']);
    Route::get('/agents/uuid/{uuid}', [AdminController::class, 'getAgentByUuid']);
    Route::put('/agents/uuid/{uuid}/status', [AdminController::class, 'updateAgentStatus']);
    Route::put('/agents/uuid/{uuid}', [AdminController::class, 'updateAgent']);
    
    // Cars
    Route::get('/cars', [AdminController::class, 'getCars']);
    Route::get('/cars/{slug}', [AdminController::class, 'getCar']);
    
    // States
    Route::get('/states', [AdminController::class, 'getStates']);
    
    // Dashboard data
    Route::get('/recent-orders', [AdminController::class, 'getRecentOrders']);
    Route::get('/recent-transactions', [AdminController::class, 'getRecentTransactions']);
    
    // Order Documents
    Route::get('/document-types', [OrderDocumentController::class, 'getDocumentTypes']);
    Route::post('/orders/{orderSlug}/documents', [OrderDocumentController::class, 'uploadDocuments']);
    Route::post('/orders/{orderSlug}/send-documents', [OrderDocumentController::class, 'sendDocumentsToUser']);
    Route::get('/orders/{orderSlug}/documents', [OrderDocumentController::class, 'getOrderDocuments']);
    Route::get('/orders/{orderSlug}/documents/{documentId}', [OrderDocumentController::class, 'viewDocument']);
    
    // Agent Payments
    Route::get('/agent-payments', [AdminController::class, 'getAllAgentPayments']);
    Route::get('/agents/{agentId}/payments', [AdminController::class, 'getAgentPayments']);
});

