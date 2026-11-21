<?php

use App\Providers\AppServiceProvider;
use App\Support\Version;
use Hampel\XenForo\Api\Laravel\XenForoServiceProvider;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\Validation\ValidationServiceProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application. This value is used when the
    | framework needs to place the application's name in a notification or
    | any other location as required by the application or its packages.
    |
    */

    'name' => 'xfmcp',

    /*
    |--------------------------------------------------------------------------
    | Application Version
    |--------------------------------------------------------------------------
    |
    | This value determines the "version" your application is currently running
    | in. You may want to follow the "Semantic Versioning" - Given a version
    | number MAJOR.MINOR.PATCH when an update happens: https://semver.org.
    |
    */

    // `git describe`, so a checkout past a tag says so; a tagged commit is exactly the tag
    'version' => Version::describe(dirname(__DIR__)),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. This can be overridden using
    | the global command line "--env" option when calling commands.
    |
    */

    'env' => 'development',

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Service Providers
    |--------------------------------------------------------------------------
    |
    | The service providers listed here will be automatically loaded on the
    | request to your application. Feel free to add your own services to
    | this array to grant expanded functionality to your applications.
    |
    */

    'providers' => [
        AppServiceProvider::class,

        // Listed by hand: Laravel Zero ignores package auto-discovery. Without this line
        // XenForoManager is unbound, and Http::fake() would miss the client's requests.
        XenForoServiceProvider::class,

        // laravel/mcp 1.2 validates every tools/call through the Validator, which Laravel Zero
        // does not register. Without these every tool call answers a generic error, with nothing
        // on stderr, while initialize and tools/list still work. The validator needs translation
        // for its messages.
        ValidationServiceProvider::class,
        TranslationServiceProvider::class,
    ],

];
