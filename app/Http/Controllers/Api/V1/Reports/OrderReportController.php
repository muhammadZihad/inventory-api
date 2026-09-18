<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reports;

use App\Contracts\OrderReports;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\OrderReportRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Operational order summary reporting.
 *
 * @group Reports
 */
class OrderReportController extends Controller
{
    /**
     * Order summary
     *
     * Return aggregate order counts and sales totals for the given filters.
     * Every figure comes from one grouped query, and the result is cached and
     * invalidated by any order write, so a dashboard polling this endpoint
     * does not re-scan the orders table on each request.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "success": true,
     *   "message": "Order report generated successfully.",
     *   "data": {
     *     "orders_count": 128,
     *     "total_sales": "48250.00",
     *     "average_order_value": "377.00",
     *     "pending_count": 12,
     *     "confirmed_count": 30,
     *     "completed_count": 80,
     *     "cancelled_count": 6
     *   }
     * }
     * @response 422 scenario="Invalid filter" {
     *   "success": false,
     *   "message": "The given data was invalid.",
     *   "errors": {"status": ["The selected status is invalid."]}
     * }
     */
    public function __invoke(OrderReportRequest $request, OrderReports $reports): JsonResponse
    {
        return ApiResponse::success(
            $reports->summary($request->validated()),
            'Order report generated successfully.',
        );
    }
}
