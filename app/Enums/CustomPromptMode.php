<?php

namespace App\Enums;

/**
 * How a document's stored `custom_prompt` relates to the standard AI processing for it (see
 * ProjectAiService::process()). A document with no mode stored predates this choice and is
 * treated as Replace, which is how every custom prompt behaved before it existed.
 */
enum CustomPromptMode: string
{
    /**
     * The standard template still runs; the custom prompt is extra guidance on top of it.
     */
    case Add = 'add';

    /**
     * The custom prompt is the entire instruction, in place of the standard template — for
     * producing something the standard processing doesn't.
     */
    case Replace = 'replace';
}
