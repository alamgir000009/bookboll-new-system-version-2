<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Strict upload ownership
    |--------------------------------------------------------------------------
    |
    | Files stored before the `uploads` table existed have no owner recorded,
    | and there is no way to work out who uploaded them after the fact. While
    | this is false, such a file is still accepted when it is submitted (it is
    | on our disk and it exists) and its use is logged, so existing content
    | keeps working.
    |
    | Turn it on once those logs go quiet — from then on a photo must belong to
    | the account submitting it, with no exception. Every file uploaded after
    | this release is owner-checked either way; the setting only decides what
    | happens to the untracked ones.
    |
    */

    'strict_ownership' => (bool) env('UPLOAD_STRICT_OWNERSHIP', false),

];
