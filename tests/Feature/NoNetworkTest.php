<?php

use App\Mcp\Servers\XenForoServer;
use App\Mcp\Tools\AddOns;

/*
 * Fakes nothing. If the client ever stopped sending through Laravel's HTTP stack - a
 * provider dropped from config/app.php is enough - Http::fake() would stop seeing its
 * requests and every other test would quietly call whatever the inventory names.
 */
it('cannot reach a forum that has not been faked', function () {
    XenForoServer::tool(AddOns::class, ['forum' => 'acme'])->assertHasErrors();

    expect(Http::recorded())->toBeEmpty();
});
