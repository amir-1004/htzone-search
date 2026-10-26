<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Search\ProductSearch;
use App\Search\SearchResult;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class SearchController extends Controller
{
    public function __invoke(SearchRequest $request, ProductSearch $search): AnonymousResourceCollection
    {
        $query = $request->searchQuery();
        $page = $request->integer('page', 1);
        $perPage = config('search.per_page');

        $result = $query === ''
            ? new SearchResult(ids: [], mode: SearchResult::MODE_KEYWORD)
            : $search->search($query);

        $pageIds = array_slice($result->ids, ($page - 1) * $perPage, $perPage);
        $products = Product::findMany($pageIds)
            ->sortBy(fn (Product $product) => array_search($product->id, $pageIds))
            ->values();

        $paginator = new LengthAwarePaginator($products, count($result->ids), $perPage, $page, [
            'path' => $request->url(),
            'query' => $request->only('q'),
        ]);

        if ($query !== '') {
            Log::info('search', [
                'query' => mb_substr($query, 0, 200),
                'total' => count($result->ids),
                'page' => $page,
                'mode' => $result->mode,
                'timings' => $result->timings,
            ]);
        }

        return ProductResource::collection($paginator)->additional([
            'search' => [
                'query' => $query,
                'mode' => $result->mode,
                'match' => $result->match(),
                'took_ms' => $result->timings['total_ms'] ?? 0,
            ],
        ]);
    }
}
