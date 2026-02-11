<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\SessionAuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\DataController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\MailOutboxController;
use App\Http\Controllers\Api\SmsOutboxController;
use App\Http\Controllers\GpsController;
use Laravel\Cashier\Http\Controllers\WebhookController;

Route::options('/{any}', function () {
    return response()->json(['ok' => true], 204);
})->where('any', '.*');

Route::get('/ping', function () {
    return response()->json([
        'ok'      => true,
        'message' => 'pong from Laravel API',
        'time'    => now()->toDateTimeString(),
    ]);
});

Route::post('/echo', function (Request $req) {
    return [
        "received" => $req->all(),
        "status" => "ok"
    ];
});

// --------------------
// ログイン不要
// --------------------
Route::post('/auth/setUserData',[UserController::class,'setUserData']);
Route::post('/auth/code',[UserController::class,'code']);
Route::post('/auth/passwordForget',[UserController::class,'passwordForget']);
Route::post('/auth/passwordChg',[UserController::class,'passwordChg']);
Route::post('/auth/done',[UserController::class,'done']);
Route::post('/auth/contact',[ContactController::class,'contact']);
Route::get('/auth/planList',[UserController::class,'plan']);
Route::get('/auth/paymentList',[UserController::class,'payment']);
Route::post('/mail/send-latest', [MailOutboxController::class, 'send']);
Route::post('/sms/send-latest', [SmsOutboxController::class, 'sendLatest']);
Route::post('/stripe/webhook', [WebhookController::class, 'handleWebhook']);

// --------------------
// セッション認証が必要（web を通す）
// --------------------
Route::middleware(['web'])->group(function () {

    // 認証
    Route::post('/auth/login',  [SessionAuthController::class, 'login']);
    Route::post('/auth/logout', [SessionAuthController::class, 'logout']);
    Route::get('/auth/me',      [SessionAuthController::class, 'me'])->middleware('auth');

    // ログイン必須（web + auth をここで統一）
    Route::middleware(['auth'])->group(function () {

        // billing もここへ（セッション前提なら）
        // Route::post('/billing/checkout', [\App\Http\Controllers\Api\BillingController::class, 'createCheckout']);
        Route::post('/billing/checkout', [BillingController::class, 'checkout']);

        Route::get('/getPlanData',[UserController::class,'planData']);
        Route::get('/getPlan',[UserController::class,'getPlan']);
        Route::post('/setPlan',[UserController::class,'setPlan']);

        Route::get('/getUserDevice', [UserController::class, 'userDevice']);

        Route::post('/userCheck',[UserController::class,'userCheck']);
        Route::post('/passwordCheck',[UserController::class,'passwordCheck']);

        Route::post('/setUserLocationInformation',[DataController::class,'userLocationInformation']);
        Route::get('/getMapUser',[DataController::class,'getMapUser']);
        Route::get('/getDevice',[DataController::class,'getDevice']);
        Route::get('/getBa',[DataController::class,'getBa']);
        Route::get('/getCar',[DataController::class,'getCar']);

        Route::post('/getdata',[DataController::class,'getdata']);
        Route::post('/baRename',[DataController::class,'baRename']);
        Route::post('/carRename',[DataController::class,'carRename']);
        Route::post('/baDelete',[DataController::class,'baDelete']);
        Route::post('/carDelete',[DataController::class,'carDelete']);

        Route::post('/groupCreate',[GroupController::class,'groupCreate']);
        Route::post('/groupEdit',[GroupController::class,'groupEdit']);
        Route::post('/groupJoin',[GroupController::class,'groupJoin']);

        Route::get('/groupList',[GroupController::class,'groupList']);
        Route::get('/getGroupData',[GroupController::class,'groupData']);
        Route::get('/getGroup',[GroupController::class,'getGroup']);
        Route::get('/getGroupUser',[GroupController::class,'groupUser']);
        Route::get('/getRequestGroups',[GroupController::class,'requestGroups']);
        Route::get('/getRequestGroupUser',[GroupController::class,'requestGroupUser']);
        Route::get('/getMemberList',[GroupController::class,'memberList']);

        Route::post('/protected',[GroupController::class,'protected']);
        Route::post('/updateObjectApproval',[GroupController::class,'approval']);
        Route::post('/requestCheck',[GroupController::class,'requestCheck']);

        Route::get('/setting',[UserController::class,'setting']);
        Route::get('/getUserSetting',[UserController::class,'userSetting']);
        Route::post('/settingUpdate',[UserController::class,'settingUpdate']);
        Route::post('/settingUpdateUser',[UserController::class,'settingUpdateUser']);
    });
});

Route::post('/gpsdata', [GpsController::class, 'store']);
