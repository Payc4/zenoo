<?php

namespace App\Course;

use App\Entity\Lesson;
use App\Entity\Product;
use App\Entity\User;
use App\Repository\ProductRepository;

/**
 * Who may open which lesson. Lessons are open to every signed-in user unless an admin marks
 * them "Locked" in the Course studio; locked lessons open after one payment for the lesson
 * unlock product (a Product with "Unlocks locked lessons" switched on, bought through Stripe).
 * Admins always see everything.
 */
final class LessonAccess
{
    public function __construct(private readonly ProductRepository $products)
    {
    }

    public function canOpen(Lesson $lesson, ?User $user, bool $isAdmin = false): bool
    {
        return $isAdmin || !$lesson->isLocked() || ($user !== null && $this->hasUnlock($user));
    }

    /** Whether this person bought the lesson unlock (any unlock product, even one since switched off). */
    public function hasUnlock(User $user): bool
    {
        foreach ($user->getPurchases() as $purchase) {
            if ($purchase->isCompleted() && $purchase->getProduct()?->unlocksLessons()) {
                return true;
            }
        }

        return false;
    }

    public function unlockProduct(): ?Product
    {
        return $this->products->findLessonUnlock();
    }
}
