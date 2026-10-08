<?php

use App\Support\ForumInventory;
use App\Support\ForumInventoryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

uses()->beforeEach(function () {
    // No test may reach the network. An inventory on a developer's machine points at real
    // forums with real keys, so a fake that failed to intercept would be a real request.
    Http::preventStrayRequests();

    config([
        'xfmcp.timezone' => 'UTC',
        // a fixture, not a location: nothing parses it, useForums() supplies the forums
        'xfmcp.forums_path' => '/nonexistent/forums.toml',
        'xfmcp.forums_path_set' => true,
    ]);

    useForums([
        'acme' => [
            'name' => 'Acme Forum',
            'url' => 'https://forum.example.test',
            'key' => READ_KEY,
            'detail_key' => DETAIL_KEY,
        ],
        'plain' => [
            'name' => 'Plain Forum',
            'url' => 'https://plain.example.test',
            'key' => PLAIN_KEY,
        ],
    ]);
})->in('Feature');

const READ_KEY = 'read-key-that-must-never-be-shown';
const DETAIL_KEY = 'detail-key-that-must-never-be-shown';
const PLAIN_KEY = 'plain-key-that-must-never-be-shown';

/**
 * Bind an inventory that answers from an array instead of reading a file.
 *
 * The suite must never read a real forums.toml: it holds live keys, and a test that read
 * the developer's own would pass or fail by whose machine it ran on. Overriding raw() is
 * enough, because everything else goes through it.
 *
 * @param  array<string, mixed>|ForumInventoryException  $forums
 */
function useForums(array|ForumInventoryException $forums): void
{
    app()->instance(ForumInventory::class, new class($forums) extends ForumInventory
    {
        public function __construct(private array|ForumInventoryException $fixture) {}

        public function raw(): array
        {
            if ($this->fixture instanceof ForumInventoryException) {
                throw $this->fixture;
            }

            return $this->fixture;
        }
    });
}

/**
 * A response body captured from a real forum running the add-on, on 2026-10-08, then
 * trimmed and with add-on names replaced. Captured, not written from the documentation:
 * a fixture built from the code that consumes it can only agree with that code.
 *
 * @return array<mixed>
 */
function captured(string $name): array
{
    return json_decode(file_get_contents(__DIR__.'/Fixtures/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Fake one forum's API. Keys are paths under /api/, values a fixture name, a
 * [fixture, status] pair, or a ready response.
 *
 * @param  array<string, mixed>  $routes
 */
function fakeForum(array $routes, string $host = 'forum.example.test'): void
{
    $fakes = [];

    foreach ($routes as $path => $answer) {
        $fakes[$host.'/api/'.$path.'*'] = match (true) {
            is_string($answer) => Http::response(captured($answer)),
            is_array($answer) => Http::response(captured($answer[0]), $answer[1]),
            default => $answer,
        };
    }

    Http::fake($fakes);
}

/** @return list<Request> every request that reached the (faked) wire */
function sentRequests(): array
{
    return Http::recorded()->map(fn (array $pair): Request => $pair[0])->all();
}

/**
 * Run a command and return its exit code and everything it printed.
 *
 * Not expectsOutputToContain(): a check renders as one line of marker, label and detail,
 * and Mockery satisfies that expectation with exactly one call, so two assertions about
 * one line fail against correct output.
 *
 * @param  array<string, mixed>  $arguments
 * @return array{0: int, 1: string}
 */
function runCommand(string $command, array $arguments = []): array
{
    $exit = Artisan::call($command, $arguments);

    return [$exit, Artisan::output()];
}
