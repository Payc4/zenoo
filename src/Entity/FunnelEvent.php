<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** A daily count of one funnel step from one source (src/Tracking/Funnel). No personal data. */
#[ORM\Entity]
#[ORM\Table(name: 'funnel_event')]
#[ORM\UniqueConstraint(name: 'UNIQ_FUNNEL_DAY_NAME_SOURCE', columns: ['day', 'name', 'source'])]
class FunnelEvent
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $day;

    #[ORM\Column(length: 60)]
    private string $name;

    #[ORM\Column(length: 80)]
    private string $source;

    #[ORM\Column]
    private int $count = 0;

    public function __construct(\DateTimeImmutable $day, string $name, string $source)
    {
        $this->day = $day;
        $this->name = $name;
        $this->source = $source;
    }

    public function getDay(): \DateTimeImmutable { return $this->day; }
    public function getName(): string { return $this->name; }
    public function getSource(): string { return $this->source; }
    public function getCount(): int { return $this->count; }
}
