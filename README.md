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
| **Quiz → account** | At the end of the Bowl Score quiz the result is posted to the site, which emails a “confirm your email” link. Clicking it creates the account (no password yet) or finds the existing one, saves the dog profile and opens the dashboard. Logged-in pet parents get the profile saved straight away. See below. |
| **My dog** (`/dashboard`) | The dog profile from the quiz: Bowl Score with the 7-part breakdown, the first tips, the daily plan, 3 lessons picked for the dog, course progress and the AI coach with starter questions. It's where pet parents land after logging in. |
| **EN / LT** | Language selector top right. The whole site, emails, quiz, course and chat coach work in English and Lithuanian. See below. |
| **Courses** (`/courses`) | The ZENOO Dog Wellness Course: 30 lessons in English and 30 in Lithuanian, 9 screens each, played one screen at a time. Logged-in pet parents only. Progress, quiz answers and written answers are saved; finished lessons and a day streak show on the course list. See below. |
| **Course studio** (`/admin/course-studio`) | Admins edit courses and lessons screen by screen with a live phone preview, publish them, and upload Agota's audio. |
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

## Quiz → account and the dashboard

- `public/quiz/app.js` posts the quiz result (the same `quiz_*` fields it sends to Klaviyo) to
  `POST /quiz/submit` (`QuizAccountController`), with a CSRF token from the page.
- Not logged in: the result waits as a `QuizSubmission` and the pet parent gets the
  `emails/quiz_confirm.html.twig` email. The link (`/quiz/confirm/{token}`, 7 days, single use)
  creates the account or uses the existing one for that email, saves the `DogProfile`, logs them in
  and opens `/dashboard`. Unconfirmed submissions are only kept for the link's 7 days.
  Limits: 3 submissions per email and 10 per IP address per hour.
- With `KLAVIYO_PRIVATE_KEY` set, the same personal link is also written (server-side,
  `src/Service/KlaviyoProfiles.php`) to the person's Klaviyo profile as `dashboard_link`, so the
  Klaviyo quiz flow's email can carry it as a button: `{{ person|lookup:'dashboard_link' }}`
  (the flow needs a short time delay before that email). `QUIZ_SITE_CONFIRM_EMAIL=false` then
  stops the site's own email, except as a fallback when Klaviyo can't be reached. The link must
  never be sent from the browser: it logs its holder into that email's account.
- Accounts made this way have no password (`hasPassword = false`). The dashboard asks them to choose
  one (no current password needed); until then they can use the email login link. They delete their
  account by typing DELETE.
- One dog per account: taking the quiz again replaces the profile.
- **Lesson picks** (`src/Dog/LessonRecommender.php`): rules map quiz answers (life stage, body
  condition, signs, low parts of the score, food type) to lesson numbers, one lesson per concern,
  most important first, skipping finished lessons.
- The chat coach gets the dog profile in its prompt (`{{dog_profile}}`), and skips the starting
  question cards when it already knows the dog. Dashboard starter questions open `/chat?ask=…`.

## Languages (English / Lithuanian)

- `LocaleSubscriber` picks the language: a `?lang=en|lt` link parameter (remembered afterwards),
  the `zenoo_lang` cookie (set by the EN/LT flags, `/language/{en|lt}`), a `.lt` address
  (app.zenoo.lt opens in Lithuanian), else the browser's language, else English. No language
  prefix in URLs.
  The choice is saved on the account (`User::locale`), and emails go out in it.
- Text in templates and PHP is the English sentence itself, wrapped in `|trans` / `trans()`.
  Lithuanian lives in `translations/messages.lt.yaml` (and `validators.lt.yaml` for form errors).
  To add text: write it in English with `|trans`, then add the same English line with its
  Lithuanian translation to `messages.lt.yaml`. `php bin/console debug:translation lt --only-missing --domain=messages`
  lists anything untranslated.
- Dates use `|local_date('long')` etc. (`src/Twig/LocaleExtension.php`).
- Separate Lithuanian files: `templates/legal/privacy.lt.html.twig`,
  `config/chatbot/intro_questions.lt.yaml`, `public/quiz/content.lt.js`.
- The quiz uses `KLAVIYO_LT_COMPANY_ID` / `KLAVIYO_LT_LIST_ID` in Lithuanian. Until they're set,
  the Lithuanian quiz doesn't send to Klaviyo (the site's own confirmation email still goes out).
- Pet parents see the course in their language; admins see both. The admin area stays in English.

## The course

- **Source:** `content/courses/zenoo-dog-wellness.md`, the course document converted from Word with
  `pandoc -t gfm --wrap=none`. English lessons first, then the Lithuanian version.
- **Loading it:** `php bin/console app:course:import`. On deploy the container runs it with
  `--if-empty`, so a new database gets both courses automatically. Both start as **drafts**: only
  admins see them until "Published" is ticked in the Course studio (or the command runs with
  `--publish`). Lessons already in the database are never overwritten unless you pass `--force`;
  learners keep their progress either way, because screen ids stay the same.
- **Team notes** in the document (`[Agota: add your story here]`, `[Vet/Agota: …]`, `App note: …`) are
  left out of the lessons and listed by the command.
- **The 9 screens** (layouts in `src/Course/LessonLayouts.php`): the hook, the science, listen (audio
  + transcript + check question), quick check, write (saved to the account), do it with your dog
  (steps + "done" tick), go deeper, vet check + today's practice, achievement (badge, lesson x of 30,
  streak). Tables in the text (`| a | b |`) show as tables, and as cards on phones.
- **Audio:** there is no recording yet, so the Listen screen shows the transcript. Upload an MP3 per
  lesson in the Course studio.
- **Language:** a course's language (English or Lietuvių) sets the player's buttons and labels.
- **Access:** courses marked "Free" (both are) are open to every logged-in pet parent; otherwise only
  to people with a paid order.

## The chatbot

- **The prompt** is in `config/chatbot/system_prompt.md`. Edit that file and deploy to change how
  the coach behaves. It can use `{{name}}` (the pet parent's first name), `{{today}}` (today's date),
  `{{course_knowledge}}` (the English course lessons, read from the database, so studio edits reach
  the coach) and `{{course_progress}}` (this pet parent's finished lessons and what they wrote in
  them). A short formatting note is added automatically, because the chat window only shows bold,
  italic and lists.
- Everything above the `## This conversation` heading is the same for everyone and is sent with
  prompt caching, so the long course text is mostly read from Claude's cache (about 19,000 tokens).
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
                     Questions (question cards and the ask_questions tool), CourseKnowledge (course → prompt)
  Course/            LessonLayouts (the 9 screen types), CourseDocumentParser (course document → lessons)
  Command/           app:course:import
  Controller/        Home, Security (login, login links), Registration, PasswordReset,
                     Account, Shop, Chat, Course, QuizAccount, Dashboard, Language, Webhook,
                     Admin/ (EasyAdmin, CourseStudio)
  Entity/            User, Product, Purchase, ChatConversation, ChatMessage, ChatUsage,
                     Course, Lesson, LessonProgress, DogProfile, QuizSubmission
  Dog/               QuizResult (cleans quiz results), LessonRecommender
  EventSubscriber/   LocaleSubscriber (EN/LT)
  Payment/           StripeClient (no SDK), StripeCheckout (session + fulfilment)
  Security/          LoginFormAuthenticator, LoginAttemptLimiter, LoginSuccessHandler
  Service/           EmailService, AvatarUploader
templates/           Twig pages and emails (emails/_layout.html.twig)
assets/              app.css (design tokens), app.js, chat.js, lesson.js (player), studio.js (editor),
                     controllers/ (Stimulus), lib/stimulus.js, fonts (served by AssetMapper)
content/courses/     The course document
translations/        Lithuanian text (messages.lt.yaml, validators.lt.yaml)
public/images/       Logos and photos; public/quiz/ is the Bowl Score quiz
migrations/          Schema migrations (MySQL and SQLite statements in each)
docker/, Dockerfile  Single-container image for Coolify (nginx, PHP-FPM, email worker)
```

Deploying: see [DEPLOY.md](DEPLOY.md).
