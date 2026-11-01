<?php

namespace App\Command;

use App\Service\AvatarUploader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Run by the container entrypoint so a fresh (or restored) volume always has the folders it needs. */
#[AsCommand(name: 'app:init-upload-dirs', description: 'Create the upload folders if they are missing')]
class InitUploadDirsCommand extends Command
{
    public function __construct(private readonly AvatarUploader $avatars)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = $this->avatars->directory();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $output->writeln('<error>Could not create ' . $dir . '</error>');

            return Command::FAILURE;
        }
        $output->writeln('Upload folders ready.');

        return Command::SUCCESS;
    }
}
