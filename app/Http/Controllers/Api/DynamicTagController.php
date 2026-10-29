<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DynamicTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DynamicTagController extends Controller
{
    // GET: List All Tags
    public function index(): JsonResponse
    {
        $tags = DynamicTag::latest()->get();
        return response()->json($tags);
    }

    // POST: Create Tag
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key'           => 'required|string|unique:dynamic_tags,key|max:100',
            'description'   => 'required|string|max:255',
            'color'         => 'nullable|string|max:50',
            'mapped_column' => 'nullable|string|max:100',
        ]);

        $cleanKey = Str::slug(str_replace(['{', '}'], '', $validated['key']), '_');

        $tag = DynamicTag::create([
            'key'           => $cleanKey,
            'label'         => '{' . $cleanKey . '}',
            'description'   => $validated['description'],
            'color'         => $validated['color'] ?? 'blue',
            'mapped_column' => $validated['mapped_column'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tag created successfully',
            'data'    => $tag,
        ], 201);
    }

    // PUT: Update Tag
    public function update(Request $request, $id): JsonResponse
    {
        $tag = DynamicTag::findOrFail($id);

        $validated = $request->validate([
            'description'   => 'required|string|max:255',
            'color'         => 'nullable|string|max:50',
            'mapped_column' => 'nullable|string|max:100',
        ]);

        $tag->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Tag updated successfully',
            'data'    => $tag,
        ]);
    }

    // DELETE: Remove Tag
    public function destroy($id): JsonResponse
    {
        $tag = DynamicTag::findOrFail($id);
        $tag->delete();

        return response()->json([
            'success' => true,
            'message' => 'Tag removed successfully',
        ]);
    }
}
