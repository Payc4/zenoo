<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Makes the ADMIN_EMAIL account (Coolify) the owner: an admin who can also make other accounts
 * admins, or take it away, in the admin panel. Run on every deploy by the entrypoint.
 *
 * - The account exists: it becomes the owner. Its password is left alone.
 * - It doesn't exist yet: it is made, with the password given (first deploy).
 * - Anyone else who was the owner (ADMIN_EMAIL changed) stays an admin but no longer the owner.
 */
#[AsCommand(name: 'app:admin:owner', description: 'Make the ADMIN_EMAIL account the owner (the admin who manages admins)')]
final class AdminOwnerCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The owner’s email (ADMIN_EMAIL)')
            ->addArgument('password', InputArgument::OPTIONAL, 'Only used when the account doesn’t exist yet (ADMIN_PASSWORD)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = trim((string) $input->getArgument('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('That is not a valid email address.');

            return Command::INVALID;
        }

        $owner = $this->users->findOneByEmail($email);
        if (!$owner) {
            $password = (string) $input->getArgument('password');
            if (\strlen($password) < 8) {
                $io->error(sprintf('No account for %s yet, and no password of at least 8 characters to make one.', $email));

                return Command::FAILURE;
            }
            $owner = (new User())->setEmail($email)->setFirstName('Admin');
            $owner->setPassword($this->hasher->hashPassword($owner, $password));
            $this->em->persist($owner);
            $io->writeln(sprintf('Owner account created: %s', $email));
        }

        foreach ($this->users->findAll() as $user) {
            if ($user !== $owner && $user->isOwner()) {
                $user->setOwner(false);
                $user->setRoles(array_merge($user->getRoles(), ['ROLE_ADMIN'])); // still an admin
                $io->writeln(sprintf('%s is no longer the owner (still an admin).', $user->getEmail()));
            }
        }
        $owner->setOwner(true);
        $this->em->flush();
        $io->writeln(sprintf('Owner: %s', $owner->getEmail()));

        return Command::SUCCESS;
    }
}
