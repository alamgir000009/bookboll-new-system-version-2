<?php

namespace App\Exceptions;

use Exception;

/**
 * A customer sign-in that failed for a reason the customer should be told about —
 * a blocked account, say — as opposed to a bug.
 *
 * It exists because the Google sign-in path now has two front doors: the mobile apps
 * POST a native id_token to /customer/google-login, and the web storefronts come
 * through the OAuth broker. The shared code between them cannot return an HTTP
 * response (one caller renders a redirect, the other JSON), so it throws this and
 * each front door phrases the answer in its own medium. getMessage() is safe to show.
 */
class CustomerAuthException extends Exception
{
}
