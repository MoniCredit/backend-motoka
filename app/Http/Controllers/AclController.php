<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AclController extends Controller
{

    public function getAllRoles(Request $request)
    {
        $roles = Role::all();
        return response()->json(['status' => true, 'data' => $roles]);
    }


    public function create_role(Request $request)
    {

        $request->validate([
            'name' => 'required',
            'description' => 'required',
        ]);
        $role = Role::create([
            'name' => $request->input('name'),
            'description' => $request->input('description')
        ]);
        // activity()
        // ->withProperties([
        //     'Name' => $request->input('name'),
        //     'Description' => $request->input('description'),
        // ])
        //     ->log($request->user()->full_name . '(' . $request->user()->email . ')' . " Created a Role");
        return response()->json(['status' => true, 'data' => $role]);
    }


    public function update_role(Request $request, $id)
    {

          $request->validate([
            'name' => 'required',
            'description' => 'required',
        ]);
        $role = Role::where('id', $id)->first();
        $previous_role = Role::where('id', $id)->first();
        $role->name = $request->input('name');
        $role->description = $request->input('description');
        $role->save();
        // $fullname = ;
        // activity()
        // ->withProperties([
        //     'Previous Name' => $previous_role->name,
        //     'Previous Description' => $previous_role->description,
        //     'New Name' => $request->input('name'),
        //     'New Description' => $request->input('description'),
        // ])
        //     ->log($request->user()->full_name . '(' . $request->user()->email . ')' . " Updated " . $previous_role->name . " Role");
        return response()->json(['status' => true, 'data' => $role]);
    }



    public function get_all_permission(Request $request)
    {

        $permission = Permission::all();
        return response()->json(['status' => true, 'data' => $permission]);
    }


    /**
     * This function get all permission with or without role permission
     */
    public function permission_with_perm_has_role(Request $request, $role_id)
    {
        $permission = Permission::leftJoin('role_has_permissions  AS r', function ($join) use ($role_id) {
            $join->on('permissions.id', '=', 'r.permission_id')
                ->on('r.role_id', '=', DB::raw($role_id));
        })->selectRaw('permissions.* , IF (r.permission_id > 0, 1, 0) AS status')->orderBy('permissions.id', 'asc')->get();
        return response()->json(['status' => true, 'data' => $permission]);
    }


    /**
     * This function get all permission with or without role permission
     */
    public function permission_with_user_has_perm(Request $request, $user_id)
    {

        $permissions = Permission::leftJoin('model_has_permissions  AS r', function ($join) use ($user_id) {
            $join->on('permissions.id', '=', 'r.permission_id')
                ->where('r.model_id', '=', $user_id);
        })->selectRaw('permissions.* , IF (r.permission_id > 0, 1, 0) AS status')->orderBy('permissions.id', 'asc')->get();

        return response()->json(['status' => true, 'data' => $permissions]);
    }


    public function role_with_user_has_role(Request $request, $user_id)
    {
        $roles = Role::leftJoin('model_has_roles  AS r', function ($join) use ($user_id) {
            $join->on('roles.id', '=', 'r.role_id')
                ->where('r.model_id', '=', $user_id);
        })->selectRaw('roles.* , IF (r.role_id > 0, 1, 0) AS status')->orderBy('roles.name', 'asc')->get();

        return response()->json(['status' => true, 'data' => $roles]);
    }



    public function attach_permission_to_role(Request $request, $role_id)
    {
        $role = Role::findById($role_id);

        $postData = [];

        //loop through permission to get true status
        foreach ($request->all() as $key => $perm) {
            if ($perm['status'] == true) {
                array_push($postData, $perm['name']);
            }
        }
        $role->syncPermissions($postData);
        // activity()
        // ->withProperties([
        //     $postData
        // ])
        //     ->log($request->user()->full_name . '(' . $request->user()->email . ')' . " attatched permissions to " . $role->name . " Role");
        return response()->json(['status' => true, 'data' => $role]);
    }



    public function attach_permission_to_user(Request $request, $user_id)
    {
        $user = User::where('id', $user_id)->first();

        $postData = [];
        //loop through permission to get true status
        foreach ($request->all() as $key => $perm) {
            if ($perm['status'] == true) {
                array_push($postData, $perm['name']);
            }
        }
        $user->syncPermissions($postData);
        $full = $user->lname . ' ' . $user->fname . ' (' . $user->email . ')';
        // activity()
        // ->withProperties([
        //     $postData
        // ])
        //     ->log($request->user()->full_name . '(' . $request->user()->email . ')' . " attatched permissions to " . $full);
        return response()->json(['status' => true, 'data' => $user]);
    }


    public function attach_role_to_user(Request $request, $user_id)
    {
        $user = User::where('id', $user_id)->first();

        $postData = [];

        //loop through permission to get true status
        foreach ($request->all() as $key => $role) {
            if ($role['status'] == true) {
                array_push($postData, $role['name']);
            }
        }
        $user->syncRoles($postData);
        $full = $user->lname . ' ' . $user->fname . ' (' . $user->email . ')';
        // activity()
        // ->withProperties([
        //     $postData
        // ])
        //     ->log($request->user()->full_name . '(' . $request->user()->email . ')' . " attatched a role to " . $full);
        return response()->json(['status' => true, 'data' => $user]);
    }


    public function get_role_permissions(Request $request, $role_id)
    {

        $role = Role::findById($role_id);
        return response()->json(['status' => true, 'data' => $role->permissions]);
    }


    public function get_paginated_user(Request $request)
    {
        $users = User::with(['roles', 'user_type']);

        // check if filters are being selected
        if ($request->has('filters')) {
            if (!is_null($request->input('filters.user_type'))) {
                $users->where('user_type_id', $request->input('filters.user_type'));
            }

            if (!is_null($request->input('filters.search'))) {

                $users->where(function ($query) use ($request) {
                    $query->orwhere('lname', 'like', '%' . $request->input('filters.search') . '%')
                        ->orWhere('fname', 'like', '%' . $request->input('filters.search') . '%')
                        ->orWhere('mname', 'like', '%' . $request->input('filters.search') . '%')
                        ->orWhere('email', 'like', '%' . $request->input('filters.search') . '%')
                        ->orWhere('phone', 'like', '%' . $request->input('filters.search') . '%');
                });
            }
        }

        return response()->json(['status' => true, 'data' => $users->paginate(20)]);
    }
}
