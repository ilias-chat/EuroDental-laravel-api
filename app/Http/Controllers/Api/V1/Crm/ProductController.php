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
use Illuminate\Support\Facades\Storage;
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
            if ($product->image?->image_name && Storage::disk('public')->exists($product->image->image_name)) {
                Storage::disk('public')->delete($product->image->image_name);
            }
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
                    'brand' => $product->brand?->brand ?? 'N/A',
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
        $path = $request->file('image')->store('products', 'public');

        return Image::create(['image_name' => $path])->id;
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
