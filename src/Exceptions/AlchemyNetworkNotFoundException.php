<?php

namespace ItHealer\LaravelEvm\Exceptions;

/**
 * Alchemy has no URL for the network: neither the built-in list nor the network's own slug
 * know it and none of the guessed slugs answered with its chain id.
 */
class AlchemyNetworkNotFoundException extends \InvalidArgumentException
{
}
