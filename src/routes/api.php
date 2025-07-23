<?php

use App\Http\Controllers\CardController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\Webhook\SubscriptionStatusController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

// REGISTER
Route::post('/register', function (Request $request) {
    $validator = Validator::make($request->all(), [
        'name' => 'required|string',
        'email' => 'required|email|unique:users',
        'password' => 'required|string|min:6',
    ]);

    if ($validator->fails()) {
        return response()->json($validator->errors(), 422);
    }

    $user = User::create([
        'name' => $request->name,
        'email' => $request->email,
        'password' => Hash::make($request->password),
    ]);

    $token = JWTAuth::fromUser($user);

    return response()->json(compact('user', 'token'));
});

// LOGIN
Route::post('/login', function (Request $request) {
    $credentials = $request->only('email', 'password');

    if (! $token = JWTAuth::attempt($credentials)) {
        return response()->json(['error' => 'Giriş başarısız'], 401);
    }

    return response()->json(compact('token'));
});

// PROTECTED ROUTES
Route::middleware('auth:api')->group(function () {

    Route::get('/me', fn () => response()->json(auth()->user()));

    // Subscription
    Route::post('/subscribe', [SubscriptionController::class, 'subscribe']);
    Route::get('/subscription/status', [SubscriptionController::class, 'checkStatus']);
    Route::post('/subscription/cancel', [SubscriptionController::class, 'unsubscribe']);

    // Saved cards
    Route::get('/cards', [CardController::class, 'list']);
});

// Webhook
Route::post('/webhook/zotlo', [SubscriptionStatusController::class, 'handleWebhook']);
