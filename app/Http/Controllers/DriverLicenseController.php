<?php

namespace App\Http\Controllers;

use App\Models\DriverLicense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Models\Notification;

class DriverLicenseController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'license_type' => 'required|in:new,renew',
        ]);
         
        $baseRules = [
            'license_type' => 'required|in:new,renew',
        ];
        $newRule = [
            'full_name' => 'required',
            'phone_number' => 'required',
            'address' => 'nullable',
            'date_of_birth' => 'required|date',
            'place_of_birth'=>'nullable',
            'state_of_origin' => 'required',
            'local_government' => 'nullable',
            'blood_group'=>'nullable',
            'height'=>'nullable',
            'occupation'=>'nullable',
            'next_of_kin'=>'nullable',
            'next_of_kin_phone'=>'nullable',
            'mother_maiden_name'=>'nullable',
            'license_year' => 'required',
            'passport_photo' => 'nullable|image|mimes:jpg,png,jpeg|max:2048',
        ];
        $renewRule =[
             'license_number' => 'required',
             'date_of_birth' => 'required|date',
        ];
    
        if ($request->license_type === 'new') {
            $rules = array_merge($baseRules,$newRule);
        }
         if ($request->license_type === 'renew') {
            $rules = array_merge($baseRules,$renewRule);
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $userId= Auth::user()->userId;
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized access.'], 403);
        }

        if ($request->license_type === 'new') {
            $licenseDetails = DriverLicense::where('user_id', $userId)->get();
            foreach ($licenseDetails as $key => $license) {
                if ($license->full_name == $request->full_name ) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'A user with the same license name already exists.',
                    ]);
                }
            }
        }
        $passportPath = $request->file('passport_photo')
            ? $request->file('passport_photo')->store('passports', 'public')
            : null;

        if ($request->license_type === 'new') {
            $license = DriverLicense::create([
                'user_id' => $userId,
                'full_name' => $request->full_name,
                'phone_number' => $request->phone_number,
                'address' => $request->address,
                'date_of_birth' => $request->date_of_birth,
                'license_type' => $request->license_type,
                'state_of_origin' => $request->state_of_origin,
                'local_government' => $request->local_government,
                'place_of_birth' => $request->place_of_birth,
                'blood_group' => $request->blood_group,
                'height' => $request->height,
                'occupation' => $request->occupation,
                'next_of_kin' => $request->next_of_kin,
                'next_of_kin_phone' => $request->next_of_kin_phone,
                'mother_maiden_name' => $request->mother_maiden_name,
                'license_year' => $request->license_year,
                'passport_photo' => $passportPath
            ]);

            // Create notification for new license
            Notification::create([
                'user_id' => $userId,
                'type' => 'license',
                'action' => 'created',
                'message' => 'Your license has been registered successfully.',
            ]);
        }

        if ($request->license_type === 'renew') {
            $license = DriverLicense::create([
                'user_id' => $userId,
                'license_type' => $request->license_type,
                'license_number' => $request->license_number,
                'date_of_birth' => $request->date_of_birth,
            ]);

            // Create notification for license renewal
            Notification::create([
                'user_id' => $userId,
                'type' => 'license',
                'action' => 'renewed',
                'message' => 'Your license has been renewed successfully.',
            ]);
        }

        // Fetch notifications for the user
        $notifications = Notification::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();

       
        $groupedNotifications = [];
        foreach ($notifications as $notification) {
            $date = $notification->created_at->format('Y-m-d');
            if (!isset($groupedNotifications[$date])) {
                $groupedNotifications[$date] = [];
            }
            $groupedNotifications[$date][] = $notification;
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Licenses registered successfully',
            'car' => $license,
            'notifications' => $groupedNotifications, 
        ]);
    }

    public function index()
    {
        $userId= Auth::user()->userId;
       $getLicense =  DriverLicense::where('user_id',$userId)->get();
        return response()->json(["status"=> true,"data"=>$getLicense],200);
    }

    public function show($id)
    {
        $userId= Auth::user()->userId;
        $license = DriverLicense::where(['id'=>$id,'user_id'=>$userId])->first();

        if ( $license) {
            return response()->json(["status"=> true,"data"=>$license],200);
        }

        return response()->json(["status"=> false,"message"=> "License not found"],401);

    }
}
