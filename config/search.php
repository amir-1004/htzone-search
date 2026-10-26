<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Results
    |--------------------------------------------------------------------------
    */

    'per_page' => 12,

    // Hard cap on ranked results, so a vague query never returns the whole catalog.
    'max_results' => 60,

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | Product vectors are computed once by `php artisan catalog:embed` and
    | stored in the products table. Each search embeds only the query.
    | 512 dimensions keep the in-memory comparison fast (see decisions #5).
    |
    */

    'embeddings' => [
        'model' => env('EMBEDDING_MODEL', 'text-embedding-3-large'),
        'dimensions' => 512,
        'query_timeout' => 2.0,
        'batch_timeout' => 60.0,
        'batch_size' => 100,
        'query_cache_ttl' => 60 * 60 * 24,
    ],

    // Store for the search index (tokens + packed vectors). Binary-safe and local.
    'index_cache_store' => env('SEARCH_INDEX_CACHE_STORE', 'file'),

    /*
    |--------------------------------------------------------------------------
    | Ranking
    |--------------------------------------------------------------------------
    |
    | A product is a candidate if it passes either channel's threshold.
    | Candidates are merged with Reciprocal Rank Fusion. Below both
    | thresholds the UI shows "no results". Thresholds are calibrated
    | with `php artisan search:eval` against tests/golden/queries.json.
    |
    */

    // Calibrated on the golden set (search:eval): gibberish tops out at 0.27,
    // the weakest real query (a bare brand name) starts at 0.41.
    'vector_min_similarity' => (float) env('SEARCH_VECTOR_MIN_SIMILARITY', 0.30),

    // Keep only vector hits close to the best one. Average similarity to an
    // unrelated product is 0.25-0.33, so an absolute cutoff alone lets in
    // hundreds of weak matches.
    'vector_relative_margin' => (float) env('SEARCH_VECTOR_RELATIVE_MARGIN', 0.10),

    // Fraction of the query's terms a product must match to count as a keyword hit.
    'keyword_min_coverage' => 0.5,

    'rrf_k' => 60,

    'field_weights' => [
        'name' => 3.0,
        'brand' => 3.0,
        'subcategory' => 2.0,
        'category' => 1.5,
        'specs' => 1.0,
        'description' => 1.0,
    ],

    // Filler words that carry no product meaning (normalized form).
    'stopwords' => [
        'של', 'את', 'עם', 'על', 'לי', 'לו', 'לה', 'זה', 'גם', 'או', 'אני', 'אנחנו',
        'משהו', 'מישהו', 'למישהו', 'שיעזור', 'שיעזרו', 'שאוהב', 'שאוהבת', 'רוצה', 'מחפש', 'מחפשת',
        'צריך', 'צריכה', 'טוב', 'הכי', 'בשביל', 'עבור', 'כמו', 'יש', 'אין', 'מה', 'איזה',
        // Generic nouns that describe "a product", not which one.
        'ציוד', 'מוצר', 'מוצרים', 'דברים', 'אביזר', 'אביזרים',
        'a', 'an', 'the', 'for', 'of', 'to', 'with', 'and', 'or', 'something', 'someone', 'who', 'loves', 'i', 'my',
    ],

    /*
    |--------------------------------------------------------------------------
    | Aliases
    |--------------------------------------------------------------------------
    |
    | Terms users type that never appear in the catalog. Each key (a single
    | normalized token) expands into catalog terms; a product scores higher
    | the more of them it matches. Found by searching the catalog, see
    | docs/decisions.md ("findings").
    |
    */

    'aliases' => [
        'אייפון' => 'apple סמארטפון',
        'iphone' => 'apple סמארטפון',
        'אייפד' => 'apple ipad',
        'מקבוק' => 'apple macbook',
        'איירפודס' => 'apple אוזניות',
        'airpods' => 'apple אוזניות',
        'גלקסי' => 'samsung סמארטפון',
        'galaxy' => 'samsung סמארטפון',
        'פלייסטיישן' => 'ps5 dualsense',
        'playstation' => 'ps5 dualsense',
        'דייסון' => 'dyson',
        'סמסונג' => 'samsung',
        'שיאומי' => 'xiaomi',
        'פיליפס' => 'philips',
        'נינג׳ה' => 'ninja',
        'קיצנאייד' => 'kitchenaid',
        'מטגן' => 'מטהג air fryer',
        'איירפרייר' => 'air fryer',
        'אקספרסו' => 'אספרסו',
        'לפטופ' => 'מחשב נייד',
        'laptop' => 'מחשב נייד',
        'headphones' => 'אוזניות',
        'vacuum' => 'שואב אבק',
    ],

];
