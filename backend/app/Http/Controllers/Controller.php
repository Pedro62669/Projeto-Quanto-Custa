<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Controller base.
 *
 * No Laravel 11+ esta classe nasce vazia — o trait AuthorizesRequests precisa
 * ser incluído explicitamente para habilitar $this->authorize() nas Policies.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
