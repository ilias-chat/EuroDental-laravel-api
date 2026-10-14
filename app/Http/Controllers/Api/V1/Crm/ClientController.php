<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\City;
use App\Models\Image;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Purchase;
use App\Models\Task;
use App\Services\Media\MediaUrl;
use App\Services\Clients\ClientDuplicateDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Client::admin()
            ->with(['image', 'city'])
            ->withCount(['orders as unpaid_orders_count' => fn ($orders) => $orders->where('paid', false)]);

        if ($request->filled('search')) {
            $this->applySearch($query, (string) $request->input('search'));
        }

        $query->when($request->filled('city_id'), fn ($clients) => $clients->where('city_id', $request->integer('city_id')))
            ->when($request->filled('source'), fn ($clients) => $clients->where('source', $request->input('source')));

        $sort = in_array($request->input('sort'), ['first_name', 'last_name', 'created_at'], true)
            ? $request->input('sort')
            : 'first_name';
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';
        $clients = $query->orderBy($sort, $direction)
            ->paginate(min(max($request->integer('per_page', 15), 1), 50));

        return response()->json([
            'clients' => collect($clients->items())->map(fn (Client $client) => $this->serializeClient($client))->values(),
            'pagination' => $this->pagination($clients),
        ]);
    }

    public function meta(): JsonResponse
    {
        return response()->json([
            'cities' => City::query()
                ->withCount(['clients' => fn ($clients) => $clients->where('source', 'admin')])
                ->orderBy('name')
                ->get(['id', 'name']),
            'webshop_clients_count' => Client::query()->where('source', 'webshop')->count(),
            'action_required_count' => Client::query()->where('handled', false)->count(),
        ]);
    }

    public function stats(): JsonResponse
    {
        $topClient = Client::admin()->withCount('orders')->orderByDesc('orders_count')->first();

        return response()->json([
            'total_clients' => Client::admin()->count(),
            'new_clients' => Client::admin()->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
            'top_client' => $topClient ? trim($topClient->first_name . ' ' . $topClient->last_name) : '-',
            'top_client_orders' => (int) ($topClient?->orders_count ?? 0),
        ]);
    }

    public function actionRequired(): JsonResponse
    {
        $clients = Client::query()->with(['city', 'image', 'webshopData.city'])
            ->where('handled', false)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Client $client) => $this->serializeClient($client, true));

        return response()->json(['clients' => $clients]);
    }

    public function duplicates(Request $request, ClientDuplicateDetector $detector): JsonResponse
    {
        $clients = Client::query()->with('webshopData')->orderBy('id')
            ->get(['id', 'first_name', 'last_name', 'email', 'phone_number', 'fixed_phone_number', 'source', 'validated'])
            ->map(fn (Client $client) => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'name' => trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')),
                'email' => $client->email,
                'phone_number' => $client->phone_number,
                'fixed_phone_number' => $client->fixed_phone_number,
                'source' => $client->source,
                'validated' => (bool) $client->validated,
            ])->all();

        $matches = $detector->detect($clients);
        $page = max(1, $request->integer('page', 1));
        $perPage = 20;

        return response()->json([
            'matches' => array_slice($matches, ($page - 1) * $perPage, $perPage),
            'pagination' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil(count($matches) / $perPage)),
                'total' => count($matches),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateClient($request);
        if ($request->hasFile('image')) {
            $data['image_id'] = $this->storeImage($request);
        }
        $data += ['source' => 'admin', 'validated' => true, 'handled' => true];
        $client = Client::create($data)->load(['city', 'image']);

        return response()->json(['message' => 'Client cree avec succes.', 'client' => $this->serializeClient($client)], 201);
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $data = $this->validateClient($request);
        if ($request->hasFile('image')) {
            $oldImage = $client->image;
            $data['image_id'] = $this->storeImage($request);
            $this->deleteMediaImage($oldImage?->image_name);
            $oldImage?->delete();
        }
        $client->update($data);

        return response()->json(['message' => 'Client mis a jour avec succes.', 'client' => $this->serializeClient($client->fresh(['city', 'image']))]);
    }

    public function validateWebshopClient(Request $request, Client $client): JsonResponse
    {
        if ($client->validated) {
            return response()->json(['message' => 'Ce client est deja valide.'], 422);
        }
        $client->update($this->validateWebshopData($request) + ['validated' => true, 'handled' => true]);
        return response()->json(['message' => 'Client valide avec succes.']);
    }

    public function syncWebshopClient(Request $request, Client $client): JsonResponse
    {
        if (! $client->validated) {
            return response()->json(['message' => 'Utilisez Valider pour ce nouveau client.'], 422);
        }
        $client->update($this->validateWebshopData($request) + ['handled' => true]);
        return response()->json(['message' => 'Donnees synchronisees avec succes.']);
    }

    public function ignoreWebshopClient(Client $client): JsonResponse
    {
        if ($client->handled) {
            return response()->json(['message' => 'Ce client est deja traite.'], 422);
        }
        $client->update(['handled' => true]);
        return response()->json(['message' => 'Demande ignoree pour le moment.']);
    }

    /**
     * Get a specific client by ID
     * Example: GET /api/clients/123
     */
    public function show($clientId)
    {
        $client = Client::admin()
            ->with(['city', 'image'])
            ->withCount(['orders as unpaid_orders_count' => fn ($orders) => $orders->where('paid', false)])
            ->find($clientId);

        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Client introuvable'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'client' => $this->serializeClient($client),
        ]);
    }

    public function clientStats($clientId): JsonResponse
    {
        $client = Client::admin()->find($clientId);

        if (! $client) {
            return response()->json(['message' => 'Client introuvable'], 404);
        }

        $orders = $client->orders()
            ->whereIn('status', ['processing', 'shipped'])
            ->with('payments')
            ->get();

        $paidTotal = (float) $orders->sum(fn ($order) => $order->payments->sum('amount'));
        $orderTotal = (float) $orders->sum('total_with_tax');
        $unpaidTotal = max(0, $orderTotal - $paidTotal);
        $unpaidCount = $orders->filter(
            fn ($order) => (float) $order->payments->sum('amount') < (float) $order->total_with_tax
        )->count();

        $activeWarrantyCount = $client->orders()
            ->with('deliveryNotes.deliveryNoteItems.orderItem.product')
            ->get()
            ->flatMap(fn ($order) => $order->deliveryNotes)
            ->flatMap(fn ($deliveryNote) => $deliveryNote->deliveryNoteItems)
            ->filter(function ($item) {
                $product = $item->orderItem?->product;
                if (! $product?->has_warranty || $product->warranty_duration_months <= 0) {
                    return false;
                }

                $deliveryDate = $item->deliveryNote->delivery_date ?? $item->deliveryNote->created_at;
                return now()->lte(\Carbon\Carbon::parse($deliveryDate)->addMonths($product->warranty_duration_months));
            })
            ->sum('delivered_quantity');

        return response()->json([
            'stats' => [
                'total_orders' => $orders->count(),
                'unpaid_count' => $unpaidCount,
                'unpaid_total' => $unpaidTotal,
                'paid_total' => $paidTotal,
                'active_warranty_count' => (int) $activeWarrantyCount,
            ],
        ]);
    }

    public function payments(Request $request, $clientId): JsonResponse
    {
        $client = Client::admin()->find($clientId);

        if (! $client) {
            return response()->json(['message' => 'Client introuvable'], 404);
        }

        $payments = $client->orders()
            ->with('payments')
            ->get()
            ->flatMap(fn ($order) => $order->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'payment_date' => $payment->payment_date?->toDateString(),
                'amount' => (float) $payment->amount,
                'payment_method' => $payment->payment_method,
                'notes' => $payment->notes,
            ]))
            ->sortByDesc('id')
            ->values();

        $perPage = min(max($request->integer('limit', 10), 1), 100);
        $currentPage = max($request->integer('page', 1), 1);
        $total = $payments->count();
        $lastPage = max((int) ceil($total / $perPage), 1);
        $items = $payments->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return response()->json([
            'payments' => $items,
            'pagination' => [
                'current_page' => $currentPage,
                'total_pages' => $lastPage,
                'total_items' => $total,
                'has_next' => $currentPage < $lastPage,
                'has_prev' => $currentPage > 1,
            ],
        ]);
    }

    /**
     * Get tasks for a specific client with pagination and filtering
     * Example: GET /api/clients/123/tasks?page=1&limit=20&status=en cours
     */
    public function tasks(Request $request, $clientId)
    {
        $client = Client::find($clientId);

        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Client introuvable'
            ], 404);
        }

        $query = $client->tasks()->with('technician.image');

        if ($request->filled('task_type')) {
            $query->where('task_type', $request->input('task_type'));
        }

        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Get pagination parameters
        $page = $request->get('page', 1);
        $limit = min($request->get('limit', 20), 100); // Max 100 items per page

        $tasks = $query->orderBy('task_date', 'desc')
                      ->paginate($limit, ['*'], 'page', $page);

        // Transform tasks to match the required format
        $tasks->getCollection()->transform(function ($task) {
            return [
                'id' => $task->id,
                'task_name' => $task->task_name,
                'task_type' => $task->task_type,
                'status' => $task->status,
                'urgent' => $task->urgent,
                'task_date' => $task->task_date,
                'description' => $task->description,
                'technician_id' => $task->technician_id,
                'is_paid' => (bool) $task->is_paid,
                'technician' => $task->technician ? [
                    'id' => $task->technician->id,
                    'name' => trim($task->technician->first_name . ' ' . $task->technician->last_name),
                    'image_url' => $task->technician->image ? MediaUrl::image($task->technician->image->image_name) : null,
                ] : null,
                'created_at' => $task->created_at ? $task->created_at->toISOString() : null
            ];
        });

        // Format pagination data
        $pagination = [
            'current_page' => $tasks->currentPage(),
            'total_pages' => $tasks->lastPage(),
            'total_items' => $tasks->total(),
            'has_next' => $tasks->hasMorePages(),
            'has_prev' => $tasks->currentPage() > 1
        ];

        return response()->json([
            'success' => true,
            'tasks' => $tasks->items(),
            'pagination' => $pagination
        ]);
    }

    /**
     * Get orders for a specific client with pagination and filtering
     * Example: GET /api/clients/123/orders?page=1&limit=20&status=completed
     */
    public function orders(Request $request, $clientId)
    {
        $client = Client::find($clientId);

        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Client introuvable'
            ], 404);
        }

        $query = $client->orders();

        // Filter by status if provided
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        if ($request->filled('paid')) {
            $paid = filter_var($request->input('paid'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($paid !== null) {
                $query->where('paid', $paid);
            }
        }

        // Get pagination parameters
        $page = $request->get('page', 1);
        $limit = min($request->get('limit', 20), 100); // Max 100 items per page

        $orders = $query->with('items')
                       ->orderBy('created_at', 'desc')
                       ->paginate($limit, ['*'], 'page', $page);

        // Transform orders to match the required format
        $orders->getCollection()->transform(function ($order) {
            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'order_date' => $order->created_at ? $order->created_at->format('Y-m-d') : null,
                'status' => $order->status,
                'total_amount' => (float) $order->total_with_tax,
                'payment_status' => $order->isFullyPaid() ? 'paid' : 'unpaid',
                'items_count' => $order->items->count(),
                'created_at' => $order->created_at ? $order->created_at->toISOString() : null
            ];
        });

        // Format pagination data
        $pagination = [
            'current_page' => $orders->currentPage(),
            'total_pages' => $orders->lastPage(),
            'total_items' => $orders->total(),
            'has_next' => $orders->hasMorePages(),
            'has_prev' => $orders->currentPage() > 1
        ];

        return response()->json([
            'success' => true,
            'orders' => $orders->items(),
            'pagination' => $pagination
        ]);
    }

    /**
     * Get warranty products for a specific client with pagination and category filtering
     * Example: GET /api/clients/123/products?page=1&limit=20&category=Implants
     * 
     * Current Features:
     * - Shows ONLY warranty products purchased by this specific client
     * - Category filtering
     * - Pagination
     * - Real warranty calculations based on actual delivery date
     * - Client-specific filtering through delivery notes
     */
    public function products(Request $request, $clientId)
    {
        $client = Client::find($clientId);

        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Client introuvable'
            ], 404);
        }

        // Get orders that have been delivered (not pending/canceled)
        $orders = $client->orders()
            ->whereNotIn('status', ['pending', 'canceled'])
            ->with([
                'deliveryNotes.deliveryNoteItems.orderItem.order',
                'deliveryNotes.deliveryNoteItems.orderItem.product.category'
            ])->get();

        // Get warranty products from delivered orders
        $warrantyProducts = $orders->flatMap(function ($order) {
            return $order->deliveryNotes->flatMap(function ($deliveryNote) {
                return $deliveryNote->deliveryNoteItems->filter(function ($dni) {
                    return $dni->orderItem
                        && $dni->orderItem->product
                        && $dni->orderItem->product->has_warranty
                        && $dni->orderItem->product->warranty_duration_months > 0;
                })->map(function ($dni) use ($deliveryNote) {
                    $product = $dni->orderItem->product;
                    
                    // Ensure category is loaded
                    if (!$product->relationLoaded('category')) {
                        $product->load('category');
                    }
                    
                    $deliveryDate = $deliveryNote->delivery_date ?? $deliveryNote->created_at;

                    $warrantyStart = \Carbon\Carbon::parse($deliveryDate);
                    $warrantyEnd = $warrantyStart->copy()->addMonths($product->warranty_duration_months);
                    
                    // Ensure the warranty end date is valid
                    if (!$warrantyEnd->isValid()) {
                        $warrantyEnd = $warrantyStart->copy()->addMonths($product->warranty_duration_months)->endOfMonth();
                    }
                    
                    $daysLeft = now()->diffInDays($warrantyEnd, false);

                    return [
                        'id' => $product->id,
                        'product_name' => $product->product_name,
                        'order_id' => $dni->orderItem->order_id,
                        'order_number' => $dni->orderItem->order?->order_number,
                        'category' => $product->category ? $product->category->category : null,
                        'category_id' => $product->id_category,
                        'purchase_date' => $warrantyStart->toDateString(),
                        'warranty_expiry' => $warrantyEnd->toDateString(),
                        'warranty_status' => $daysLeft > 0 ? 'active' : 'expired',
                        'days_left_in_warranty' => (string) max(0, $daysLeft),
                        'delivered_quantity' => (float) $dni->delivered_quantity,
                        'serial_number' => $product->reference,
                        'price' => (float) $product->price,
                        'technician_notes' => 'Product delivered successfully'
                    ];
                });
            });
        });

        // Filter by category if provided
        if ($request->has('category') && $request->category) {
            $warrantyProducts = $warrantyProducts->filter(function ($product) use ($request) {
                return $product['category'] === $request->category;
            });
        }

        // Convert to collection and paginate
        $collection = collect($warrantyProducts->values());
        $perPage = min($request->get('limit', 20), 100); // Max 100 items per page
        $currentPage = $request->get('page', 1);
        $offset = ($currentPage - 1) * $perPage;
        
        $paginatedProducts = $collection->slice($offset, $perPage);
        $total = $collection->count();
        $lastPage = ceil($total / $perPage);

        // Format pagination data
        $pagination = [
            'current_page' => $currentPage,
            'total_pages' => $lastPage,
            'total_items' => $total,
            'has_next' => $currentPage < $lastPage,
            'has_prev' => $currentPage > 1
        ];

        return response()->json([
            'success' => true,
            'products' => $paginatedProducts->values(),
            'pagination' => $pagination
        ]);
    }

    public function checkDependencies(Client $client): JsonResponse
    {
        $counts = [
            'orders_count' => $client->orders()->count(),
            'tasks_count' => $client->tasks()->count(),
            'invoices_count' => $client->invoices()->count(),
            'purchases_count' => Purchase::query()->where('client_id', $client->id)->count(),
        ];

        return response()->json(['has_links' => array_sum($counts) > 0] + $counts);
    }

    public function replacements(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $clients = Client::admin()->with('city')
            ->when($request->filled('exclude'), fn ($query) => $query->where('id', '!=', $request->integer('exclude')))
            ->when($search !== '', function ($query) use ($search) {
                $this->applySearch($query, $search);
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->paginate(15);

        $items = $clients->getCollection()->map(fn (Client $client) => [
                'id' => $client->id,
                'name' => trim($client->first_name . ' ' . $client->last_name),
                'city' => $client->city?->name ?? '',
            ]);

        return response()->json([
            'clients' => $items,
            'pagination' => [
                'current_page' => $clients->currentPage(),
                'last_page' => $clients->lastPage(),
                'per_page' => $clients->perPage(),
                'total' => $clients->total(),
                'has_more' => $clients->hasMorePages(),
            ],
        ]);
    }

    public function destroyWithReplacement(Request $request, Client $client): JsonResponse
    {
        $dependencies = [
            $client->orders()->count(),
            $client->tasks()->count(),
            $client->invoices()->count(),
            Purchase::query()->where('client_id', $client->id)->count(),
        ];
        $hasLinks = array_sum($dependencies) > 0;
        $replacementId = $request->integer('replacement_client_id') ?: null;

        if ($hasLinks) {
            $request->validate(['replacement_client_id' => ['required', 'integer', 'exists:clients,id']]);
            if ($replacementId === $client->id) {
                return response()->json(['message' => 'Le client de remplacement doit etre different.'], 422);
            }
        }

        DB::transaction(function () use ($client, $replacementId, $hasLinks) {
            if ($hasLinks) {
                Order::query()->where('client_id', $client->id)->update(['client_id' => $replacementId]);
                Task::query()->where('client_id', $client->id)->update(['client_id' => $replacementId]);
                Invoice::query()->where('client_id', $client->id)->update(['client_id' => $replacementId]);
                Purchase::query()->where('client_id', $client->id)->update(['client_id' => $replacementId]);
            }

            $image = $client->image;
            $client->delete();
            $this->deleteMediaImage($image?->image_name);
            $image?->delete();
        });

        return response()->json(['message' => 'Client supprime avec succes.']);
    }

    public function storeCity(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255', 'unique:cities,name']]);
        return response()->json(['city' => City::create($data)], 201);
    }

    public function updateCity(Request $request, City $city): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255', 'unique:cities,name,' . $city->id]]);
        $city->update($data);
        return response()->json(['city' => $city->fresh()]);
    }

    private function validateClient(Request $request): array
    {
        return $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'fixed_phone_number' => ['nullable', 'string', 'max:20'],
            'ice' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'max:2048'],
        ]);
    }

    private function applySearch($query, string $search): void
    {
        $terms = preg_split('/\s+/u', strtolower(trim($search)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach (array_slice($terms, 0, 10) as $term) {
            $like = '%' . $term . '%';

            $query->where(function ($inner) use ($like) {
                $inner->whereRaw('LOWER(TRIM(first_name)) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(TRIM(last_name)) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                    ->orWhere('phone_number', 'like', $like)
                    ->orWhere('fixed_phone_number', 'like', $like)
                    ->orWhereHas('city', fn ($cityQuery) => $cityQuery->whereRaw('LOWER(name) LIKE ?', [$like]));
            });
        }
    }

    private function validateWebshopData(Request $request): array
    {
        return $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
        ]);
    }

    private function serializeClient(Client $client, bool $includeWebshop = false): array
    {
        $data = [
            'id' => $client->id,
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'name' => trim($client->first_name . ' ' . $client->last_name),
            'email' => $client->email,
            'phone_number' => $client->phone_number,
            'fixed_phone_number' => $client->fixed_phone_number,
            'ice' => $client->ice,
            'address' => $client->address,
            'description' => $client->description,
            'city_id' => $client->city_id,
            'city' => $client->city?->name,
            'source' => $client->source,
            'validated' => (bool) $client->validated,
            'handled' => (bool) $client->handled,
            'unpaid_orders_count' => (int) ($client->unpaid_orders_count ?? 0),
            'image_url' => $client->image ? MediaUrl::image($client->image->image_name) : null,
            'created_at' => $client->created_at?->toISOString(),
            'updated_at' => $client->updated_at?->toISOString(),
        ];

        if ($includeWebshop) {
            $data['webshop_data'] = $client->webshopData ? [
                'first_name' => $client->webshopData->first_name,
                'last_name' => $client->webshopData->last_name,
                'phone_number' => $client->webshopData->phone_number,
                'address' => $client->webshopData->address,
                'city_id' => $client->webshopData->city_id,
                'city' => $client->webshopData->city?->name,
            ] : null;
        }

        return $data;
    }

    private function pagination($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    private function storeImage(Request $request): int
    {
        $file = $request->file('image');
        $serviceUrl = rtrim((string) config('media.service_url'), '/');
        $token = (string) config('media.token');
        abort_if($serviceUrl === '' || $token === '', 503, 'Le service media est mal configure.');

        $response = Http::timeout(30)->withToken($token)
            ->attach('file', fopen($file->getRealPath(), 'r'), $file->getClientOriginalName())
            ->post($serviceUrl . '/api/v1/files', [
                'directory' => 'clients',
                'visibility' => 'public',
                'uploaded_by_app' => 'crm',
            ]);
        abort_unless($response->successful() && $response->json('data.path'), 502, 'Impossible d enregistrer l image.');

        return Image::create(['image_name' => $response->json('data.path')])->id;
    }

    private function deleteMediaImage(?string $path): void
    {
        if (! $path || ! config('media.service_url') || ! config('media.token')) {
            return;
        }
        Http::timeout(15)->withToken((string) config('media.token'))
            ->delete(rtrim((string) config('media.service_url'), '/') . '/api/v1/files/' . implode('/', array_map('rawurlencode', explode('/', trim($path, '/')))));
    }
}
