<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Base controller providing policy authorization to API controllers.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
