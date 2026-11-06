<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddOns;
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
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * Read-only operational state of XenForo forums, over stdio.
 *
 * Every tool here is read-only, and the class deliberately has no way to be
 * anything else: a write would be a new scope on the forum and a new tool marked
 * destructive, not a flag on one of these.
 */
#[Name('XenForo forum operations')]
#[Instructions(<<<'TEXT'
    Read-only operational state of XenForo forums: the server error log, installed add-ons, cron, the job queue,
    who is online, the forum's other logs, the approval queue, reports, registrations and posting, user upgrades,
    and the forum's options and config.php settings. Every answer is counts or settings, never a member. Nothing here changes a forum.

    Start with list-forums; every other tool takes a forum id from it. list-forums says what is configured;
    forum-access says what a forum will actually answer, and is the way to find out before relying on it.

    For "how is this forum configured?", ask forum-options and forum-configuration; they show values with
    secrets removed where the forum's key allows it, and say so when it does not.

    For "is anything wrong with this forum?", ask error-log-summary, cron-entries, job-queue and add-ons; for
    "is it being looked after?", forum-logs, approval-queue, reports, forum-activity and user-upgrades. Each reports what the forum
    recorded, so a clean answer means nothing was recorded, which is weaker than nothing being wrong - say so
    when reporting one. A forum that is down cannot answer at all, and a tool will say that
    it could not be reached rather than return an empty result.

    Every time is Unix seconds in UTC, with an ISO 8601 `*_iso` field beside it. Cron schedules are in UTC too.

    When a tool returns an error, read it: it says whether the forum is unreachable, lacks the add-on, or
    refused the key, and those are three different problems.
    TEXT)]
class XenForoServer extends Server
{
    protected array $tools = [
        ListForums::class,
        ForumAccess::class,
        ErrorLogSummary::class,
        ErrorLogEntries::class,
        AddOns::class,
        CronEntries::class,
        JobQueue::class,
        WhoIsOnline::class,
        ForumLogs::class,
        ApprovalQueue::class,
        Reports::class,
        ForumActivity::class,
        UserUpgrades::class,
        ForumOptions::class,
        ForumConfiguration::class,
    ];

    protected function boot(): void
    {
        $this->version = (string) config('app.version');
    }
}
