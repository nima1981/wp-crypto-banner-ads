# Crypto Banner Ads

A self-contained WordPress plugin for **cookie-free, account-free paid banner ads**.

- Visitors upload one or more banners, pay Bitcoin to that banner's own address, and get a private management link.
- Every click on a banner deducts a configurable dollar amount (default **$0.02**) worth of bitcoin from that banner's balance.
- The site owner just pastes a snippet wherever a banner should appear. If no banner has money left, the slot stays completely empty.
- Sweeping the wallet does not affect the plugin: a banner's balance is *money that ever arrived* minus *money deducted by clicks*. Spending from the wallet is invisible to it.

This is a separate plugin from `wpd-bitcoin`, and does not share code with it.

---

## Requirements

| Need | Why |
|---|---|
| PHP 7.4+ | WordPress baseline |
| PHP **GMP** or **BCMath** | secp256k1 maths for deriving addresses from your xpub |
| PHP **RIPEMD160** | Bitcoin addresses (hash160) |
| GD or Imagick | re-encoding uploaded images (strips EXIF/embedded payloads) |
| Working WP-Cron or a real cron job | payment checks every 5 minutes |
| REST API enabled | the snippet asks `/wp-json/dwads/v1/serve` for a banner |

The plugin warns you on the Plugins screen if a requirement is missing.

---

## Install

1. Copy the `crypto-banner-ads` folder to `wp-content/plugins/`.
2. Activate **Crypto Banner Ads**.
3. Go to **Crypto Ads → Settings**.

### One-time setup checklist

1. **Wallet key.** Paste an account-level **xpub / ypub / zpub** (watch-only; no private keys are stored). Derivation is non-hardened, default path `0/{i}`. See [Wallet setup](#wallet-setup-the-xpub-and-staying-in-control-of-the-money) for wallet-by-wallet steps, how to verify it, and the gap-limit caveat.
2. **Upload page.** Create a page containing the shortcode:

   ```
   [dwads_upload]
   ```

3. **Placement.** In the page editor use

   ```
   [dwads slot="300x250"]
   ```

   or the **Banner Ad Slot** widget, or paste the raw snippet from the settings page (works with page caching and with themes that ignore shortcodes):

   ```html
   <script async src="https://example.com/wp-content/plugins/crypto-banner-ads/assets/dwads.js"></script>
   <div data-dwads-slot="300x250" data-dwads-api="https://example.com/wp-json/dwads/v1/serve"></div>
   ```

   **The slot value is a size filter, not a CSS size.** It must be one of the configured sizes — by default `728x90`, `320x100`, `300x250`, `336x280`, `250x250`, `160x600`, `300x600`, `970x250` — and a banner of exactly those pixels will fill it. A slot that is blank, misspelled or no longer configured renders nothing at all, and the endpoint answers HTTP 400 (the loader logs a console warning in that case). Add your own sizes under **Settings → Uploads → Allowed sizes**; they then appear in the snippet picker, the upload form and the widget.

4. **Exchange widget (optional).** The plugin builds the ChangeNOW iframe itself. The **link id** field is pre-filled with `bc1c9b656c7061`, the plugin's default affiliate id, so the widget works without any setup — that default sends a share of the exchange fees to the plugin author. Replace it with your own `link_id` if you have one, or type `none` to drop the parameter entirely (an empty field keeps the default). The widget URL is also editable, so you can change `from=`, the size, or the whole provider; `{ADDRESS}` marks where the banner address goes.
5. **Cron.** WP-Cron only runs on traffic. For a quiet site add a real cron job:

   ```
   */5 * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron >/dev/null
   ```

---

## Wallet setup: the xpub, and staying in control of the money

### What the plugin actually stores

An **extended public key** (BIP32: a public key plus a chain code). From it, anyone can compute every address in that account and read its balance. Nobody can spend from it — signing needs the seed or the private keys, which never leave your wallet.

The plugin derives one address per banner along the external chain, `0/{i}`, and writes no private key anywhere. If the web server is compromised, the attacker learns which addresses belong to you — which is already public on-chain — and gains nothing spendable. It is still a privacy link, so treat the key as sensitive-ish, not as a secret to guard with your life.

The field refuses `xprv`/`yprv`/`zprv` keys outright: an extended *private* key would let anyone drain the wallet, and it is never needed here.

### Which key, from which wallet

Export the key at **account level** — the node three hardened steps below the master, e.g. `m/84'/0'/0'`. The plugin's `0/{i}` then reproduces exactly the wallet's normal receiving addresses, which is what makes them visible and spendable in the wallet's own UI.

| Wallet | Where to get it | Notes |
|---|---|---|
| **Sparrow** (recommended, also for Coldcard / Ledger / Trezor) | Open the ad wallet → Settings → Keystore tab → copy the xpub/zpub | Shows the script type; a zpub produces `bc1…` addresses. Has a Gap limit setting. |
| **Electrum** (desktop or mobile) | Wallet → Information → “Master public key” | For a standard Electrum wallet the receive chain is `m/0/i`, exactly what `0/{i}` produces from that key. |
| **Bitcoin Core** (descriptor wallet) | `listdescriptors`, take the external descriptor | e.g. `wpkh([f1ngerpr1nt/84h/0h/0h]xpub6C…/0/*)` — use the xpub portion, path `0/{i}`. |
| **Ledger Live / Trezor Suite** | Not exposed | Connect the device to Sparrow or Electrum and export the account key there. |
| **BTCPay Server** | Store → Wallet → derivation scheme | Same kind of account key. |

If a wallet gives you a key labelled `xpub` for an account that is actually native segwit, set **Address type** to “Native segwit (bc1…)” in the settings. The prefix in the name is cosmetic; the script type is what decides the address format. Getting this wrong does not lose money, but the addresses will not appear in the wallet that created them.

### Verify before you rely on it

1. Save the key. The settings page then prints the **next address** it will hand out, and **Crypto Ads → Diagnostics** lists the next five.
2. Compare those with the wallet's own receive addresses (Sparrow/Electrum: Addresses tab). Identical = correct key and correct path. Different = stop, fix the key or the path, do not upload banners yet.
3. Create one test banner, send a small payment, press **Check for payment now** on its management page, and confirm the balance moves and the coins appear in your wallet.
4. Sweep that test amount once and confirm the advertiser-facing balance does **not** change. That is the whole sweep-proof design in one check.

### The gap limit — the one thing that actually bites people

Wallets do not scan addresses forever. They scan a fixed number of *unused* addresses ahead of the highest one they know — 20 by default in most software. Every banner consumes a derivation index even if it is never funded, so with enough banners a payout can land on an index your wallet never looked at. The coins are still yours; the wallet just does not show them.

- Raise the limit before it matters: Sparrow → Settings → Gap limit; Electrum → set `wallet.gap_limit` in the config and restart; Bitcoin Core descriptor wallets are unaffected.
- Or keep it small: give ads their own account and keep the banner count modest, so a re-scan with a higher gap is quick.
- Recovery is always possible: import the address or the descriptor with a high gap, or rescan the wallet with a larger limit.

### Sweeping and custody

Revenue arrives as many small UTXOs on many addresses. The plugin counts received outputs and never looks at what the address spent, so consolidation cannot disturb it:

- Sweep the whole account to one of your own addresses when fees are low, ideally as one batched transaction rather than one per address.
- Emptying the addresses is fine; the account and the seed are what matter.
- Back up the site database alongside the wallet. If the database is lost, the plugin forgets which address belongs to which banner (the coins are still in your wallet, but the click accounting is gone).
- The **seed is the only thing that must survive**. Losing the xpub costs nothing — re-export it. Losing the seed loses everything, and no amount of plugin data helps.

### Rotating the key later

Existing banners keep the addresses they were issued, and the plugin keeps polling them, so late payments still credit. A new key only applies to banners created after the change. Keep the old wallet — or at least its seed — for as long as any old banner could still receive money.

---

## Decisions you made, and how they are implemented

| Decision | Implementation |
|---|---|
| **xpub, no private keys** | `DWADS_Xpub` parses xpub/ypub/zpub/tpub/upub/vpub, derives non-hardened children, and encodes legacy, P2SH-segwit or native segwit addresses. Nothing secret is ever written to disk. |
| **Admin approval configurable** | Setting *Require admin approval*. On: new banners are `pending` until approved. Off: they are `active` immediately. |
| **Credit as soon as it appears on the network** | `credit_mempool` (default on). A payment is credited when mempool.space lists it, and its credit is removed automatically if it disappears twice without confirming (`missing_polls` counter), so dropped/replaced transactions do not leave phantom balance. |
| **No refunds** | Stated on the upload and management pages. There is no refund path in the code. |
| **mempool.space only** | One API. HTTP 429/5xx pauses all polling for 10 minutes and logs it, instead of hammering the endpoint. |

---

## How the accounting works

```
banner balance (sats) = sats_received − sats_spent
```

- `sats_received` is the sum of **received outputs** credited in `wp_dwads_payments`. It never looks at what the address *spent*, so sweeping is irrelevant.
- `sats_spent` is summed from charged clicks.
- A click charges `ceil(price_usd / btc_usd × 100,000,000)` satoshis, using mempool.space's price feed (cached 15 minutes, optional manual fallback).
- The charge is a single SQL statement:

  ```sql
  UPDATE … SET sats_spent = sats_spent + n
  WHERE id = ? AND status = 'active' AND (sats_received - sats_spent) >= n
  ```

  Two simultaneous clicks can never push a balance negative.
- **Crypto Ads → Diagnostics → Rebuild balances from history** recomputes both totals from the ledger tables.

---

## Cookie-free click handling

1. The snippet asks for a banner; the server returns a **short-lived signed token** (HMAC-SHA256 with a per-install secret, 30 minutes by default) and records one impression row.
2. The click goes to `/ads-go/{token}`. The impression is marked used, so a replayed token is redirected but not charged.
3. Duplicate visitors: `HMAC(IP + user-agent + banner + UTC date)` — no cookie, no stored IP. One paid click per banner per visitor per day by default (`dedupe_hours`).
4. Bots (user-agent regex), non-GET requests, over-rate-limit addresses, and empty balances are redirected normally but **not charged**.
5. Only `REMOTE_ADDR` is used for the IP hash, so a forged `X-Forwarded-For` cannot reset de-duplication.

Nothing is stored that can be turned back into an IP address without the install secret.

---

## Advertiser management page

`/ads/manage/{id}/{token}/` shows the balance in BTC and dollars, clicks left, charged clicks and impressions, the deposit address, a QR code, an **Open in wallet** (`bitcoin:`) button, the exchange widget, a preview of the banner, editable destination/alt/keywords, image replacement, and pause/resume.

**Check for payment now** re-polls only that banner, throttled to once a minute and respecting the API back-off. Someone who just sent coins does not have to wait for the 5-minute cron pass.

---

## Data model

Four custom tables (no post meta, so the atomic decrement above is possible):

- `wp_dwads_banners` — image, link, status, address + derivation index, `sats_received`, `sats_spent`, counters, `keywords`, `site_id` (reserved for the publisher phase)
- `wp_dwads_payments` — one row per received output: `txid`, `vout`, `sats`, `status` (`mempool` / `confirmed` / `dropped`), `credited`, `missing_polls`
- `wp_dwads_clicks` — hashed visitor/IP/UA, charged amount, price at charge time, reason when not charged
- `wp_dwads_impressions` — nonce, expiry, used flag (pruned after 2 days)

Amounts are integers in satoshis. Each click also records the dollar price it was charged at.

---

## Security notes

- Banners are **re-encoded** from pixel data on upload, so EXIF and anything appended to the file are discarded. SVG is not accepted.
- Exact dimensions are enforced, the destination must be `https://`, and files are stored under random names in `uploads/dwads/` with an `.htaccess` that denies PHP execution.
- Management links are the only credential. Only `HMAC-SHA256(token)` is stored, so a database leak does not expose working links. Losing the link means losing the banner — the advertiser can add an email for human recovery by the site owner, but nothing is emailed automatically.
- Preview before approving: with approval on, nothing is shown until you release it. Without approval, anyone who pays can publish a link, which is why approval defaults to on.

---

## Known limits

- **The exchange widget is per-account, with a default.** The ChangeNOW embed carries a `link_id` that identifies whose fees apply. It ships pre-filled with the maintainer's id so a fresh install has a working widget; replace it with your own, or type `none` to remove it — an empty field falls back to the default. The frame is rendered by the plugin from the stored URL, so no raw HTML or `unfiltered_html` capability is needed.
- **QR images come from an external service by default** (`api.qrserver.com`) because a bundled QR encoder would be far more code than the rest of the plugin. The code encodes a `bitcoin:` URI, so a wallet app can scan it directly. The address is public information; change the template to your own endpoint, or type `none` to hide it — the page still has a copy button and an “Open in wallet” link.
- **REST must be reachable.** If a security plugin blocks `/wp-json/`, the snippet cannot fetch banners.
- **Animated GIFs are flattened** to PNG on upload.
- **Public uploads can be spammed** with unpaid banners. Rate limiting is per hashed IP (5/hour by default); combine with approval and, if needed, a Cloudflare rule on the upload page.
- **WP-Cron is traffic-driven.** Slow payment crediting on quiet sites is a cron scheduling issue, not an accounting one.

---

## Reserved for the next phases

- **Publisher sites:** every click and banner already carries `site_id`; `/serve` accepts a `site` parameter and sends CORS headers. What is still missing is publisher identity, payout, and click-fraud handling (publishers clicking their own ads).
- **Context matching:** banners have a `keywords` field, and the snippet sends `page_id`. The server can favour banners whose keywords match the post's tags/categories/title without touching the schema.

---

## Files

```
crypto-banner-ads.php              bootstrap, autoload of includes
includes/class-dwads-bigint.php      decimal big-int maths (GMP or BCMath)
includes/class-dwads-base58.php      Base58Check
includes/class-dwads-bech32.php      Bech32 (segwit addresses)
includes/class-dwads-secp256k1.php   point ops, k·G, BIP32 tweak
includes/class-dwads-address.php     P2PKH / P2SH-P2WPKH / P2WPKH encoding
includes/class-dwads-xpub.php        xpub parsing, derivation, index reservation
includes/class-dwads-settings.php    settings + sizes
includes/class-dwads-install.php     schema, .htaccess for uploads
includes/class-dwads-log.php         small diagnostics ring buffer
includes/class-dwads-price.php       BTC/USD with caching
includes/class-dwads-banners.php     CRUD, image validation, slot selection
includes/class-dwads-payments.php    mempool.space polling, credit/drop logic
includes/class-dwads-clicks.php      tokens, dedupe, charging
includes/class-dwads-rewrite.php     /ads-go/… and /ads/manage/…
includes/class-dwads-serve.php       REST slot endpoint
includes/class-dwads-shortcodes.php  shortcodes, widget, snippet
includes/class-dwads-public-pages.php upload form and management page
includes/class-dwads-admin.php       admin screens
includes/class-dwads-maintenance.php hourly pruning + reconciliation
assets/dwads.js, dwads.css, admin.css
uninstall.php
```

Algorithm sanity: the secp256k1, BIP32 and address code was validated against BIP32 test vector 1 (public derivation `m/0' → m/0'/1`) and the BIP173 address vectors, using an independent mirror of the same algorithms. Run `php -l` (or a staging install) once on a machine with PHP before going live.

---

## Troubleshooting: settings that will not save

1. **Does a “Settings saved” banner appear after clicking Save?** If not, the POST never reached the plugin — the values were never sent. Look at the Network tab: a blocked request (mixed content, CSP), a redirect to a different hostname (www vs non-www, http vs https), or WordPress’s “link you followed has expired” page (nonce) all mean the same thing.
2. **Crypto Ads → Diagnostics → Test settings write.** This writes and reads back a timestamp. If it fails, the database user cannot write to `wp_options`, or an object cache is stuck; if it passes, the database is fine and the problem is the form/nonce path above.
3. **Deleting the plugin folder does not reset it.** WordPress only runs `uninstall.php` when you delete the plugin from the Plugins screen while it is inactive. Replacing the folder over FTP leaves every setting, table and balance in place — including “Delete all banner data when the plugin is uninstalled”, which is stored, not defaulted.

---

## Behaviour notes (1.1.0)

**Ad disclosure.** Banners carry a small corner tag (`Sponsored` by default) with a translucent background, plus `rel="sponsored"` on the link. This mirrors how AdSense (“Ad”), Taboola and Outbrain (“Sponsored”) disclose paid placements: a persistent label rather than something that only appears on hover. Text and on/off are settings.

**Centring.** A filled slot is a flex box with `min-height` equal to the banner height, so the creative sits centred both ways inside whatever container the theme gives it. An empty slot still has zero height and `display:none`.

**Advertiser email — what it actually does.** It is not required to upload, but with an address the plugin:

- emails the management link(s) straight after upload (the recovery path when a link is lost),
- emails again when the banner is approved or rejected,
- lets the admin resend the link from the Banners list.

Without an address nothing is emailed; recovery then depends on the admin opening **Banners** and copying the stored link. If neither exists, the link is gone for good — only a hash was ever needed to use it.

**Management links are stored twice on purpose.** `token_hash` (HMAC) is what the plugin looks up; `token_enc` (AES-256-GCM with the install secret) exists only so the owner can hand a lost link back. A database dump alone cannot produce a working link without the corresponding salts in `wp-config.php`. Banners created before 1.1 have no recoverable copy.

**The public management page is read-only.** It shows balance, address, QR, exchange widget, stats and a “Check for payment now” button. It cannot change the destination, replace the image, pause or resume. Those actions belong to the admin.

**Keywords at upload.** Advertisers can add comma-separated keywords on the same screen as the image; they are stored and displayed but not yet used for targeting.

**Clicks and proxies.** The visitor fingerprint uses the real client address: `CF-Connecting-IP` behind Cloudflare, `X-Forwarded-For` only when the direct peer is a private address, otherwise `REMOTE_ADDR`. Without this, a CDN rotating edge addresses makes every click look like a new visitor, which defeats de-duplication and can charge several clicks for one — the reason 1.1.0 changed it. **Diagnostics → Recent clicks** lists the last 40 attempts with their charge decision and reason, so an over-charge is explainable rather than mysterious.

---

## Size guidance and the upload page message (1.2.0)

One field drives both the whitelist and what advertisers are told. Each line is `WxH`, optionally with a label, a short note and the word `recommended`:

```
728x90   | Leaderboard          | top of every page
320x100  | Large mobile banner  | phones
300x250  | Medium rectangle     | in-content, best performing | recommended
336x280  | Large rectangle      | in-content
250x250  | Square               | sidebar
160x600  | Wide skyscraper      | tall sidebar
300x600  | Half page            | end of article
970x250  | Billboard            | wide header | recommended
```

(The alignment above is cosmetic; the parser only cares about the pipes.)

- The upload form lists every size with its label, its note and a “recommended” badge, so an advertiser can pick a shape that suits their artwork instead of guessing.
- **Settings → Allowed sizes** previews exactly what advertisers will see, under the field.
- **Settings → Upload page introduction** is the paragraph above the form: what happens after upload, that no account is needed, where the Bitcoin address comes from. Plain text or simple HTML; empty hides it.
- Recommended marks are guidance only — they do not change rotation. Weighting is still by remaining balance, by design: an advertiser who tops up less should not outrank one who pays more.

---

## Near-miss uploads (1.2.1)

Exact dimensions are still the rule, but a banner that is close is no longer thrown away. With **Fit near-miss images automatically** on (default):

1. the plugin compares the image's **aspect ratio** with each allowed size — not its pixel count, so a 2× retina export of a 728×90 still fits;
2. picks the closest shape;
3. scales it to the target size and **centre-crops** the overflow, so there is no distortion, only a sliver lost from two edges;
4. refuses the upload if the crop would remove more than 8% of the image, and says how much and which sizes are allowed.

The advertiser sees exactly what happened on the results screen (“Your image was 731×89; it has been fitted to 728×90 by centre-cropping”), and the original dimensions are stored, shown next to the banner in the admin and on the management page. Set the field to off if you would rather reject anything that is not pixel-perfect.

Worked examples with the default size list:

| Uploaded | Chosen | Crop | Outcome |
|---|---|---|---|
| 731×89 | 728×90 | 2% | fitted |
| 1456×180 (2×) | 728×90 | 0% | scaled down only |
| 300×251 | 300×250 | 0% | fitted |
| 640×480 | 300×250 | 10% | rejected, lists the sizes |
| 1200×628 | 300×250 | 37% | rejected |

---

## Reinstalling, wiping data, and address reuse (1.2.2)

Balances are tracked per **address**, not per banner. Deleting the plugin data drops every table *and* the `dwads_next_index` counter, so the next install starts again at index 0 of whichever key you paste. If that is the same key as before, banners 1, 2, 3… are issued the same addresses that banners 1, 2, 3… had last time — and the money that was already sent to them is still on-chain. The plugin cannot tell old money from new, so it is credited to the new banner. Nothing is lost; the balance is simply inherited.

To start clean you do **not** need a new seed. Any one of these works:

1. **A different account.** Export the xpub of another account from the same wallet (e.g. the next `m/84'/0'/<n>`). Different address space, same seed, same backup.
2. **A higher starting index.** Crypto Ads → Diagnostics → *Start the next banner at index* → set something above every index used before (say 1000). Then *Check if the next address is unused* and confirm it reports no history. This is the cheap option when you keep one account.
3. **A new wallet or seed**, if you want the old funds and the new campaign to be unrelated in every way.

The plugin now says this in three places: under the xpub field, in the wallet help panel, and on the Diagnostics screen where the index can be changed. Raising the index can never go below an index already used by an existing banner — the form refuses it.

## Link under the banner (1.2.3)

The disclosure label sits on the creative's corner; this is the opposite: a real
link in the page flow, below the image, pointing wherever you like — usually
your own "advertise here" page.

Settings → **Link under the banner**: enable it, set the text (default
*Advertise here*), the address, and the alignment. Alignment is measured against
the **image**, not the column, so "left" starts at the creative's left edge even
in a wide sidebar.

- Leave the address empty and the plugin uses the page that contains the
  `[dwads_upload]` shortcode, if it finds one.
- Type `none` in the address to switch the link off without clearing your fields.
- Per placement, the widget offers *use the site setting / show a link here /
  never show it here*, plus text, address and alignment overrides. Blank fields
  inherit the site setting. The shortcode takes the same options:
  `[dwads slot="300x250" foot="on" foot_text="Advertise here" foot_align="right"]`.
- The link is plain text in the flow, `rel="nofollow noopener"`, never counted as
  a click, and it does not appear when the slot is empty.

## Responsive slots (1.2.6)

A wide creative in a phone column gets scaled down with the browser's image
scaling, and small text inside it becomes unreadable. A responsive slot fixes
that by naming **two** sizes: one for wide screens, one for narrow ones.

```
[dwads slot="728x90" slot_mobile="320x100"]
```

or, as a raw div on a template page:

```html
<div data-dwads-slot="728x90" data-dwads-slot-mobile="320x100"></div>
```

The loader picks one size per viewport and asks the server for exactly that
size — the same way a media query picks a stylesheet. Below the breakpoint
(600px wide by default) the narrow size is requested; at or above it, the wide
size. Nothing is rendered twice and no device is fingerprinted: resizing the
window across the breakpoint simply swaps the creative for the one that fits.

- **The mobile size must be one of the allowed sizes**, or it is dropped when the
  slot is printed (no banner could ever match it).
- **If the narrow size has no inventory**, the wide creative is requested
  instead, so the space is not left blank at phone width. Add `fallback="off"`
  (or `data-dwads-fallback="0"`) to leave it empty instead.
- Only `728x90` and `970x250` genuinely need this. `320x100`, `300x250`,
  `336x280`, `250x250`, `300x600` and `160x600` fit a 358px phone column
  natively and need no partner size.
- The breakpoint is filterable, not a setting: `add_filter( 'dwads_mobile_breakpoint', fn() => 782 );`
- The widget has the same option: *Size on phones (optional)*.

Two creatives means two balances: a `320x100` banner is a separate banner with
its own funding, exactly like any other size.

## Footer credit (1.2.7)

Settings → Display → **Show a small credit line in the footer** prints one quiet
line at the bottom of every public page:

> Powered by [Crypto Banner Ads](https://github.com/nima1981/wp-crypto-banner-ads) from [Reza Consulting](https://reza.consulting)

It is on by default and can be switched off completely. The styles are printed
inline with the markup, so the line looks the same on pages that never enqueue
the banner stylesheet. It is plain text in the flow — 11px, centred, inherited
colour at 55% opacity — so it does not compete with the page or with the ads.
