<?php

declare(strict_types=1);

namespace Jedarden\Pdftract;

/**
 * Exception thrown when the pdftract endpoint has no route for the request
 *
 * A 404 response: the path this client POSTs to does not exist on the
 * server that answered. Usually a proxy routed the request somewhere that
 * is not a pdftract serve endpoint, or the deployment runs a build from
 * before the route was added.
 */
class NotFoundException extends PdftractException
{
}
