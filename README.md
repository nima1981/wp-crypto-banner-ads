# Crypto Banner Ads

A self-contained WordPress plugin for **cookie-free, account-free paid banner ads**.

- Visitors upload one or more banners, fund that banner's own address with their cryptocurrency or regular money of choice, and get a management link.
- Every click on a banner deducts a configurable dollar amount (default **$0.02**) worth of the balance.
- The site owner just pastes a snippet wherever a banner should appear. If no banner has money left, the slot stays completely empty.
- Sweeping the wallet does not affect the plugin: a banner's balance is *money that ever arrived* minus *money deducted by clicks*. Spending from the wallet is invisible to it.

This is a separate plugin from `wpd-bitcoin`, and does not share code with it.

---

## Requirements

| Need | Why |
|---|---|
| PHP 7.4+ | WordPress baseline |
| PHP **GMP** or **BCMath** | secp256k1 maths for deriving addresses from your account key |
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

1. **Wallet key.** Paste an account-level **zpub / ypub** (watch-only; no private keys are stored). Legacy xpub and testnet keys are refused — see [Wallet setup](#wallet-setup-the-account-key-and-staying-in-control-of-the-money). Derivation is non-hardened, default path `0/{i}`. See [Wallet setup](#wallet-setup-the-account-key-and-staying-in-control-of-the-money) for wallet-by-wallet steps, how to verify it, and the gap-limit caveat.
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

4. **Swap provider (optional).** One field holds the provider's complete embed code, pasted exactly as they give it. It ships pre-filled with the ChangeNOW embed, so it works without any setup. The only thing the plugin changes is `{ADDRESS}`: it becomes that banner's own deposit address, which no visitor could type in by hand. No URL detection, no wrapping, no height setting — whatever you paste is what renders. Type `none` to show no widget.
5. **Cron.** WP-Cron only runs on traffic. For a quiet site add a real cron job:

   ```
   */5 * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron >/dev/null
   ```

---

## Wallet setup: the account key, and staying in control of the money

### What the plugin actually stores

An **extended public key** (BIP32: a public key plus a chain code). From it, anyone can compute every address in that account and read its balance. Nobody can spend from it — signing needs the seed or the private keys, which never leave your wallet.

The plugin derives one address per banner along the external chain, `0/{i}`, and writes no private key anywhere. If the web server is compromised, the attacker learns which addresses belong to you — which is already public on-chain — and gains nothing spendable. It is still a privacy link, so treat the key as sensitive-ish, not as a secret to guard with your life.

The field refuses `xprv`/`yprv`/`zprv` keys outright: an extended *private* key would let anyone drain the wallet, and it is never needed here.

### Which key, from which wallet

Export the key at **account level** — the node three hardened steps below the master, e.g. `m/84'/0'/0'`. The plugin's `0/{i}` then reproduces exactly the wallet's normal receiving addresses, which is what makes them visible and spendable in the wallet's own UI.

| Wallet | Where to get it | Notes |
|---|---|---|
| **Sparrow** (recommended, also for Coldcard / Ledger / Trezor) | Open the ad wallet → Settings → Keystore tab → copy the zpub/ypub | A zpub produces `bc1…` addresses. Has a Gap limit setting. |
| **Electrum** (desktop or mobile) | Wallet → Information → “Master public key” | For a fresh native-segwit wallet the key is a `zpub` — that's the one to paste (Address type: Native segwit). Receive chain is `m/0/i`, exactly what `0/{i}` produces. The “type” line is Standard/2FA/multisig, not descriptor/legacy; every new standard wallet since 4.6 is a descriptor wallet (change on `m/1/i`). See **One wallet, one job**. |
| **Bitcoin Core** (descriptor wallet) | `listdescriptors` shows the descriptor | Core exports plain `xpub` even for segwit accounts, which the plugin refuses; import the wallet into Sparrow and copy the `zpub` it shows instead. |
| **Ledger Live / Trezor Suite** | Not exposed | Connect the device to Sparrow or Electrum and export the account key there. |
| **BTCPay Server** | Store → Wallet → derivation scheme | Same kind of account key. |

Address type follows the key: `zpub` derives native segwit (bc1…), `ypub` derives wrapped segwit (3…). Leave it on Automatic. Getting the script type wrong would hand out addresses that do not appear in the wallet, so it is locked to what the key says.

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

### One wallet, one job

The plugin credits a banner by reading the **entire history** of that banner's address, not just what arrived after the banner was created. It has no way to tell a payment from a payment-shaped accident. Money that already sits on an index is invisible while that index is unused — and the moment the index is handed to a banner, that money becomes the advertiser's balance.

So: **give the plugin its own wallet account and use it for nothing else.** No incoming payments, no manual addresses, no second plugin or store on the same key. A separate account from the same seed (a new key) is enough, and it is the simplest arrangement that cannot go wrong. After changing wallets, restoring a backup, or wiping plugin data, press **Check if the next address is unused** on the Diagnostics screen before uploading again.

**Change addresses.** A wallet that spends from banner addresses may need to send change somewhere.

- **Descriptor wallets** — every new wallet in Electrum 4.6+, Sparrow, Bitcoin Core: change goes to a separate internal chain (`m/1/i`). The plugin derives `0/{i}` only, so change can never land on a banner address. Safe.
- **Legacy wallets** — which Electrum 4.5 could still create: change goes to the next unused address of the *same* receive chain, which is exactly the sequence banners are numbered from. If a change output lands on index `N`, and a later banner is assigned index `N`, that banner is credited with money nobody paid it. That is why the plugin **refuses xpub keys at save time**: the plugin has no legacy installs to support, so the risky shape is simply not accepted. `zpub` and `ypub` are only ever produced by descriptor wallets, whose change stays on `m/1/i`.

**Sweep everything, never a partial amount.** Sending the full balance spends every coin and produces no change output at all, so there is nothing to misplace. Do not send a round figure and leave the remainder. Sweeping does not affect any advertiser balance: the plugin counts what arrived and never reads what an address spent.

Since the plugin only accepts descriptor-wallet keys, the change-address hazard is structurally closed; sweep everything anyway, because it is the rule that costs nothing and has no downside.

### Sweeping and custody

Revenue arrives as many small UTXOs on many addresses. The plugin counts received outputs and never looks at what the address spent, so consolidation cannot disturb it:

- Sweep the whole account to one of your own addresses when fees are low, ideally as one batched transaction rather than one per address.
- Emptying the addresses is fine; the account and the seed are what matter.
- Back up the site database alongside the wallet. If the database is lost, the plugin forgets which address belongs to which banner (the coins are still in your wallet, but the click accounting is gone).
- The **seed is the only thing that must survive**. Losing the key costs nothing — re-export it. Losing the seed loses everything, and no amount of plugin data helps.

### Rotating the key later

Existing banners keep the addresses they were issued, and the plugin keeps polling them, so late payments still credit. A new key only applies to banners created after the change. Keep the old wallet — or at least its seed — for as long as any old banner could still receive money.

---

## Decisions you made, and how they are implemented

| Decision | Implementation |
|---|---|
| **zpub/ypub only, no private keys** | `DWADS_Xpub` parses xpub/ypub/zpub/tpub/upub/vpub, derives non-hardened children, and encodes P2SH-segwit or native segwit addresses, but the settings page refuses legacy (xpub) and testnet keys on save: legacy wallets reuse the banner chain for change. Nothing secret is ever written to disk. |
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

- **The swap provider is one pasted embed.** The field holds the provider's code verbatim, including the `link_id` inside it — the shipped default carries the maintainer's id so a fresh install works, and you change it by editing the text. Nothing is sanitised or rewritten beyond the `{ADDRESS}` substitution, so the field is administrator-only.
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
- **Settings → Upload page introduction** is the paragraph above the form: what happens after upload, that no account is needed, where the payment address comes from. Plain text or simple HTML; empty hides it.
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

1. **A different account.** Export the key of another account from the same wallet (e.g. the next `m/84'/0'/<n>`). Different address space, same seed, same backup.
2. **A higher starting index.** Crypto Ads → Diagnostics → *Start the next banner at index* → set something above every index used before (say 1000). Then *Check if the next address is unused* and confirm it reports no history. This is the cheap option when you keep one account.
3. **A new wallet or seed**, if you want the old funds and the new campaign to be unrelated in every way.

The plugin now says this in three places: under the wallet-key field, in the wallet help panel, and on the Diagnostics screen where the index can be changed. Raising the index can never go below an index already used by an existing banner — the form refuses it.

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
- By default it navigates in the same tab; turn on **Open the link in a new tab**
  under Settings → Link under the banner to send readers to a fresh tab instead.

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

## Credit line on the plugin's own pages (1.2.7, scoped in 1.3.0)

Settings → Display → **Show a small credit line on the plugin's own pages**
prints one quiet line:

> Powered by [Crypto Banner Ads](https://github.com/nima1981/wp-crypto-banner-ads) from [Reza Consulting](https://reza.consulting)

It appears **only where the plugin itself is the subject**:

- the page holding `[dwads_upload]`
- the banner management page
- the invalid-link page

Ordinary posts and pages never show it, even when they display banners. The test
is "did the upload form print during this request", not "does this page carry an
ad", so an article with three banners in it stays clean.

The management and invalid-link pages build a complete HTML document of their
own and never run `wp_footer`, so they place the same markup at the bottom of
their own body; the upload page gets it through `wp_footer`.

Styles are printed inline with the markup, so the line looks the same on pages
that never enqueue the banner stylesheet. It is plain text in the flow — 11px,
centred, inherited colour at 55% opacity. The setting switches it off everywhere
at once.

## “Fund this banner” link (1.2.8)

A second optional link sits under each creative, in the same style as
*Advertise here*, pointing at **that banner's own management page** so a reader
can add funds to it.

Settings → **“Fund this banner” link**: on by default, text defaults to
*Fund this banner*, alignment defaults to *right*. Type `none` as the text to
switch the link off without clearing the alignment. There is also an **Open the
link in a new tab** checkbox, off by default.

- The address is never a setting: it is the banner's management page, which
  only exists once a banner has been served, so the link is per-banner.
- Both links can appear at once, and they never share a side. If both ask for
  the same alignment, the fund link moves to the opposite side
  (left ↔ right, centre → right). Two links under one creative share a single
  row, one at each edge.
- On creatives too narrow to hold both — a `160x600` skyscraper — the two links
  stack on separate lines instead of running past the image.
- Per placement: the widget offers *use the site setting / show it here / never
  show it here* plus text and alignment overrides. The shortcode takes the same
  options: `[dwads slot="300x250" fund="on" fund_text="Top this up" fund_align="left"]`.
- `rel="nofollow noopener"`, and it is never counted as a click.

**Worth knowing before you leave it on:** the management page is a banner's
only credential. Putting this link under every creative publishes it for every
banner that serves, so anyone can open that page. They can see the balance and
the top-up address, and add funds; they cannot change the destination, the
image or the status, because that page is read-only. Turn the link off if you
would rather keep those pages private.

## Management page disclaimer (1.2.8)

Settings → Uploads → **Management page disclaimer** prints at the very bottom
of the page each advertiser uses to top up a banner. It is **empty by default**,
which prints nothing. Plain text or simple HTML.

The fixed sentence that used to sit there — "Balances are never refunded. Keep
this link private…" — has been removed, since the fund link above deliberately
publishes those pages. Put your own wording in the setting if you still want a
note there.

## Mobile and dark mode (1.3.2)

Two things were wrong on a phone, both now fixed.

**The upload form had no font of its own.** It inherited whatever face the theme
set on the page — on Dissent Watch that is Courier New, so the intro paragraph
and the size list rendered as typewriter text, and the theme's centred content
column pulled it into a centred ragged block. `form.css` now sets a system sans
stack on `.dwads-upload` and forces `text-align: left` on it and its paragraphs.
Inline styles in your own `upload_intro` HTML still win, so deliberate formatting
survives.

**The management page washed out in dark mode.** Its dark rules were written
before the light ones, so for `.dwads-card` both rules had equal specificity and
the later light background won, while the inherited dark-mode text colour stayed:
pale grey text on a white card. The dark block now comes after every light rule,
covers the summary tiles, notices and table borders, and declares
`color-scheme: dark` from inside the media query so the browser does not try to
auto-darken the page on top of it.

Both checks are cheap to repeat: the management page's colours should be
`#101318` page / `#171b22` card / `#e8eaf0` text under `prefers-color-scheme:
dark`, and the upload form should render in a sans face at any theme.

## How impressions are counted (1.4.0)

An impression used to be "we answered a serve request", which counted every
page load, every reload, and every crawler that runs JavaScript. On a site with
a few hundred page views a month that produced figures in the thousands.

Three filters now sit between a serve and the counter:

1. **No user agent, no impression.** A request without one is a script, not a
   browser.
2. **Crawler user agents are ignored.** The same `bot_uas` pattern that protects
   clicking also protects the counter.
3. **One impression per visitor per window** — Settings → Anti-fraud →
   *Count an impression once per visitor every N hours*, default **24**. The
   visitor is a daily-rotating HMAC of address plus user agent, the same
   cookie-free fingerprint the click de-duplication uses; nothing readable is
   stored.

Set that window to `0` to restore the old count-every-request behaviour. It is
clamped to 48 hours because impression rows are pruned after two days.

Two consequences worth knowing:

- The counter needs impressions to be recorded. With *Record impressions* off
  there is nothing to de-duplicate against, and every serve counts again.
- Existing totals keep their old, inflated history. If you want the number to
  start from zero under the new rules, say so and I will add a reset action;
  the plugin does not clear a paid banner's counters on its own.

Clicks are unchanged: a click is charged once per visitor per `dedupe_hours`,
and bots are redirected but never charged. That is why the click figure sits
below the impression figure, and the click rate is charged clicks ÷ impressions.

The management page shows three separate figures — *Clicks charged*, *Impressions*
and *Click rate* — so the two numbers are no longer stacked in one tile.

## What counts as a person (1.5.0)

The counter is only useful if it is close to true, so several checks now sit
between a banner and a charge. All of them are on by default, and all of them
would rather drop a legitimate click than bill for a machine.

**Impressions.** A serve request no longer counts. The ad script reports when at
least half of the creative has been on screen for a full second — the same
"viewable impression" rule the ad industry uses — and only then does the
advertiser's counter move. A 728x90 in a sidebar that nobody scrolls to now
counts for nothing. Turn off *Only count an impression when the banner is
actually on screen* to go back to counting requests.

**Clicks.** A click is billable only when all of these hold:

1. The request is a real navigation (`Sec-Fetch-Mode: navigate`), which scripts
   asking for the URL cannot send.
2. The user agent is present and does not match your bot pattern.
3. The creative was reported on screen, at least `click_min_dwell_ms`
   (400 ms) before the click. The timestamp is written by the server, so it
   cannot be backdated.
4. A pointer, key press or context menu touched the creative.
5. The token has not been used before, and this visitor has not already been
   charged for this banner inside the dedupe window.
6. Every cap below has room.

The decoy link is invisible, off screen and untabbable. Nothing a person can see
or reach by keyboard leads to it, so following it is proof of a machine; that
visitor is flagged for 30 days and never billed.

**Caps.** A rolling day limits charged clicks to 3 per visitor (across all
banners) and 15 per address. Those two are always on and are what bound the
damage. A third cap, *maximum charged clicks per banner per day*, is **off by
default**: it is an opt-in guard for when you suspect one specific banner is
being drained. Set to a number, it is the ceiling on what that banner can be
charged in a rolling day; billing stops when it hits the cap (the banner keeps
serving). Set any cap to `0` to disable it.

**Click-rate brake.** Real audiences click a banner a fraction of a percent of
the time. Above *Pause billing above this click rate* (20%) with at least 20
viewable impressions behind it, the banner stops billing until the rolling day
resets. It keeps serving normally; it just stops charging.

### Honest limits

Two of these checks, the gesture and the decoy, are claims made by the browser.
They stop every scraper and every agent that does not render the page, which is
most abuse. They cannot stop a determined farm that runs a real browser and
sends the same signals — nothing in JavaScript can, because the client is
attacker-controlled.

What does hold against that is arithmetic, not identity: the per-visitor cap,
the per-address caps and the click-rate brake mean a farm can only ever extract
a bounded, predictable amount per banner per day. If you suspect a specific
banner is being drained, switch on its per-banner daily cap as an extra bound;
if you would rather lose more, loosen the per-visitor and per-address caps. The
caps, not identity, are what actually cost a fraudster money.

### Measurement note

These rules tighten counting, so numbers fall. Totals recorded before 1.5.0
keep their old inflated history; only new activity follows the new rules.

To start a banner's impression figure from zero under the new rules, use
**Reset impressions** in that banner's row on the Banners screen. It clears the
counter and the stored impression rows (which is what the de-duplication window
and the click-rate brake read) and nothing else — clicks charged, the balance,
the money spent and the click log all stay. Only an administrator can run it, and
it asks for confirmation.

## Swapping the provider (1.7.0)

**Settings → Top-up page → Swap provider embed** holds the provider's own embed
code. Paste it, save, done. There is no URL field, no affiliate-id field, no
height field and no detection: if the provider gives you an `<iframe>` and a
`<script>`, both go in the box and both come out unchanged.

The single exception is `{ADDRESS}`, which is replaced with the banner's own
deposit address before printing. It has to be — every banner has a different
address, so it cannot be typed into the embed by hand. A save is refused if the
embed does not contain it, because the widget would have nowhere to send funds.

To change the affiliate id, edit the `link_id=` value inside the pasted code.
To use a different provider, replace the whole box with theirs.

The field is administrator-only and its contents are trusted as pasted, since a
provider embed is markup and any sanitiser would break it.

### Paying in other coins and in fiat

The widget is the conversion path: the visitor pays in whatever the provider
accepts and the provider sends Bitcoin to the deposit address. The copy across
the plugin says **"your cryptocurrency or regular money of choice"** for
exactly that reason.

One exception is deliberate and must stay: the address itself is a Bitcoin
address. Sending ETH, USDT or anything else directly to it is unrecoverable, so
the direct-send line still says "send Bitcoin straight to this address". The
choice lives in the conversion path, not in the address. If you would rather
word that differently, change **Settings → Upload page introduction** and the
management-page disclaimer; the direct-send sentence is in
`includes/class-dwads-public-pages.php`.

## When the balance runs out (1.8.0)

Two rules, and they point the same way: the advertiser never gets a free click,
and the site never shows an ad that cannot be billed.

**A click is always billed.** There is no balance check in the charge statement.
If a click arrives when the balance cannot cover the price, the balance goes
negative rather than the click being discarded. That can only happen from a token
handed out before the balance dropped — serving stops as soon as the balance is
not positive — and those tokens expire in 30 minutes and work once.

A negative balance is a small debt against the next top-up. The banner list shows
it as a negative figure, the badges say it is not being served, and it returns to
rotation the moment the balance covers one click again.

**Serving needs one click's worth.** A banner with a dust balance is skipped by
the rotation picker rather than filling the slot for free. The row explains
itself: "Below one click (6 of 28 sats): not served", where 28 is that banner's
own price at the current rate.

**Resetting counters starts a new accounting window.** `reset_at` is recorded, and
the click-rate brake and the per-banner daily cap ignore anything charged before
it. Without that boundary, a reset left a banner comparing a fresh impression
count against a day of old clicks, which tripped the 20% brake almost
immediately and stopped billing while still serving.

