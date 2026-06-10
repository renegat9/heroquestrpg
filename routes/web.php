<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SPA Vue — route attrape-tout
|--------------------------------------------------------------------------
| Toutes les routes front (/, /table/{groupe}, /manette/{groupe},
| /direction, …) sont gérées par vue-router côté client.
| Les routes API vivent dans routes/api.php, les canaux temps réel
| dans routes/channels.php.
*/

Route::view('/{any?}', 'app')->where('any', '^(?!api|broadcasting).*$');
