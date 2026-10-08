<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Display timezone
    |--------------------------------------------------------------------------
    |
    | XenForo reports every time as Unix seconds in UTC. Each tool adds the same instant as
    | ISO 8601 in UTC, and - when this is set to anything but UTC - once more in this zone,
    | so "03:00" is never left for the reader to place.
    |
    | The variable is APP_TIMEZONE but it is read HERE, never in config/app.php: app:build
    | evaluates that file on the build machine and compiles the result in as a literal.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Forum inventory
    |--------------------------------------------------------------------------
    |
    | Path to the TOML file listing the forums this server can ask, one table per forum.
    | forums.toml.example is the template.
    |
    | ONLY THE PATH LIVES HERE. Every table holds a live API key, so the file is read by
    | App\Support\ForumInventory when a tool needs a forum and never at config load, which
    | would put every key in the config repository for every command.
    |
    | The default is inside the application directory. A compiled binary resolves
    | storage_path() from the working directory, which an MCP client chooses, so an
    | installed binary sets this absolutely; app:validate warns when it has not.
    |
    */

    'forums_path' => env('XFMCP_FORUMS_PATH', storage_path('forums.toml')),

    // whether forums_path came from the environment; app:validate cannot recover that
    // from the path itself
    'forums_path_set' => env('XFMCP_FORUMS_PATH') !== null,

];
