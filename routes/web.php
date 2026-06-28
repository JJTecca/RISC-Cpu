<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SimulatorController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// nu acum
// Route::get('/', function () {
//     return Inertia::render('Welcome', [
//         'canLogin' => Route::has('login'),
//         'canRegister' => Route::has('register'),
//         'laravelVersion' => Application::VERSION,
//         'phpVersion' => PHP_VERSION,
//     ]);
// });
Route::get('/', [SimulatorController::class, 'index'])->name('sim.index');
Route::post('/sim/load', [SimulatorController::class, 'load'])->name('sim.load');
Route::post('/sim/step', [SimulatorController::class, 'step'])->name('sim.step');
Route::post('/sim/reset', [SimulatorController::class, 'reset'])->name('sim.reset');

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
