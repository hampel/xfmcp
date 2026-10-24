<?php

namespace App\Support;

use Throwable;
use Yosymfony\Toml\Toml;

/**
 * The TOML file listing the forums this server can ask.
 *
 * READ ON DEMAND, never at config load. Every table carries a live XenForo API key, and
 * parsing in config/ would put all of them in the config repository for every command,
 * `--version` included. Nothing is read until a tool or a check asks.
 *
 * Bound as a singleton, and the parse is kept for as long as the file is unchanged. The
 * MCP server is one process that lives as long as its client's session, so a parse kept
 * for the life of the process would mean a forum added to the file is invisible until the
 * client reconnects. The file's modification time and size are checked on every read
 * instead, which costs one stat. Tests override raw(); see useForums() in tests/Pest.php.
 */
class ForumInventory
{
    /** @var array<string, mixed>|null parsed lazily, and again when the file changes */
    private ?array $raw = null;

    /** the file's modification time and size when it was last parsed */
    private ?string $parsedAt = null;

    public function path(): string
    {
        return (string) config('xfmcp.forums_path');
    }

    /**
     * The file as parsed, before any table is checked. app:validate reads this so that one
     * bad table does not hide the others.
     *
     * @return array<string, mixed>
     *
     * @throws ForumInventoryException if the file cannot be read or parsed
     */
    public function raw(): array
    {
        $path = $this->path();

        // PHP caches stat results per process, which in a long-lived one is forever
        clearstatcache(true, $path);

        if (! is_file($path)) {
            throw new ForumInventoryException('file not found');
        }

        $stamp = filemtime($path).':'.filesize($path);

        if ($this->raw !== null && $this->parsedAt === $stamp) {
            return $this->raw;
        }

        if (! is_readable($path)) {
            throw new ForumInventoryException('file not readable');
        }

        try {
            $parsed = Toml::parseFile($path);
        } catch (Throwable $e) {
            // never the parser's message, nor the exception chained: either can quote the token it
            // stopped on, and any value in this file may be an API key
            throw new ForumInventoryException('parse error; the parser\'s own message is not shown, as it can quote the file');
        }

        $this->parsedAt = $stamp;

        return $this->raw = is_array($parsed) ? $parsed : [];
    }

    /**
     * What is wrong with one table, or null if it is usable.
     *
     * `user` is refused rather than ignored: paired with `key` it makes the client send a
     * super user key acting as that user, which the add-on answers with a 403 that reads
     * like a broken forum.
     */
    public function problem(string $id, mixed $table): ?string
    {
        if (! is_array($table)) {
            return 'not a table';
        }

        if ($id !== strtolower($id)) {
            return 'the table name must be lowercase';
        }

        foreach (['url', 'key'] as $required) {
            if (! is_string($table[$required] ?? null) || trim($table[$required]) === '') {
                return "`{$required}` is missing or empty";
            }
        }

        if (array_key_exists('user', $table)) {
            return '`user` is set: that makes `key` a super user key, which the add-on refuses';
        }

        if (array_key_exists('detail_key', $table)
            && (! is_string($table['detail_key']) || trim($table['detail_key']) === '')) {
            return '`detail_key` is empty; remove the line if the forum has none';
        }

        return null;
    }

    /**
     * Every usable forum, keyed by id. A table that is not usable is left out here and
     * reported by app:validate.
     *
     * @return array<string, Forum>
     *
     * @throws ForumInventoryException if the file cannot be read or parsed
     */
    public function all(): array
    {
        $forums = [];

        foreach ($this->raw() as $id => $table) {
            $id = (string) $id;

            if ($this->problem($id, $table) !== null) {
                continue;
            }

            $forums[$id] = new Forum(
                $id,
                is_string($table['name'] ?? null) && $table['name'] !== '' ? $table['name'] : $id,
                rtrim($table['url'], '/'),
                $table['key'],
                $table['detail_key'] ?? null,
            );
        }

        return $forums;
    }

    /**
     * @throws ForumInventoryException if the file cannot be read, or holds no such forum
     */
    public function get(string $id): Forum
    {
        $forums = $this->all();
        $id = strtolower(trim($id));

        if (! isset($forums[$id])) {
            throw new ForumInventoryException(sprintf(
                'no forum "%s" is configured. Configured forums: %s',
                $id,
                $forums === [] ? 'none' : implode(', ', array_keys($forums)),
            ));
        }

        return $forums[$id];
    }
}
