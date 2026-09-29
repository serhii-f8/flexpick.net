<?php

namespace App\Constants;

/**
 * Why a request was closed as awaiting_credit. The dashboard reads it long
 * after the email went out, and the two cases need opposite advice.
 */
enum AwaitingCreditReason: string
{
    /** The workspace couldn't cover the runs: buy credit or upgrade. */
    case INSUFFICIENT = 'insufficient';

    /** Above every self-serve size band: more credit wouldn't help, contact us. */
    case TOO_LARGE = 'too_large';
}
