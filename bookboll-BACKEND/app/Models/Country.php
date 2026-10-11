<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Nnjeim\World\Models\Country as WorldCountry;

/**
 * The world package's Country with soft deletes.
 *
 * Use this when something needs the soft-delete API itself — deleting, restoring, or
 * looking at trashed rows (withTrashed / onlyTrashed). Ordinary reads do NOT need it:
 * App\Support\GeoSoftDeletes puts the same "not trashed" condition on the vendor model
 * at boot, so the thirty-odd files already importing Nnjeim\World\Models\Country stay
 * correct without being touched — including the package's own routes, which this app
 * cannot edit at all.
 */
class Country extends WorldCountry
{
    use SoftDeletes;
}
