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
 * (test report proposals 2, 3 and 6): {{ member_dogs() }}, {{ member_dog() }} (the selected one).
 */
final class MemberExtension extends AbstractExtension
{
    private ?array $dogs = null;

    public function __construct(private readonly Security $security, private readonly DogProfileRepository $repo)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('member_dogs', $this->dogs(...)),
            new TwigFunction('member_dog', $this->dog(...)),
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

    public function dog(): ?DogProfile
    {
        $user = $this->security->getUser();
        return $user instanceof User ? $this->repo->findForUser($user) : null;
    }
}
