# White-label subdomains (`*.bookboll.com`) on cPanel — step by step

Verified against the live server on 2026-09-13.

| Fact | Value | How it was checked |
|---|---|---|
| Host | Namecheap shared hosting, **LiteSpeed** | `server: LiteSpeed` response header |
| DNS nameservers | `dns1/dns2.namecheaphosting.com` → zone lives in **cPanel Zone Editor** | `dig NS bookboll.com` |
| Server IP | **209.74.67.145** | `dig A bookboll.com` |
| AutoSSL provider | **Sectigo** — the provider that *can* issue wildcards | cert issuer on `bookboll.com` |
| Wildcard DNS | **missing** — `zzztest123.bookboll.com` is NXDOMAIN | `dig A zzztest123.bookboll.com` |
| Wildcard cert | **missing** — SANs are only `bookboll.com, www.bookboll.com` | `openssl s_client` |
| SPA rewrite | **already present** on the apex, but naive | `/this/is/not/a/route` returns `index.html` |

LiteSpeed reads `.htaccess` with full Apache syntax, so `public/.htaccess` in this repo
works as-is. Nothing in the app needs changing: `src/lib/brand.js` → `detectBrandSlug()`
resolves the brand from `window.location.hostname` in the browser, and the API already
allows every subdomain via the CORS pattern `#^https?://([\w-]+\.)?bookboll\.com$#`.

Two things to do, in this order.

---

## Step 1 — Create the wildcard subdomain (this also creates the DNS record)

cPanel → **Domains** → **Create A New Domain**.

- **Domain:** `*.bookboll.com`
- **Share document root:** leave it **ticked**, pointed at `bookboll.com`'s existing root.

That checkbox is the whole trick. If you untick it, cPanel invents an empty
`public_html/_wildcard_` folder, DNS resolves fine, and every provider subdomain serves a
blank directory — the most common way this step silently fails.

To find the apex's document root: cPanel → **Domains** and read the *Document Root* column
for `bookboll.com`. It's `/home/<user>/public_html` if bookboll.com is the primary domain,
or `/home/<user>/bookboll.com` if it's an addon domain.

One shared root is correct here — the same build serves the marketplace and every provider
storefront, because the brand is decided client-side from the hostname.

**Verify DNS before moving on.** Creating the subdomain normally writes the `*` A record
into the zone automatically:

```bash
dig +short A zzztest123.bookboll.com     # expect 209.74.67.145
```

If that's empty, add it by hand — cPanel → **Zone Editor** → *Manage* on `bookboll.com` →
**Add Record**: Type `A`, Name `*`, Record `209.74.67.145`, TTL `300`.

Existing subdomains are unaffected: explicit records (`admin`, `business`, `newbackend`,
`www`, `mail`, `cpanel`, `webmail`, `ftp`) always take priority over `*`. Glance at the zone
and confirm the mail records are still listed before you leave.

> If cPanel refuses the `*` character, wildcard subdomains are disabled server-side. That's
> a WHM setting you don't have on shared hosting — open a Namecheap ticket asking them to
> enable wildcard subdomains for the account.

## Step 2 — Get a wildcard certificate

`bookboll.com`'s current cert covers only `bookboll.com` and `www.bookboll.com`, so
`alamgir.bookboll.com` will throw `NET::ERR_CERT_COMMON_NAME_INVALID` until a cert with a
`*.bookboll.com` SAN is installed.

Your AutoSSL provider is **Sectigo**, which supports wildcards — so try the free path first:

cPanel → **SSL/TLS Status** → tick `*.bookboll.com` → **Run AutoSSL**. Give it a few
minutes, then check the SANs actually changed:

```bash
echo | openssl s_client -connect alamgir.bookboll.com:443 -servername alamgir.bookboll.com 2>/dev/null \
  | openssl x509 -noout -text | grep -A2 "Subject Alternative Name"
```

You want `DNS:*.bookboll.com` in that list. If AutoSSL declines the wildcard (shared hosts
sometimes restrict it), pick one:

- **Buy a wildcard cert** — Namecheap sells PositiveSSL Wildcard; install under cPanel →
  **SSL/TLS** → *Install and Manage SSL* → paste cert + key for `*.bookboll.com`. Simplest,
  costs money, needs manual renewal yearly.
- **Move DNS to Cloudflare** and orange-cloud the `*` record — Universal SSL covers one
  wildcard level free. Set SSL/TLS mode to **Full (strict)** and install a Cloudflare
  **Origin CA** cert on the cPanel vhost. Free, but it moves your DNS off Namecheap.

## Step 3 — Deploy the build (including the new `.htaccess`)

```bash
cd apps/web
npm run build
```

Build with production env — `.env` currently has `VITE_API_URL=http://localhost:8000/api`;
production is `https://newbackend.bookboll.com/api`.

Upload the **contents** of `dist/` into the shared document root.

**Back up the existing `.htaccess` first.** The apex already has a rewrite (deep links work
today), and this repo's file will replace it. Rename the current one to `.htaccess.bak`
before uploading, and if the server file contains anything beyond an SPA fallback — a
redirect, a PHP handler, an IP block — merge those lines into the new file.

`.htaccess` is a dotfile: File Manager hides it until you enable Settings → *Show Hidden
Files*, and plenty of FTP clients skip dotfiles entirely. Confirm it actually landed.

### What this repo's `.htaccess` fixes beyond what's on the server now

The live rewrite returns `index.html` for *everything* missing, including assets —
`https://bookboll.com/assets/definitely-not-real.js` currently answers `200 text/html`.
That's why a stale deploy shows a blank page with `Unexpected token '<'` instead of a clean
404. The new file returns a real 404 for missing `/assets/*`, keeps the HTTPS redirect
host-preserving via `%{HTTP_HOST}` (a hardcoded domain would bounce every provider
subdomain to the apex), sets `Options -MultiViews` so `/venues` can't be swallowed by
content negotiation, and marks `index.html` `no-store` while hashed assets are `immutable`.

## Step 4 — Verify end to end

```bash
dig +short alamgir.bookboll.com                        # 209.74.67.145
curl -sI https://alamgir.bookboll.com/                 # 200 text/html
curl -sI https://alamgir.bookboll.com/venues           # 200  (SPA fallback, not 404)
curl -sI https://alamgir.bookboll.com/assets/nope.js   # 404  (not 200 text/html)
```

Then open `https://alamgir.bookboll.com` — you should see that provider's logo, colors and
only their listings.

**If it renders the plain Bookboll marketplace instead**, serving is fixed and the rest is
data, not infrastructure. Check the brand resolves:

```bash
curl -s "https://newbackend.bookboll.com/api/marketplace/brand?slug=alamgir"
```

White-labelling is opt-in, so a brand that isn't **enabled** only resolves with
`&preview=1`. If preview works and plain doesn't, enable the brand in the dashboard. Also
confirm the provider's slug really is `alamgir` — `providerSlug()` in `brand.js` falls back
through `brand_subdomain` → `username` → slugified business name → `biz-<id>`.

---

## Known follow-ups (not fixed here)

- **Canonical tags point every storefront at the apex.** `index.html` hardcodes
  `<link rel="canonical" href="https://bookboll.com/">` plus `og:url`/`og:image`, and
  `public/robots.txt` sets `Sitemap: https://bookboll.com/sitemap.xml`. On a white-label
  subdomain this tells Google the provider site is a duplicate of the marketplace. Decide
  whether provider sites should be indexed independently, then rewrite these per-host.
- **`RESERVED_SUBS`** in `brand.js` lists `www, app, admin, api, business, staging, dev` —
  it doesn't include `new`, `newadmin`, `newbackend`, `stagingadmin`, `mail` or `webmail`.
  Harmless today, since each has its own DNS record and never reaches the wildcard vhost.
- **`apps/business` and `apps/admin` have no `.htaccess`.** Same deep-link 404 risk on
  LiteSpeed; they just don't need the wildcard parts.
