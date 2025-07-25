<?php

namespace App\Http\Controllers;

use App\Models\Car;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PlateController extends Controller
{
    public function index(Request $request)
    {
        $userId = Auth::user()->userId;
        $cars = Car::where('user_id', $userId)
            ->where('registration_status', 'unregistered')
            ->whereNotNull('plate_number')
            ->get();
        return response()->json($cars);
    }

    public function store(Request $request)
    {
        $userId = Auth::user()->userId;
        if ($request->car_id) {
            // Attach plate info to existing unregistered car
            $plateRules = [
                'car_id' => 'required|exists:cars,id',
                // 'plate_number' => 'required|string|unique:cars,plate_number,' . $request->car_id, // No longer required
                'type' => 'required|in:Normal,Customized,Dealership',
                'preferred_name' => 'nullable|string',
                'business_type' => 'nullable|in:Co-operate,Business',
                'cac_document' => 'nullable|file|mimes:pdf,jpg,png',
                'letterhead' => 'nullable|file|mimes:pdf,jpg,png',
                'means_of_identification' => 'nullable|file|mimes:pdf,jpg,png',
            ];
            $validated = $request->validate($plateRules);
            $car = \App\Models\Car::where('id', $request->car_id)
                ->where('user_id', $userId)
                ->where('registration_status', 'unregistered')
                ->first();
            if (!$car) {
                return response()->json(['status' => 'error', 'message' => 'Unregistered car not found'], 404);
            }
            $updateData = [
                'type' => $request->type,
                'preferred_name' => $request->preferred_name,
                'business_type' => $request->business_type,
                'state_of_origin' => $request->state_of_origin,
                'local_government' => $request->local_government,
                'blood_group' => $request->blood_group,
                'height' => $request->height,
                'occupation' => $request->occupation,
                'next_of_kin' => $request->next_of_kin,
                'next_of_kin_phone' => $request->next_of_kin_phone,
                'mother_maiden_name' => $request->mother_maiden_name,
                'license_years' => $request->license_years,
            ];
            if ($request->hasFile('cac_document')) {
                $filename = time() . '_' . uniqid() . '.' . $request->file('cac_document')->getClientOriginalExtension();
                $request->file('cac_document')->move(public_path('images/car-documents'), $filename);
                $updateData['cac_document'] = 'images/car-documents/' . $filename;
            }
            if ($request->hasFile('letterhead')) {
                $filename = time() . '_' . uniqid() . '.' . $request->file('letterhead')->getClientOriginalExtension();
                $request->file('letterhead')->move(public_path('images/car-documents'), $filename);
                $updateData['letterhead'] = 'images/car-documents/' . $filename;
            }
            if ($request->hasFile('means_of_identification')) {
                $filename = time() . '_' . uniqid() . '.' . $request->file('means_of_identification')->getClientOriginalExtension();
                $request->file('means_of_identification')->move(public_path('images/car-documents'), $filename);
                $updateData['means_of_identification'] = 'images/car-documents/' . $filename;
            }
            $car->update($updateData);
            return response()->json(['status' => 'success', 'car' => $car], 200);
        } else {
            // Create new unregistered car with plate info
            $rules = [
                // 'plate_number' => 'required|string|unique:cars,plate_number', // No longer required
                'type' => 'required|in:Normal,Customized,Dealership',
                'preferred_name' => 'nullable|string',
                'full_name' => 'required|string',
                'address' => 'required|string',
                'chasis_no' => 'required|string|unique:cars,chasis_no',
                'engine_no' => 'required|string|unique:cars,engine_no',
                'phone_number' => 'required|string',
                'vehicle_make' => 'required|string',
                'vehicle_model' => 'required|string',
                'vehicle_color' => 'required|string',
                'car_type' => 'required|in:private,commercial',
                'business_type' => 'nullable|in:Co-operate,Business',
                'cac_document' => 'nullable|file|mimes:pdf,jpg,png',
                'letterhead' => 'nullable|file|mimes:pdf,jpg,png',
                'means_of_identification' => 'nullable|file|mimes:pdf,jpg,png',
                // New fields
                'state_of_origin' => 'nullable|string',
                'local_government' => 'nullable|string',
                'blood_group' => 'nullable|string',
                'height' => 'nullable|string',
                'occupation' => 'nullable|string',
                'next_of_kin' => 'nullable|string',
                'next_of_kin_phone' => 'nullable|string',
                'mother_maiden_name' => 'nullable|string',
                'license_years' => 'nullable|string',
            ];
            $validated = $request->validate($rules);
            $carData = [
                'user_id' => $userId,
                // 'plate_number' => $request->plate_number, // Do not set here
                'type' => $request->type,
                'preferred_name' => $request->preferred_name,
                'name_of_owner' => $request->full_name, // Map full_name to name_of_owner
                'address' => $request->address,
                'chasis_no' => $request->chasis_no,
                'engine_no' => $request->engine_no,
                'phone_number' => $request->phone_number,
                'vehicle_make' => $request->vehicle_make,
                'vehicle_model' => $request->vehicle_model,
                'vehicle_color' => $request->vehicle_color,
                'car_type' => $request->car_type,
                'registration_status' => 'unregistered',
                'business_type' => $request->business_type,
                // New fields
                'state_of_origin' => $request->state_of_origin,
                'local_government' => $request->local_government,
                'blood_group' => $request->blood_group,
                'height' => $request->height,
                'occupation' => $request->occupation,
                'next_of_kin' => $request->next_of_kin,
                'next_of_kin_phone' => $request->next_of_kin_phone,
                'mother_maiden_name' => $request->mother_maiden_name,
                'license_years' => $request->license_years,
            ];
            if ($request->hasFile('cac_document')) {
                $carData['cac_document'] = $request->file('cac_document')->store('car-documents', 'public');
            }
            if ($request->hasFile('letterhead')) {
                $carData['letterhead'] = $request->file('letterhead')->store('car-documents', 'public');
            }
            if ($request->hasFile('means_of_identification')) {
                $carData['means_of_identification'] = $request->file('means_of_identification')->store('car-documents', 'public');
            }
            $car = \App\Models\Car::create($carData);
            return response()->json(['status' => 'success', 'car' => $car], 201);
        }
    }
}
