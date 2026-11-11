<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Outside the "web" group: no session or CSRF. Each request is verified with the project's secret.
Route::post('webhooks/github/{projectId}', [WebhookController::class, 'github'])->whereNumber('projectId')->name('webhooks.github');
Route::post('webhooks/gitlab/{projectId}', [WebhookController::class, 'gitlab'])->whereNumber('projectId')->name('webhooks.gitlab');
