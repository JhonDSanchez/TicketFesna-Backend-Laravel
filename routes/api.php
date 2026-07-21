<?php
use App\Http\Controllers\Api\MensajeController;
use App\Http\Controllers\Api\AreaController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UsuarioController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

Route::get('/dashboard', [DashboardController::class, 'summary']);
Route::get('/tickets', [TicketController::class, 'index']);
Route::post('/tickets', [TicketController::class, 'store']);
Route::get('/tickets/mine/{userId}', [TicketController::class, 'myTickets']);
Route::get('/tickets/area/{userId}', [TicketController::class, 'areaInbox']);
Route::put('/tickets/{ticketId}/area-update', [TicketController::class, 'updateAreaTicket']);
Route::post('/tickets/{ticketId}/report', [TicketController::class, 'reportTicket']);
Route::post('/tickets/{ticketId}/requester-decision', [TicketController::class, 'requesterDecision']);
Route::get('/tickets/{ticketId}/messages', [TicketController::class, 'messages']);
Route::post('/tickets/{ticketId}/messages', [TicketController::class, 'storeMessage']);
Route::get('/reports/misrouted', [TicketController::class, 'misroutedReports']);
Route::delete('/reports/misrouted/{reportId}', [TicketController::class, 'deleteMisroutedReport']);
Route::get('/users', [UsuarioController::class, 'index']);
Route::post('/users', [UsuarioController::class, 'store']);
Route::put('/users/{id}', [UsuarioController::class, 'update']);
Route::post('/users/{id}/reset-password', [UsuarioController::class, 'resetPassword']);
Route::get('/areas', [AreaController::class, 'index']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/change-password', [AuthController::class, 'changePassword']);
Route::post('/mensajes/enviar', [MensajeController::class, 'enviarMensaje']);