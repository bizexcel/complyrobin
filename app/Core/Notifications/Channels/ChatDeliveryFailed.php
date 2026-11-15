<?php

namespace App\Core\Notifications\Channels;

use RuntimeException;

/**
 * A chat message couldn't be delivered. The message is safe to show: it never includes the remote reply.
 */
final class ChatDeliveryFailed extends RuntimeException {}
