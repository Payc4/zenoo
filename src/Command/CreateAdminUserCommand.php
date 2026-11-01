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

#[AsCommand(name: 'app:create-admin-user', description: 'Create an admin account (fails if the email is taken)')]
class CreateAdminUserCommand extends Command
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
            ->addArgument('email', InputArgument::REQUIRED, 'Admin email')
            ->addArgument('password', InputArgument::REQUIRED, 'Admin password (at least 8 characters)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $password = (string) $input->getArgument('password');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('That is not a valid email address.');

            return Command::INVALID;
        }
        if (strlen($password) < 8) {
            $io->error('The password needs at least 8 characters.');

            return Command::INVALID;
        }
        if ($this->users->findOneByEmail($email)) {
            $io->error('An account with this email already exists.');

            return Command::FAILURE;
        }

        $user = (new User())->setEmail($email)->setFirstName('Admin')->setRoles(['ROLE_ADMIN']);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('Admin account created: %s', $user->getEmail()));

        return Command::SUCCESS;
    }
}
