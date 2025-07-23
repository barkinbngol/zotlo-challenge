<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    protected function unauthenticated($request, AuthenticationException $exception)
    {
        return response()->json(['error' => 'Unauthenticated.'], 401);
    }

    public function register(): void
    {
        $this->reportable(function (\App\Exceptions\ZotloRequestFailed $e) {
            Log::warning('Zotlo API error', [
                'http' => $e->httpStatus,
                'message' => $e->getMessage(),
            ]);
        });

        $this->renderable(function (\App\Exceptions\ZotloRequestFailed $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => 'zotlo_request_failed',
                    'message' => $e->getMessage(),
                ], $e->httpStatus);
            }
        });
    }
}
