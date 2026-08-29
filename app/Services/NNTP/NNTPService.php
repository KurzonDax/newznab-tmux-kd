<?php

declare(strict_types=1);

namespace App\Services\NNTP;

use App\Models\Settings;
use App\Services\NNTP\Contracts\ProviderClient;
use App\Services\NNTP\DTO\ArticleDownloadResult;
use App\Services\Tmux\Tmux;
use App\Services\YencService;
use DariusIII\NetNntp\Client as NntpClient;
use DariusIII\NetNntp\Error as NntpError;
use DariusIII\NetNntp\Protocol\ResponseCode;

/**
 * NNTP Service for connecting to usenet, retrieving articles and article headers,
 * decoding yEnc articles, and decompressing article headers.
 *
 * This service wraps the DariusIII\NetNntp\Client class with enhanced functionality
 * and Laravel-friendly dependency injection.
 */
class NNTPService extends NntpClient implements ProviderClient
{
    /**
     * Guard against a concatenated body growing past what PHP can hold: measure one body,
     * multiply by the loop count, and bail before 1.7GB. Without this it is a fatal error.
     */
    private const int MAX_CONCATENATED_BYTES = 1_700_000_000;

    protected bool $_debugBool;

    protected bool $_echo;

    /**
     * Does the server support XFeature GZip header compression?
     */
    protected bool $_compressionSupported = true;

    /**
     * Is header compression enabled for the session?
     */
    protected bool $_compressionEnabled = false;

    /**
     * Currently selected group.
     */
    protected string $_currentGroup = '';

    protected string|int $_currentPort = 0;

    /**
     * Address of the current NNTP server.
     */
    protected string $_currentServer = '';

    /**
     * Are we allowed to post to usenet?
     */
    protected bool $_postingAllowed = false;

    /**
     * How many times should we try to reconnect to the NNTP server?
     */
    protected int $_nntpRetries;

    /**
     * Seconds to wait for the blocking socket to timeout.
     */
    protected int $_socketTimeout = 120;

    protected Tmux $_tmux;

    /**
     * The provider this client talks to. Resolved lazily to the primary on first use; the pool
     * re-points its own clients at the other backbones via {@see self::useProvider()}.
     *
     * Lazy on purpose: constructing this service must not require NNTP config to exist, or a
     * misconfigured deployment loses every artisan command -- including the one that diagnoses it.
     */
    protected ?NntpProvider $_provider = null;

    /**
     * May this client fall back to the provider pool when an article fetch fails?
     *
     * False for the pool's own per-provider clients, which must stay single-provider so a
     * failed fetch walks the pool exactly once instead of recursing into it.
     */
    protected bool $_poolFailoverEnabled = true;

    protected bool $_configCompressedHeaders;

    /**
     * If on unix, hide yydecode CLI output.
     */
    protected string $_yEncSilence;

    /**
     * Path to temp yEnc input storage file.
     */
    protected string $_yEncTempInput;

    /**
     * Path to temp yEnc output storage file.
     */
    protected string $_yEncTempOutput;

    /**
     * YEnc encoding/decoding service.
     */
    protected YencService $_yencService;

    /**
     * Create a new NNTP service instance.
     */
    public function __construct(?Tmux $tmux = null, ?YencService $yencService = null)
    {
        parent::__construct();

        $this->_echo = config('nntmux.echocli');
        $this->_tmux = $tmux ?? new Tmux;
        $this->_yencService = $yencService ?? app(YencService::class);
        $this->_nntpRetries = Settings::settingValue('nntpretries') !== '' ? (int) Settings::settingValue('nntpretries') : 0 + 1;

        $this->initializeConfig();
    }

    /**
     * Initialize configuration values from Laravel config.
     */
    protected function initializeConfig(): void
    {
        $this->_configCompressedHeaders = (bool) config('nntmux_nntp.compressed_headers');
        $this->_selectedGroupSummary = null;
        $this->_overviewFormatCache = null;
    }

    /**
     * Point this client at a specific provider.
     *
     * Any existing connection is dropped first: the socket belongs to the old backbone and
     * article numbers do not carry across servers.
     *
     * @param  bool  $poolFailover  Whether article fetches on this client may walk the pool.
     *                              The pool's own clients pass false to stay single-provider.
     */
    public function useProvider(NntpProvider $provider, bool $poolFailover = true): void
    {
        if ($this->_provider !== null && $this->_provider->name !== $provider->name && parent::_isConnected()) {
            $this->doQuit();
        }

        $this->_provider = $provider;
        $this->_poolFailoverEnabled = $poolFailover;
        $this->_currentPort = $provider->port;
        $this->_currentServer = $provider->host;
    }

    /**
     * The provider this client is currently pointed at, defaulting to the primary.
     */
    public function provider(): NntpProvider
    {
        if ($this->_provider === null) {
            $this->_provider = NntpProviderPool::primaryProvider();
            $this->_currentPort = $this->_provider->port;
            $this->_currentServer = $this->_provider->host;
        }

        return $this->_provider;
    }

    /**
     * Check if a value is a DariusIII\NetNntp\Error instance.
     *
     * @param  mixed  $data  The value to check
     * @return bool True if $data is a DariusIII\NetNntp\Error instance
     */
    public static function isError(mixed $data): bool
    {
        return NntpError::isError($data);
    }

    /**
     * Destruct.
     * Close the NNTP connection if still connected.
     */
    public function __destruct()
    {
        $this->doQuit();
    }

    /**
     * Connect this client to its provider.
     *
     * @param  bool  $compression  Should we attempt to enable XFeature Gzip compression on this connection?
     * @return mixed On success = (bool)   Did we successfully connect to the usenet?
     *
     * @throws \Exception
     *                    On failure = (object) DariusIII\NetNntp\Error.
     */
    public function doConnect(bool $compression = true): mixed
    {
        $provider = $this->provider();

        // Reuse an existing socket rather than opening another one when this provider already
        // has more sockets open than its advisory budget. Only worth asking when we are already
        // connected -- resolving the host and shelling out to `ss` is not free.
        if ($this->_isConnected() && $this->_currentServer === $provider->host) {
            $active = $this->_tmux->getProviderSocketCounts(
                gethostbyname($provider->host),
                $provider->port,
            )['active'];

            if ($provider->connections < $active) {
                return true;
            }
        }

        $this->doQuit();

        $ret = $connected = $cError = $aError = false;

        $sslEnabled = $provider->ssl;
        $this->_currentServer = $provider->host;
        $this->_currentPort = $provider->port;
        $userName = $provider->username;
        $password = $provider->password;
        $socketTimeout = $provider->timeout;

        $enc = ($sslEnabled ? ' (ssl)' : ' (non-ssl)');
        $sslEnabled = ($sslEnabled ? 'tls' : false);

        // Try to connect until we run of out tries.
        $retries = $this->_nntpRetries;
        while (true) {
            $retries--;
            $authenticated = false;

            // If we are not connected, try to connect.
            if (! $connected) {
                $ret = $this->connect($this->_currentServer, $sslEnabled, (int) $this->_currentPort, 5, $socketTimeout);
            }
            // Check if we got an error while connecting.
            $cErr = self::isError($ret);

            // If no error, we are connected.
            if (! $cErr) {
                // Say that we are connected so we don't retry.
                $connected = true;
                // When there is no error it returns bool if we are allowed to post or not.
                $this->_postingAllowed = $ret;
            } elseif (! $cError) {
                $cError = $ret->getMessage();
            }

            // If error, try to connect again.
            if ($cErr && $retries > 0) {
                continue;
            }

            // If we have no more retries and could not connect, return an error.
            if ($retries === 0 && ! $connected) {
                $message =
                    'Cannot connect to NNTP provider '.
                    $provider->label().
                    $enc.
                    ': '.
                    $cError;

                return $this->throwError(cli()->error($message));
            }

            // If we are connected, try to authenticate.
            if ($connected) {
                // If the username is empty it probably means the server does not require a username.
                if ($userName === '') {
                    $authenticated = true;

                    // Try to authenticate to usenet.
                } else {
                    $ret2 = $this->authenticate($userName, $password);

                    // Check if there was an error authenticating.
                    $aErr = self::isError($ret2);

                    // If there was no error, then we are authenticated.
                    if (! $aErr) {
                        $authenticated = true;
                    } elseif (! $aError) {
                        $aError = $ret2->getMessage();
                    }

                    // If error, try to authenticate again.
                    if ($aErr && $retries > 0) {
                        continue;
                    }

                    // If we ran out of retries, return an error.
                    if ($retries === 0 && ! $authenticated) {
                        $message =
                            'Cannot authenticate to NNTP provider '.
                            $provider->label().
                            $enc.
                            ' - '.
                            $userName.
                            ' ('.$aError.')';

                        return $this->throwError(cli()->error($message));
                    }
                }
            }
            // If we are connected and authenticated, try enabling compression if we have it enabled.
            if ($connected && $authenticated) {
                // Check if we should use compression on the connection.
                if (! $compression || ! $this->_configCompressedHeaders) {
                    $this->_compressionSupported = false;
                }

                return true;
            }
            // If we reached this point and have not connected after all retries, break out of the loop.
            if ($retries === 0) {
                break;
            }

            // Sleep .4 seconds between retries.
            usleep(400000);
        }
        // If we somehow got out of the loop, return an error.
        $message = 'Unable to connect to NNTP provider '.$provider->label().$enc;

        return $this->throwError(cli()->error($message));
    }

    /**
     * Disconnect from the current NNTP server.
     *
     * @param  bool  $force  Force quit even if not connected?
     * @return mixed On success : (bool)   Did we successfully disconnect from usenet?
     *               On Failure : (object) DariusIII\NetNntp\Error.
     */
    public function doQuit(bool $force = false): mixed
    {
        $this->_resetProperties();

        // Check if we are connected to usenet.
        if ($force || parent::_isConnected()) {
            // Disconnect from usenet.
            return $this->disconnect();
        }

        return true;
    }

    /**
     * Reset some properties when disconnecting from usenet.
     */
    protected function _resetProperties(): void
    {
        $this->_compressionEnabled = false;
        $this->_compressionSupported = true;
        $this->_currentGroup = '';
        $this->_postingAllowed = false;
        $this->_selectedGroupSummary = null;
        $this->_overviewFormatCache = null;
        $this->_socket = null;
    }

    /**
     * Attempt to enable compression if the admin enabled the site setting.
     *
     * @note   This can be used to enable compression if the server was connected without compression.
     *
     * @throws \Exception
     */
    public function enableCompression(): void
    {
        if (! $this->_configCompressedHeaders) {
            return;
        }
        $this->_enableCompression();
    }

    /**
     * @param  string  $group  Name of the group to select.
     * @param  mixed  $articles  (optional) experimental! When true the article numbers is returned in 'articles'.
     * @param  bool  $force  Force a refresh to get updated data from the usenet server.
     * @return mixed On success : (array)  Group information.
     *
     * @throws \Exception
     *                    On failure : (object) DariusIII\NetNntp\Error.
     */
    public function selectGroup(string $group, mixed $articles = false, bool $force = false): mixed
    {
        $connected = $this->_checkConnection(false);
        if ($connected !== true) {
            return $connected;
        }

        // Check if the current selected group is the same, or if we have not selected a group or if a fresh summary is wanted.
        if ($force || $this->_currentGroup !== $group || $this->_selectedGroupSummary === null) {
            $this->_currentGroup = $group;

            return parent::selectGroup($group, $articles);
        }

        return $this->_selectedGroupSummary;
    }

    /**
     * Fetch an overview of article(s) in the currently selected group.
     *
     * @return mixed On success : (array)  Multidimensional array with article headers.
     *
     * @throws \Exception
     *                    On failure : (object) DariusIII\NetNntp\Error.
     */
    public function getOverview(mixed $range = null, bool $names = true, bool $forceNames = true): mixed
    {
        $connected = $this->_checkConnection();
        if ($connected !== true) {
            return $connected;
        }

        // Enabled header compression if not enabled.
        $this->_enableCompression();

        return parent::getOverview($range, $names, $forceNames);
    }

    /**
     * Pass a XOVER command to the NNTP provider, return array of articles using the overview format as array keys.
     *
     * @note This is a faster implementation of getOverview.
     *
     * Example successful return:
     *    array(9) {
     *        'Number'     => string(9)  "679871775"
     *        'Subject'    => string(18) "This is an example"
     *        'From'       => string(19) "Example@example.com"
     *        'Date'       => string(24) "26 Jun 2014 13:08:22 GMT"
     *        'Message-ID' => string(57) "<part1of1.uS*yYxQvtAYt$5t&wmE%UejhjkCKXBJ!@example.local>"
     *        'References' => string(0)  ""
     *        'Bytes'      => string(3)  "123"
     *        'Lines'      => string(1)  "9"
     *        'Xref'       => string(66) "e alt.test:679871775"
     *    }
     *
     * @param  string  $range  Range of articles to get the overview for. Examples follow:
     *                         Single article number:         "679871775"
     *                         Range of article numbers:      "679871775-679999999"
     *                         All newer than article number: "679871775-"
     *                         All older than article number: "-679871775"
     *                         Message-ID:                    "<part1of1.uS*yYxQvtAYt$5t&wmE%UejhjkCKXBJ!@example.local>"
     * @return array<string, mixed>|string|NNTPService Multi-dimensional Array of headers on success, Error object on failure.
     *
     * @throws \Exception
     */
    public function getXOVER(string $range): mixed
    {
        // Check if we are still connected.
        $connected = $this->_checkConnection();
        if ($connected !== true) {
            return $connected;
        }

        // Enabled header compression if not enabled.
        $this->_enableCompression();

        // Send XOVER command to NNTP with wanted articles.
        $response = $this->_sendCommand('XOVER '.$range);
        if (self::isError($response)) {
            return $response;
        }

        // Verify the NNTP server got the right command, get the headers data.
        if ($response === ResponseCode::OverviewFollows->value) {
            $data = $this->_getTextResponse();
            if (self::isError($data)) {
                return $data;
            }
        } else {
            return $this->_handleErrorResponse($response);
        }

        // Fetch the header overview format (for setting the array keys on the return array).
        // The cache is shared with the vendor client, whose getOverview() stores the format
        // with 'Number' prepended (the article number is implicit in LIST OVERVIEW.FMT).
        // Normalise to that same shape, otherwise part repair and the range scan running on
        // one connection key each other's headers one field out of step.
        $overview = $this->_overviewFormatCache;
        if ($overview === null) {
            $overview = $this->getOverviewFormat(false, true);
            if (self::isError($overview) || ! \is_array($overview)) {
                return $overview;
            }
        }

        if (! \array_key_exists('Number', $overview)) {
            $overview = array_merge(['Number' => false], $overview);
        }

        $this->_overviewFormatCache = $overview;

        // Pre-compute keys array and Xref position for faster processing
        $keys = array_keys($overview);
        $keyCount = \count($keys);
        $xrefIndex = array_search('Xref', $keys, true);

        // Loop over strings of headers.
        foreach ($data as $key => $header) {
            // Split the individual headers by tab.
            $parts = explode("\t", $header);

            // Make sure it's not empty.
            if (empty($parts)) {
                continue;
            }

            // Build header array using pre-computed keys
            $headerArray = [];
            $partCount = \count($parts);

            for ($i = 0; $i < $keyCount && $i < $partCount; $i++) {
                $value = $parts[$i];
                // Strip "Xref: " prefix if this is the Xref field
                if ($i === $xrefIndex && isset($value[5])) {
                    $value = substr($value, 6);
                }
                $headerArray[$keys[$i]] = $value;
            }

            // Add the individual header array back to the return array.
            $data[$key] = $headerArray;
        }

        // Return the array of headers.
        return $data;
    }

    /**
     * Fetch valid groups.
     *
     * Returns a list of valid groups (that the client is permitted to select) and associated information.
     *
     * @param  mixed  $wildMat  (optional) http://tools.ietf.org/html/rfc3977#section-4
     * @return array<string, mixed>|string Pear error on failure, array with groups on success.
     *
     * @throws \Exception
     */
    public function getGroups(mixed $wildMat = null): mixed
    {
        // Enabled header compression if not enabled.
        $this->_enableCompression();

        return parent::getGroups($wildMat);
    }

    /**
     * Download multiple article bodies and string them together.
     *
     * Message-ID identifiers walk the provider pool (this client's own provider first, when it
     * is the one the pool starts with). Article *numbers* cannot: they are per-server, so they
     * are fetched from this connection's group only, with no failover.
     *
     * @param  string  $groupName  The name of the group the articles are in.
     * @param  mixed  $identifiers  (string) Message-ID.
     *                              (int)    Article number.
     *                              (array)  Article numbers or Message-ID's (can contain both in the same array)
     * @return mixed On success : (string) The article bodies.
     *
     * @throws \Exception
     *                    On failure : (object) DariusIII\NetNntp\Error.
     */
    public function getMessages(string $groupName, mixed $identifiers): mixed
    {
        if (! \is_array($identifiers) && ! \is_string($identifiers) && ! is_numeric($identifiers)) {
            $message = 'Wrong Identifier type, array, int or string accepted. This type of var was passed: '.gettype($identifiers);

            return $this->throwError(cli()->error($message));
        }

        $wanted = \is_array($identifiers) ? $identifiers : [$identifiers];
        $body = '';
        $messageSize = 0;
        $loops = 0;

        foreach ($wanted as $identifier) {
            /* Attempt to prevent string size overflow: measure one body, multiply by the loop
             * count, and bail before 1.7GB (under 2GB for headroom). These errors are fatal. */
            if ((++$loops * $messageSize) >= self::MAX_CONCATENATED_BYTES) {
                return $body;
            }

            $message = is_numeric($identifier)
                ? $this->fetchArticleNumberFromGroup($groupName, $identifier)
                : $this->fetchArticleBodyWithFailover((string) $identifier);

            if (self::isError($message)) {
                // If we got some data, return it, otherwise surface the error.
                return $body !== '' ? $body : $message;
            }

            $body .= $message;

            if ($messageSize === 0) {
                $messageSize = \strlen($message);
            }
        }

        return $body;
    }

    /**
     * Download multiple article bodies by Message-ID only (no group selection), concatenating them.
     *
     * The return shape is unchanged -- concatenated yEnc-decoded bodies, or an Error when nothing
     * could be fetched. Only the internals changed: each article now walks the provider pool, so
     * an article fails only once every enabled provider has failed it.
     *
     * @param  mixed  $identifiers  string|array Message-ID(s) (with or without < >)
     * @return mixed string concatenated bodies on success, Error object on total failure.
     *
     * @throws \Exception
     */
    public function getMessagesByMessageID(mixed $identifiers): mixed
    {
        return $this->getMessagesByMessageIDWithCrcStatus($identifiers)->data;
    }

    /**
     * Download message-ID article bodies while retaining yEnc CRC verification failures.
     */
    public function getMessagesByMessageIDWithCrcStatus(mixed $identifiers): ArticleDownloadResult
    {
        $ids = is_array($identifiers) ? $identifiers : [$identifiers];

        if (! $this->_poolFailoverEnabled) {
            return $this->concatenateFromOwnProviderWithCrcStatus($ids);
        }

        return $this->pool()->fetchArticleBodiesWithCrcStatus($ids, $this);
    }

    /**
     * Fetch one article body from this client's provider only -- no pool failover.
     *
     * This is the pool's per-provider entry point; going through it (rather than
     * {@see self::getMessagesByMessageID()}) is what keeps a pool walk from recursing.
     *
     * @return mixed string body on success, Error on failure.
     *
     * @throws \Exception
     */
    public function fetchArticleBody(string $messageId): mixed
    {
        $result = $this->fetchArticleBodyWithCrcStatus($messageId);

        return $result instanceof ArticleDownloadResult ? $result->data : $result;
    }

    /**
     * Fetch one article from this provider with its yEnc CRC status.
     */
    public function fetchArticleBodyWithCrcStatus(string $messageId): mixed
    {
        $connected = $this->_checkConnection(false);
        if ($connected !== true) {
            return $connected;
        }

        return $this->_getMessageByMessageIDWithCrcStatus($messageId);
    }

    /**
     * Ask this client's provider whether it holds the article (STAT, no body transfer).
     *
     * Three-valued on purpose: `true` = exists, `false` = the provider answered and does not
     * have it, Error = we could not ask (connect/protocol failure). Callers that need to tell
     * "absent" from "unreachable" -- the pool's breaker, for one -- depend on the difference.
     *
     * @return mixed true|false|Error
     *
     * @throws \Exception
     */
    public function statArticle(string $messageId): mixed
    {
        if (trim($messageId) === '') {
            return false;
        }

        $connected = $this->_checkConnection(false);
        if ($connected !== true) {
            return $connected;
        }

        $formatted = $this->_formatMessageID($messageId);
        if ($formatted === false) {
            return false;
        }

        $result = $this->selectArticle($formatted, -1);

        if (! self::isError($result)) {
            return true;
        }

        // 430 is the server telling us it does not carry the article -- an answer, not a fault.
        return (int) $result->getCode() === ResponseCode::NoSuchArticleId->value ? false : $result;
    }

    /**
     * Fetch one article body by Message-ID through the provider pool.
     *
     * @throws \Exception
     */
    protected function fetchArticleBodyWithFailover(string $messageId): mixed
    {
        if (! $this->_poolFailoverEnabled) {
            return $this->fetchArticleBody($messageId);
        }

        return $this->pool()->fetchArticleBody($messageId, callerClient: $this);
    }

    /**
     * Fetch one article by its per-server article number, from this connection's group.
     *
     * Never fails over: an article number means nothing on another backbone.
     *
     * @throws \Exception
     */
    protected function fetchArticleNumberFromGroup(string $groupName, mixed $articleNumber): mixed
    {
        $connected = $this->_checkConnection();
        if ($connected !== true) {
            return $connected;
        }

        return $this->_getMessage($groupName, $articleNumber);
    }

    /**
     * @param  list<mixed>  $ids
     *
     * @throws \Exception
     */
    private function concatenateFromOwnProviderWithCrcStatus(array $ids): ArticleDownloadResult
    {
        $body = '';
        $messageSize = 0;
        $loops = 0;
        $crcFailedMessageIds = [];

        foreach ($ids as $id) {
            if ((++$loops * $messageSize) >= self::MAX_CONCATENATED_BYTES) {
                break;
            }

            $message = $this->fetchArticleBodyWithCrcStatus((string) $id);

            if (self::isError($message)) {
                return new ArticleDownloadResult($body !== '' ? $body : $message, $crcFailedMessageIds);
            }

            if (! $message instanceof ArticleDownloadResult) {
                return new ArticleDownloadResult($body);
            }

            $crcFailedMessageIds = array_values(array_unique([
                ...$crcFailedMessageIds,
                ...$message->crcFailedMessageIds,
            ]));
            if ($message->damaged) {
                return new ArticleDownloadResult($body, $crcFailedMessageIds, true);
            }

            if (! is_string($message->data)) {
                return new ArticleDownloadResult($body, $crcFailedMessageIds);
            }

            $body .= $message->data;

            if ($messageSize === 0) {
                $messageSize = \strlen($message->data);
            }
        }

        return new ArticleDownloadResult($body, $crcFailedMessageIds);
    }

    private function pool(): NntpProviderPool
    {
        return app(NntpProviderPool::class);
    }

    /**
     * Internal: fetch single article body by Message-ID (yEnc decoded) without selecting a group.
     * Accepts article numbers but these require a group; will return error if numeric passed.
     *
     * @param  mixed  $identifier  Message-ID or article number.
     * @return mixed string body on success, Error on failure.
     *
     * @throws \Exception
     */
    protected function _getMessageByMessageID(mixed $identifier): mixed
    {
        $result = $this->_getMessageByMessageIDWithCrcStatus($identifier);

        return $result instanceof ArticleDownloadResult ? $result->data : $result;
    }

    /**
     * @throws \Exception
     */
    protected function _getMessageByMessageIDWithCrcStatus(mixed $identifier): mixed
    {
        // If numeric we cannot safely fetch without group context – delegate to existing path via error.
        if (is_numeric($identifier)) {
            return $this->throwError('Numeric article number requires group selection');
        }
        $id = $this->_formatMessageID($identifier);
        $response = $this->_sendCommand('BODY '.$id);
        if (self::isError($response)) {
            return $response;
        }
        if ($response !== ResponseCode::BodyFollows->value) {
            return $this->_handleErrorResponse($response);
        }

        // Use array to accumulate lines (faster than string concatenation)
        $bodyParts = [];
        $socket = $this->_socket;

        while (! feof($socket)) {
            $line = fgets($socket, 8192);
            if ($line === false) {
                return $this->throwError('Failed to read line from socket.', null);
            }
            if ($line === ".\r\n") {
                $body = implode('', $bodyParts);
                $decoded = $this->_yencService->decodeWithCrcStatus($body);

                return new ArticleDownloadResult(
                    data: $decoded->data,
                    crcFailedMessageIds: $decoded->crcFailed ? [(string) $identifier] : [],
                    damaged: $decoded->crcFailed,
                );
            }
            if ($line[0] === '.' && isset($line[1]) && $line[1] === '.') {
                $line = substr($line, 1);
            }
            $bodyParts[] = $line;
        }

        return $this->throwError('End of stream! Connection lost?', null);
    }

    /**
     * Restart the NNTP connection if an error occurs in the selectGroup
     * function, if it does not restart display the error.
     *
     * @param  NNTPService  $nntp  Instance of class NNTPService.
     * @param  string  $group  Name of the group.
     * @param  bool  $comp  Use compression or not?
     * @return mixed On success : (array)  The group summary.
     *
     * @throws \Exception
     *                    On Failure : (object) DariusIII\NetNntp\Error.
     */
    public function dataError(NNTPService $nntp, string $group, bool $comp = true): mixed
    {
        // Disconnect.
        $nntp->doQuit();
        // Try reconnecting. This uses another round of max retries.
        if ($nntp->doConnect($comp) !== true) {
            return $this->throwError('Unable to reconnect to usenet!');
        }

        // Try re-selecting the group.
        $data = $nntp->selectGroup($group);
        if (self::isError($data)) {
            $message = "Code {$data->getCode()}: {$data->getMessage()}\nSkipping group: {$group}";

            if ($this->_echo) {
                cli()->error($message);
            }
            $nntp->doQuit();
        }

        return $data;
    }

    /**
     * Split a string into lines of 510 chars ending with \r\n.
     * Usenet limits lines to 512 chars, with \r\n that leaves us 510.
     *
     * @param  string  $string  The string to split.
     * @param  bool  $compress  Compress the string with gzip?
     * @return string The split string.
     */
    protected function _splitLines(string $string, bool $compress = false): string
    {
        // Check if the length is longer than 510 chars.
        if (\strlen($string) > 510) {
            // If it is, split it @ 510 and terminate with \r\n.
            $string = chunk_split($string, 510, "\r\n");
        }

        // Compress the string if requested.
        return $compress ? gzdeflate($string, 4) : $string;
    }

    /**
     * Try to see if the NNTP server implements XFeature GZip Compression,
     * change the compression bool object if so.
     *
     * @param  bool  $secondTry  This is only used if enabling compression fails, the function will call itself to retry.
     * @return mixed On success : (bool)   True:  The server understood and compression is enabled.
     *               (bool)   False: The server did not understand, compression is not enabled.
     *               On failure : (object) DariusIII\NetNntp\Error.
     *
     * @throws \Exception
     */
    protected function _enableCompression(bool $secondTry = false): mixed
    {
        if ($this->_compressionEnabled) {
            return true;
        }
        if (! $this->_compressionSupported) {
            return false;
        }

        // Send this command to the usenet server.
        $response = $this->_sendCommand('XFEATURE COMPRESS GZIP');

        // Check if it's good.
        if (self::isError($response)) {
            $this->_compressionSupported = false;

            return $response;
        }
        if ($response !== 290) {
            if (! $secondTry) {
                // Retry.
                $this->cmdQuit();
                if ($this->_checkConnection()) {
                    return $this->_enableCompression(true);
                }
            }
            $msg = "Sent 'XFEATURE COMPRESS GZIP' to server, got '$response: ".$this->_currentStatusResponse()."'";

            $this->_compressionSupported = false;

            return false;
        }

        $this->_compressionEnabled = true;
        $this->_compressionSupported = true;

        return true;
    }

    /**
     * Override the parent's _getTextResponse to use our _getXFeatureTextResponse instead
     * of their _getTextResponse function since it is incompatible at decoding
     * headers when XFeature GZip compression is enabled server side.
     *
     * @return NNTPService|array<string, mixed>|string Our overridden function when compression is enabled.
     *                                                 parent  Parent function when no compression.
     */
    public function _getTextResponse(): NNTPService|array|string
    {
        if ($this->_compressionEnabled &&
            isset($this->_currentStatusResponse[1]) &&
            stripos($this->_currentStatusResponse[1], 'COMPRESS=GZIP') !== false) {
            return $this->_getXFeatureTextResponse();
        }

        return parent::_getTextResponse();
    }

    /**
     * Loop over the compressed data when XFeature GZip Compress is turned on,
     * string the data until we find a indicator
     * (period, carriage feed, line return ;; .\r\n), decompress the data,
     * split the data (bunch of headers in a string) into an array, finally
     * return the array.
     *
     * Have we failed to decompress the data, was there a
     * problem downloading the data, etc..
     *
     * @return array<string, mixed>|string|NntpError On success : (array)  The headers.
     *                                               On failure : (object) DariusIII\NetNntp\Error.
     *                                               On decompress failure: (string) error message
     */
    protected function &_getXFeatureTextResponse(): array|string|NntpError
    {
        $possibleTerm = false;
        // Use array accumulation for better performance with large data
        $dataParts = [];
        $socket = $this->_socket;

        while (! feof($socket)) {
            // Did we find a possible ending ? (.\r\n)
            if ($possibleTerm) {
                // Use stream_select for more efficient socket polling
                $read = [$socket];
                $write = $except = null;

                // Check if data is available with a short timeout (5ms)
                $ready = @stream_select($read, $write, $except, 0, 5000);

                if ($ready > 0) {
                    // Data available, read it
                    stream_set_blocking($socket, false);
                    $buffer = fgets($socket, 16384);
                    stream_set_blocking($socket, true);
                } else {
                    $buffer = '';
                }

                // If the buffer was really empty, then we know $possibleTerm was the real ending.
                if ($buffer === '' || $buffer === false) {
                    // Join all parts and remove .\r\n from end, decompress data.
                    $data = implode('', $dataParts);
                    $deComp = @gzuncompress(substr($data, 0, -3));

                    if (! empty($deComp)) {
                        $bytesReceived = \strlen($data);
                        if ($this->_echo && $bytesReceived > 10240) {
                            cli()->primaryOver(
                                'Received '.round($bytesReceived / 1024).
                                'KB from group ('.$this->group().').'
                            );
                        }

                        // Split the string of headers into an array of individual headers, then return it.
                        $deComp = explode("\r\n", trim($deComp));

                        return $deComp; // @phpstan-ignore return.type
                    }
                    $message = 'Decompression of OVER headers failed.';

                    return $this->throwError(cli()->error($message), 1000);
                }
                // The buffer was not empty, so we know this was not the real ending, so reset $possibleTerm.
                $possibleTerm = false;
                $dataParts[] = $buffer;
            } else {
                // Get data from the stream with larger buffer.
                $buffer = fgets($socket, 16384);
            }

            // If we got no data at all try one more time to pull data.
            if (empty($buffer)) {
                usleep(5000);
                $buffer = fgets($socket, 16384);

                // If we got nothing again, return error.
                if (empty($buffer)) {
                    $message = 'Error fetching data from usenet server while downloading OVER headers.';

                    return $this->throwError(cli()->error($message), 1000);
                }
            }

            // Append current buffer to parts array.
            $dataParts[] = $buffer;

            // Check if we have the ending (.\r\n) - check last 3 chars directly
            $bufLen = \strlen($buffer);
            if ($bufLen >= 3 && $buffer[$bufLen - 3] === '.' && $buffer[$bufLen - 2] === "\r" && $buffer[$bufLen - 1] === "\n") {
                // We have a possible ending, next loop check if it is.
                $possibleTerm = true;
            }
        }

        $message = 'Unspecified error while downloading OVER headers.';

        return $this->throwError(cli()->error($message), 1000);
    }

    /**
     * Check if the Message-ID has the required opening and closing brackets.
     *
     * @param  string  $messageID  The Message-ID with or without brackets.
     * @return string|false Message-ID with brackets or false if empty.
     */
    protected function _formatMessageID(string $messageID): string|false
    {
        $messageID = (string) $messageID;
        if ($messageID === '') {
            return false;
        }

        // Check if the first char is <, if not add it.
        if ($messageID[0] !== '<') {
            $messageID = ('<'.$messageID);
        }

        // Check if the last char is >, if not add it.
        if (! str_ends_with($messageID, '>')) {
            $messageID .= '>';
        }

        return $messageID;
    }

    /**
     * Download an article body (an article without the header).
     *
     * @return mixed|object|string
     *
     * @throws \Exception
     */
    protected function _getMessage(string $groupName, mixed $identifier): mixed
    {
        // Make sure the requested group is already selected, if not select it.
        if ($this->group() !== $groupName) {
            // Select the group.
            $summary = $this->selectGroup($groupName);
            // If there was an error selecting the group, return an error object.
            if (self::isError($summary)) {
                return $summary;
            }
        }

        // Check if this is an article number or message-id.
        if (! is_numeric($identifier)) {
            // It's a message-id so check if it has the triangular brackets.
            $identifier = $this->_formatMessageID($identifier);
        }

        // Tell the news server we want the body of an article.
        $response = $this->_sendCommand('BODY '.$identifier);
        if (self::isError($response)) {
            return $response;
        }

        if ($response === ResponseCode::BodyFollows->value) {
            // Use array to accumulate lines (faster than string concatenation for many appends)
            $bodyParts = [];
            $socket = $this->_socket;

            // Continue until connection is lost
            while (! feof($socket)) {
                // Retrieve and append up to 8192 characters from the server (larger buffer = fewer syscalls)
                $line = fgets($socket, 8192);

                // If the socket is empty/ an error occurs, false is returned.
                if ($line === false) {
                    return $this->throwError('Failed to read line from socket.', null);
                }

                // Check if the line terminates the text response.
                if ($line === ".\r\n") {
                    // Join all parts and attempt to yEnc decode
                    $body = implode('', $bodyParts);

                    return $this->_yencService->decodeIgnore($body);
                }

                // Check for line that starts with double period, remove one.
                if ($line[0] === '.' && isset($line[1]) && $line[1] === '.') {
                    $line = substr($line, 1);
                }

                // Add the line to the array
                $bodyParts[] = $line;
            }

            return $this->throwError('End of stream! Connection lost?', null);
        }

        return $this->_handleErrorResponse($response);
    }

    /**
     * Check if we are still connected. Reconnect if not.
     *
     * @param  bool  $reSelectGroup  Select back the group after connecting?
     * @return mixed On success: (bool)   True;
     *
     * @throws \Exception
     *                    On failure: (object) DariusIII\NetNntp\Error
     */
    protected function _checkConnection(bool $reSelectGroup = true): mixed
    {
        $currentGroup = $this->_currentGroup;
        // Check if we are connected.
        if (parent::_isConnected()) {
            $retVal = true;
        } else {
            if (\is_resource($this->_socket)) {
                $this->doQuit(true);
            }
            $retVal = $this->doConnect();

            if ($retVal === true && $reSelectGroup) {
                $group = $this->selectGroup($currentGroup);
                if (self::isError($group)) {
                    $retVal = $group;
                }
            }
        }

        return $retVal;
    }

    /**
     * Verify NNTP error code and return error.
     *
     * @param  int  $response  NNTP Response code
     * @return object DariusIII\NetNntp\Error
     */
    protected function _handleErrorResponse(int $response): object
    {
        return match ($response) {
            // 381, RFC2980: 'More authentication information required'
            ResponseCode::AuthenticationContinue->value => $this->throwError('More authentication information required', $response, $this->_currentStatusResponse()),
            // 400, RFC977: 'Service discontinued'
            ResponseCode::DisconnectingForced->value => $this->throwError('Server refused connection', $response, $this->_currentStatusResponse()),
            // 411, RFC977: 'no such news group'
            ResponseCode::NoSuchGroup->value => $this->throwError('No such news group on server', $response, $this->_currentStatusResponse()),
            // 412, RFC2980: 'No news group current selected'
            ResponseCode::NoGroupSelected->value => $this->throwError('No news group current selected', $response, $this->_currentStatusResponse()),
            // 420, RFC2980: 'Current article number is invalid'
            ResponseCode::NoArticleSelected->value => $this->throwError('Current article number is invalid', $response, $this->_currentStatusResponse()),
            // 421, RFC977: 'no next article in this group'
            ResponseCode::NoNextArticle->value => $this->throwError('No next article in this group', $response, $this->_currentStatusResponse()),
            // 422, RFC977: 'no previous article in this group'
            ResponseCode::NoPreviousArticle->value => $this->throwError('No previous article in this group', $response, $this->_currentStatusResponse()),
            // 423, RFC977: 'No such article number in this group'
            ResponseCode::NoSuchArticleNumber->value => $this->throwError('No such article number in this group', $response, $this->_currentStatusResponse()),
            // 430, RFC977: 'No such article found'
            ResponseCode::NoSuchArticleId->value => $this->throwError('No such article found', $response, $this->_currentStatusResponse()),
            // 435, RFC977: 'Article not wanted'
            ResponseCode::TransferUnwanted->value => $this->throwError('Article not wanted', $response, $this->_currentStatusResponse()),
            // 436, RFC977: 'Transfer failed - try again later'
            ResponseCode::TransferFailure->value => $this->throwError('Transfer failed - try again later', $response, $this->_currentStatusResponse()),
            // 437, RFC977: 'Article rejected - do not try again'
            ResponseCode::TransferRejected->value => $this->throwError('Article rejected - do not try again', $response, $this->_currentStatusResponse()),
            // 440, RFC977: 'posting not allowed'
            ResponseCode::PostingProhibited->value => $this->throwError('Posting not allowed', $response, $this->_currentStatusResponse()),
            // 441, RFC977: 'posting failed'
            ResponseCode::PostingFailure->value => $this->throwError('Posting failed', $response, $this->_currentStatusResponse()),
            // 481, RFC2980: 'Groups and descriptions unavailable'
            ResponseCode::XgtitleUnavailable->value => $this->throwError('Groups and descriptions unavailable', $response, $this->_currentStatusResponse()),
            // 482, RFC2980: 'Authentication rejected'
            ResponseCode::AuthenticationRejected->value => $this->throwError('Authentication rejected', $response, $this->_currentStatusResponse()),
            // 500, RFC977: 'Command not recognized'
            ResponseCode::UnknownCommand->value => $this->throwError('Command not recognized', $response, $this->_currentStatusResponse()),
            // 501, RFC977: 'Command syntax error'
            ResponseCode::SyntaxError->value => $this->throwError('Command syntax error', $response, $this->_currentStatusResponse()),
            // 502, RFC2980: 'No permission'
            ResponseCode::NotPermitted->value => $this->throwError('No permission', $response, $this->_currentStatusResponse()),
            // 503, RFC2980: 'Program fault - command not performed'
            ResponseCode::NotSupported->value => $this->throwError('Internal server error, function not performed', $response, $this->_currentStatusResponse()),
            // RFC4642: 'Can not initiate TLS negotiation'
            ResponseCode::TlsRefused->value => $this->throwError('Can not initiate TLS negotiation', $response, $this->_currentStatusResponse()),
            default => $this->throwError("Unexpected response: '{$this->_currentStatusResponse()}'", $response, $this->_currentStatusResponse()),
        };
    }

    /**
     * Connect to a NNTP server.
     *
     * @param  string|null  $host  (optional) The address of the NNTP-server to connect to, defaults to 'localhost'.
     * @param  mixed|null  $encryption  (optional) Use TLS/SSL on the connection?
     *                                  (string) 'tcp'                 => Use no encryption.
     *                                  'ssl', 'sslv3', 'tls' => Use encryption.
     *                                  (null)|(false) Use no encryption.
     * @param  int|null  $port  (optional) The port number to connect to, defaults to 119.
     * @param  int|null  $timeout  (optional) How many seconds to wait before giving up when connecting.
     * @param  int  $socketTimeout  (optional) How many seconds to wait before timing out the (blocked) socket.
     * @return mixed (bool) On success: True when posting allowed, otherwise false.
     *                      (object) On failure: DariusIII\NetNntp\Error
     */
    public function connect(?string $host = null, mixed $encryption = null, ?int $port = null, ?int $timeout = 15, int $socketTimeout = 120): mixed
    {
        if ($this->_isConnected()) {
            return $this->throwError('Already connected, disconnect first!', null);
        }
        // v1.0.x API
        if (is_int($encryption)) {
            trigger_error('You are using deprecated API v1.0 in DariusIII\NetNntp\Protocol\Client: connect() !', E_USER_NOTICE);
            $port = $encryption;
            $encryption = false;
        }
        if ($host === null) {
            $host = 'localhost';
        }
        // Choose transport based on encryption, and if no port is given, use default for that encryption.
        switch ($encryption) {
            case null:
            case false:
            case 'tcp':
                $transport = 'tcp';
                $port = $port ?? 119;
                break;
            case 'ssl':
            case 'tls':
                $transport = $encryption;
                $port = $port ?? 563;
                break;
            default:
                $message = '$encryption parameter must be either tcp, tls, ssl.';
                trigger_error($message, E_USER_ERROR);
        }
        // Attempt to connect to usenet.
        // Only create SSL context if using TLS/SSL transport
        $context = preg_match('/tls|ssl/', $transport)
            ? stream_context_create(streamSslContextOptions())
            : null;

        $socket = stream_socket_client(
            $transport.'://'.$host.':'.$port,
            $errorNumber,
            $errorString,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($socket === false) {
            $message = "Connection to $transport://$host:$port failed.";
            if (preg_match('/tls|ssl/', $transport)) {
                $message .= ' Try disabling SSL/TLS, and/or try a different port.';
            }
            $message .= ' [ERROR '.$errorNumber.': '.$errorString.']';

            return $this->throwError($message);
        }
        // Store the socket resource as property.
        $this->_socket = $socket;
        $this->_socketTimeout = $socketTimeout ?: $this->_socketTimeout;
        // Set the socket timeout.
        stream_set_timeout($this->_socket, $this->_socketTimeout);
        // Retrieve the server's initial response.
        $response = $this->_getStatusResponse();
        if (self::isError($response)) {
            return $response;
        }
        switch ($response) {
            // 200, Posting allowed
            case ResponseCode::ReadyPostingAllowed->value:
                return true;
                // 201, Posting NOT allowed
            case ResponseCode::ReadyPostingProhibited->value:

                return false;
            default:
                return $this->_handleErrorResponse($response);
        }
    }

    /**
     * Test whether we are connected or not.
     *
     * @return bool true or false
     */
    public function _isConnected(): bool
    {
        return is_resource($this->_socket) && ! feof($this->_socket);
    }

    /**
     * Check if we are connected to the usenet server.
     */
    public function isConnected(): bool
    {
        return $this->_isConnected();
    }

    /**
     * Get the current server address.
     */
    public function getCurrentServer(): string
    {
        return $this->_currentServer;
    }

    /**
     * Get the current port.
     */
    public function getCurrentPort(): int|string
    {
        return $this->_currentPort;
    }

    /**
     * Check if posting is allowed on the current connection.
     */
    public function isPostingAllowed(): bool
    {
        return $this->_postingAllowed;
    }

    /**
     * Get the currently selected group.
     */
    public function getCurrentGroup(): string
    {
        return $this->_currentGroup;
    }

    /**
     * Check if compression is enabled.
     */
    public function isCompressionEnabled(): bool
    {
        return $this->_compressionEnabled;
    }

    /**
     * Check if compression is supported.
     */
    public function isCompressionSupported(): bool
    {
        return $this->_compressionSupported;
    }
}
