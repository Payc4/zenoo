# Deploying ZENOO on Coolify

This is the same setup as Accept Your Wealth: one Docker image (nginx, PHP-FPM and the
email worker, run by supervisord) built from the `Dockerfile`, plus a MySQL 8 database.
On every start the container waits for the database and runs the migrations, so a deploy
needs no manual steps.

## 1. Create the resources

1. **Database:** in Coolify, add a **MySQL 8** resource. Note its internal URL, user and password.
2. **Application:** add this GitHub repository with the **Dockerfile** build pack (not Docker Compose). The port is **80**.
3. **Persistent storage:** mount a volume at `/var/www/html/public/uploads` so profile
   photos survive deploys.

## 2. Environment variables

| Variable | Value |
|---|---|
| `APP_ENV` | `prod` |
| `APP_SECRET` | A long random string, e.g. `openssl rand -hex 32`. **Required.** |
| `APP_URL` | This deployment's URL, e.g. `https://account.myzenoo.com`. **Required.** Used in email links and Stripe redirects. |
| `DATABASE_URL` | `mysql://USER:PASSWORD@HOST:3306/zenoo?serverVersion=8.0.32&charset=utf8mb4` **Required.** |
| `MAILER_DSN` | Amazon SES, e.g. `ses+api://ACCESS_KEY:SECRET_KEY@default?region=eu-west-1`. Empty means emails are discarded. |
| `MAIL_FROM_EMAIL` | A sender verified in SES, e.g. `noreply@myzenoo.com`. |
| `STRIPE_SECRET_KEY` | `sk_test_...` for the test product. Switch to `sk_live_...` only for real sales. |
| `STRIPE_WEBHOOK_SECRET` | `whsec_...` from step 3. Checkout stays off until both Stripe values are set. |
| `ANTHROPIC_API_KEY` | From the Claude Console (**API keys**). Switches on the Chat tab. Empty means the chat is off. |
| `ANTHROPIC_MODEL` | Optional. Defaults to `claude-haiku-5-5`. |
| `CHAT_DAILY_LIMIT` | Optional. Messages per pet parent per day, default `60`. |
| `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY` | From Cloudflare Turnstile. Empty switches the sign-up captcha off. |
| `QUIZ_SEND_TO_KLAVIYO` | Defaults to `true`: finished quizzes go to the **live** Klaviyo flow and email the pet parent. Set `false` for a staging copy. |
| `KLAVIYO_COMPANY_ID`, `KLAVIYO_LIST_ID` | Default to the EN account (`SVCcfJ`, list `Xubzza`). |
| `KLAVIYO_LT_COMPANY_ID`, `KLAVIYO_LT_LIST_ID` | Optional: the zenoo.lt Klaviyo account and list for the Lithuanian quiz. Empty means Lithuanian quiz results aren't sent to Klaviyo. |
| `KLAVIYO_PRIVATE_KEY` | Optional, secret (untick Buildtime). A Klaviyo private key with only "Profiles: Full access". When set, each quiz-taker's personal dashboard link is written to their Klaviyo profile as `dashboard_link`, for a button in the quiz flow's email: `{{ person\|lookup:'dashboard_link' }}`. `KLAVIYO_LT_PRIVATE_KEY` is the same for a separate Lithuanian account. |
| `QUIZ_SITE_CONFIRM_EMAIL` | Default `true`: the site also sends its own confirmation email. Set `false` once the Klaviyo email has the button, so people get one email instead of two (the site email is then only sent if Klaviyo can't be reached). |
| `EMAIL_DELIVERY` | `auto` (default): site emails go through Klaviyo flows when `MAILER_DSN` isn't set and `KLAVIYO_PRIVATE_KEY` is (the key then also needs **Events: Full access**), otherwise through `MAILER_DSN`. Force with `smtp` or `klaviyo`. See "Site emails through Klaviyo" below. |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | First deploy only: creates the admin account. Remove them afterwards. |
| `SESSION_DSN` | Optional, e.g. `redis://...`. The default keeps sessions in files inside the container, so a redeploy logs everyone out. |

## 3. Stripe webhook

In the Stripe dashboard (test mode first): **Developers → Webhooks → Add endpoint**

- URL: `https://YOUR-APP-URL/webhook/stripe`
- Events: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `charge.refunded`
- Copy the signing secret (`whsec_...`) into `STRIPE_WEBHOOK_SECRET` and redeploy.

Test it: log in, open **Shop → ZENOO Test Product → Buy now**, and pay with
`4242 4242 4242 4242`. The order appears under **My account → My orders** and in
**Admin → Purchases**. A refund in Stripe marks it refunded.

## 4. Amazon SES

Verify the `myzenoo.com` domain (or the sender address) in SES, in the same region as the
DSN. While SES is in sandbox mode it only delivers to verified addresses, so request
production access before real pet parents sign up.

## 5. After the first deploy

- Log in at `/login` with `ADMIN_EMAIL`, then remove `ADMIN_EMAIL` and `ADMIN_PASSWORD` from Coolify.
- Check `/health` returns `{"status":"ok"}`.
- The 30-lesson course (English and Lithuanian) is loaded automatically as drafts. Publish each
  course in Admin → Course studio once the vet review is done.
- `docker exec <container> check-messenger.sh` shows whether the email worker is running.

## Troubleshooting

- **Container exits with "APP_SECRET is empty" (or DATABASE_URL or APP_URL):** set the variable in Coolify.
- **"table already exists" on start:** see the comments in `docker/docker-entrypoint.sh`.
  Set `RUN_MIGRATIONS=false`, then reconcile with `doctrine:migrations:version --add --all`.
- **Emails don't arrive:** check `MAILER_DSN`, then the worker (`supervisorctl status messenger-consume`)
  and failed messages (`php bin/console messenger:failed:show`).
- **Buy now says payments are switched off:** one of the two Stripe variables is empty.


## Site emails through Klaviyo

With `EMAIL_DELIVERY=klaviyo` (or `auto` and no `MAILER_DSN`), the site sends no email itself. Each
email becomes a Klaviyo event on the person's profile, and one Klaviyo flow per event sends it.
Build each flow as: trigger = the metric below, **mark it Transactional** (and get it approved, so
it reaches people who aren't subscribed to marketing), **Smart Sending off**, no delay. Every event
also has `subject` (already translated), `first_name`, `locale` (`en` / `lt`, use a conditional
split for two languages) and `site_url`.

| Metric (flow trigger) | When | Properties for the email (`{{ event.name }}`) |
|---|---|---|
| ZENOO Welcome | Account created on the site | `dashboard_url`, `quiz_url`, `courses_url` |
| ZENOO Password Reset | "Forgot password" | `reset_link` (button; valid 1 hour) |
| ZENOO Login Link | "Email me a login link" | `login_link` (button), `expires_in_minutes` |
| ZENOO Password Changed | Password changed | `account_url`, `forgot_password_url` |
| ZENOO Order Confirmation | Payment received | `order_id`, `product_name`, `amount`, `discount`, `currency`, `order_date`, `order_url`, `test_order` |
| ZENOO Quiz Confirmation | Quiz finished, when the link isn't already in the quiz flow (Lithuanian quiz, or `QUIZ_SITE_CONFIRM_EMAIL=true`) | `confirm_link` (button), `dog_name`, `bowl_score` |

The English quiz's link is on the profile instead (`{{ person|lookup:'dashboard_link' }}` in the
"Dog Quiz Flow", after a 5-minute delay). Set `QUIZ_SITE_CONFIRM_EMAIL=false` once that button is
in, so English quiz-takers don't also get "ZENOO Quiz Confirmation".
