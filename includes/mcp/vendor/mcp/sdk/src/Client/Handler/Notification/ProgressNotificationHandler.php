<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace ADBC\Vendor\Mcp\Client\Handler\Notification;

use ADBC\Vendor\Mcp\Client\State\ClientStateInterface;
use ADBC\Vendor\Mcp\Schema\JsonRpc\Notification;
use ADBC\Vendor\Mcp\Schema\Notification\ProgressNotification;
/**
 * Internal handler for progress notifications.
 *
 * Writes progress data to state for transport to consume and execute callbacks.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 *
 * @internal
 */
class ProgressNotificationHandler implements NotificationHandlerInterface
{
    public function __construct(private readonly ClientStateInterface $state)
    {
    }
    public function supports(Notification $notification): bool
    {
        return $notification instanceof ProgressNotification;
    }
    public function handle(Notification $notification): void
    {
        if (!$notification instanceof ProgressNotification) {
            return;
        }
        $this->state->storeProgress((string) $notification->progressToken, $notification->progress, $notification->total, $notification->message);
    }
}
