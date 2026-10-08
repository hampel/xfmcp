<?php

/*
|--------------------------------------------------------------------------
| XenForo API transport
|--------------------------------------------------------------------------
|
| Only the two timeouts. hampel/xenforo-api-laravel merges its own config beneath this
| one, so a key set here wins. Its named forums are never used: every client comes from
| XenForoManager::build() with a forum's own URL and key, which stay in the inventory
| file - see App\Support\ForumInventory.
|
*/

return [

    // seconds a request to a forum may take, start to finish
    'timeout' => env('XENFORO_TIMEOUT', 10),

    // seconds to wait for the connection to a forum to open
    'connect_timeout' => env('XENFORO_CONNECT_TIMEOUT', 5),

];
