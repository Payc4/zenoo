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
| **Chat** (`/chat`) | A Claude-powered chatbot in its own tab. Logged-in pet parents only, **one chat per person**, **60 messages per day** (admins unlimited). Replies stream in as they're written, the chat is saved, and "Clear chat" empties it. See below. |
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

59 tests cover sign-up, login, the lockout, login links, password reset, account changes,
photo uploads, deletion, access control, checkout parameters, the return page, webhooks
(valid, forged, old, retried), refunds, private orders, the chat (streaming, history, daily limit, refunds,
privacy, escaping, question cards), and that the quiz files still match the handoff. Stripe and Claude are replaced by `tests/Support/FakeStripe.php` and
`FakeClaude.php`, so tests never touch the network.

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

## The chatbot

- **The prompt** is in `config/chatbot/system_prompt.md`. Edit that file and deploy to change how
  the assistant behaves. It can use `{{name}}` (the pet parent's first name) and `{{today}}`
  (today's date). A short formatting note is added automatically, because the chat window only
  shows bold, italic and lists.
- **Switching it on:** set `ANTHROPIC_API_KEY` (from the Claude Console). Without it, the Chat
  tab says the chat isn't switched on yet.
- **Model:** `claude-haiku-5-5` by default. Set `ANTHROPIC_MODEL` to change it.
- **Daily limit:** `CHAT_DAILY_LIMIT` (default `60`) messages per pet parent per calendar day in
  `CHAT_TIMEZONE` (default `Europe/Vilnius`). Counts are stored separately from the chats, so
  clearing the chat doesn't give messages back. A reply that fails doesn't count. Admins have no
  limit, so they can test the prompt.
- Each pet parent has a single chat that keeps going. Claude sees its last 20 messages.
- **Question cards** (one at a time: "2 of 4", options with a short description, "Type your answer…",
  Skip / Next):
  - **Starting questions** open in an empty chat. Edit them in `config/chatbot/intro_questions.yaml`
    (up to 5 questions, up to 5 options each; no options = a typed answer; `questions: []` turns them off).
    They're shown without calling Claude, so they cost nothing.
  - **Claude can ask** with the `ask_questions` tool (up to 4 questions) whenever it needs details.
    Unanswered cards open again after a reload, and can be closed and reopened.
  - All answers are sent back as one chat message (one of the day's messages), so Claude remembers them. The admin overview shows messages today and tokens
  this month.

## Project layout

```
src/
  Chat/              ClaudeClient (streaming, no SDK), ChatAgent (prompt + history), ChatQuota (daily limit),
                     Questions (question cards and the ask_questions tool)
  Controller/        Home, Security (login, login links), Registration, PasswordReset,
                     Account, Shop, Chat, Webhook, Admin/ (EasyAdmin)
  Entity/            User, Product, Purchase, ChatConversation, ChatMessage, ChatUsage
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
