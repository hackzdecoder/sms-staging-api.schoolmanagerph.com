<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\File;

// ============================================
// FIRST: Serve static assets with correct MIME types
// ============================================
Route::get('/assets/{path}', function ($path) {
  $fullPath = public_path("assets/{$path}");

  if (File::exists($fullPath)) {
    $extension = File::extension($fullPath);

    $mimeTypes = [
      'js' => 'application/javascript',
      'css' => 'text/css',
      'json' => 'application/json',
      'png' => 'image/png',
      'jpg' => 'image/jpeg',
      'jpeg' => 'image/jpeg',
      'gif' => 'image/gif',
      'svg' => 'image/svg+xml',
      'ico' => 'image/x-icon',
      'woff' => 'font/woff',
      'woff2' => 'font/woff2',
      'ttf' => 'font/ttf',
    ];

    $mime = $mimeTypes[$extension] ?? 'application/octet-stream';

    return response(File::get($fullPath), 200)
      ->header('Content-Type', $mime);
  }

  abort(404);
})->where('path', '.*');

// ============================================
// LAST: Serve index.html for all other routes (SPA routing)
// ============================================
Route::get('/{any}', function ($any = null) {
  $indexPath = public_path('index.html');

  if (!File::exists($indexPath)) {
    return response()->json(['error' => 'index.html not found. Run npm run build first'], 404);
  }

  return response(File::get($indexPath), 200)
    ->header('Content-Type', 'text/html');
})->where('any', '.*');



// use Illuminate\Support\Facades\Route;

// Route::get('/{any}', function () {
//   return file_get_contents(public_path('index.html'));
// })->where('any', '.*');

// Route::get('/', function () {
//   return view('welcome');
// });
