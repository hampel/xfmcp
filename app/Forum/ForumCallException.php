<?php

namespace App\Forum;

use RuntimeException;

/**
 * A forum could not be asked, or refused. The message is the whole of what a tool tells the
 * agent, so it says whose problem it is and never carries a key or a response body.
 */
class ForumCallException extends RuntimeException {}
