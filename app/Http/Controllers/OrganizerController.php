<?php

namespace App\Http\Controllers;

use App\Models\organizer;
use Illuminate\Http\Request;

class OrganizerController extends Controller
{
    // Get /api/organizers
    public function index(){
        $organizers = organizer::with('users')->latest()->get();

        return response()->json([
            'success' => true,
            'data' => $organizers
        ]);
    }
    // Post/api/organizers

    public function store(Request $request){
        $validated = $request->validate([
            'user_id'=>'required|exists:users,id',
            'company_name'=>'nullable|string|max:150',
            'company_logo'=>'nullable|string|max:150',
            'phone'=>'nullable|string|max:20',
            'website'=>'nullable|string|max:255',
            'description'=>'nullable|string',
            'is_verified'=>'boolean',
        ]);

        $organizer = organizer::create($validated);

        return response()->json(
            [
                'success' =>true,
                'message' =>"Organizer create successfully",
                'data' =>$organizer
            ],201);
    }


    //get/ api / organizer/{id}

    public function show($id){
        $organizer = organizer::with('user','events')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' =>$organizer
        ]);
    }

    // put /api /organizers/{id}

    public function update(Request $request, $id){
        $organizer = organizer::findOrFail($id);

        $validated = $request->validate([
            'company_name' => 'nullable|string|max:150',
            'company_logo' =>'nullable|string|max:255',
            'phone' =>'nullable|string|max:20',
            'website'=>'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_verified' =>'boolean',
        ]);
        $organizer ->update($validated);

        return  response()->json([
            'success' => true,
            'message' => 'Organizer update successfully',
            'data ' => $organizer
        ]);

    }

    // delete /api/ organizers/{id}

    public function destroy($id){
        $organizer = organizer::findOrFail($id);

        $organizer->delete();

        return response()->json([
            'success' => true,
            'message' => 'Organizer deleted successfully'
        ]);
    }
}
