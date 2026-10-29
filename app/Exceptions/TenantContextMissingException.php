<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Thrown when an operation requires an active tenant context but none is configured or available.
 */
class TenantContextMissingException extends \RuntimeException
{
    /**
     * Render the exception into an HTTP response.
     */
    public function render(Request $request): Response|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage() ?: 'No tenant context available.',
            ], 503);
        }

        return response()->view('errors.503', [
            'message' => $this->getMessage() ?: 'No tenant context available.',
        ], 503);
    }
}
