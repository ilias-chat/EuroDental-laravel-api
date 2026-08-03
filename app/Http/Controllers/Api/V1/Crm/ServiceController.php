<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Service;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Service::with('category');

        // Filter by name
        if ($request->filled('name')) {
            $query->where('name', 'like', '%' . $request->name . '%');
        }

        // Filter by category
        if ($request->filled('service_category_id')) {
            $query->where('service_category_id', $request->service_category_id);
        }

        $services = $query
            ->orderBy('name')
            ->paginate(min($request->integer('per_page', 15), 100))
            ->withQueryString();

        return response()->json($services);
    }

    /**
     * Store a newly created service
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'service_category_id' => 'required|exists:service_categories,id',
        ]);

        $service = Service::create($validated)->load('category');

        return response()->json([
            'success' => true,
            'message' => 'Service créé avec succès',
            'service' => $service
        ]);
    }

    /**
     * Update the specified service
     */
    public function update(Request $request, Service $service)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'service_category_id' => 'required|exists:service_categories,id',
        ]);

        $service->update($validated);
        $service->refresh()->load('category');

        return response()->json([
            'success' => true,
            'message' => 'Service mis à jour avec succès',
            'service' => $service
        ]);
    }

    /**
     * Remove the specified service
     */
    public function destroy(Service $service)
    {
        // Check if service is used in any tasks
        if ($service->tasks()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer ce service car il est utilisé dans des tâches'
            ], 400);
        }

        $service->delete();

        return response()->json([
            'success' => true,
            'message' => 'Service supprimé avec succès'
        ]);
    }

    /**
     * Get all services (API endpoint for task modals)
     */
    public function getAll()
    {
        $services = Service::with('category')
            ->orderBy('name', 'asc')
            ->get();
        
        return response()->json([
            'success' => true,
            'services' => $services
        ]);
    }
}

