# Image Studio

A Laravel 13 + Inertia (Vue) app where users generate images through [Pixelforge](https://pixelforge.promptedit.dev/docs) and pay Pixelforge's cost plus 25%.

## Running it

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
composer run dev            # web server, queue worker, logs and Vite
php artisan schedule:work   # in a second terminal: polls generations whose webhooks never arrived
```

Set `PIXELFORGE_API_KEY` in `.env`. To receive webhooks locally, expose the app with a tunnel (`expose share`, ngrok, Cloudflare Tunnel), set the key's webhook URL to `https://<tunnel>/api/webhooks/pixelforge` in the Pixelforge dashboard and copy its signing secret into `PIXELFORGE_WEBHOOK_SECRET`. Use one Pixelforge key per environment: each has its own webhook URL, secret and usage report.

```bash
php artisan test            # Pest; never calls the real API
composer types:check        # Larastan, level 7
npm run types:check && npm run check
```

## Deploying (Laravel Cloud)

1. Create a **separate Pixelforge API key** for production, so `GET /v1/usage` for that key covers exactly this app's generations.
2. Set `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET`, attach a database, and run `php artisan migrate --force` on deploy.
3. Enable a **queue worker** (submits generations) and the **scheduler** (runs `generations:sync` every minute).
4. Set the key's webhook URL to `https://<your-app>/api/webhooks/pixelforge`. It must answer directly: Pixelforge doesn't follow redirects. "Send test event" in the dashboard should get a 204.
5. Keep the app awake (no hibernation): Pixelforge gives up on a delivery that takes longer than 10 seconds.
6. Give operators the spend report: have them sign up, then run `php artisan users:make-admin their@email`.

## How it works

**Submitting.** The form saves the generation (status `pending`, with its estimate) before Pixelforge hears about it, then a queued job submits it. The job sends our generation id as the `Idempotency-Key` and as `metadata.generation_id`, with an identical body on every retry, so a retry can never create or bill a second generation.

| Pixelforge says                                                            | We do                                                                                            |
| -------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| 202                                                                        | Record its id, status and rates                                                                  |
| `concurrency_limit_exceeded`                                               | Wait for a free slot: at least `Retry-After`, backing off to 60s, for up to 10 attempts (~5 min) |
| `overloaded`, `rate_limit_exceeded`, 5xx, timeout                          | Retry after at least `Retry-After`, backing off further each time; give up after 4 such errors   |
| `content_policy_violation`, `validation_failed`, `daily_limit_exceeded`, … | Fail straight away: retrying can't help                                                          |

Waiting for a slot doesn't use up the error budget (`generations.submission_errors` counts errors separately), so a generation that queued behind others still survives a one-off overload. A generation we give up on is marked failed and costs nothing. If Pixelforge turns out to have created it after all (say the request timed out after it was accepted), its webhook overrules our "failed" and the real cost is recorded. The poller can't help there, since we never learned the Pixelforge id. If those webhooks were all lost too, resending them from the Pixelforge dashboard fixes it.

Every user shares the account's Pixelforge limits, so each user can start at most 10 generations a minute and 50 a day. Going over shows a form error.

**Webhooks** (`POST /api/webhooks/pixelforge`, outside the web middleware group, so there's no session, CSRF or redirect):

- The `Pixelforge-Signature` HMAC is checked against the raw body in constant time, and signatures older than 5 minutes are rejected. An unset secret rejects everything.
- Each event id is inserted into `pixelforge_events` (primary key) in the same transaction as the update it causes, so a duplicate, even one arriving at the same moment, is a no-op.
- `Generation::syncFromPixelforge()` is the one place a Pixelforge generation object is applied, whether it came from a webhook, a poll or the POST response. It only moves the status forward (`pending → queued → processing → succeeded | failed`) with a conditional `UPDATE … WHERE status IN (earlier statuses)`, and writes the cost in that same statement. A late `processing` event can't undo `succeeded`, and a cost can't be recorded twice.
- A webhook can arrive before our POST returns. It's matched by `metadata.generation_id`, and the POST response then can't move the status back.
- Delivery isn't guaranteed, so `generations:sync` polls anything accepted but unfinished for 3+ minutes, 20 per minute, well within the 60 requests a minute the account's keys share.

## Money

- All amounts are **integer nanodollars** (1e-9 USD), the precision Pixelforge reports to. Pixelforge's rates are decimal strings, multiplied with `brick/math` (already a Laravel dependency), never floats. `toInt()` throws rather than silently rounding if a future rate ever needs more precision. SQLite would store a `decimal` column as a float, so integers are used.
- **Actual cost** = each token count × the rate in the generation's own `rates` (fixed when it was created), not today's prices. The user pays that × 1.25. At current rates that's always a whole number of nanodollars, so nothing is rounded; if it ever weren't, we'd round up to the next nanodollar. Amounts are shown exactly, e.g. `$0.0145625`, so they can be checked against Pixelforge's numbers.
- **Estimate**, computed by the server from live `GET /v1/pricing`, including its token tables. Output and reference-image tokens are exact. Text tokens are `ceil(characters / 4)` (Unicode characters, not bytes) ±15%. Reasoning tokens are a range per quality. The form shows the expected price (estimated text tokens, mid-range reasoning) and the min–max range; the expected price and the maximum are stored with the generation.
- **Failed generations are free**: `cost = 0`, and never billed by Pixelforge either.
- Pixelforge bills a generation on the **UTC day it completed**, so `completed_at` is Pixelforge's own timestamp, not the time our webhook arrived. Generations outlive a deleted user account (`user_id` is nulled), so the totals still match Pixelforge's.

Checked live against the real API: per-generation costs recompute exactly from usage × rates × 1.25, and the day's provider cost summed from our records equals `GET /v1/usage` (`0.109200000`).

## Credits

- Users buy credits ($5, $10 or $20; a fake purchase, no payment taken) from the sidebar or from the generate form when they run short.
- The balance isn't a stored running total that could drift. `User::creditBalance()` derives it: what was bought (`credit_purchases`) minus what generations were charged (`generations.cost`, written exactly once, as above).
- While a generation is unfinished, **the most it could cost is held** (`max_cost`, the top of the estimate), like a card authorisation. The final price isn't known up front, so this is what stops several generations at once from overspending a balance. The update that charges a generation also ends its hold, because the hold is just "unfinished generations' `max_cost`". Charges and holds are summed in one SQL statement, so a generation finishing mid-read is counted exactly once. A failed generation releases its hold and charges nothing.
- Starting a generation locks the user's row, checks `available ≥ max_cost`, and records the generation in one transaction, so two submissions at once can't both spend the same credits. The form shows the shortfall and disables Generate before it gets that far.
- A balance can still dip below zero in two rare cases. We always charge the real cost; the user then can't start another generation until they top up.
    - Pixelforge raises its rates after we priced the hold. Prices are cached and served stale while refreshing, so the copy can be hours old after a quiet spell, and a generation can also wait a few minutes for a free slot before Pixelforge fixes its rates.
    - We gave up submitting a generation (so its hold was released), but Pixelforge had created it after all. Its webhook then charges it.

## Spend report

`/admin/report` (operators only, via `users:make-admin`) shows what users were charged, what Pixelforge charged us, and the profit, per UTC day and per user. The range defaults to the last 30 days, and at most 92 (Pixelforge's limit).

- A generation counts on the **day it completed**, the day Pixelforge bills it, so each day's provider cost lines up with `GET /v1/usage`.
- Every day is checked against `GET /v1/usage` for this app's key and flagged when it differs. Days where only Pixelforge has costs are listed too, since that's a generation we never recorded. If Pixelforge can't be reached, the report still shows our own figures.

## Choices worth knowing

- Email verification is off: the app sends no real mail, and anyone should be able to sign up and look around.
- Webhooks are processed inline. It's a single short transaction, far inside the 10-second limit, and it saves a queue hop.
- The history page polls the server every 2 seconds, only while something is unfinished. There's no websocket infrastructure to run.
- Images aren't copied; Pixelforge's links expire after 24 hours and the card then says so.
- New users start with no credits, so the first thing anyone does is buy some. That shows the balance flow straight away.
