<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Requests\Catalog\CategoryIndexRequest;
use App\Http\Requests\Catalog\ProductIndexRequest;
use App\Http\Requests\Customers\CustomerIndexRequest;
use App\Http\Requests\IndexRequest;
use App\Http\Requests\Inventory\InventoryIndexRequest;
use App\Http\Requests\Orders\OrderIndexRequest;
use PHPUnit\Framework\TestCase;

class IndexRequestPaginationTest extends TestCase
{
    public function test_all_index_requests_share_centralized_pagination_rules(): void
    {
        $requests = [
            CategoryIndexRequest::class,
            ProductIndexRequest::class,
            CustomerIndexRequest::class,
            InventoryIndexRequest::class,
            OrderIndexRequest::class,
        ];

        foreach ($requests as $requestClass) {
            $request = new $requestClass;

            $this->assertInstanceOf(IndexRequest::class, $request);
            $this->assertSame(IndexRequest::paginationRules(), $request->rules()['per_page']);
            $this->assertSame(IndexRequest::DEFAULT_PER_PAGE, $request->perPage());
        }
    }
}
