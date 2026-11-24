<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    case Purchase = 'purchase';
    /** Reserves the maximum possible charge while a generation is in flight. */
    case Hold = 'hold';
    /** Returns a hold once the generation finishes (succeeded or failed). */
    case HoldRelease = 'hold_release';
    /** The actual cost (Pixelforge cost plus markup) of a succeeded generation. */
    case Charge = 'charge';
}
