<?php

namespace App\Http\Controllers;

use App\Models\CarType;
use Illuminate\Http\Request;

class CarTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = CarType::query();
        
        if ($request->has('make')) {
            $query->where('make', $request->make);
        }
        
        if ($request->has('model')) {
            $query->where('model', $request->model);
        }
        
        if ($request->has('year')) {
            $query->where('year', $request->year);
        }
        
        if ($request->has('body_type')) {
            $query->where('body_type', $request->body_type);
        }
        
        $carTypes = $query->get();
        
        return response()->json([
            'success' => true,
            'message' => 'Car types retrieved successfully',
            'data' => $carTypes
        ]);
    }
    
   
}
