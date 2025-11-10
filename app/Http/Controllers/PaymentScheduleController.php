<?php

namespace App\Http\Controllers;

use App\Models\CarType;
use App\Models\PaymentHead;
use App\Models\PaymentSchedule;
use Faker\Provider\ar_EG\Payment;
use Illuminate\Http\Request;

class PaymentScheduleController extends Controller
{
    public function getAllPaymentSchedule(Request $request)
    {
        $getAllSchedules = PaymentSchedule::get();
        return response()->json(['status' => true, 'data' => $getAllSchedules]);
    }

    public function getAllPaymentHead(Request $request)
    {
        $getAllSchedules = PaymentHead::get();
        return response()->json(['status' => true, 'data' => $getAllSchedules]);
    }

    // public function store(Request $request)
    // {
    //     // Only allow admin or specific role
    //     if (!auth()->user() || auth()->user()->role !== 'admin') {
    //         return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
    //     }
    //     $request->validate([
    //         'payment_head_id' => "required",
    //         'gateway_id' => "required",
    //         'amount' => "required",
    //         'revenue_head_id' => "required",
    //     ]);

    //     PaymentSchedule::create([
    //         'payment_head_id' => $request->payment_head_id,
    //         'gateway_id' => $request->gateway_id,
    //         'revenue_head_id' => $request->revenue_head_id,
    //         'amount' => $request->amount,
    //     ]);
    //     return response()->json(['status' => true, 'message' => "Payment Schedule created successfully"]);
    // }

    public function show(Request $request) {}

    // public function update(Request $request)
    // {
    //     $request->validate([
    //         'id' => "required",
    //         'payment_head_id' => "required",
    //         'gateway_id' => "required",
    //         'amount' => "required",
    //         'revenue_head_id' => "required",
    //     ]);

    //     $updateSchedule = PaymentSchedule::where('id', $request->id)->first();
    //     $updateSchedule->payment_head_id = $request->payment_head_id;
    //     $updateSchedule->gateway_id = $request->gateway_id;
    //     $updateSchedule->amount = $request->amount;
    //     $updateSchedule->revenue_head_id = $request->revenue_head_id;
    //     $updateSchedule->save();
    //     return response()->json(['status' => true, 'data' => "Payment Schedule updated successfully"]);
    // }

    public function getPaymentScheduleByPaymenthead(Request $request) 
    {
          $request->validate([
            'payment_head_id' => "required",
        ]);
        $getSchedule = PaymentSchedule::where('payment_head_id', $request->payment_head_id)->first();
        if ($getSchedule == null) {
            return response()->json(['status' => false, 'message' => "Payment Schedule not found"]);
        }
        return response()->json(['status' => true, 'data' => $getSchedule]);

    }
}
