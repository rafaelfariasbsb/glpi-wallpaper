# Microsoft Intune configuration

Step-by-step guide for consuming the images hosted by the plugin.

This document covers the **Intune** side. Installing and using the plugin in GLPI is
covered in the [README](../README.md).

There are two ways to consume the images. Read the next section before picking one.

---

## Which approach: `DesktopImageUrl` or ADMX + Remediation

| | **Personalization CSP** (`DesktopImageUrl`) | **ADMX `Desktop\Wallpaper` + Remediation** |
|---|---|---|
| Windows editions | Enterprise / Education / IoT only | **Any**, including Pro and Home |
| Downloads the image | Windows itself, from the URL | A Remediation script, to a local path |
| Moving parts | One policy | One policy + one Remediation |
| URL must end in `.jpg`/`.png` | **Yes** — otherwise `DesktopImageStatus = 4` | No — PowerShell does not care |

**The edition mix decides it.** Count the fleet before choosing — in the deployment this
plugin was written for, 73% of 658 Windows devices were Enterprise and the rest Pro,
Home or unreported. On those remaining devices `DesktopImageUrl` applies, **reports
success, and changes nothing** — the worst kind of failure. The ADMX policy works
everywhere, at the cost of a script to fetch the file.

Pick `DesktopImageUrl` when the fleet is uniformly Enterprise/Education; pick ADMX +
Remediation when it is mixed. Both consume the same channel URLs from the plugin, and
in both cases changing the wallpaper is done in GLPI, never in Intune.

Scripts for the second approach live in [`intune/`](../intune/) — see
[ADMX + Remediation](#admx--remediation-any-windows-edition) below.

---

## Before you start

### 1. Confirm the Windows edition across the fleet

The `Personalization` CSP is supported on:

| Edition | Works? |
|---|---|
| Enterprise / Education | ✅ Directly |
| IoT Enterprise | ✅ Directly |
| **Pro / Pro Education** | ⚠️ Only with `SetEduPolicies` from the [SharedPC CSP](https://learn.microsoft.com/windows/client-management/mdm/sharedpc-csp), or with `BootToCloudPCEnhanced` |

If the fleet runs Pro without either of those, the policy will apply but the image will
not change — and Intune will report success. Sort this out before investing in the pilot.

> **Side effect:** setting the wallpaper through this policy **prevents users from
> changing it**. That is CSP behavior, not the plugin's.

### 2. Test the URLs before creating the policy

From any machine on a network the devices actually use:

```bash
curl -I https://YOUR-GLPI/plugins/wallpaper/piloto.jpg
```

You should see `HTTP/1.1 200`, `Content-Type: image/jpeg` (or `image/png`) and
`Cache-Control`. A `404` means the channel has no image yet — upload one from the GLPI
panel first. A `302` to a login page means the GLPI firewall did not exempt the route:
check that the plugin is **enabled**.

### 3. Prepare the groups

| Group | Members | Points to |
|---|---|---|
| `Wallpaper - Pilot` | A few test devices (ideally across different user profiles) | `piloto` channel URL |
| `Wallpaper - Production` | The fleet | `producao` channel URL |

A device **must not be in both groups** — two policies driving the same CSP conflict and
produce unpredictable results. If you use dynamic groups, explicitly exclude the pilot
devices from the production group.

---

## Creating the policy (Settings catalog)

Do this **twice**: once for the pilot, once for production.

1. Go to the [Intune admin center](https://intune.microsoft.com) →
   **Devices** → **Configuration** → **Create** → **New Policy**
2. **Platform:** `Windows 10 and later`
3. **Profile type:** `Settings catalog`
4. **Name:** `Wallpaper - Pilot` (or `Wallpaper - Production`)
5. Under **Configuration settings**, click **Add settings** and search for
   `Desktop Image Url`
6. Select the **Personalization** category → tick **Desktop Image Url**
7. Fill in the value:

   | Policy | Value |
   |---|---|
   | Pilot | `https://YOUR-GLPI/plugins/wallpaper/piloto.jpg` |
   | Production | `https://YOUR-GLPI/plugins/wallpaper/producao.jpg` |

   > Use exactly the URL shown in the plugin panel. The extension must be present:
   > Windows classifies the file type from the URL and reports *Unknown file type* when
   > it cannot recognize the target.

8. **Assignments:** assign to the matching group
9. **Review + create**

### Alternative: custom policy (OMA-URI)

If you prefer a **Custom** profile over the Settings catalog:

| Field | Value |
|---|---|
| OMA-URI | `./Vendor/MSFT/Personalization/DesktopImageUrl` |
| Data type | `String` |
| Value | `https://YOUR-GLPI/plugins/wallpaper/producao.jpg` |

For the lock screen, the equivalent node is
`./Vendor/MSFT/Personalization/LockScreenImageUrl`.

---

## Routine for changing the wallpaper

Once configured, changing the image **never requires editing the policy**:

1. In GLPI: **Plugins → Wallpaper**, upload the new image to the **pilot** channel
2. Force a sync on a pilot device
   (**Settings → Accounts → Access work or school → Info → Sync**)
3. Validate the result on screen
4. In GLPI, click **Promote pilot → production**
5. **Purge the Azure Front Door cache** (see below)
6. The fleet picks up the new image on its next sync

### Purging the Front Door cache

Because the URL is fixed, the edge may keep serving the old image for up to the TTL
configured in the plugin (default 1 hour). After promoting:

```bash
az afd endpoint purge \
  --resource-group YOUR-RG \
  --profile-name YOUR-AFD-PROFILE \
  --endpoint-name YOUR-ENDPOINT \
  --content-paths '/plugins/wallpaper/producao.jpg'
```

Alternative: lower the TTL in the plugin panel. The cost is more requests reaching GLPI —
the 1-hour default is a reasonable middle ground (recommended).

---

## ADMX + Remediation (any Windows edition)

Use this when the fleet is not uniformly Enterprise/Education. Two pieces per channel,
assigned to the **same** group.

### 1. The policy — where the image is read from

**Devices → Configuration → Create → Settings catalog**, platform `Windows 10 and later`.
Search for **Desktop Wallpaper** (category `Administrative Templates\Desktop\Desktop`):

| Field | Value |
|---|---|
| Wallpaper Name | `C:\ProgramData\Wallpaper\wallpaper.jpg` |
| Wallpaper Style | `Fill` |

The setting is **user-scoped** (`./User/Vendor/MSFT/Policy`), so it is assigned to a group
of users. It accepts only a local or UNC path — never an `http` URL, which is exactly why
the second piece exists.

> If the file is missing when the user signs in, **no wallpaper is displayed at all** —
> and the user cannot set their own, because this policy blocks that too. Everything
> below exists to keep that file present and valid.

### 2. The Remediation — how the image gets there

**Devices → Remediations → Create script package**, using
[`intune/detect-wallpaper.ps1`](../intune/detect-wallpaper.ps1) and
[`intune/remediate-wallpaper.ps1`](../intune/remediate-wallpaper.ps1):

| Setting | Value |
|---|---|
| Run this script using the logged-on credentials | **No** (runs as SYSTEM) |
| Enforce script signature check | No |
| Run script in 64-bit PowerShell | Yes |
| Schedule | Daily (hourly if wallpaper changes are urgent) |

Edit the `$Channel` line at the top of both scripts — `piloto` or `producao`. That is the
only difference between the two versions.

**Why a Remediation and not a platform script.** Platform scripts (*Devices → Scripts*)
run **once per device** and never again after they succeed. A wallpaper that can change
needs something that re-checks: a Remediation runs on a schedule, so promoting an image
in GLPI reaches the fleet on its own. It also self-heals if someone deletes the file.

**What detection does:** compares the plugin's `ETag` (a sha256 of the content) against a
marker file next to the image, using one `HEAD` request — a few bytes, no image download.
It also flags a missing, empty or non-image file. If the server is unreachable it reports
compliant on purpose: the image already on disk is still good, and re-downloading would
not fix the network.

**What remediation does:** downloads to a temporary file, verifies the magic bytes are
JPEG or PNG, and only then moves it into place. A half-written file would leave the fleet
with a broken background, so the destination is never written directly. It then records
the ETag and grants read access to the local users.

> **One device, one channel.** Both channels write to the same `wallpaper.jpg`, so a
> device that receives the pilot *and* the production Remediation would flip between
> images depending on which ran last. Keep the groups mutually exclusive, exactly as with
> the policies.

---

## Verification and diagnostics

### Status on the device itself

The CSP exposes the download result through `DesktopImageStatus`:

| Code | Meaning | What to do |
|---|---|---|
| `1` | Downloaded successfully | Nothing — it worked |
| `2` | Download in progress | Wait and re-check |
| `3` | Download failed | Device cannot reach the URL: network, DNS, TLS or Front Door |
| `4` | **Unknown file type** | The URL does not end in an image extension, or the `Content-Type` is wrong |
| `5` | Unsupported URL scheme | Use `https://` |
| `6` | Max retries failed | Network instability or endpoint down |

Query it on the device (PowerShell as administrator):

```powershell
Get-ChildItem -Path 'HKLM:\SOFTWARE\Microsoft\PolicyManager\current\device\Personalization'
```

### The downloaded image

Windows keeps the local copy at:

```
C:\ProgramData\Microsoft\Windows\Personalization\Desktop
```

If the file is there and correct but the screen did not change, the problem is the
Windows edition (see item 1) or another policy overriding it.

### On the GLPI side

If you enabled the network restriction in the plugin, every block shows up under
**Administration → Log**, with the IP and channel. Remember that **behind Front Door the
observed IP is the edge's**, not the device's — see the corresponding section in the
[README](../README.md#optional-network-restriction).

---

## Common problems

| Symptom | Likely cause |
|---|---|
| Policy reports success, wallpaper does not change | Windows Pro without `SetEduPolicies` |
| `DesktopImageStatus = 4` | URL without an image extension — use the URL shown in the panel |
| `DesktopImageStatus = 3` | Device cannot reach GLPI; test with `curl -I` from the device's network |
| Old image persists after promoting | Front Door cache — purge it |
| Works on pilot, not on production | Device is in both groups, with conflicting policies |
| `404` on the URL | Channel has no image yet in the GLPI panel |
| Redirect to the login page | Plugin disabled in GLPI |

---

## References

- [Personalization CSP](https://learn.microsoft.com/windows/client-management/mdm/personalization-csp)
- [Configure the desktop and lock screen backgrounds](https://learn.microsoft.com/windows/configuration/background/)
- [Windows device restrictions in Intune](https://learn.microsoft.com/intune/device-configuration/templates/ref-device-restrictions-windows#personalization)
- [SharedPC CSP](https://learn.microsoft.com/windows/client-management/mdm/sharedpc-csp)
