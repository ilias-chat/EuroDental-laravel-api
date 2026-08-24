<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Image;
use App\Models\Order;
use App\Models\Product;
use App\Models\SubCategory;
use App\Services\Media\MediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 15), 50));
        $products = $this->baseQuery($request)
            ->orderBy('product_name')
            ->paginate($perPage)
            ->withQueryString();

        $products->getCollection()->transform(fn (Product $product) => $this->serializeProduct($product));

        return response()->json([
            'products' => $products->items(),
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'from' => $products->firstItem(),
                'to' => $products->lastItem(),
            ],
        ]);
    }

    public function meta(): JsonResponse
    {
        return response()->json([
            'categories' => Category::orderBy('category')->get(['id', 'category']),
            'sub_categories' => SubCategory::orderBy('sub_category')->get(['id', 'category_id', 'sub_category']),
            'brands' => Brand::orderBy('brand')->get(['id', 'brand']),
        ]);
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'total_stock' => (int) Product::sum('stock_quantity'),
            'low_stock_count' => Product::where('stock_quantity', '<', 5)->count(),
            'stock_value' => (float) Product::query()->selectRaw('COALESCE(SUM(stock_quantity * price), 0) as value')->value('value'),
        ]);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load(['category', 'subCategory', 'brand', 'image']);

        return response()->json(['product' => $this->serializeProduct($product)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedProduct($request);
        $validated['slug'] = $this->uniqueSlug($validated['product_name']);
        $validated['show_price'] = false;
        $validated['visibility'] = 'offline';
        $validated['has_warranty'] = (bool) ($validated['has_warranty'] ?? false);
        $validated['warranty_duration_months'] = $validated['has_warranty'] ? ($validated['warranty_duration_months'] ?? null) : null;

        if ($request->hasFile('image')) {
            $validated['image_id'] = $this->storeImage($request);
        }

        $product = Product::create($validated);
        $product->detail()->create([]);
        $product->load(['category', 'subCategory', 'brand', 'image']);

        return response()->json(['product' => $this->serializeProduct($product)], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $validated = $this->validatedProduct($request, $product);
        $validated['has_warranty'] = (bool) ($validated['has_warranty'] ?? false);
        $validated['warranty_duration_months'] = $validated['has_warranty'] ? ($validated['warranty_duration_months'] ?? null) : null;

        if ($request->hasFile('image')) {
            $this->deleteMediaImage($product->image?->image_name);
            $validated['image_id'] = $this->storeImage($request);
        }

        $product->update($validated);
        if (! $product->detail) {
            $product->detail()->create([]);
        }
        $product->load(['category', 'subCategory', 'brand', 'image']);

        return response()->json(['product' => $this->serializeProduct($product)]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(['message' => 'Produit supprime.']);
    }

    public function updateStock(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate(['stock_quantity' => ['required', 'integer', 'min:0']]);
        $product->update(['stock_quantity' => $validated['stock_quantity']]);
        $product->load(['category', 'subCategory', 'brand', 'image']);

        return response()->json(['product' => $this->serializeProduct($product)]);
    }

    public function stockShortages(): JsonResponse
    {
        $orders = Order::where('status', 'processing')
            ->with(['orderItems.product.category', 'orderItems.product.brand', 'orderItems.product.image', 'orderItems.deliveryNoteItems'])
            ->get();

        $reservedByProduct = [];
        foreach ($orders as $order) {
            foreach ($order->orderItems as $item) {
                if (! $item->product) {
                    continue;
                }
                $undelivered = (int) $item->quantity - (int) $item->deliveryNoteItems->sum('delivered_quantity');
                if ($undelivered <= 0) {
                    continue;
                }
                $reservedByProduct[$item->product_id] ??= ['product' => $item->product, 'reserved' => 0];
                $reservedByProduct[$item->product_id]['reserved'] += $undelivered;
            }
        }

        $products = collect($reservedByProduct)
            ->filter(fn (array $row) => (int) $row['product']->stock_quantity < (int) $row['reserved'])
            ->map(function (array $row) {
                /** @var Product $product */
                $product = $row['product'];
                return [
                    'id' => $product->id,
                    'name' => $product->product_name,
                    'reference' => $product->reference ?? 'N/A',
                    'category' => $product->category?->category ?? 'N/A',
                    'category_id' => $product->id_category,
                    'brand' => $product->brand?->brand ?? 'N/A',
                    'brand_id' => $product->id_brand,
                    'current_stock' => (int) $product->stock_quantity,
                    'reserved_stock' => (int) $row['reserved'],
                    'shortage' => (int) $row['reserved'] - (int) $product->stock_quantity,
                    'image_url' => $product->image ? MediaUrl::image($product->image->image_name) : null,
                ];
            })
            ->sortBy('name')
            ->values();

        return response()->json(['count' => $products->count(), 'products' => $products]);
    }

    public function productOrders(Product $product): JsonResponse
    {
        $orders = Order::where('status', 'processing')
            ->whereHas('orderItems', fn ($query) => $query->where('product_id', $product->id))
            ->with([
                'orderItems' => fn ($query) => $query->where('product_id', $product->id)->with('deliveryNoteItems'),
                'client',
                'createdBy',
            ])
            ->get();

        $rows = collect();
        foreach ($orders as $order) {
            foreach ($order->orderItems as $item) {
                $delivered = (int) $item->deliveryNoteItems->sum('delivered_quantity');
                $undelivered = (int) $item->quantity - $delivered;

                if ($undelivered <= 0) {
                    continue;
                }

                $clientName = trim(($order->client?->first_name ?? '') . ' ' . ($order->client?->last_name ?? ''));
                $creatorName = trim(($order->createdBy?->first_name ?? '') . ' ' . ($order->createdBy?->last_name ?? ''));

                $rows->push([
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'order_status' => ucfirst((string) $order->status),
                    'order_date' => $order->created_at?->format('d/m/Y'),
                    'client_id' => $order->client_id,
                    'client_name' => $clientName !== '' ? $clientName : 'Client inconnu',
                    'created_by' => $creatorName !== '' ? $creatorName : 'Inconnu',
                    'quantity' => (int) $item->quantity,
                    'delivered_quantity' => $delivered,
                    'undelivered_quantity' => $undelivered,
                    'product_reference' => $product->reference ?? 'N/A',
                    'unit_price' => (float) $item->unit_price,
                ]);

                break;
            }
        }

        return response()->json(['orders' => $rows->sortByDesc('id')->values()]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['required', 'string', 'max:255', 'unique:categories,category'],
        ]);

        return response()->json(['category' => Category::create($validated)], 201);
    }

    public function updateCategory(Request $request, Category $category): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['required', 'string', 'max:255', 'unique:categories,category,' . $category->id],
        ]);

        $category->update($validated);

        return response()->json(['category' => $category->fresh()]);
    }

    public function destroyCategory(Category $category): JsonResponse
    {
        if ($category->subCategories()->exists()) {
            return response()->json(['message' => 'Impossible de supprimer la categorie car elle a des sous-categories assignees.'], 422);
        }

        if ($category->products()->exists()) {
            return response()->json(['message' => 'Impossible de supprimer la categorie car elle est assignee a des produits.'], 422);
        }

        $category->delete();

        return response()->json(['message' => 'Categorie supprimee.']);
    }

    public function storeSubCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sub_category' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'exists:categories,id'],
        ]);
        $this->validateUniqueSubCategory($validated['sub_category'], (int) $validated['category_id']);

        return response()->json(['sub_category' => SubCategory::create($validated)], 201);
    }

    public function updateSubCategory(Request $request, SubCategory $subCategory): JsonResponse
    {
        $validated = $request->validate([
            'sub_category' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'exists:categories,id'],
        ]);
        $this->validateUniqueSubCategory($validated['sub_category'], (int) $validated['category_id'], $subCategory->id);

        $subCategory->update($validated);

        return response()->json(['sub_category' => $subCategory->fresh()]);
    }

    public function destroySubCategory(SubCategory $subCategory): JsonResponse
    {
        if ($subCategory->products()->exists()) {
            return response()->json(['message' => 'Impossible de supprimer la sous-categorie car elle est assignee a des produits.'], 422);
        }

        $subCategory->delete();

        return response()->json(['message' => 'Sous-categorie supprimee.']);
    }

    public function storeBrand(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'brand' => ['required', 'string', 'max:255', 'unique:brands,brand'],
        ]);

        return response()->json(['brand' => Brand::create($validated)], 201);
    }

    public function updateBrand(Request $request, Brand $brand): JsonResponse
    {
        $validated = $request->validate([
            'brand' => ['required', 'string', 'max:255', 'unique:brands,brand,' . $brand->id],
        ]);

        $brand->update($validated);

        return response()->json(['brand' => $brand->fresh()]);
    }

    public function destroyBrand(Brand $brand): JsonResponse
    {
        if ($brand->products()->exists()) {
            return response()->json(['message' => 'Impossible de supprimer la marque car elle est assignee a des produits.'], 422);
        }

        $brand->delete();

        return response()->json(['message' => 'Marque supprimee.']);
    }

    private function baseQuery(Request $request)
    {
        return Product::with(['category', 'subCategory', 'brand', 'image'])
            ->when($request->category_id, fn ($query, $value) => $query->where('id_category', $value))
            ->when($request->brand_id, fn ($query, $value) => $query->where('id_brand', $value))
            ->when($request->reference, fn ($query, $value) => $query->where('reference', 'like', "%{$value}%"))
            ->when($request->product_name, fn ($query, $value) => $query->where('product_name', 'like', "%{$value}%"))
            ->when($request->visibility, fn ($query, $value) => $query->where('visibility', $value));
    }

    private function validatedProduct(Request $request, ?Product $product = null): array
    {
        $id = $product?->id;

        return $request->validate([
            'product_name' => ['required', 'string', 'max:255'],
            'id_category' => ['required', 'exists:categories,id'],
            'id_sub_category' => ['nullable', 'exists:sub_categories,id'],
            'id_brand' => ['required', 'exists:brands,id'],
            'reference' => ['nullable', 'string', 'max:100', 'unique:products,reference' . ($id ? ',' . $id : '')],
            'price' => ['required', 'numeric', 'min:0'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'has_warranty' => ['nullable', 'boolean'],
            'warranty_duration_months' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'max:2048'],
        ]);
    }

    private function storeImage(Request $request): int
    {
        $file = $request->file('image');
        $serviceUrl = rtrim((string) config('media.service_url'), '/');
        $token = (string) config('media.token');

        if ($serviceUrl === '' || $token === '') {
            abort(503, 'Le service media est mal configure.');
        }

        $response = Http::timeout(30)
            ->withToken($token)
            ->attach('file', fopen($file->getRealPath(), 'r'), $file->getClientOriginalName())
            ->post($serviceUrl . '/api/v1/files', [
                'directory' => 'products',
                'visibility' => 'public',
                'uploaded_by_app' => 'crm',
            ]);

        if (! $response->successful() || ! $response->json('data.path')) {
            abort(502, 'Impossible d enregistrer l image dans le service media.');
        }

        return Image::create(['image_name' => $response->json('data.path')])->id;
    }

    private function deleteMediaImage(?string $path): void
    {
        if (! $path || ! config('media.service_url') || ! config('media.token')) {
            return;
        }

        Http::timeout(15)
            ->withToken((string) config('media.token'))
            ->delete(rtrim((string) config('media.service_url'), '/') . '/api/v1/files/' . implode('/', array_map('rawurlencode', explode('/', trim($path, '/')))));
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $count = 1;

        while (Product::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $count++;
        }

        return $slug;
    }

    private function validateUniqueSubCategory(string $name, int $categoryId, ?int $exceptId = null): void
    {
        $exists = SubCategory::whereRaw('LOWER(sub_category) = ?', [Str::lower($name)])
            ->where('category_id', $categoryId)
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();

        if ($exists) {
            abort(422, 'Cette sous-categorie existe deja dans la categorie selectionnee.');
        }
    }

    private function serializeProduct(Product $product): array
    {
        return [
            'id' => $product->id,
            'product_name' => $product->product_name,
            'category_id' => $product->id_category,
            'sub_category_id' => $product->id_sub_category,
            'brand_id' => $product->id_brand,
            'category' => $product->category?->category,
            'sub_category' => $product->subCategory?->sub_category,
            'brand' => $product->brand?->brand,
            'reference' => $product->reference,
            'price' => (float) $product->price,
            'stock_quantity' => (int) $product->stock_quantity,
            'has_warranty' => (bool) $product->has_warranty,
            'warranty_duration_months' => $product->warranty_duration_months ? (int) $product->warranty_duration_months : null,
            'description' => $product->description,
            'visibility' => $product->visibility,
            'status' => $product->status,
            'image_url' => $product->image ? MediaUrl::image($product->image->image_name) : null,
            'created_at' => $product->created_at?->toISOString(),
        ];
    }
}
