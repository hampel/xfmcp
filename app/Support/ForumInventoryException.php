<?php

namespace App\Support;

use RuntimeException;

/**
 * The inventory file could not be read, or a table in it is not usable.
 *
 * The message is written for the person fixing the file and never carries a key.
 */
class ForumInventoryException extends RuntimeException {}
