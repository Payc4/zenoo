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
| **Flags** | Language selector top right: English, Lithuanian, Ukrainian, Polish. The whole site, emails, quiz, course and chat coach work in all four. See below. |
| **Courses** (`/courses`) | The ZENOO Dog Wellness Course: 30 lessons in each of the four languages, 9 screens each, played one screen at a time. Logged-in pet parents only. Progress, quiz answers and written answers are saved; finished lessons and a day streak show on the course list. See below. |
| **Course studio** (`/admin/course-studio`) | Admins edit courses and lessons screen by screen with a live phone preview, publish them, and upload Agota's audio or generate it with ElevenLabs. |
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

## Chat check-ins and coach conversations

- **Check-ins** (`src/Chat/CheckIns.php`, questions in `config/chatbot/checkins.yaml`, wording
  for other languages in `checkins.lt.yaml`, `checkins.uk.yaml`, `checkins.pl.yaml`): every few days (`every_days`) the chat shows 1–2 multiple-choice cards about
  the dog to pet parents who have a quiz profile, most important due question first; each question
  comes back after its `repeat_days`. Answers go to `POST /chat/checkin` and are stored on the
  `DogProfile` (last 5 per question). They are **not** chat messages and cost **no AI call**: the
  coach sees them as a few lines in the dog profile part of its instructions. Claude's own question
  cards take priority over a check-in.
- **Coach conversations** (`/admin/conversations`, admin only): every chat with the AI coach, with
  the dog's profile and check-in answers beside it, and "Download as text" for reviewing tester
  feedback and improving `config/chatbot/system_prompt.md`.

## How the coach is built (roadmap part 1)

Every message goes: **safety check → coach (AI) → tools and data**.

- **Safety runs first** (`src/Chat/SafetyCheck.php`): a small, separate AI call sorts the message
  against a fixed red-flag list (bloat, toxins, black stool or blood, repeated vomiting, sick
  puppy, breathing, collapse or seizure, heatstroke, can't pee). A match gets an approved "call
  your vet now" reply in the pet parent's language and nothing else; it doesn't use a message and
  is marked as a safety alert in the admin. General questions ("can dogs eat grapes?") aren't red
  flags. If the check fails, the coach answers (its prompt has the same rules).
- **Code does the maths** (`src/Chat/Calculators.php`, the `calculate` tool): calories, grams a
  day and per meal (typical, from the pack's kcal or from the label; whole bowl or a topper share),
  weight-loss pace, switching schedule, cost per day, label energy. Same formulas as the quiz
  (`public/quiz/scoring.js`); the prompt says every number comes from the tool.
- **One dog record** (`DogProfile`): the quiz, the food, check-in answers, a newer weight and dated
  facts with their source (the coach's `update_dog_profile`, or added by the owner). The owner sees
  them on the dashboard ("What the coach knows about Bella") and can add or remove facts.
- **Approved content:** the 30 lessons are in the prompt; the coach links them as
  `/courses/lesson/15`, which opens lesson 15 in the reader's language. The chat only shows links
  to pages inside the app. (The product catalogue comes with the Shopify connection.)
- **It follows up** (`schedule_followup` tool): the coach plans a check-in ("two weeks in, what does
  Bella weigh?"). When it's due, `php bin/console app:coach:followups` (daily) emails the question
  in their language (`ZENOO Coach Check-in`, SMTP or Klaviyo like the other site emails), and the
  coach asks it when they next open the chat. Pet parents switch these off in their account or with
  the signed link in the email.
- **Tested and watched:** `php bin/console app:coach:eval` runs the fixed test questions in
  `config/chatbot/eval.yaml` (emergencies, numbers, health limits, ZENOO questions, all four
  languages) against the real model; run it before changing the prompt, model or coach code. In the
  admin, **Coach conversations → Vet review sample** shows random recent replies with the question
  before each, every safety alert and every marked reply, with a note field for the vet. Under the
  chat, "Talk to a person" opens an email to support@myzenoo.com.

## Store catalogue and the personal ZENOO plan (roadmap part 2: 01, 02)

- **Catalogue** (`src/Catalogue/CatalogueSync.php`, `app:catalogue:sync`, Admin → Store catalogue):
  copies the main store's products from Shopify's public `products.json` (`SHOPIFY_STORE_URL`,
  default `https://zenoo.lt`; no API key). Pack sizes come from the variant or product title
  ("300 g", "6 x 300 g"); the energy (kcal/100 g, kcal/kg or MJ/kg) and the analytical constituents
  are read from the description in LT/EN/PL/UK, or the energy is estimated from the analysis. In the
  admin, check each product's energy, kind (complete food, topper, treat, supplement) and species,
  hide products, and add a note for the coach ("high fat: not for low-fat diets"). Corrections
  survive the next sync. Only **usable** products (for dogs, in the store, with energy and a pack
  size) are used.
- **Personal plan** (`src/Catalogue/PlanBuilder.php`): for the complete ZENOO food with the lowest
  cost per 1,000 kcal: grams and cost a day as a topper (25%), half the bowl or the whole bowl,
  using the quiz's calorie maths. The pick is the biggest share under `PLAN_DAILY_BUDGET` euros a day
  (default 4), so big dogs get a topper or half-bowl plan. "2-week starter" goes to a Shopify cart
  permalink (`/cart/<variant>:<qty>`) with the cheapest packs for two weeks, plus
  `?discount=SHOPIFY_STARTER_CODE` when that's set. Shown on the quiz result (the same maths in
  `public/quiz/app.js`), on the dashboard ("Bella's ZENOO plan", clicks counted on the dog profile)
  and through the coach's `zenoo_plan` tool, with the selling rules in the prompt.

## Logged in vs. visitors

- `/` is the welcome page for visitors; logged-in pet parents are sent to `/dashboard` (the logo
  links there too).
- The quiz skips the name/email step for logged-in pet parents and saves straight to their dashboard.
- The email step links to the app's own privacy policy and has an **unticked** "tips and offers"
  box. Only a ticked box subscribes the profile to the Klaviyo list (marketing); the "Completed Dog
  Quiz" event is always sent with the profile and plan, so the plan email should be triggered by
  that event rather than by the list.
- The "data sent to Klaviyo" panel under the result only appears in debug mode.

## Photos and files (vet partner feedback)

- **Chat attachments** (`src/Chat/ChatFiles.php`): up to 3 photos or PDFs per message (camera button
  in the composer; photos are shrunk to 1568 px in the browser). Images are re-encoded to JPEG, PDFs
  checked, and both stored **privately** in `public/uploads/private/chat/{userId}/` — nginx refuses
  `/uploads/private/`, and `GET /chat/file/{message}/{n}` serves them to the owner or an admin. The
  newest 2 messages with files go to Claude as image/document blocks; older ones are only mentioned.
  Clearing the chat or deleting the account deletes the files.
- **Food first:** until the dog's food is known (`DogProfile::getFood()`), the chat opens with a
  "snap the food label" step instead of check-ins. The coach saves what it reads with the
  `save_food` tool, then carries on answering; the system prompt's "Photos and files" section says
  how to handle labels, health photos (first tips and next steps, never a diagnosis) and vet documents.
- **Finish the profile** card on the dashboard: food step (links to the chat) and a dog photo
  (`POST /dashboard/dog-photo`, `src/Service/DogPhotoUploader.php`, 600×600 JPEG in `uploads/dogs/`).
  The coach card also links to `/chat?photo=1` ("Something looks off?").
- **Quiz label try-out** (`src/Controller/QuizLabelController.php`, `POST /quiz/label-scan`): on the
  label step, "Let our AI read the label" reads one photo with a forced `label_reading` tool and fills
  the numbers in. 2 tries per visitor session and 6 per IP a day; the photo isn't stored. Brand and
  product go out as `quiz_food_brand` / `quiz_food_product` / `quiz_food_format` / `quiz_label_scanned`,
  so the coach knows the food after sign-up. Extra quiz styles live in `public/quiz/site.css`
  (`styles.css` stays the handoff copy). Hidden when `ANTHROPIC_API_KEY` isn't set.
- Paid vet review of a photo is planned for later.

## Languages (English, Lithuanian, Ukrainian, Polish)

- The list is `src/Locale.php` (codes `en`, `lt`, `uk`, `pl`); its comment lists every file a new
  language needs.
- `LocaleSubscriber` picks the language: a `?lang=en|lt|uk|pl` link parameter (remembered
  afterwards), the `zenoo_lang` cookie (set by the flags, `/language/{code}`), then on a `.lt`
  address (app.zenoo.lt) Lithuanian unless the browser prefers Ukrainian or Polish, elsewhere the
  browser's language, else English. No language prefix in URLs.
  The choice is saved on the account (`User::locale`), and emails go out in it.
- Text in templates and PHP is the English sentence itself, wrapped in `|trans` / `trans()`.
  The other languages live in `translations/messages.{lt,uk,pl}.yaml` (and `validators.*.yaml` for
  form errors). To add text: write it in English with `|trans`, then add the same English line with
  its translation to each `messages.*.yaml`. `php bin/console debug:translation uk --only-missing --domain=messages`
  lists anything untranslated.
- Dates use `|local_date('long')` etc. (`src/Twig/LocaleExtension.php`); `decimal_sep()` gives 1,5 vs 1.5.
- Separate files per language: `templates/legal/privacy.<code>.html.twig`,
  `config/chatbot/intro_questions.<code>.yaml`, `config/chatbot/checkins.<code>.yaml`,
  `public/quiz/content.<code>.js`.
- The quiz uses `KLAVIYO_LT_COMPANY_ID` / `KLAVIYO_LT_LIST_ID` in Lithuanian. Until they're set,
  the Lithuanian quiz doesn't send to Klaviyo (the site's own confirmation email still goes out).
  Ukrainian and Polish quiz results aren't added to a Klaviyo list (the "Dog Quiz Flow" emails are
  English); they always get the site's own, translated confirmation email instead. To collect them
  in Klaviyo, give `uk`/`pl` a list in `HomeController::quiz()` once there are translated flows.
- The coach replies in the pet parent's site language (`CourseKnowledge::language()`).
- The Ukrainian and Polish texts (site, quiz, course) were machine-translated from English and
  should be read by a native speaker before launch, as the vet review covers the content.
- The course list shows the courses in the language picked in the header (admins also see its drafts), or the English ones if that language has none. The admin area stays in English.

## The course

- **Source:** `content/courses/zenoo-dog-wellness.md`, the course document converted from Word with
  `pandoc -t gfm --wrap=none`. English lessons first, then the Lithuanian version. The Ukrainian and
  Polish courses are `zenoo-dog-wellness.uk.json` / `.pl.json`: the English lessons' slides with
  every text translated (same screens, their own screen ids).
- **Loading it:** `php bin/console app:course:import`. On deploy the container runs it with
  `--missing`, so any language without its course gets it automatically (a new database gets all
  four). New courses start as **drafts**: only
  admins see them until "Published" is ticked in the Course studio (or the command runs with
  `--publish`). Lessons already in the database are never overwritten unless you pass `--force`;
  learners keep their progress either way, because screen ids stay the same.
- **Team notes** in the document (`[Agota: add your story here]`, `[Vet/Agota: …]`, `App note: …`) are
  left out of the lessons and listed by the command.
- **The 9 screens** (layouts in `src/Course/LessonLayouts.php`): the hook, the science, listen (audio
  + transcript + check question), quick check, write (saved to the account), do it with your dog
  (steps + "done" tick), go deeper, vet check + today's practice, achievement (badge, lesson x of 30,
  streak). Tables in the text (`| a | b |`) show as tables, and as cards on phones.
- **Audio:** upload an MP3 per lesson in the Course studio, or make it with ElevenLabs
  (`ELEVENLABS_API_KEY` + `ELEVENLABS_VOICE_ID`, `src/Course/VoiceGenerator.php`): "Generate with
  ElevenLabs" on a Listen screen in the editor, "Generate voices with ElevenLabs" under each course
  for every screen without audio (one at a time, saved as it goes), or `php bin/console app:course:voice`
  (`--dry-run` shows the characters it costs). The script is read without its Markdown, in the
  course's language. Generated audio is marked "AI-generated voice", and learners see a short note
  under the player. Until there is audio, the Listen screen shows the transcript.
- **Language:** a course's language (English, Lietuvių, Українська, Polski) sets the player's buttons and labels.
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
- **Model:** `claude-haiku-5-5` by default. Set `ANTHROPIC_MODEL` to change it. Haiku 5.5 "thinks"
  before answering unless told not to, and that thinking counts toward `max_tokens`, so
  `ClaudeClient` sends `thinking: {type: disabled}` for it (replies used to stop mid-sentence or come
  back empty). Replies get up to 4,096 tokens; one that still hits the limit is cut back to its last
  full sentence, and an empty reply is retried once, then reported as an error that doesn't use a message.
- **The dog's profile grows:** the profile part of the prompt (Bowl Score, breakdown, plan, food,
  check-ins) is rebuilt from the database for every message and cached too. The coach saves what the
  pet parent tells it with the `update_dog_profile` tool (a new weight, symptoms, vet diagnoses,
  treats, routine, likes): `DogProfile::getNotes()`, up to 30, kept when the quiz is retaken. They show
  on the dashboard ("What the coach knows about Bella") and on the admin conversation page.
- **Proactive coach:** when a pet parent opens the chat 2+ days after the last message
  (`ChatController::OPENER_AFTER_DAYS`), or for the first time once the food is known, the page calls
  `POST /chat/opener` and the coach starts the conversation with one specific question from the
  profile. It doesn't use one of their messages, and replaces the check-in cards that visit.
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
  EventSubscriber/   LocaleSubscriber (language per visitor)
  Payment/           StripeClient (no SDK), StripeCheckout (session + fulfilment)
  Security/          LoginFormAuthenticator, LoginAttemptLimiter, LoginSuccessHandler
  Service/           EmailService, AvatarUploader
templates/           Twig pages and emails (emails/_layout.html.twig)
assets/              app.css (design tokens), app.js, chat.js, lesson.js (player), studio.js (editor),
                     controllers/ (Stimulus), lib/stimulus.js, fonts (served by AssetMapper)
content/courses/     The course document
translations/        Lithuanian, Ukrainian, Polish text (messages.*.yaml, validators.*.yaml)
public/images/       Logos and photos; public/quiz/ is the Bowl Score quiz
migrations/          Schema migrations (MySQL and SQLite statements in each)
docker/, Dockerfile  Single-container image for Coolify (nginx, PHP-FPM, email worker)
```

Deploying: see [DEPLOY.md](DEPLOY.md).
