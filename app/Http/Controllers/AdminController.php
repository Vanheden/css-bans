<?php

namespace App\Http\Controllers;

use App\Helpers\CommonHelper;
use App\Helpers\PermissionsHelper;
use App\Http\Requests\StoreAdminRequest;
use App\Http\Requests\StoreGroupRequest;
use App\Models\Permission;
use App\Models\SaAdmin;
use App\Models\SaAdminsFlags;
use App\Models\SaGroupsFlags;
use App\Models\SaGroups;
use App\Models\SaGroupsServers;
use App\Models\SaServer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;

class AdminController extends Controller
{
    public function admins()
    {
        return view('admin.admins.list');
    }

    public function create()
    {
        $permissions = Permission::all();
        $servers = SaServer::all();
        $groups = SaGroups::all();
        return view('admin.admins.create', compact('permissions', 'servers', 'groups'));
    }

    public function store(StoreAdminRequest $request)
    {
        $validatedData = $request->validated();
        try {
            if(in_array('all', $validatedData['server_ids'])) {
                $validatedData['server_ids'] = SaServer::all()->pluck('id')->toArray();
            }
            $adminAddedToServerCount = [];
            foreach ($validatedData['server_ids'] as $server_id) {
                if(!empty($validatedData['permissions'])) {
                    foreach ($validatedData['permissions'] as $permissionId) {
                        $existingAdmin = SaAdmin::where('player_steamid', $validatedData['steam_id'])
                            ->where('server_id', $server_id)
                            ->first()
                            ?->adminFlags()
                            ->where('flag', $permissionId)
                            ->exists();
                        if (!$existingAdmin) {
                            $permission = Permission::find($permissionId);
                            $admin = new SaAdmin();
                            $admin->player_steamid = $validatedData['steam_id'];
                            $admin->player_name = $validatedData['player_name'];
                            $admin->immunity = $validatedData['immunity'];
                            $admin->server_id = $server_id;
                            $admin->ends = isset($validatedData['ends']) ? CommonHelper::formatDate($validatedData['ends']) : null;
                            $admin->created = now();
                            $admin->save();

                            $adminFlag = new SaAdminsFlags();
                            $adminFlag->admin_id = $admin->id;
                            $adminFlag->flag = $permission->permission;
                            $adminFlag->save();
                            $adminAddedToServerCount[$server_id] = $server_id;
                        }
                    }
                }
                if(!empty($validatedData['groups'])) {
                    foreach ($validatedData['groups'] as $groupId) {
                        $adminGroupExists = SaAdmin::with('adminGroups.groups')
                            ->where('player_steamid', $validatedData['steam_id'])
                            ->where('server_id', $server_id)
                            ->where('group_id', $groupId)
                            ->exists();
                        if (!$adminGroupExists) {
                            $saAdmin = new SaAdmin();
                            $saAdmin->player_steamid = $validatedData['steam_id'];
                            $saAdmin->player_name = $validatedData['player_name'];
                            $saAdmin->immunity = $validatedData['immunity'];
                            $saAdmin->server_id = $server_id;
                            $saAdmin->ends = isset($validatedData['ends']) ? CommonHelper::formatDate($validatedData['ends']) : null;
                            $saAdmin->group_id = $groupId;
                            $saAdmin->created = now();
                            $saAdmin->save();
                            $adminAddedToServerCount[$server_id] = $server_id;
                        }
                    }
                }
            }
            return redirect()->route('admins.list')->with('success', 'Admin added successfully to '.count($adminAddedToServerCount).' Servers');
        } catch (\Exception $e) {
            return Redirect::back()->withErrors(['msg' => 'There was an error saving the admin: ' . $e->getMessage()]);
        }
    }

    public function getAdminsList(Request $request)
    {
        $start = $request->input('start', 0);
        $length = $request->input('length', 10);
        $searchValue = $request->input('search.value');
        $orderColumnIndex = $request->input('order.0.column');
        $orderDir = $request->input('order.0.dir', 'asc');
        $orderColumnName = $request->input("columns.$orderColumnIndex.data", 'steamid');

        $query = SaAdmin::query();

        if (!empty($searchValue)) {
            $query->where(function ($q) use ($searchValue) {
                $q->where('player_name', 'like', "%{$searchValue}%")
                    ->orWhere('player_steamid', 'like', "%{$searchValue}%");
            });
        }

        $recordsTotal = $query->distinct()->count('player_steamid');
        DB::statement("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));");
        $admins = $query->select(
            'player_steamid',
            'player_name',
            'sa_admins.id',
            'sa_admins.group_id',
            DB::raw('CASE WHEN COUNT(sa_admins_flags.flag) = 0 THEN COALESCE(GROUP_CONCAT(DISTINCT sa_groups.name SEPARATOR ", "), "") ELSE COALESCE(GROUP_CONCAT(DISTINCT sa_admins_flags.flag SEPARATOR ", "), "") END AS flags'),
            DB::raw('GROUP_CONCAT(DISTINCT CONCAT("[Hostname] ", sa_servers.hostname) SEPARATOR ", ") as hostnames'),
            'created',
            'ends',
            'sa_admins.server_id'
            )
            ->join('sa_servers', 'sa_admins.server_id', '=', 'sa_servers.id')
            ->leftJoin('sa_admins_flags', function($join) {
                $join->on('sa_admins_flags.admin_id', '=', 'sa_admins.id')
                    ->where('sa_admins_flags.flag', 'not like', '#%');
            })
            ->leftJoin('sa_groups', 'sa_admins.group_id', '=', 'sa_groups.id')
            ->leftJoin('sa_groups_flags', 'sa_groups.id', '=', 'sa_groups_flags.group_id')
            ->leftJoin('sa_groups_servers', 'sa_groups_servers.group_id', '=', 'sa_groups_flags.group_id')
            ->groupBy('player_steamid', DB::raw('CASE
                        WHEN sa_admins_flags.flag LIKE "#%" THEN "#"
                        WHEN sa_admins_flags.flag LIKE "@%" THEN "@"
                        ELSE ""
                        END'))
            ->orderBy($orderColumnName, $orderDir)
            ->offset($start)
            ->limit($length)
            ->get();
        // Format each ban record
        $formattedData = [];
        $siteDir = env('VITE_SITE_DIR');
        foreach ($admins as $admin) {
            $formattedData[] = [
                "id" => $admin->id,
                "player_steamid" => $admin->player_steamid,
                "player_name" => $admin->player_name,
                "ends" => empty($admin->ends) ? "<h6><span class='badge badge-primary'>Never Expires</span></h6>" : $admin->ends,
                "created" => $admin->created,
                "flags" => $admin->flags,
                "hostnames" => $admin->hostnames,
                'actions' => PermissionsHelper::isSuperAdmin() ? ($admin->group_id !== null ? "<a href='$siteDir/admin/groups/edit/{$admin->player_steamid}' class='btn btn-info btn-sm'><i class='fa fa-edit'></i></a> <a href='$siteDir/admin/delete/{$admin->player_steamid}' class='btn btn-danger btn-sm'><i class='fa fa-trash'></i></a>" : "<a href='$siteDir/admin/edit/{$admin->player_steamid}/{$admin->server_id}' class='btn btn-info btn-sm'><i class='fa fa-edit'></i></a> <a href='$siteDir/admin/delete/{$admin->player_steamid}' class='btn btn-danger btn-sm'><i class='fa fa-trash'></i></a>") : "",
            ];
        }
        $response = [
            'draw' => intval($request->input('draw', 0)),
            'recordsTotal' => $recordsTotal,
            "recordsFiltered" => !empty($searchValue) ? count($formattedData) : $recordsTotal ,
            'data' => $formattedData
        ];

        return response()->json($response);
    }

    public function editAdmin($player_steam, $server_id)
    {
        $admin = SaAdmin::with('adminFlags.permissions')
            ->where('player_steamid', $player_steam)
            ->where('server_id', $server_id)
            ->get();
        $allowMigrate = true;
        $groups = SaGroups::all();
        $adminGroups = [];
        if ($admin->isEmpty()) {
            return redirect()->route('admins.list')->with('error', 'Admin does not exists for the server!. Add Admin!');
        }
        $permissions = Permission::all();
        $servers = SaServer::all();
        $adminPermissions = $admin->pluck('adminFlags.*.permissions.permission')->flatten()->toArray();
        return view('admin.admins.edit', compact('admin', 'permissions', 'adminPermissions', 'servers', 'allowMigrate', 'groups', 'adminGroups'));
    }

    public function editAdminGroup($player_steam) {
        $allowMigrate = false;
        $admin = SaAdmin::with('adminGroups.groups')
            ->where('player_steamid', $player_steam)
            ->get();
        if ($admin->isEmpty()) {
            return redirect()->route('admins.list')->with('error', 'Admin with a group does not exist for the selected server!. Add admin to the server!');
        }
        $groups = SaGroups::all();
        $servers = SaServer::all();
        $adminGroups = $admin->pluck('adminGroups.*.groups.name')->flatten()->unique()->toArray();
        return view('admin.admins.edit', compact('admin', 'groups', 'adminGroups', 'servers', 'allowMigrate'));
    }
    public function updateAdmin(Request $request, $player_steam)
    {
        // Validate the submitted permissions
        $validated = $request->validate([
            'permissions' => 'required_without:groups|array',
            'permissions.*' => 'exists:permissions,permission',
            'ends' => 'required_without:permanent|date|after:today',
            'server_id' => 'exists:sa_servers,id',
            'immunity' => 'required',
            'groups' => 'required_without:permissions|array',
            'player_name' => 'required',
        ]);
        if(!empty($validated['groups'])){
            // migrate
            $servers = SaServer::all()->pluck('id')->toArray();
            SaAdmin::where('player_steamid', $player_steam)
                ->whereIn('server_id', SaServer::all()->pluck('id')->toArray())
                ->whereNull('group_id')
                ->delete();

            foreach($servers as $server) {
                foreach($validated['groups'] as $groupId){
                    $saAdmin = new SaAdmin();
                    $saAdmin->player_steamid = $player_steam;
                    $saAdmin->player_name = $validated['player_name'];
                    $saAdmin->immunity = $validated['immunity'];
                    $saAdmin->server_id = $server;
                    $saAdmin->ends = isset($validated['ends']) ? CommonHelper::formatDate($validated['ends']): null;
                    $saAdmin->group_id = $groupId;
                    $saAdmin->created = now();
                    $saAdmin->save();
                }
            }
        } else {
            $admin = SaAdmin::with('adminFlags.permissions')
                ->where('player_steamid', $player_steam)
                ->where('server_id', $validated['server_id'])
                ->get();
            $submittedPermissions = $validated['permissions'];

            // Fetch current permissions from the database
            $currentPermissions = $admin->pluck('adminFlags.*.permissions.permission')->flatten()->toArray();

            // Determine permissions to add and delete
            $permissionsToAdd = array_diff($submittedPermissions, $currentPermissions);
            $permissionsToDelete = array_diff($currentPermissions, $submittedPermissions);

            // Handle permissions to add
            foreach ($permissionsToAdd as $permissionName) {
                $saAdmin = new SaAdmin();
                $saAdmin->player_steamid = $admin->first()->player_steamid;
                $saAdmin->player_name = $validated['player_name'];
                $saAdmin->immunity = $validated['immunity'];
                $saAdmin->server_id = $admin->first()->server_id;
                $saAdmin->ends = isset($validated['ends']) ? CommonHelper::formatDate($validated['ends']) : null;
                $saAdmin->created = now();
                $saAdmin->save();

                $adminFlag = new SaAdminsFlags();
                $adminFlag->admin_id = $saAdmin->id;
                $adminFlag->flag = $permissionName;
                $adminFlag->save();
            }

            // Handle permissions to delete
            $adminData = SaAdmin::where('player_steamid', $player_steam)
                ->where('server_id', $validated['server_id'])
                ->get('id');

            SaAdminsFlags::whereIn('flag', $permissionsToDelete)
                ->whereIn('admin_id', $adminData->pluck('id')->toArray())
                ->delete();

            // update new expiry irrespective or no. of servers
            SaAdmin::where('player_steamid', $player_steam)
                ->whereNull('group_id')
                ->update([
                    'ends' => isset($validated['ends']) ? CommonHelper::formatDate($validated['ends']) : null
                ]);
        }
        return redirect()->route('admins.list')->with('success', 'Admin updated successfully.');
    }

    public function showDeleteForm($player_steam)
    {
        $admin = SaAdmin::where('player_steamid', $player_steam)->firstOrFail();
        $servers = SaServer::all();
        return view('admin.admins.delete', compact('admin', 'servers'));
    }

    public function delete(Request $request, $player_steam)
    {
        $validated = $request->validate([
            'server_ids' => 'required|array',
            'server_ids.*' => 'exists:sa_servers,id',
        ]);
        $serverIds = $validated['server_ids'];
        SaAdmin::where('player_steamid', $player_steam)
            ->whereIn('server_id', $serverIds)
            ->delete();

        return redirect()->route('admins.list')->with('success', 'Admin deleted successfully.');
    }

    public function createGroup()
    {
        $permissions = Permission::all();
        $servers = SaServer::all();
        return view('admin.groups.create', compact('permissions', 'servers'));
    }

    public function storeGroup(StoreGroupRequest $request)
    {
        $validatedData = $request->validated();
        try {
            if(in_array('all', $validatedData['server_ids'])) {
                $validatedData['server_ids'] = SaServer::all()->pluck('id')->toArray();
            }
            $groupAddedToServerCount = [];
            if (empty($group = SaGroups::where('name', $validatedData['group_name'])->first())) {
                $group = new SaGroups();
                $group->name = $validatedData['group_name'];
                $group->immunity = $validatedData['immunity'];
                $group->save();
            }
            foreach ($validatedData['server_ids'] as $server_id) {
                if (empty(SaGroupsServers::where('group_id', $group->id)->where('server_id', $server_id)->first()))
                {
                    $groupServer = new SaGroupsServers();
                    $groupServer->group_id = $group->id;
                    $groupServer->server_id = $server_id;
                    $groupServer->save();
                    $groupAddedToServerCount[$server_id] = $server_id;
                }
                foreach ($validatedData['permissions'] as $permissionId) {
                    $permission = Permission::find($permissionId);
                    if(empty($group->groupFlags()->where('flag', $permission->permission)->first())) {
                        $groupFlags = new SaGroupsFlags();
                        $groupFlags->group_id = $group->id;
                        $groupFlags->flag = $permission->permission;
                        $groupFlags->save();
                        $groupAddedToServerCount[$server_id] = $server_id;
                    }
                }
            }
            return redirect()->route('admins.list')->with('success', 'Group added successfully to '.count($groupAddedToServerCount).' Servers');
        } catch (\Exception $e) {
            return Redirect::back()->withErrors(['msg' => 'There was an error saving the group: ' . $e->getMessage()]);
        }
    }

    public function updateAdminGroup(Request $request, $player_steam)
    {
        // Validate the submitted permissions
        $validated = $request->validate([
            'ends' => 'required_without:permanent|date|after:today',
            'server_id' => 'exists:sa_servers,id',
            'immunity' => 'required',
            'groups' => 'required'
        ]);

        $admin = SaAdmin::with('adminGroups.groups')
            ->where('player_steamid',$player_steam)
            ->where('server_id',  $validated['server_id'])
            ->get();
        $submittedGroups = $validated['groups'];

        // Fetch current groups from the database
        $currentGroups = $admin->pluck('adminGroups.*.groups.id')->flatten()->toArray();

        // Determine groups to add and delete
        $groupsToAdd = array_diff($submittedGroups, $currentGroups);
        $groupsToDelete = array_diff($currentGroups, $submittedGroups);
        // Handle groups to add
        foreach ($groupsToAdd as $groupId) {
            $saAdmin = new SaAdmin();
            $saAdmin->player_steamid = $admin->first()->player_steamid;
            $saAdmin->player_name = $admin->first()->player_name;
            $saAdmin->immunity = $validated['immunity'];
            $saAdmin->server_id = $admin->first()->server_id;
            $saAdmin->ends = isset($validated['ends']) ? CommonHelper::formatDate($validated['ends']): null;
            $saAdmin->group_id = $groupId;
            $saAdmin->created = now();
            $saAdmin->save();
        }

        // Handle groups to delete
        SaAdmin::whereIn('group_id', $groupsToDelete)
            ->where('player_steamid',$player_steam)
            ->where('server_id',  $validated['server_id'])
            ->delete();

        // update new expiry
        SaAdmin::where('player_steamid', $player_steam)
            ->where('server_id', $validated['server_id'])
            ->whereNotNull('group_id')
            ->update([
                'ends' => isset($validated['ends']) ? CommonHelper::formatDate($validated['ends']) : null
            ]);
        return redirect()->route('admins.list')->with('success', 'Admin Group(s) updated successfully.');
    }

    public function getGroupsList(Request $request)
    {
        // Extract parameters sent by DataTables
        $start = $request->input('start');
        $length = $request->input('length');
        $searchValue = $request->input('search.value');
        $orderColumn = $request->input('order.0.column');
        $orderDirection = $request->input('order.0.dir');

        $query = SaGroups::query();

        // Join the sa_groups_flag table to fetch flags
        $query->leftJoin('sa_groups_flags', 'sa_groups.id', '=', 'sa_groups_flags.group_id');

        $query->select(
            'sa_groups.*',
            DB::raw('GROUP_CONCAT(sa_groups_flags.flag SEPARATOR ", ") as flags')
        );

        // Apply search filter on group name
        if (!empty($searchValue)) {
            $query->where('sa_groups.name', 'like', '%' . $searchValue . '%');
        }

        // Group by group id and name
        $query->groupBy('sa_groups.id', 'sa_groups.name');

        // Apply sorting
        if ($orderColumn !== null) {
            $query->orderBy($request->input('columns.' . $orderColumn . '.data'), $orderDirection);
        }

        // Paginate the results
        $groups = $query->offset($start)->limit($length)->get();

        // Get total count for pagination
        $totalGroups = SaGroups::count();

        $formattedData = [];
        // Format each group record
        foreach ($groups as $group) {
            $formattedData[] = [
                "id" => $group->id,
                "name" => $group->name,
                "flags" => $group->flags ?: "No flags assigned",
            ];
        }

        $response = [
            'draw' => $request->input('draw'),
            "recordsTotal" => $totalGroups,
            "recordsFiltered" => !empty($searchValue) ? count($formattedData) : $totalGroups,
            "data" => $formattedData
        ];

        return response()->json($response);
    }

    public function groups()
    {
        return view('admin.groups.list');
    }
}
