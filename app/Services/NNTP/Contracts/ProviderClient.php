<?php

declare(strict_types=1);

namespace App\Services\NNTP\Contracts;

use App\Services\NNTP\NntpProvider;

/**
 * What the provider pool needs from a single-provider NNTP client.
 *
 * Article operations only. There is deliberately no header operation here: article numbers
 * are per-server, so group scanning, backfill and part repair belong to the primary alone
 * and must not be reachable through anything the pool hands out.
 */
interface ProviderClient
{
    /**
     * The provider this client talks to.
     */
    public function provider(): NntpProvider;

    /**
     * @return mixed true on success, Error on failure.
     */
    public function doConnect(bool $compression = true): mixed;

    /**
     * Fetch one article body from this provider only -- no failover.
     *
     * @return mixed string body on success, Error on failure.
     */
    public function fetchArticleBody(string $messageId): mixed;

    /**
     * Does this provider hold the article?
     *
     * @return mixed true (exists), false (answered, does not have it), or Error (could not ask).
     */
    public function statArticle(string $messageId): mixed;

    /**
     * @return mixed true on success, Error on failure.
     */
    public function doQuit(bool $force = false): mixed;
}
