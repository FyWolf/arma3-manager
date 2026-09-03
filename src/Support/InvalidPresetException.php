<?php

namespace FyWolf\Arma3Manager\Support;

use RuntimeException;

/**
 * An uploaded file was refused before it was parsed as a preset.
 *
 * Its message is written to be shown to a customer verbatim, so every throw site
 * says what was wrong and what to do about it — "that is not a launcher preset"
 * rather than "validation failed". A customer who exported the wrong file from
 * the launcher, or picked the wrong file from their downloads folder, has to be
 * able to fix it without opening a ticket.
 */
class InvalidPresetException extends RuntimeException
{
}
