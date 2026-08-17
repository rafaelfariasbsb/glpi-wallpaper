# GLPI Wallpaper

A **GLPI 11** plugin that hosts wallpaper images at fixed, public URLs so they can be
consumed by the **Microsoft Intune** `Personalization/DesktopImageUrl` policy.

Uploads are protected by GLPI profiles; downloads are anonymous, because Windows
fetches the image under the machine's `SYSTEM` context, with no session and no cookie.

## Why it exists

There is no equivalent plugin in the GLPI ecosystem.
[Branding](https://help.glpi-project.org/doc-plugins/plugin-glpi-network/branding)
only changes the background of GLPI's own login screen, and
[phonebg](https://github.com/monta990/phonebg) generates phone wallpapers from
inventory data. Neither serves an image for an MDM to consume.

## How it works

Two fixed channels, each with a URL that **never changes**:

| Channel | Purpose | URL |
|---|---|---|
| `producao` | entire fleet | `https://YOUR-GLPI/plugins/wallpaper/producao.jpg` |
| `piloto` | test group | `https://YOUR-GLPI/plugins/wallpaper/piloto.jpg` |

The workflow is built so the team can validate before reaching everyone:

1. Upload the new image to the **pilot** channel
2. The Intune test group already points at the pilot URL — validate on a real machine
3. Click **Promote pilot → production**
4. The production URL does not change; the fleet picks up the new image on its next sync

Changing the wallpaper never requires touching the Intune policy.

### Why the URL ends in `.jpg`

The Personalization CSP **classifies the file type** and reports the result through
`DesktopImageStatus`, where code **`4` means "Unknown file type"**. Microsoft's
documentation consistently describes the value as *"an http or https URL to a jpg,
jpeg or png image"*, with examples that always carry an explicit extension.

That is why the public URL is a native GLPI 11 route
([`src/Controller/ImageController.php`](wallpaper/src/Controller/ImageController.php))
ending in an image extension, rather than `image.php?c=producao`.

The requested extension **does not decide what is served** — the `Content-Type` always
comes from the actual file. If you switch the image from PNG to JPEG, the old URL keeps
responding and the Intune policy does not break; the panel simply warns about the
mismatch so you can align it when convenient.

The legacy route remains available for diagnostics:
`https://YOUR-GLPI/plugins/wallpaper/front/image.php?c=producao`

## Installation

```bash
cd /var/www/glpi/plugins
git clone https://github.com/rafaelfariasbsb/glpi-wallpaper.git
mv glpi-wallpaper/wallpaper .    # the plugin directory must be named "wallpaper"
chown -R www-data:www-data wallpaper
```

Then install and enable *Wallpaper* under **Setup → Plugins**.

**Log out and log back in.** GLPI caches both the menu (`$_SESSION['glpimenu']`) and the
profile rights at login time, so a freshly installed plugin does not appear until you
start a new session. The entry then shows up under **Setup → Wallpaper**.

The plugin stores images in `files/_plugins/wallpaper/`, created at install time. It
never writes to its own code directory.

### The menu entry does not appear

| Check | How |
|---|---|
| Did you start a new session? | Log out and back in — the menu is built at login |
| Does your profile have the right? | **Administration → Profiles → (your profile) → Wallpaper**, tick at least *Read* and save |
| Is the panel reachable directly? | Open `https://YOUR-GLPI/plugins/wallpaper/front/wallpaper.php` — if it loads, the issue is only the menu cache |

## Permissions

Under **Administration → Profiles → (profile) → Wallpaper**:

| Right | Allows |
|---|---|
| Read | viewing the panel and the URLs |
| Update | uploading to the **pilot** channel and editing settings |
| Promote | promoting pilot → production, and uploading directly to **production** |

Uploading straight to production requires the promote right, because it reaches the
entire fleet without passing through the pilot.

At install time, profiles that can already update GLPI's Configuration receive all
three rights; every other profile gets **no access**.

## Response headers

Delivery builds its headers explicitly
([`src/ImageResponse.php`](wallpaper/src/ImageResponse.php)) instead of delegating to
the core, so the behavior is auditable in one place:

| Header | Value | Rationale |
|---|---|---|
| `Content-Type` | `image/jpeg` or `image/png` | Exact, from the allowlist — never reflected from user input |
| `X-Content-Type-Options` | `nosniff` | Prevents the content being reinterpreted as another type |
| `Content-Disposition` | `inline; filename="wallpaper-<channel>.<ext>"` | Direct display, no forced download |
| `Cache-Control` | `public, max-age=<TTL>` | Enables edge and client caching |
| `ETag` | `"<sha256 of the content>"` | Strong validator for conditional requests |
| `Last-Modified` | file timestamp | Alternative validator |
| `Content-Length` | actual size | Sent on `HEAD` as well |

Other behavior:

- **`HEAD`** returns the same headers with no body — Front Door and Intune itself may
  probe before downloading.
- **`If-None-Match` / `If-Modified-Since`** return **304**, with `If-None-Match` taking
  precedence (RFC 9110). Weak validators (`W/"..."`) are accepted.
- **Any method other than GET/HEAD** returns **405** with `Allow`.
- **Empty channel, incomplete record, or missing file** returns a **clean 404** — never
  a 200 with an empty body, which Windows would treat as an invalid image.
- Output buffers are discarded before writing, so no PHP warning can corrupt the image
  bytes.

### Caching and Azure Front Door

The TTL is configurable in the panel (default **3600s**; `0` disables it). Edge caching
matters because this is an **anonymous** endpoint — without it, it would be a cheap
source of load against GLPI.

⚠️ **Operational trap:** since the URL is fixed, after *Promote pilot → production* the
edge may keep serving the old image for up to the TTL. **Purge the Front Door cache**
after promoting, or use a TTL that matches how urgent your changes are.

## Optional network restriction

**Decision: the IP filter ships DISABLED by default.**

The reason is this plugin's actual deployment — GLPI published on the internet behind
**Azure Front Door**, serving cloud-native machines:

1. **Behind a CDN, `REMOTE_ADDR` is always the edge IP, never the machine's.** A naive
   filter would evaluate Front Door rather than the device — blocking everyone or no
   one, but never what was intended.
2. **Cloud-native machines connect from anywhere** — home office, 4G, customer networks.
   Restricting by IP breaks precisely the devices that depend on MDM the most.
3. **The URL is fixed and guessable by design.** That is a requirement of the Intune
   policy, and the content is a static image meant to appear across the whole fleet.
   Acceptable as long as the wallpaper carries no sensitive information.

If you still need to restrict access, fill in the panel:

| Field | Effect |
|---|---|
| `Allowed networks` | CIDRs (IPv4/IPv6) or bare IPs. Empty = any origin allowed |
| `Trusted proxies` | **Required behind a CDN.** Azure Front Door ranges (service tag `AzureFrontDoor.Backend`) |
| `Client IP header` | `X-Forwarded-For` or `X-Azure-ClientIP` |

The IP header is **only read when the connection comes from an address listed under
"Trusted proxies"** — never raw. Without that rule, any client could forge its own IP
and the restriction would be decorative. Front Door populates `X-Azure-ClientIP` with a
single address, whereas `X-Forwarded-For` arrives as a chain.

Saving allowed networks without declaring any trusted proxy raises a warning in the
panel, because that combination silently locks out the entire fleet.

Every block is recorded in the GLPI log with the IP and channel — Intune does not report
the block, the wallpaper simply fails to apply.

Recommended: deploy with the list empty, validate the pilot end to end, and only then
consider restricting.

## Security

- The GLPI 11 firewall exempts **only** image delivery (`Firewall::STRATEGY_NO_CHECK`
  for the legacy script and `#[SecurityStrategy]` on the route); the panel still
  requires authentication and rights.
- The channel is validated against a fixed allowlist (`producao`, `piloto`) and the file
  path derives from it: directory traversal is not possible.
- Uploads are validated by the file's real content (`getimagesize`), not by extension.
  Only JPEG and PNG are accepted.
- The served `Content-Type` is re-validated against the allowlist at delivery time, even
  though it was already validated at upload.
- Images live outside the docroot, in `files/_plugins/`, stored as `.bin` and served by
  PHP.

## Intune configuration

Full step-by-step — creating the policies, pilot/production groups, Front Door purge,
on-device verification, and a troubleshooting table:

📄 **[docs/INTUNE.md](docs/INTUNE.md)**

In short: a **Settings catalog** policy → **Personalization** category → **Desktop
Image Url** set to the URL shown in the panel, assigned to the matching group.

Two things that commonly bite:

- The Personalization CSP is supported on **Enterprise/Education** and works on **Pro**
  only with `SetEduPolicies` from the
  [SharedPC CSP](https://learn.microsoft.com/windows/client-management/mdm/sharedpc-csp)
  or with `BootToCloudPCEnhanced`. Pro is **not** automatically out, but it does require
  extra configuration.
- Setting the wallpaper through this policy **prevents users from changing it**.

## Development

None of the tests require a GLPI instance or a local PHP installation.

CIDR logic and real-client-IP detection (32 assertions):

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/network_filter_test.php
```

End-to-end HTTP delivery with `curl` — headers, conditional 304, `HEAD`, 404 and 405
(24 assertions). Runs the plugin's real code against minimal GLPI stubs:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli sh tests/endpoint/run.sh
```

Lint:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli sh -c 'find wallpaper -name "*.php" -exec php -l {} \;'
```

## Note on language

Code comments and the admin UI are in Portuguese; the documentation is in English.

## License

GPL-3.0-or-later, the same as GLPI.
