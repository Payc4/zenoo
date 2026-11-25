<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Yaml\Yaml;

/** The public "What's new" page: what changed in each release (config/patch_notes.yaml), in English. */
final class PatchNotesController extends AbstractController
{
    #[Route('/whats-new', name: 'app_whats_new', methods: ['GET'])]
    public function __invoke(#[Autowire('%kernel.project_dir%/config/patch_notes.yaml')] string $file): Response
    {
        $releases = array_values(array_filter((array) Yaml::parseFile($file), fn ($r) => \is_array($r) && isset($r['date'], $r['title'])));
        usort($releases, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));
        $response = $this->render('patch_notes.html.twig', ['releases' => $releases]);
        $response->setPublic();
        $response->setMaxAge(600);
        return $response;
    }
}
