<?php

namespace App\Repositories;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardRepository
{
    protected string $revenueCol = 'transaction_details.subtotal';

    protected string $qtyCol = 'transaction_details.qty';

    private function applyCategoryFilter(mixed $query, ?int $categoryId, string $column = 'products.category_id'): mixed
    {
        if ($categoryId) {
            $query->where($column, $categoryId);
        }

        return $query;
    }

    /**
     * @return array<int, int>
     */
    public function getAvailableYears(): array
    {
        return DB::table('transactions')->selectRaw('YEAR(trx_date) as year')->distinct()->orderBy('year', 'desc')->pluck('year')->toArray();
    }

    public function getCategoriesList(): Collection
    {
        return DB::table('categories')
            ->whereNull('deleted_at')
            ->select('id', 'name')
            ->get();
    }

    public function getTotalTransactionsSince(Carbon $startDate): int
    {
        return DB::table('transactions')->where('trx_date', '>=', $startDate)->count('id');
    }

    public function getLatestTransactionDate(): ?Carbon
    {
        $latestDate = DB::table('transactions')->max('trx_date');

        return $latestDate ? Carbon::parse($latestDate) : null;
    }

    public function getTotalTransactionsBetween(Carbon $startDate, Carbon $endDate): int
    {
        return DB::table('transactions')
            ->whereBetween('trx_date', [$startDate, $endDate])
            ->count('id');
    }

    public function getInventoryUsageSince(Carbon $startDate): Collection
    {
        return DB::table('transaction_details')
            ->join('transactions', 'transaction_details.transaction_id', '=', 'transactions.id')
            ->join('product_inventory', 'transaction_details.product_id', '=', 'product_inventory.product_id')
            ->where('transactions.trx_date', '>=', $startDate)
            ->select(
                'product_inventory.inventory_id',
                DB::raw('SUM(transaction_details.qty * product_inventory.usage_qty) as total_usage')
            )
            ->groupBy('product_inventory.inventory_id')
            ->get();
    }

    public function getInventoryUsageBetween(Carbon $startDate, Carbon $endDate): Collection
    {
        return DB::table('transaction_details')
            ->join('transactions', 'transaction_details.transaction_id', '=', 'transactions.id')
            ->join('product_inventory', 'transaction_details.product_id', '=', 'product_inventory.product_id')
            ->whereBetween('transactions.trx_date', [$startDate, $endDate])
            ->select(
                'product_inventory.inventory_id',
                DB::raw('SUM(transaction_details.qty * product_inventory.usage_qty) as total_usage')
            )
            ->groupBy('product_inventory.inventory_id')
            ->get();
    }

    public function getAllInventories(): Collection
    {
        return DB::table('inventories')->get();
    }

    public function getKpiStats(Carbon $startDate, Carbon $endDate, array $excludeCategories = [], ?int $categoryId = null): ?object
    {
        if (empty($excludeCategories) && ! $categoryId) {
            return DB::table('transactions')
                ->whereBetween('trx_date', [$startDate, $endDate])
                ->selectRaw('SUM(total_amount) as total_revenue, SUM(total_cogs) as total_cogs, SUM(net_profit) as net_profit, COUNT(id) as total_count')
                ->first();
        } else {
            $query = DB::table('transaction_details')
                ->join('transactions', 'transaction_details.transaction_id', '=', 'transactions.id')
                ->join('products', 'transaction_details.product_id', '=', 'products.id')
                ->whereBetween('transactions.trx_date', [$startDate, $endDate]);

            if (! empty($excludeCategories)) {
                $query->whereNotIn('products.category_id', $excludeCategories);
            }
            $this->applyCategoryFilter($query, $categoryId);

            return $query->selectRaw('SUM(transaction_details.subtotal) as total_revenue, SUM(transaction_details.subtotal_cogs) as total_cogs, COUNT(DISTINCT transactions.id) as total_count')->first();
        }
    }

    public function getChartData(Carbon $startDate, Carbon $endDate, string $period, ?int $categoryId = null): Collection
    {
        $query = DB::table('transaction_details')
            ->join('transactions', 'transaction_details.transaction_id', '=', 'transactions.id')
            ->join('products', 'transaction_details.product_id', '=', 'products.id')
            ->whereBetween('transactions.trx_date', [$startDate, $endDate]);

        $this->applyCategoryFilter($query, $categoryId);

        return $query->select(
            'products.category_id',
            DB::raw("SUM({$this->revenueCol}) as total_revenue"),
            DB::raw($period === 'year' ? 'MONTH(transactions.trx_date) as time_unit' : 'DAY(transactions.trx_date) as time_unit')
        )->groupBy('products.category_id', 'time_unit')->get();
    }

    public function getLatestTransactions(Carbon $startDate, Carbon $endDate, array $excludeCategories = [], ?int $categoryId = null): Collection
    {
        $query = DB::table('transaction_details')
            ->join('transactions', 'transaction_details.transaction_id', '=', 'transactions.id')
            ->join('products', 'transaction_details.product_id', '=', 'products.id')
            ->whereBetween('transactions.trx_date', [$startDate, $endDate]);

        if (! empty($excludeCategories)) {
            $query->whereNotIn('products.category_id', $excludeCategories);
        }
        $this->applyCategoryFilter($query, $categoryId);

        return $query->select('transactions.id', 'transactions.receipt_no', DB::raw("SUM({$this->revenueCol}) as total_amount"))
            ->groupBy('transactions.id', 'transactions.receipt_no')
            ->orderByRaw('MAX(transactions.trx_date) DESC')
            ->limit(10)->get();
    }

    public function getTransactionsForDrillThrough(Carbon $startDate, Carbon $endDate, ?int $categoryId = null): Collection
    {
        $query = DB::table('transaction_details')
            ->join('transactions', 'transaction_details.transaction_id', '=', 'transactions.id')
            ->join('products', 'transaction_details.product_id', '=', 'products.id')
            ->whereBetween('transactions.trx_date', [$startDate, $endDate]);

        $this->applyCategoryFilter($query, $categoryId);

        return $query->select(
            'transactions.id',
            'transactions.receipt_no',
            'transactions.trx_date',
            'transactions.payment_method',
            DB::raw("SUM({$this->revenueCol}) as total_amount")
        )
            ->groupBy('transactions.id', 'transactions.receipt_no', 'transactions.trx_date', 'transactions.payment_method')
            ->orderByDesc('transactions.trx_date')
            ->limit(25)
            ->get();
    }

    public function getTopProducts(Carbon $startDate, Carbon $endDate, array $excludeCategories = [], ?int $categoryId = null): Collection
    {
        $query = DB::table('transaction_details')
            ->join('transactions', 'transaction_details.transaction_id', '=', 'transactions.id')
            ->leftJoin('products', 'transaction_details.product_id', '=', 'products.id')
            ->whereBetween('transactions.trx_date', [$startDate, $endDate]);

        if (! empty($excludeCategories)) {
            $query->whereNotIn('products.category_id', $excludeCategories);
        }
        $this->applyCategoryFilter($query, $categoryId);

        return $query->select(
                DB::raw("COALESCE(products.name, transaction_details.product_name) as name"),
                DB::raw("SUM({$this->qtyCol}) as total_qty"),
                DB::raw("SUM({$this->revenueCol}) as total_revenue")
            )
            ->groupBy('transaction_details.product_id', DB::raw("COALESCE(products.name, transaction_details.product_name)"))
            ->orderBy('total_qty', 'desc')
            ->limit(5)->get();
    }

    public function getTransactionById(int $id): ?object
    {
        return DB::table('transactions')->where('id', $id)->first();
    }

    public function getTransactionDetails(int $transactionId): Collection
    {
        return DB::table('transaction_details')
            ->leftJoin('products', 'transaction_details.product_id', '=', 'products.id')
            ->select(
                DB::raw('COALESCE(products.name, transaction_details.product_name) as name'),
                'transaction_details.qty',
                DB::raw('(transaction_details.subtotal / transaction_details.qty) as price'),
                'transaction_details.subtotal'
            )
            ->where('transaction_details.transaction_id', $transactionId)
            ->get();
    }

    public function getCategoryProportions(Carbon $startDate, Carbon $endDate, array $excludeCategories = [], ?int $categoryId = null): Collection
    {
        $query = DB::table('transaction_details')
            ->join('transactions', 'transaction_details.transaction_id', '=', 'transactions.id')
            ->join('products', 'transaction_details.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->whereBetween('transactions.trx_date', [$startDate, $endDate]);

        if (! empty($excludeCategories)) {
            $query->whereNotIn('products.category_id', $excludeCategories);
        }
        $this->applyCategoryFilter($query, $categoryId);

        return $query->select('categories.name as label', DB::raw("SUM({$this->qtyCol}) as value"))
            ->groupBy('categories.id', 'categories.name')
            ->get();
    }

    public function getDailyRevenue(Carbon $startDate, Carbon $endDate, ?int $categoryId = null): Collection
    {
        $daysMap = [
            1 => 'Sunday',
            2 => 'Monday',
            3 => 'Tuesday',
            4 => 'Wednesday',
            5 => 'Thursday',
            6 => 'Friday',
            7 => 'Saturday'
        ];

        if (! $categoryId) {
            $data = DB::table('transactions')
                ->whereBetween('trx_date', [$startDate, $endDate])
                ->selectRaw('DAYOFWEEK(trx_date) as day_num, SUM(total_amount) as total')
                ->groupBy('day_num')
                ->orderBy('day_num')
                ->get();
        } else {
            $data = DB::table('transaction_details')
                ->join('transactions', 'transaction_details.transaction_id', '=', 'transactions.id')
                ->join('products', 'transaction_details.product_id', '=', 'products.id')
                ->whereBetween('transactions.trx_date', [$startDate, $endDate])
                ->where('products.category_id', $categoryId)
                ->selectRaw('DAYOFWEEK(transactions.trx_date) as day_num, SUM(transaction_details.subtotal) as total')
                ->groupBy('day_num')
                ->orderBy('day_num')
                ->get();
        }

        return $data->map(function ($row) use ($daysMap) {
            $row->day_name = $daysMap[$row->day_num] ?? 'Unknown';
            return $row;
        });
    }

    public function getPeakHours(Carbon $startDate, Carbon $endDate, ?int $categoryId = null): Collection
    {
        $daysMap = [
            1 => 'Sunday',
            2 => 'Monday',
            3 => 'Tuesday',
            4 => 'Wednesday',
            5 => 'Thursday',
            6 => 'Friday',
            7 => 'Saturday'
        ];

        if (! $categoryId) {
            $data = DB::table('transactions')
                ->whereBetween('trx_date', [$startDate, $endDate])
                ->whereNotNull('trx_date')
                ->selectRaw('DAYOFWEEK(trx_date) as day_num, HOUR(trx_date) as hour, COUNT(id) as total_trx')
                ->groupBy('day_num', 'hour')
                ->havingRaw('day_num IS NOT NULL AND hour IS NOT NULL')
                ->get();
        } else {
            $data = DB::table('transaction_details')
                ->join('transactions', 'transaction_details.transaction_id', '=', 'transactions.id')
                ->join('products', 'transaction_details.product_id', '=', 'products.id')
                ->whereBetween('transactions.trx_date', [$startDate, $endDate])
                ->whereNotNull('transactions.trx_date')
                ->where('products.category_id', $categoryId)
                ->selectRaw('DAYOFWEEK(transactions.trx_date) as day_num, HOUR(transactions.trx_date) as hour, COUNT(DISTINCT transactions.id) as total_trx')
                ->groupBy('day_num', 'hour')
                ->havingRaw('day_num IS NOT NULL AND hour IS NOT NULL')
                ->get();
        }

        return $data->map(function ($row) use ($daysMap) {
            $row->day_num = (int) $row->day_num;
            $row->hour = (int) $row->hour;
            $row->total_trx = (int) $row->total_trx;
            $row->day_name = $daysMap[$row->day_num] ?? 'Unknown';
            return $row;
        });
    }

    public function getStackedCategoryTrend(Carbon $startDate, Carbon $endDate, string $period, ?int $categoryId = null): Collection
    {
        $timeUnit = $period === 'year' ? 'MONTH(transactions.trx_date)' : 'DAY(transactions.trx_date)';

        $query = DB::table('transaction_details')
            ->join('transactions', 'transaction_details.transaction_id', '=', 'transactions.id')
            ->join('products', 'transaction_details.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->whereBetween('transactions.trx_date', [$startDate, $endDate]);

        $this->applyCategoryFilter($query, $categoryId);

        return $query->selectRaw("categories.name as category_name, {$timeUnit} as time_unit, SUM({$this->revenueCol}) as total_revenue")
            ->groupBy('categories.id', 'categories.name', 'time_unit')
            ->get();
    }

    public function getMarketBasket(Carbon $startDate, Carbon $endDate, ?int $categoryId = null): Collection
    {
        $query = DB::table('transaction_details as td1')
            ->join('transaction_details as td2', function ($join) {
                $join->on('td1.transaction_id', '=', 'td2.transaction_id')
                    ->whereRaw('td1.product_id < td2.product_id');
            })
            ->join('products as p1', 'td1.product_id', '=', 'p1.id')
            ->join('products as p2', 'td2.product_id', '=', 'p2.id')
            ->join('transactions as trx', 'td1.transaction_id', '=', 'trx.id')
            ->whereBetween('trx.trx_date', [$startDate, $endDate]);

        if ($categoryId) {
            $query->where(function ($inner) use ($categoryId) {
                $inner->where('p1.category_id', $categoryId)
                    ->orWhere('p2.category_id', $categoryId);
            });
        }

        return $query->select('p1.name as product_a', 'p2.name as product_b', DB::raw('COUNT(DISTINCT td1.transaction_id) as times_bought_together'))
            ->groupBy('product_a', 'product_b')
            ->orderByDesc('times_bought_together')
            ->limit(5)
            ->get();
    }

    /**
     * @return array{total_trx: int, top_items: Collection, market_basket: Collection}
     */
    public function getPeakHourDrillDown(Carbon $startDate, Carbon $endDate, mixed $dayName, mixed $hour, ?int $categoryId = null): array
    {
        $daysOfWeekMap = [
            'Sunday' => 1,
            'Monday' => 2,
            'Tuesday' => 3,
            'Wednesday' => 4,
            'Thursday' => 5,
            'Friday' => 6,
            'Saturday' => 7,
        ];
        $dayNum = $daysOfWeekMap[$dayName] ?? null;

        $trxQuery = DB::table('transactions')
            ->whereBetween('trx_date', [$startDate, $endDate])
            ->whereRaw('HOUR(trx_date) = ?', [$hour]);

        if ($dayNum !== null) {
            $trxQuery->whereRaw('DAYOFWEEK(trx_date) = ?', [$dayNum]);
        } else {
            $trxQuery->whereRaw('DAYNAME(trx_date) = ?', [$dayName]); // Fallback
        }

        if ($categoryId) {
            $trxQuery->whereExists(function ($subquery) use ($categoryId) {
                $subquery->select(DB::raw(1))
                    ->from('transaction_details')
                    ->join('products', 'transaction_details.product_id', '=', 'products.id')
                    ->whereColumn('transaction_details.transaction_id', 'transactions.id')
                    ->where('products.category_id', $categoryId);
            });
        }

        $trxCount = $trxQuery->count('id');

        $topItemsQuery = DB::table('transaction_details')
            ->join('transactions', 'transaction_details.transaction_id', '=', 'transactions.id')
            ->join('products', 'transaction_details.product_id', '=', 'products.id')
            ->whereBetween('transactions.trx_date', [$startDate, $endDate])
            ->whereRaw('HOUR(transactions.trx_date) = ?', [$hour]);

        if ($dayNum !== null) {
            $topItemsQuery->whereRaw('DAYOFWEEK(transactions.trx_date) = ?', [$dayNum]);
        } else {
            $topItemsQuery->whereRaw('DAYNAME(transactions.trx_date) = ?', [$dayName]); // Fallback
        }

        $this->applyCategoryFilter($topItemsQuery, $categoryId);

        $topItems = $topItemsQuery
            ->select('products.name', DB::raw('SUM(transaction_details.qty) as total_qty'))
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_qty')
            ->limit(3)
            ->get();

        $marketBasketQuery = DB::table('transaction_details as td1')
            ->join('transaction_details as td2', function ($join) {
                $join->on('td1.transaction_id', '=', 'td2.transaction_id')
                    ->whereRaw('td1.product_id < td2.product_id');
            })
            ->join('products as p1', 'td1.product_id', '=', 'p1.id')
            ->join('products as p2', 'td2.product_id', '=', 'p2.id')
            ->join('transactions as trx', 'td1.transaction_id', '=', 'trx.id')
            ->whereBetween('trx.trx_date', [$startDate, $endDate])
            ->whereRaw('HOUR(trx.trx_date) = ?', [$hour]);

        if ($dayNum !== null) {
            $marketBasketQuery->whereRaw('DAYOFWEEK(trx.trx_date) = ?', [$dayNum]);
        } else {
            $marketBasketQuery->whereRaw('DAYNAME(trx.trx_date) = ?', [$dayName]); // Fallback
        }

        if ($categoryId) {
            $marketBasketQuery->where(function ($inner) use ($categoryId) {
                $inner->where('p1.category_id', $categoryId)
                    ->orWhere('p2.category_id', $categoryId);
            });
        }

        $marketBasket = $marketBasketQuery
            ->select('p1.name as product_a', 'p2.name as product_b', DB::raw('COUNT(DISTINCT td1.transaction_id) as times_bought_together'))
            ->groupBy('product_a', 'product_b')
            ->orderByDesc('times_bought_together')
            ->limit(2)
            ->get();

        return [
            'total_trx' => $trxCount,
            'top_items' => $topItems,
            'market_basket' => $marketBasket,
        ];
    }

    public function getWasteLoss(Carbon $startDate, Carbon $endDate): float
    {
        return (float) DB::table('inventory_waste_logs')
            ->whereBetween('logged_at', [$startDate, $endDate])
            ->sum('total_loss');
    }

    public function getOpnameLoss(Carbon $startDate, Carbon $endDate): float
    {
        // Sum only negative adjustments (representing shrinkage/loss) as a positive cost value
        $val = DB::table('stock_opnames')
            ->whereBetween('adjusted_at', [$startDate, $endDate])
            ->where('total_adjustment_value', '<', 0)
            ->sum('total_adjustment_value');

        return abs((float) $val);
    }
}
