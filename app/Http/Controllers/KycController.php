<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Kyc;
use Illuminate\Support\Facades\Auth;

class KycController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'nin' => 'nullable|string|unique:kycs,nin',
            'bvn' => 'nullable|string|unique:kycs,bvn',
        ]);

        if (!$request->nin && !$request->bvn) {
            return response()->json(['message' => 'Either NIN or BVN is required.'], 422);
        }

        $user = Auth::user();

        $kyc = Kyc::create([
            'user_id' => $user->id,
            'nin' => $request->nin,
            'bvn' => $request->bvn,
            'first_name' => $user->first_name ?? $user->name ?? '',
            'last_name' => $user->last_name ?? '',
            'email' => $user->email ?? '',
            'phone' => $user->phone_number ?? $user->phone ?? '',
        ]);

        return response()->json(['message' => 'KYC created successfully', 'kyc' => $kyc], 201);
    }

    public function index()
    {
        $kycs = Kyc::where('user_id', Auth::id())->get();
        return response()->json($kycs);
    }
}
