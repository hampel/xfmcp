<?php

use App\Mcp\Servers\XenForoServer;
use App\Mcp\Tools\AddOns;
use App\Mcp\Tools\AddOnValidate;
use App\Mcp\Tools\ApprovalQueue;
use App\Mcp\Tools\CronEntries;
use App\Mcp\Tools\ErrorLogEntries;
use App\Mcp\Tools\ErrorLogSummary;
use App\Mcp\Tools\ForumAccess;
use App\Mcp\Tools\ForumActivity;
use App\Mcp\Tools\ForumConfiguration;
use App\Mcp\Tools\ForumLogs;
use App\Mcp\Tools\ForumOptions;
use App\Mcp\Tools\JobQueue;
use App\Mcp\Tools\ListForums;
use App\Mcp\Tools\Reports;
use App\Mcp\Tools\UserUpgrades;
use App\Mcp\Tools\WhoIsOnline;
use App\Support\ForumInventoryException;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Tool;

const TOOLS = [
    ListForums::class, ForumAccess::class, ErrorLogSummary::class, ErrorLogEntries::class, AddOns::class,
    CronEntries::class, JobQueue::class, WhoIsOnline::class, ForumLogs::class, ApprovalQueue::class, Reports::class,
    ForumActivity::class, UserUpgrades::class, AddOnValidate::class,
    ForumOptions::class, ForumConfiguration::class,
];

it('registers every tool', function () {
    XenForoServer::tools()->assertRegistered(TOOLS);
});

it('is registered as a local server and as nothing else', function () {
    expect(Mcp::getLocalServer('xenforo'))->not->toBeNull()
        ->and(array_keys(Mcp::servers()))->toBe(['xenforo']);
});

it('marks every tool read-only, and never destructive', function (string $class) {
    /** @var Tool $tool */
    $tool = app($class);

    expect($tool->toArray()['annotations'])
        ->toMatchArray(['readOnlyHint' => true])
        ->not->toHaveKey('destructiveHint');
})->with(TOOLS);

it('marks every tool idempotent except the one that is a snapshot', function (string $class) {
    $annotations = (array) app($class)->toArray()['annotations'];

    // who is online changes from one call to the next, so calling it twice is not calling it once
    $class === WhoIsOnline::class
        ? expect($annotations)->not->toHaveKey('idempotentHint')
        : expect($annotations)->toMatchArray(['idempotentHint' => true]);
})->with(TOOLS);

it('hides the error entries tool when no forum has a detail key', function () {
    useForums(['plain' => ['url' => 'https://plain.example.test', 'key' => PLAIN_KEY]]);

    XenForoServer::tools()
        ->assertNotRegistered(ErrorLogEntries::class)
        ->assertRegistered(ErrorLogSummary::class);
});

it('hides the error entries tool when the inventory cannot be read', function () {
    useForums(new ForumInventoryException('file not found'));

    XenForoServer::tools()->assertNotRegistered(ErrorLogEntries::class);
});

it('gives every tool a description that is more than its name', function (string $class) {
    expect(strlen(app($class)->description()))->toBeGreaterThan(150);
})->with(TOOLS);
