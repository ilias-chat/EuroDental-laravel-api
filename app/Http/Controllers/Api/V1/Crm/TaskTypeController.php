<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TaskType;

class TaskTypeController extends Controller
{
    /**
     * Get all task types
     */
    public function index()
    {
        $types = TaskType::orderBy('name')->get();
        return response()->json(['types' => $types]);
    }

    /**
     * Store a new task type
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:task_types,name'
        ]);

        $type = TaskType::create(['name' => $request->name]);

        return response()->json([
            'success' => true,
            'message' => 'Type de tâche créé avec succès',
            'type' => $type
        ]);
    }

    /**
     * Update a task type
     */
    public function update(Request $request, TaskType $taskType)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:task_types,name,' . $taskType->id
        ]);

        $taskType->update(['name' => $request->name]);

        return response()->json([
            'success' => true,
            'message' => 'Type de tâche modifié avec succès',
            'type' => $taskType
        ]);
    }
}
