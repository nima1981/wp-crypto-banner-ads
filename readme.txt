=== Crypto Banner Ads ===
Contributors: rezaconsulting
Tags: banner ads, advertising, bitcoin, cryptocurrency, pay per click, monetization, cookie-free, no accounts, self-serve ads
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.9.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cookie-free, account-free banner ads paid in Bitcoin: watch-only address per banner, pay-per-click balances, empty slots when unfunded.

== Description ==

Turn your site into its own ad network — no ad broker, no accounts, no cookies, no KYC, no credit-card processor.

Crypto Banner Ads is a self-contained banner advertising system. Visitors who want to advertise upload a banner image, send a small Bitcoin payment to that banner's own address, and the banner starts rotating through the slots you have placed on your pages. Every click deducts a configurable dollar amount (default **$0.02**) from the banner's balance. When the balance runs out, the slot quietly shows nothing — no broken images, no begging.

**For the site owner**

* Paste a single snippet (or shortcode, or a widget) anywhere a banner should appear. The slot automatically picks the best-funded banner that fits that size.
* No private keys on your server. The plugin derives each banner's address **watch-only** from your extended public key (zpub/ypub), so advertisers pay your wallet directly and a compromise of the site can never move funds.
* Approve or reject each upload (optional, on by default).
* Everything is configurable: click price, allowed sizes, caps, human-visitor checks.

**For the advertiser**

* No account, no signup, no password — a management link is the whole identity. It lets them top up, edit the destination link, and watch the balance.
* Pay with Bitcoin, or with their crypto or fiat of choice through the optional swap widget.
* One payment address per banner, so a payment always lands on the right banner.

**The anti-fraud layer**

The plugin is paranoid by default because that is how the site owner stays safe:

* A click is only billed after the visitor's actions look human: the banner was actually on screen for a moment, the click was a real navigation (not a script), and it carries a gesture marker.
* De-duplication without cookies — an HMAC of IP + user agent + banner + day, so a refresh or an incognito tab cannot farm clicks.
* Caps: per visitor per day, per IP per hour/day, and an optional per-banner daily budget.
* A rate brake pauses billing above a suspicious click-through rate.
* Payments are checked against the public mempool and credited as soon as they appear; unconfirmed payments that vanish from the network are removed on a later check.

**Privacy by design**

* No cookies are set anywhere in the flow — not for impressions, clicks, or management.
* The plugin talks only to well-known services: mempool.space for price and payment confirmation, an optional QR-code service, and the optional swap-widget provider you configure. See *What data is sent where* in the FAQ.
* It does not mine cryptocurrency, does not use visitors' CPUs, and has no tracker pixels.

**Developer friendly**

* Source on GitHub: `https://github.com/nima1981/wp-crypto-banner-ads`
* GPL v2 or later, plain readable PHP, WordPress coding conventions, text domain `dwads`.

= Requirements =

* PHP 7.4+ with either the **GMP** or **BCMath** extension (secp256k1 maths) and **RIPEMD160** (address hashing).
* GD or Imagick to re-encode uploaded images (this also strips EXIF and embedded payloads).
* Working WP-Cron or a real cron job (payment checks run every 5 minutes).
* The REST API enabled (the placement snippet fetches a banner from `/wp-json/dwads/v1/serve`).

The plugin warns you on the Plugins screen if a requirement is missing.

== Installation ==

1. Upload the `crypto-banner-ads` folder to `/wp-content/plugins/`, or install from **Plugins → Add New**.
2. Activate the plugin.
3. Go to **Settings → Crypto Banner Ads** and paste your account key — an extended **public** key (`zpub` or `ypub`) from your Bitcoin wallet. This is a watch-only key; the plugin never stores or needs a private key.
4. Check *Verify before you rely on it* in Settings to confirm the plugin derives the same address your wallet expects for the next unused receiving address.
5. Create a page with the `[dwads_upload]` shortcode — that is where advertisers upload and fund banners.
6. Place ads: use the **Crypto Banner Ads** widget, the `[dwads slot="300x250"]` shortcode, or the snippet shown in Settings → Placement. Unfunded slots render nothing.
7. Wait for a payment to appear (checks run every 5 minutes) and approve the banner if approval is required.

For the full setup walkthrough, wallet recommendations, and the "one wallet, one job" hygiene notes, see the README in the plugin folder.

== Frequently Asked Questions ==

= Do I need to give the plugin my private key? =

No — never. You paste only an extended **public** key (zpub/ypub). The plugin derives each banner's address from it, watch-only. A compromised server cannot spend anything; advertisers pay straight to your wallet.

= What does an advertiser need to do? =

Upload an image, send a small Bitcoin payment to the banner's own address (a QR code and a swap widget are provided), then paste the destination link. A management link is issued that lets them top up and edit later. No account, no cookies, no KYC.

= How is a click billed? =

Every click on a banner deducts the configured price (default $0.02) from the banner's balance, in sats, using the live BTC price. A banner is never billed more than once for the same visitor/day, and it can never go negative.

= What happens when a balance runs out? =

The slot simply stops showing that banner. If no banner is funded for a slot size, the slot renders nothing — no placeholder, no broken image.

= Why do some clicks not count? =

The plugin is deliberately strict. A click only bills after the banner was actually visible on screen, the click looks like a real navigation with a gesture, and it is not a duplicate for that visitor/day. Caps and the click-rate brake can also pause billing for a banner. Erring on the side of dropping a click is a deliberate trade-off to protect the site owner from bots.

= What data is sent where? =

* **mempool.space** — the plugin asks the public API for the current BTC/USD price and to check the status of payments to the banners' addresses.
* **QR code service** (configurable, default `api.qrserver.com`) — generates the payment QR image shown to advertisers.
* **Swap widget** (optional) — if you configure one, its embed code renders in the advertiser's payment area so they can pay in other coins or fiat; the provider's own page handles the conversion.

No cookies are set. Impression/click data stays in your own database. No data is ever sold.

= Can I turn the credit line off? =

Yes. The "Powered by Crypto Banner Ads" credit on the plugin's own pages is off by default and fully optional (Settings → General → *Show a small credit line on the plugin's own pages*).

= Does this mine cryptocurrency? =

No. It is an advertising system. It never uses visitors' CPU, has no mining code, and requires no third-party accounts.

== Screenshots ==

1. A live 970×250 billboard placement at the top of a page, with the ad label and the "Advertise here" link.
2. The advertiser upload page: drop one or many banner images, and each gets its own set of fields.
3. Mobile view — the same slots reflow to phone-sized banners automatically.

== Changelog ==

= 1.9.2 =
* The "Powered by" credit line on the plugin's own pages is now opt-in (off by default), matching the directory guideline that credits must default to not showing. Existing sites keep their saved choice.

= 1.9.1 =
* "Maximum banners per upload" now saves exactly what you enter (the old hidden ceiling of 20 is gone; 200 is a sanity bound).
* The real ceiling is PHP's `max_file_uploads`; the field description quotes it, the drop box names the number in force, and an upload arriving at the ceiling reports the truncation instead of silently dropping banners.

= 1.9.0 =
* Drop box on the upload page: drop one or many images, each becomes its own set of fields, grouped by size, with the detected size shown on each row.

= 1.8.8 =
* "Advertise here" and "Fund this banner" links can open in a new tab.

= 1.8.7 =
* Removed the "private" framing from the management-link copy.

= 1.8.6 =
* Every outgoing email is editable (Settings → Email copy) — upload confirmation, approved, rejected, re-sent link, and the admin notice.

= 1.8.5 =
* The per-banner daily cap is now opt-in (default 0 = off). Caps stop billing, never serving.

= 1.8.4 =
* Every cap now honours 0 = disabled (the 1.8.x clamps that silently made 0 become 1 are gone).

= 1.8.0 =
* No free clicks: a click is billed even when the balance cannot cover it, so a dust balance stops serving instead of giving away impressions.

= 1.7.0 =
* One swap-provider field: paste the provider's complete embed code and it renders with the banner's address substituted. No wrapper, no token system.

= 1.6.0 =
* Swap-widget consolidation and settings cleanup.

= 1.5.0 =
* Human-visitor checks for impressions and clicks: on-screen visibility, dwell time, navigation gesture, de-dup without cookies.

= 1.4.0 =
* Impression counting (viewable impressions, once per visitor per window).

= 1.3.0 =
* Responsive slots: one size per viewport from a single slot. Mobile and dark-mode styles.

= 1.2.0 =
* Allowed-sizes whitelist, near-miss auto-fit with an 8% crop limit, size guidance on the upload page.

= 1.1.0 =
* Admin approval, advertiser self-service management page, wallet page with QR and fund link.

= 1.0.0 =
* First release: watch-only derivation, one address per banner, pay-per-click balances, placement snippet.

== Upgrade Notice ==

= 1.9.2 =
Credit line is now opt-in on new installs; no action needed if you already made your choice.

= 1.9.1 =
If you were limited to 20 banners per upload, re-enter your desired number and save — it now sticks.

= 1.9.0 =
The upload page now accepts multiple dropped files in one go, grouped by size.
