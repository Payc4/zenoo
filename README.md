# ZENOO

The ZENOO account site: pet parents create an account, log in, manage their details,
take the Bowl Score quiz and buy a product through Stripe.

Built on the same stack as Accept Your Wealth: **Symfony 7.3 (PHP 8.2+), MySQL 8,
Stripe Checkout, Amazon SES, EasyAdmin, Docker on Coolify**. The look follows the
ZENOO Design System: forest `#445942`, sun `#f7a223`, cream, Inter Tight 800 headlines
in capitals, Inter body copy, 24px corners and no shadows.

## What's in it

| Area | What it does |
|---|---|
| **Sign-up** | Name, email and password, with a Cloudflare Turnstile captcha. Sends a welcome email. |
| **Login** | Email and password, or a one-time email login link (10 minutes, single use). “Keep me logged in” lasts 2 weeks. After 5 wrong passwords, that email and IP pair is locked for 15 minutes. |
| **Forgot password** | An emailed reset link valid for 1 hour. Only a hash of the token is stored. |
| **My account** | Orders, name, profile photo (re-encoded as a 400×400 JPEG), password change (logs out other devices and sends a notice email), and account deletion. |
| **Shop** | Products from the database. **Buy now** opens Stripe Checkout, and the order is recorded by the signed Stripe webhook or the return page, whichever arrives first. It is never recorded twice. Refunds made in Stripe mark the order refunded. |
| **Test product** | “ZENOO Test Product”, €1.00, created by the first migration. Nothing is shipped. |
| **Bowl Score quiz** | The quiz from the ZENOO handoff at `/quiz/`. Answers are sent to Klaviyo (`QUIZ_SEND_TO_KLAVIYO=true`, see below). |
| **Admin** (`/admin`) | Overview, products, purchases (read-only) and pet parents (create, edit, set passwords, grant Admin). |

Security basics: CSRF tokens on every form, hashed passwords (Symfony `auto`), login
throttling, no account enumeration on the login-link and reset forms, webhook signature
and timestamp checks, safe `?next=` redirects, uploads decoded and re-encoded, and orders
visible only to their owner.

## Run it locally

You need PHP 8.2+ with `pdo_sqlite`, `gd` and `intl`, plus Composer.

```bash
composer install
echo "APP_ENV=dev" > .env.local              # .env.dev uses SQLite and sends email synchronously
php bin/console doctrine:migrations:migrate -n   # creates var/data_dev.db with the test product
php bin/console app:create-admin-user you@example.com 'a-long-password'
symfony serve                                 # or: php -S 127.0.0.1:8000 -t public
```

Locally, email goes nowhere (`MAILER_DSN=null://null`). To read the emails, run
`docker compose -f docker-compose.local.yaml up mailer` and set `MAILER_DSN=smtp://127.0.0.1:1025` in
`.env.local`, then open http://localhost:8025. (That compose file is for your computer only.
On Coolify, always use the **Dockerfile** build pack.)

To try the checkout locally, put Stripe **test** keys in `.env.local` and forward webhooks
with the Stripe CLI:

```bash
stripe listen --forward-to 127.0.0.1:8000/webhook/stripe   # prints the whsec_... secret
# .env.local:
# STRIPE_SECRET_KEY=sk_test_...
# STRIPE_WEBHOOK_SECRET=whsec_...
```

Pay with card `4242 4242 4242 4242`, any future date and any CVC.

## Tests

```bash
php bin/phpunit
```

40 tests cover sign-up, login, the lockout, login links, password reset, account changes,
photo uploads, deletion, access control, checkout parameters, the return page, webhooks
(valid, forged, old, retried), refunds, private orders, and that the quiz files still
match the handoff. Stripe is replaced by `tests/Support/FakeStripe.php`, so tests never
touch the network.

## The Bowl Score quiz

`public/quiz/app.js`, `scoring.js`, `content.en.js`, `styles.css` and `assets/` are
**unchanged copies** of `prototype/quiz/` from the ZENOO handoff (a test checks the
hashes). Only the page shell, `templates/quiz/index.html.twig`, is new: the logo links to
the site and the settings come from the environment.

`QUIZ_SEND_TO_KLAVIYO` is **`true`**, and the **“Dog Quiz – EN” Klaviyo flow is live**: every
finished quiz subscribes that email (single opt-in, list `Xubzza`) and sends it a real
email. To test without sending, put `QUIZ_SEND_TO_KLAVIYO=false` in `.env.local` (or in
Coolify for a staging copy), or use your own address. When it's off, the quiz still says
“we've emailed the plan”, because that text comes from the unchanged handoff code.

If you update the quiz from the handoff, copy the files over and update the hashes in
`tests/Functional/PagesTest.php`.

## Project layout

```
src/
  Controller/        Home, Security (login, login links), Registration, PasswordReset,
                     Account, Shop, Webhook, Admin/ (EasyAdmin)
  Entity/            User, Product, Purchase
  Payment/           StripeClient (no SDK), StripeCheckout (session + fulfilment)
  Security/          LoginFormAuthenticator, LoginAttemptLimiter, LoginSuccessHandler
  Service/           EmailService, AvatarUploader
templates/           Twig pages and emails (emails/_layout.html.twig)
assets/              app.css (design tokens), app.js, fonts (served by AssetMapper)
public/images/       Logos and photos; public/quiz/ is the Bowl Score quiz
migrations/          One initial migration (MySQL and SQLite statements)
docker/, Dockerfile  Single-container image for Coolify (nginx, PHP-FPM, email worker)
```

Deploying: see [DEPLOY.md](DEPLOY.md).
