<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A provider recording that can't be fetched until something outside Projector changes (a
 * missing permission, a deleted file) — retrying on its own won't help, so the job reports it
 * to the user right away instead.
 */
class RecordingUnavailableException extends RuntimeException {}
