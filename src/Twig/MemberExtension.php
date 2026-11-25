<?php

namespace App\Twig;

use App\Entity\DogProfile;
use App\Entity\User;
use App\Repository\DogProfileRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The signed-in pet parent's dogs, for the dog switcher in the top bar and the tab bar
 * (test report proposals 2, 3 and 6): {{ member_dogs() }}, {{ member_dog() }} (the selected one),
 * {{ dog_label(dog) }} (the name, told apart from a same-named one).
 */
final class MemberExtension extends AbstractExtension
{
    private ?array $dogs = null;

    public function __construct(
        private readonly Security $security,
        private readonly DogProfileRepository $repo,
        private readonly \Symfony\Contracts\Translation\TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('member_dogs', $this->dogs(...)),
            new TwigFunction('member_dog', $this->dog(...)),
            new TwigFunction('dog_label', $this->label(...)),
        ];
    }

    /** @return list<DogProfile> in the order they were added */
    public function dogs(): array
    {
        if ($this->dogs === null) {
            $user = $this->security->getUser();
            $this->dogs = $user instanceof User ? $this->repo->findAllForUser($user) : [];
            usort($this->dogs, fn (DogProfile $a, DogProfile $b) => $a->getId() <=> $b->getId());
        }
        return $this->dogs;
    }

    /**
     * The dog's name, told apart from another of theirs with the same name (test report: two "Max"
     * looked identical): "Max · Beagle", or "Max · 2" when the breed doesn't tell them apart either.
     */
    public function label(DogProfile $dog): string
    {
        $key = fn (DogProfile $d) => mb_strtolower(trim($d->getName()));
        $same = array_values(array_filter($this->dogs(), fn (DogProfile $d) => $key($d) === $key($dog)));
        if (\count($same) < 2) {
            return $dog->getName();
        }
        $breed = fn (DogProfile $d) => $d->get('breed') === 'mixed' ? $this->translator->trans('Mixed breed') : (string) $d->get('breed', '');
        $breeds = array_map($breed, $same);
        $mine = $breed($dog);
        if ($mine !== '' && \count(array_keys($breeds, $mine, true)) === 1) {
            return $dog->getName() . ' · ' . $mine;
        }
        foreach ($same as $i => $d) {
            if ($d->getId() === $dog->getId()) {
                return $dog->getName() . ' · ' . ($i + 1);
            }
        }
        return $dog->getName();
    }

    public function dog(): ?DogProfile
    {
        $user = $this->security->getUser();
        return $user instanceof User ? $this->repo->findForUser($user) : null;
    }
}
