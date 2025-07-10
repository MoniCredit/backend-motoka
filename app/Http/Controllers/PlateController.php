<?php

namespace App\Http\Controllers;

use App\Models\Plate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PlateController extends Controller
{
    public function index(Request $request)
    {
         $userId= Auth::user()->userId;
        return response()->json(Plate::where('user_id',$userId)->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'plate_number' => 'required|unique:plates',
            'type' => 'required|in:Normal,Customized,Dealership',
            'preferred_name' => 'nullable|string',
            'full_name' => 'required|string',
            'address' => 'required|string',
            'chassis_number' => 'required|unique:plates,chassis_number',
            'engine_number' => 'required|unique:plates,engine_number',
            'phone_number' => 'required|string',
            'colour' => 'required|string',
            'car_make' => 'required|string',
            'car_type' => 'required|string',
            'business_type' => 'nullable|string',
            'cac_document' => 'nullable|file|mimes:pdf,jpg,png',
            'letterhead' => 'nullable|file|mimes:pdf,jpg,png',
            'means_of_identification' => 'nullable|file|mimes:pdf,jpg,png',
        ]);
    
        $cacPath = $request->hasFile('cac_document')
            ? $request->file('cac_document')->store('car-documents', 'public')
            : null;
    
        $letterheadPath = $request->hasFile('letterhead')
            ? $request->file('letterhead')->store('car-documents', 'public')
            : null;
    
        $meansOfIDPath = $request->hasFile('means_of_identification')
            ? $request->file('means_of_identification')->store('car-documents', 'public')
            : null;

        $userId= Auth::user()->userId;
    
        $plate = Plate::create([
            'user_id' => $userId,
            'plate_number' => $request->plate_number,
            'type' => $request->type,
            'preferred_name' => $request->preferred_name,
            'full_name' => $request->full_name,
            'address' => $request->address,
            'chassis_number' => $request->chassis_number,
            'engine_number' => $request->engine_number,
            'phone_number' => $request->phone_number,
            'colour' => $request->colour,
            'car_make' => $request->car_make,
            'car_type' => $request->car_type,
            'business_type' => $request->business_type,
            'cac_document' => $cacPath,
            'letterhead' => $letterheadPath,
            'means_of_identification' => $meansOfIDPath,
        ]);
    
        return response()->json($plate, 201);
    }
    

    public function show(Plate $plate)
    {
        return response()->json($plate);
    }

    public function update(Request $request, Plate $plate)
    {
        $request->validate([
            'plate_number' => 'required|unique:plates,plate_number,' . $plate->id,
            'type' => 'required|in:Normal,Customized,Dealership',
            'preferred_name' => 'nullable|string',
            'full_name' => 'required|string',
            'address' => 'required|string',
            'chassis_number' => 'required|unique:plates,chassis_number,' . $plate->id,
            'engine_number' => 'required|unique:plates,engine_number,' . $plate->id,
            'phone_number' => 'required|string',
            'colour' => 'required|string',
            'car_make' => 'required|string',
            'car_type' => 'required|string',
            'business_type' => 'nullable|in:Co-operate,Business',
            'cac_document' => 'nullable|file|mimes:pdf,jpg,png',
            'letterhead' => 'nullable|file|mimes:pdf,jpg,png',
            'means_of_identification' => 'nullable|file|mimes:pdf,jpg,png',
        ]);

        $plate->update($request->all());

        return response()->json($plate);
    }

    public function destroy(Plate $plate)
    {
        $plate->delete();

        return response()->json(null, 204);
    }
}
