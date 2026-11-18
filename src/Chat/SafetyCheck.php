<?php

namespace App\Chat;

use Psr\Log\LoggerInterface;

/**
 * Runs before the coach on every message. A small, separate model call sorts the message against
 * a fixed red-flag list (the list and the replies are set here, not by the model). A match gets an
 * approved "call your vet now" message and nothing else: the coach doesn't answer, so it can't
 * talk the pet parent out of going.
 *
 * Questions in general ("can dogs eat grapes?") are not red flags; "she ate grapes an hour ago" is.
 * If the check itself fails, the message goes on to the coach, whose prompt has the same rules.
 */
final class SafetyCheck
{
    /** The fixed red-flag list. Keys are stored on the message (ChatMessage::flag = "red_flag:<key>"). */
    public const FLAGS = [
        'bloat' => 'Bloat signs: a swollen or tight belly, retching or trying to vomit with nothing coming up, restless and drooling.',
        'toxin' => 'Has eaten or may have eaten something toxic: grapes or raisins, xylitol (sugar-free gum or sweets), chocolate, onion or garlic, macadamia nuts, alcohol, human medicines, rat poison, antifreeze, slug pellets, cannabis; or swallowed a toy, sock, corn cob or cooked bone.',
        'blood' => 'Black, tarry stool; a lot of blood in the stool or vomit; bleeding that doesn\'t stop.',
        'vomiting' => 'Vomiting again and again (several times in a day), or vomiting with tiredness, weakness or refusing food and water.',
        'puppy' => 'A puppy (under about 6 months) that is vomiting or has diarrhoea and is quiet, weak or not eating.',
        'breathing' => 'Trouble breathing: struggling, gasping, blue or very pale gums or tongue.',
        'collapse' => 'Collapse, a seizure or fit, can\'t stand or walk, sudden weakness, unresponsive.',
        'heat' => 'Heatstroke signs: heavy panting that won\'t settle, very hot, wobbly or collapsing after heat or exercise.',
        'urine' => 'Straining to pee with little or nothing coming out, especially a male dog.',
    ];

    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** The red flag the message describes, or null. Never throws: on a failed check the coach answers. */
    public function check(string $message, ?string $dogName = null, ?string $earlier = null): ?string
    {
        if (trim($message) === '' || !$this->claude->isConfigured()) {
            return null;
        }
        $list = implode("\n", array_map(fn ($k, $v) => "- $k: $v", array_keys(self::FLAGS), self::FLAGS));
        try {
            $result = $this->claude->stream([
                'max_tokens' => 200,
                'system' => "You check messages that pet parents send to a dog-nutrition coach, before the coach sees them. "
                    . "Decide whether the message says that their dog is in one of these situations RIGHT NOW (today or ongoing):\n$list\n\n"
                    . "Not a red flag: general questions (\"is chocolate bad for dogs?\", \"what are bloat signs?\"), things that happened long ago or are already resolved, "
                    . "a vet has already seen it and they ask about feeding afterwards, mild single episodes (one vomit, then fine; one soft stool), itching, weight, picky eating. "
                    . "When it clearly describes one of the situations above for their dog, report it, even if they only ask about food. The message can be in any language. "
                    . "Answer only with the triage tool.",
                'messages' => [['role' => 'user', 'content' => ($earlier ? "Their previous message: \"" . mb_substr($earlier, 0, 500) . "\"\n\n" : '')
                    . ($dogName ? "Dog's name: $dogName\n" : '') . "Message: \"" . mb_substr($message, 0, 2000) . '"']],
                'tools' => [[
                    'name' => 'triage',
                    'description' => 'Report whether the message describes a red flag right now.',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'red_flag' => ['type' => 'string', 'enum' => array_merge(['none'], array_keys(self::FLAGS))],
                        ],
                        'required' => ['red_flag'],
                    ],
                ]],
                'tool_choice' => ['type' => 'tool', 'name' => 'triage'],
            ], static function (): void {});
        } catch (\Throwable $e) {
            $this->logger->warning('Safety check failed; the coach answers instead', ['exception' => $e]);
            return null;
        }
        foreach ($result['tool_uses'] as $use) {
            $flag = $use['input']['red_flag'] ?? 'none';
            if (isset(self::FLAGS[$flag])) {
                return $flag;
            }
        }
        return null;
    }

    /** The approved reply for a red flag, in the pet parent's language. */
    public static function reply(string $flag, string $locale, ?string $dogName): string
    {
        $t = self::TEXT[$locale] ?? self::TEXT['en'];
        $dog = $dogName !== null && $dogName !== '' ? $dogName : $t['your_dog'];
        $why = $t[$flag] ?? '';
        return strtr($t['head'] . ($why !== '' ? "\n\n" . $why : '') . "\n\n" . $t['tail'], ['%dog%' => $dog]);
    }

    private const TEXT = [
        'en' => [
            'your_dog' => 'your dog',
            'head' => '**Please call your vet or the nearest emergency vet clinic now.** What you describe can be serious for %dog%, and it needs a vet today, not a change of food.',
            'bloat' => 'A swollen belly with retching can be bloat, which gets worse within hours. Go now, and don’t give food or water on the way.',
            'toxin' => 'Tell the vet what %dog% ate, roughly how much and when, and bring the packet if you have it. Don’t make %dog% vomit unless a vet tells you to.',
            'blood' => 'Black, tarry stool or a lot of blood can mean bleeding inside. If you can, take a photo or a sample for the vet.',
            'vomiting' => 'Repeated vomiting, or vomiting with tiredness or refusing water, can quickly lead to dehydration.',
            'puppy' => 'Puppies dehydrate much faster than adult dogs, so a sick, quiet puppy needs to be seen today.',
            'breathing' => 'Keep %dog% calm and cool, and go straight to the clinic.',
            'collapse' => 'Keep %dog% somewhere safe and quiet, note the time, and go straight to the clinic.',
            'heat' => 'Move %dog% into the shade or a cool room, wet the coat with cool (not icy) water, and go to the vet right away.',
            'urine' => 'Not being able to pee is an emergency, especially in male dogs.',
            'tail' => 'I’m an AI coach and can’t examine %dog%, so I won’t give feeding advice for this. When %dog% has been seen, I’m happy to help with food and recovery. To talk to a person at ZENOO, write to support@myzenoo.com.',
        ],
        'lt' => [
            'your_dog' => 'jūsų šuo',
            'head' => '**Prašome nedelsiant skambinti savo veterinarui arba į artimiausią skubios pagalbos veterinarijos kliniką.** Tai, ką aprašote, gali būti pavojinga (%dog%), todėl veterinaro reikia šiandien, o ne maisto pakeitimo.',
            'bloat' => 'Išpūstas pilvas su bandymu vemti gali būti skrandžio išsiplėtimas, kuris per kelias valandas blogėja. Vykite dabar ir pakeliui neduokite nei maisto, nei vandens.',
            'toxin' => 'Pasakykite veterinarui, ką šuo suėdė, maždaug kiek ir kada, ir, jei turite, pasiimkite pakuotę. Nesukelkite vėmimo, nebent tai lieptų veterinaras.',
            'blood' => 'Juodos, dervą primenančios išmatos ar daug kraujo gali reikšti vidinį kraujavimą. Jei galite, nufotografuokite ar paimkite mėginį veterinarui.',
            'vomiting' => 'Pasikartojantis vėmimas ar vėmimas kartu su vangumu ar atsisakymu gerti gali greitai sukelti dehidrataciją.',
            'puppy' => 'Šuniukai dehidratuoja daug greičiau nei suaugę šunys, todėl sergantį, vangų šuniuką reikia apžiūrėti šiandien.',
            'breathing' => 'Pasirūpinkite, kad šuo būtų ramus ir vėsoje, ir vykite tiesiai į kliniką.',
            'collapse' => 'Paguldykite šunį saugioje, ramioje vietoje, užsirašykite laiką ir vykite tiesiai į kliniką.',
            'heat' => 'Perkelkite šunį į pavėsį ar vėsų kambarį, sudrėkinkite kailį vėsiu (ne lediniu) vandeniu ir nedelsdami vykite pas veterinarą.',
            'urine' => 'Negalėjimas nusišlapinti yra skubus atvejis, ypač patinams.',
            'tail' => 'Esu DI patarėjas ir negaliu apžiūrėti šuns, todėl dėl šito nepatarsiu dėl šėrimo. Kai šunį apžiūrės veterinaras, mielai padėsiu su maistu ir sveikimu. Jei norite pasikalbėti su ZENOO žmogumi, rašykite support@myzenoo.com.',
        ],
        'uk' => [
            'your_dog' => 'ваш собака',
            'head' => '**Будь ласка, негайно зателефонуйте своєму ветеринару або до найближчої цілодобової ветклініки.** Те, що ви описуєте, може бути небезпечним (%dog%), і сьогодні потрібен ветеринар, а не зміна корму.',
            'bloat' => 'Здутий живіт із блювальними позивами може бути заворотом шлунка, який погіршується за години. Їдьте зараз і не давайте дорогою ні їжі, ні води.',
            'toxin' => 'Скажіть ветеринару, що саме собака з’їв, приблизно скільки і коли, і візьміть упаковку, якщо вона є. Не викликайте блювання, якщо цього не скаже ветеринар.',
            'blood' => 'Чорний дьогтеподібний кал або багато крові можуть означати внутрішню кровотечу. Якщо можете, зробіть фото або візьміть зразок для ветеринара.',
            'vomiting' => 'Повторне блювання або блювання разом із млявістю чи відмовою від води може швидко призвести до зневоднення.',
            'puppy' => 'Цуценята зневоднюються набагато швидше, ніж дорослі собаки, тож хворе, мляве цуценя треба показати лікарю сьогодні.',
            'breathing' => 'Тримайте собаку спокійним і в прохолоді та їдьте прямо до клініки.',
            'collapse' => 'Покладіть собаку в безпечному, тихому місці, запишіть час і їдьте прямо до клініки.',
            'heat' => 'Перенесіть собаку в тінь або прохолодну кімнату, змочіть шерсть прохолодною (не крижаною) водою і негайно їдьте до ветеринара.',
            'urine' => 'Неможливість помочитися — це невідкладний стан, особливо в кобелів.',
            'tail' => 'Я ШІ-помічник і не можу оглянути собаку, тож щодо цього не даватиму порад із годування. Коли собаку огляне ветеринар, я охоче допоможу з харчуванням і відновленням. Щоб поговорити з людиною з ZENOO, напишіть на support@myzenoo.com.',
        ],
        'pl' => [
            'your_dog' => 'twój pies',
            'head' => '**Zadzwoń teraz do swojego weterynarza albo do najbliższej całodobowej kliniki weterynaryjnej.** To, co opisujesz, może być groźne (%dog%) i wymaga dziś weterynarza, a nie zmiany karmy.',
            'bloat' => 'Wzdęty brzuch z próbami wymiotów może oznaczać skręt żołądka, który pogarsza się w ciągu godzin. Jedź teraz i nie podawaj po drodze jedzenia ani wody.',
            'toxin' => 'Powiedz weterynarzowi, co pies zjadł, mniej więcej ile i kiedy, i zabierz opakowanie, jeśli je masz. Nie wywołuj wymiotów, chyba że zaleci to weterynarz.',
            'blood' => 'Czarny, smolisty kał lub dużo krwi może oznaczać krwawienie wewnętrzne. Jeśli możesz, zrób zdjęcie albo weź próbkę dla weterynarza.',
            'vomiting' => 'Powtarzające się wymioty albo wymioty razem z osłabieniem czy odmową picia mogą szybko doprowadzić do odwodnienia.',
            'puppy' => 'Szczenięta odwadniają się dużo szybciej niż dorosłe psy, więc chore, osowiałe szczenię trzeba pokazać weterynarzowi dziś.',
            'breathing' => 'Zadbaj, żeby pies był spokojny i w chłodzie, i jedź prosto do kliniki.',
            'collapse' => 'Połóż psa w bezpiecznym, cichym miejscu, zanotuj godzinę i jedź prosto do kliniki.',
            'heat' => 'Przenieś psa w cień lub do chłodnego pokoju, zwilż sierść chłodną (nie lodowatą) wodą i od razu jedź do weterynarza.',
            'urine' => 'Brak możliwości oddania moczu to stan nagły, zwłaszcza u samców.',
            'tail' => 'Jestem asystentem AI i nie mogę zbadać psa, więc w tej sprawie nie doradzę w kwestii żywienia. Gdy weterynarz obejrzy psa, chętnie pomogę z karmą i powrotem do zdrowia. Aby porozmawiać z człowiekiem z ZENOO, napisz na support@myzenoo.com.',
        ],
    ];
}
