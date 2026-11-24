<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Markup
    |--------------------------------------------------------------------------
    |
    | Users pay Pixelforge's cost plus this percentage. Charges are computed in
    | nanodollars and rounded up to the next whole nanodollar.
    |
    */

    'markup_percent' => (int) env('BILLING_MARKUP_PERCENT', 25),

    /*
    |--------------------------------------------------------------------------
    | Credit Packs
    |--------------------------------------------------------------------------
    |
    | The fake "Buy credits" amounts, in whole US dollars.
    |
    */

    'credit_packs' => [5, 10, 25],

];
