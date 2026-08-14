<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    private const MONTHS = ['Jan', 'Fev', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Aout', 'Sep', 'Oct', 'Nov', 'Dec'];

    public function revenue(Request $request): JsonResponse
    {
        $year = (int) $request->query('year', date('Y'));

        try {
            $monthlyData = Order::selectRaw('MONTH(created_at) as month_num, SUM(total_with_tax) as revenue, COUNT(*) as order_count')
                ->whereYear('created_at', $year)
                ->whereIn('status', ['processing', 'shipped'])
                ->groupBy('month_num')
                ->orderBy('month_num')
                ->get()
                ->keyBy('month_num');

            $revenues = [];
            $orderCounts = [];

            for ($month = 1; $month <= 12; $month++) {
                $revenues[] = isset($monthlyData[$month]) ? (float) $monthlyData[$month]->revenue : 0;
                $orderCounts[] = isset($monthlyData[$month]) ? (int) $monthlyData[$month]->order_count : 0;
            }

            $totalRevenue = array_sum($revenues);

            return response()->json([
                'total_revenue' => $totalRevenue,
                'total_orders' => array_sum($orderCounts),
                'average_monthly' => $totalRevenue / 12,
                'year' => $year,
                'chart_data' => [
                    'months' => self::MONTHS,
                    'revenues' => $revenues,
                    'order_counts' => $orderCounts,
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('CRM dashboard revenue failed.', ['year' => $year, 'error' => $exception->getMessage()]);

            return response()->json([
                'total_revenue' => 0,
                'total_orders' => 0,
                'average_monthly' => 0,
                'year' => $year,
                'chart_data' => [
                    'months' => self::MONTHS,
                    'revenues' => array_fill(0, 12, 0),
                    'order_counts' => array_fill(0, 12, 0),
                ],
            ]);
        }
    }

    public function payments(Request $request): JsonResponse
    {
        $year = (int) $request->query('year', date('Y'));
        $month = (int) $request->query('month', date('n'));

        try {
            $orders = Order::with('payments')
                ->whereYear('created_at', $year)
                ->whereMonth('created_at', $month)
                ->whereIn('status', ['processing', 'shipped'])
                ->get();

            $totalRevenue = (float) $orders->sum('total_with_tax');
            $paidRevenue = (float) $orders->sum(fn (Order $order) => $order->payments->sum('amount'));
            $unpaidRevenue = max(0, $totalRevenue - $paidRevenue);

            return response()->json([
                'total_revenue' => $totalRevenue,
                'paid_revenue' => $paidRevenue,
                'unpaid_revenue' => $unpaidRevenue,
                'paid_percentage' => $totalRevenue > 0 ? ($paidRevenue / $totalRevenue) * 100 : 0,
                'unpaid_percentage' => $totalRevenue > 0 ? ($unpaidRevenue / $totalRevenue) * 100 : 0,
                'year' => $year,
                'month' => $month,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('CRM dashboard payments failed.', ['year' => $year, 'month' => $month, 'error' => $exception->getMessage()]);

            return response()->json([
                'total_revenue' => 0,
                'paid_revenue' => 0,
                'unpaid_revenue' => 0,
                'paid_percentage' => 0,
                'unpaid_percentage' => 0,
                'year' => $year,
                'month' => $month,
            ]);
        }
    }

    public function products(Request $request): JsonResponse
    {
        $year = (int) $request->query('year', date('Y'));
        $metric = $request->query('metric', 'units') === 'revenue' ? 'revenue' : 'units';
        $type = $request->query('type', 'top_products');
        $productId = $request->integer('product_id');

        if ($type === 'product_performance' && $productId) {
            return $this->productPerformance($year, $productId, $metric);
        }

        return $this->topProducts($year, $metric);
    }

    private function topProducts(int $year, string $metric): JsonResponse
    {
        try {
            $revenueExpression = 'SUM(order_items.quantity * order_items.unit_price)';
            $query = OrderItem::select(
                    'product_id',
                    DB::raw($revenueExpression . ' as total_revenue'),
                    DB::raw('SUM(quantity) as total_units'),
                    DB::raw('products.product_name as product_name')
                )
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->whereYear('orders.created_at', $year)
                ->whereIn('orders.status', ['processing', 'shipped'])
                ->groupBy('product_id', 'products.product_name')
                ->orderBy($metric === 'revenue' ? 'total_revenue' : 'total_units', 'desc')
                ->limit($metric === 'revenue' ? 12 : 10);

            $topProducts = $query->get();

            if ($topProducts->isEmpty()) {
                $topProducts = OrderItem::select(
                        'product_id',
                        DB::raw($revenueExpression . ' as total_revenue'),
                        DB::raw('SUM(quantity) as total_units'),
                        DB::raw('products.product_name as product_name')
                    )
                    ->join('orders', 'order_items.order_id', '=', 'orders.id')
                    ->join('products', 'order_items.product_id', '=', 'products.id')
                    ->whereIn('orders.status', ['processing', 'shipped'])
                    ->groupBy('product_id', 'products.product_name')
                    ->orderBy($metric === 'revenue' ? 'total_revenue' : 'total_units', 'desc')
                    ->limit(10)
                    ->get();
            }

            return response()->json([
                'top_products' => [
                    'product_names' => $topProducts->pluck('product_name')->values(),
                    'revenue_data' => $topProducts->pluck('total_revenue')->map(fn ($value) => (float) $value)->values(),
                    'units_sold' => $topProducts->pluck('total_units')->map(fn ($value) => (int) $value)->values(),
                    'product_ids' => $topProducts->pluck('product_id')->map(fn ($value) => (int) $value)->values(),
                ],
                'total_products' => Product::where('status', 'active')->count(),
                'total_units_sold' => (int) $topProducts->sum('total_units'),
                'total_revenue' => (float) $topProducts->sum('total_revenue'),
                'year' => $year,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('CRM dashboard products failed.', ['year' => $year, 'metric' => $metric, 'error' => $exception->getMessage()]);

            return response()->json([
                'top_products' => ['product_names' => [], 'revenue_data' => [], 'units_sold' => [], 'product_ids' => []],
                'total_products' => 0,
                'total_units_sold' => 0,
                'total_revenue' => 0,
                'year' => $year,
            ]);
        }
    }

    private function productPerformance(int $year, int $productId, string $metric): JsonResponse
    {
        try {
            $monthlyData = OrderItem::select(
                    DB::raw('MONTH(orders.created_at) as month_num'),
                    DB::raw('SUM(order_items.quantity) as units_sold'),
                    DB::raw('SUM(order_items.quantity * order_items.unit_price) as revenue')
                )
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->where('order_items.product_id', $productId)
                ->whereYear('orders.created_at', $year)
                ->whereIn('orders.status', ['processing', 'shipped'])
                ->groupBy('month_num')
                ->orderBy('month_num')
                ->get()
                ->keyBy('month_num');

            $unitsSold = [];
            $revenue = [];

            for ($month = 1; $month <= 12; $month++) {
                $unitsSold[] = isset($monthlyData[$month]) ? (int) $monthlyData[$month]->units_sold : 0;
                $revenue[] = isset($monthlyData[$month]) ? (float) $monthlyData[$month]->revenue : 0;
            }

            $product = Product::find($productId);

            return response()->json([
                'product_name' => $product?->product_name ?? 'Produit',
                'product_id' => $productId,
                'year' => $year,
                'metric' => $metric,
                'chart_data' => [
                    'months' => self::MONTHS,
                    'units_sold' => $unitsSold,
                    'revenue' => $revenue,
                ],
                'total_units' => array_sum($unitsSold),
                'total_revenue' => array_sum($revenue),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('CRM dashboard product performance failed.', ['year' => $year, 'product_id' => $productId, 'error' => $exception->getMessage()]);

            return response()->json([
                'product_name' => 'Produit',
                'product_id' => $productId,
                'year' => $year,
                'metric' => $metric,
                'chart_data' => [
                    'months' => self::MONTHS,
                    'units_sold' => array_fill(0, 12, 0),
                    'revenue' => array_fill(0, 12, 0),
                ],
                'total_units' => 0,
                'total_revenue' => 0,
            ]);
        }
    }
}
