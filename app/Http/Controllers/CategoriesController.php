<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;


class CategoriesController extends Controller
{
    public function index()
    {
        $categorys = Category::get();
        return response()->json([
            "message" => "get all category succesfully",
            "status" => true,
            "data" => $categorys
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:categories,name',
            'description' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',
        ]);

        $categorys = Category::create($validated);

        return response()->json([
            "message" => "create category succesfully",
            "status" => true,
            "data" => $categorys
        ]);
    }

    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);
        $request->validate([
            'name' => 'required|string|max:100|unique:categories,name,' . $category->id,
            'description' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',
        ]);
        $category->update($request->validated());

        return response()->json([
            "message" => "update category succesfully",
            "status" => true,
            "data" => $category
        ]);
    }
    public function destroy($id)
    {
        $category = Category::findOrFail($id);


        $category->delete();

        return response()->json([
            "message" => "create category succesfully",
            "status" => true,
            "data" => $category
        ]);
    }
}
